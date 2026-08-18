<?php

namespace justinholtweb\alarmclock\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\ScheduleRecord;
use yii\console\ExitCode;

/**
 * `craft alarm-clock/schedule`
 */
class ScheduleController extends Controller
{
    public $defaultAction = 'list';

    /** Enable the entry when the draft is applied. */
    public bool $enable = false;

    /** Site handle the draft belongs to. Defaults to the primary site. */
    public ?string $site = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'draft' => array_merge(parent::options($actionID), ['enable', 'site']),
            default => parent::options($actionID),
        };
    }

    /**
     * Lists what is scheduled.
     */
    public function actionList(): int
    {
        $plugin = Plugin::getInstance();
        $entries = $plugin->ticker->upcoming(50);
        $drafts = $plugin->schedules->upcoming(50);

        $this->stdout("\nEntries waiting on a post date\n", Console::FG_CYAN, Console::BOLD);

        if (!$entries) {
            $this->stdout("  (none)\n", Console::FG_GREY);
        }

        foreach ($entries as $entry) {
            $this->stdout(sprintf(
                "  %s  %-40s  %s\n",
                $entry->postDate?->format('Y-m-d H:i') ?? '????-??-?? ??:??',
                mb_strimwidth((string)$entry->title, 0, 40, '…'),
                $entry->getSite()->handle,
            ));
        }

        $this->stdout("\nDrafts waiting to be applied\n", Console::FG_CYAN, Console::BOLD);

        if (!$drafts) {
            $this->stdout("  (none)\n", Console::FG_GREY);
        }

        foreach ($drafts as $record) {
            $this->stdout(sprintf("  %s  draft %d → entry %s\n", $record->getPublishAtDate()?->format('Y-m-d H:i') ?? '?', $record->draftId, $record->canonicalId ?? '?'));
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Schedules a draft to be applied at a given time.
     *
     * @param int $draftId The draft's ID (the `draftId` column, not the element ID).
     * @param string $when Anything `DateTimeHelper` understands — "2026-09-01 09:00", "+2 hours".
     */
    public function actionDraft(int $draftId, string $when): int
    {
        $siteId = $this->site
            ? Craft::$app->getSites()->getSiteByHandle($this->site)?->id
            : Craft::$app->getSites()->getPrimarySite()->id;

        if (!$siteId) {
            $this->stderr("No such site: {$this->site}\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $draft = Entry::find()
            ->draftId($draftId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(true)
            ->provisionalDrafts(null)
            ->one();

        if (!$draft) {
            $this->stderr("No draft $draftId on that site.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $date = DateTimeHelper::toDateTime($when);

        if (!$date) {
            $this->stderr("Could not read “$when” as a date and time.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $record = Plugin::getInstance()->schedules->schedule($draft, $date, $this->enable);

        $this->stdout(sprintf(
            "Scheduled draft %d (%s) to be applied at %s.\n",
            $draftId,
            $draft->title,
            $record->getPublishAtDate()?->format('Y-m-d H:i') ?? '?',
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Cancels a scheduled draft.
     */
    public function actionCancel(int $id): int
    {
        if (!Plugin::getInstance()->schedules->cancel($id)) {
            $this->stderr("Schedule $id is not something that can be canceled.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("Canceled schedule $id.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
