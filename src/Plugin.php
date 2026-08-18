<?php

namespace justinholtweb\alarmclock;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Request as WebRequest;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\alarmclock\models\Settings;
use justinholtweb\alarmclock\records\TransitionRecord;
use justinholtweb\alarmclock\services\Diagnostics;
use justinholtweb\alarmclock\services\Notifier;
use justinholtweb\alarmclock\services\Schedules;
use justinholtweb\alarmclock\services\Tasks;
use justinholtweb\alarmclock\services\Ticker;
use justinholtweb\alarmclock\services\Transitions;
use justinholtweb\alarmclock\twig\AlarmClockVariable;
use Throwable;
use yii\base\Event;

/**
 * Alarm Clock — scheduled publishing that tells you it happened.
 *
 * @property-read Ticker $ticker
 * @property-read Transitions $transitions
 * @property-read Tasks $tasks
 * @property-read Schedules $schedules
 * @property-read Notifier $notifier
 * @property-read Diagnostics $diagnostics
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'alarmClock:view';
    public const PERMISSION_MANAGE = 'alarmClock:manage';
    public const PERMISSION_SCHEDULE = 'alarmClock:schedule';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'alarm-clock';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'ticker' => Ticker::class,
                'transitions' => Transitions::class,
                'tasks' => Tasks::class,
                'schedules' => Schedules::class,
                'notifier' => Notifier::class,
                'diagnostics' => Diagnostics::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerEntrySidebar();
        $this->registerWebTrigger();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('alarm-clock', 'Alarm Clock');

        $item['subnav'] = [
            'upcoming' => [
                'label' => Craft::t('alarm-clock', 'Upcoming'),
                'url' => 'alarm-clock/upcoming',
            ],
            'history' => [
                'label' => Craft::t('alarm-clock', 'History'),
                'url' => 'alarm-clock/history',
            ],
            'problems' => [
                'label' => Craft::t('alarm-clock', 'Problems'),
                'url' => 'alarm-clock/problems',
            ],
            'diagnostics' => [
                'label' => Craft::t('alarm-clock', 'Diagnostics'),
                'url' => 'alarm-clock/diagnostics',
            ],
        ];

        // A number beside the nav item, but only when it means "something needs you". A badge that
        // is permanently showing a count is a badge people stop reading.
        try {
            $problems = $this->tasks->statusCounts();
            $count = ($problems['abandoned'] ?? 0);

            if ($count > 0) {
                $item['badgeCount'] = $count;
            }
        } catch (Throwable) {
            // Before the install migration has run, the tables are not there yet.
        }

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('alarm-clock', 'Settings'),
                'url' => 'settings/plugins/alarm-clock',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('alarm-clock/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'userGroups' => Craft::$app->getUserGroups()->getAllGroups(),
        ]);
    }

    // ------------------------------------------------------------------ the web trigger

    /**
     * Ticks on front-end requests, once the response is out of the door.
     *
     * This is the trigger borrowed most directly from WordPress's Scheduled Post Trigger, and the
     * borrowing stops at the idea. That plugin checks on the way *in*, publishes inline, and says
     * in its own description that it is a stop-gap not to be used on a busy site — because the
     * visitor pays for the work.
     *
     * Here it hangs off `Response::EVENT_AFTER_SEND`, which fires after `sendContent()`, and the
     * work it does is bounded twice over: at most `maxTasksPerWebTick` tasks, and at most
     * `webTickBudget` seconds of them. Whatever does not fit is left for the next tick — which is
     * the whole reason tasks are durable rows rather than something held in memory for the length
     * of one request.
     *
     * `Application::EVENT_AFTER_REQUEST` is the obvious-looking hook and the wrong one: Yii fires
     * it at `Application::run()` line 385, three lines *before* `$response->send()`. Everything
     * done there is done while the visitor is still waiting.
     *
     * Deliberately front-end only. Ticking on control-panel requests would mean an editor's own
     * page load sends the email announcing their post, and any error surfacing inside the CP
     * rather than in the log.
     */
    private function registerWebTrigger(): void
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest || !$request->getIsSiteRequest() || $request->getIsPreview()) {
            return;
        }

        Event::on(Response::class, Response::EVENT_AFTER_SEND, function() {
            try {
                if (!$this->ticker->webTickIsDue()) {
                    return;
                }

                // The bytes have been written but FPM keeps the connection open until the script
                // ends. Letting go of it here means the tick runs on time we have already been
                // paid for rather than on the visitor's.
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }

                $this->ticker->tick(TransitionRecord::SOURCE_WEB);
                $this->ticker->scheduleNext();
            } catch (Throwable $e) {
                // The response has already gone. There is nothing left to fail into, and anything
                // thrown here would land in the log as an unhandled exception on a page the
                // visitor received perfectly well.
                Craft::error('Alarm Clock’s front-end tick failed: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    // ------------------------------------------------------------------ entry sidebar

    /**
     * Adds a panel to the entry editor.
     *
     * Two jobs, depending on what is being edited: for a pending entry it says when Alarm Clock
     * expects to act and what it will do; for a draft it offers a time to apply it. Both answer a
     * question the editor is already asking on that screen, which is why the panel lives there
     * rather than on a settings page they would have to go and find.
     */
    private function registerEntrySidebar(): void
    {
        Event::on(Entry::class, Entry::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if (!Craft::$app->getRequest()->getIsCpRequest() || $entry->id === null) {
                return;
            }

            if ($entry->getIsRevision()) {
                return;
            }

            try {
                $event->html .= Craft::$app->getView()->renderTemplate('alarm-clock/_sidebar', [
                    'entry' => $entry,
                    'schedule' => $entry->getIsDraft()
                        ? $this->schedules->forDraft((int)$entry->draftId, (int)$entry->siteId)
                        : null,
                    'canSchedule' => Craft::$app->getUser()->checkPermission(self::PERMISSION_SCHEDULE),
                ], View::TEMPLATE_MODE_CP);
            } catch (Throwable $e) {
                Craft::warning('Could not render the Alarm Clock sidebar: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    // ------------------------------------------------------------------ registration

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'alarm-clock' => 'alarm-clock/dashboard/upcoming',
                'alarm-clock/upcoming' => 'alarm-clock/dashboard/upcoming',
                'alarm-clock/history' => 'alarm-clock/dashboard/history',
                'alarm-clock/problems' => 'alarm-clock/dashboard/problems',
                'alarm-clock/diagnostics' => 'alarm-clock/diagnostics/index',
                'alarm-clock/diagnostics/<entryId:\d+>' => 'alarm-clock/diagnostics/entry',
                'alarm-clock/diagnostics/<entryId:\d+>/<siteHandle:{handle}>' => 'alarm-clock/diagnostics/entry',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('alarm-clock', 'Alarm Clock'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('alarm-clock', 'View the schedule, history and diagnostics'),
                        'nested' => [
                            self::PERMISSION_SCHEDULE => [
                                'label' => Craft::t('alarm-clock', 'Schedule drafts'),
                            ],
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('alarm-clock', 'Retry failed work and run ticks by hand'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('alarmClock', AlarmClockVariable::class);
        });
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->transitions->prune();
            $this->tasks->prune();
            $this->schedules->collectGarbage();
        });
    }
}
