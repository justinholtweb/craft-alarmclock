<?php

namespace justinholtweb\alarmclock\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\alarmclock\models\Finding;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\TaskRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use Throwable;
use yii\base\Component;

/**
 * The "why isn't my post live?" service.
 *
 * Every question this answers is one somebody currently answers by reading forum threads about
 * cron, which in Craft is nearly always the wrong place to look. There are two shapes of question
 * and they need different answers:
 *
 * - `health()` — is scheduled publishing working *at all* on this installation? Clock drift,
 *   whether anything is ticking, whether statuses are stored, whether work is piling up.
 * - `explain()` — why is *this particular entry* not on the site? Almost always one of six things,
 *   none of which is cron.
 */
class Diagnostics extends Component
{
    // ------------------------------------------------------------------ installation health

    /**
     * @return Finding[]
     */
    public function health(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $findings = [];

        if (!$settings->enabled) {
            $findings[] = Finding::blocker(
                'Switched off',
                'Alarm Clock is disabled in its settings, so nothing is being detected and no task will run.',
                'Turn it back on under Settings → Plugins → Alarm Clock.',
            );

            return $findings;
        }

        $findings[] = $this->checkClockDrift();
        $findings[] = $this->checkTimeZones();
        $findings[] = $this->checkWatermarks();
        $findings[] = $this->checkTicking();
        $findings[] = $this->checkTriggers();
        $findings[] = $this->checkStaticStatuses();
        $findings[] = $this->checkAbandonedWork();
        $findings[] = $this->checkLatency();
        $findings[] = $this->checkNotificationConfig();

        return array_values(array_filter($findings));
    }

    /**
     * Whether PHP's host and the database's host agree about what time it is.
     *
     * Asked in UTC on both sides, deliberately. The obvious version — `SELECT NOW()` compared
     * against `time()` — measures the gap between the database's *session time zone* and PHP's
     * default zone, which are routinely different by design and say nothing at all about the
     * clocks. On this very machine that comparison reports a confident three-hour drift between
     * two containers whose clocks are identical.
     *
     * Framed as a note rather than a blocker, because Craft compares dates using values PHP
     * computes on both sides — the database's clock does not move publication times by itself. It
     * matters when it means NTP is broken somewhere, because the host running cron is then drifting
     * too, and that one does move them.
     */
    private function checkClockDrift(): Finding
    {
        try {
            $db = Craft::$app->getDb();
            $sql = $db->getIsMysql() ? 'SELECT UTC_TIMESTAMP()' : "SELECT timezone('UTC', now())";
            $dbUtc = (string)$db->createCommand($sql)->queryScalar();

            $drift = abs(strtotime($dbUtc . ' UTC') - time());
        } catch (Throwable $e) {
            return Finding::note('Clock', 'Could not read the database clock: ' . $e->getMessage());
        }

        if ($drift <= 5) {
            return Finding::ok('Clock', "PHP and the database agree to within {$drift}s.");
        }

        if ($drift < 120) {
            return Finding::note(
                'Clock',
                "The PHP and database hosts' clocks differ by {$drift} seconds.",
                'Craft compares dates with values PHP computes, so this does not move publication times on its own — but it usually means NTP is not running somewhere, and the host running cron is then drifting too.',
            );
        }

        return Finding::warning(
            'Clock',
            "The PHP and database hosts' clocks differ by {$drift} seconds — around " . round($drift / 60) . ' minutes.',
            'Check NTP on both hosts. A drift this size on whichever host runs cron publishes scheduled content exactly that early or that late, with no error anywhere.',
        );
    }

    /**
     * Whether a watermark is in the future.
     *
     * Only ever happens when two hosts disagree about the time — a web server writes a watermark,
     * and cron on another box reads it as being ahead of its own "now". Detection then stops dead
     * and stays stopped, because every scan finds its window inverted and returns immediately.
     * Silent, permanent, and impossible to guess at without being told.
     */
    private function checkWatermarks(): ?Finding
    {
        $ticker = Plugin::getInstance()->ticker;
        $now = new DateTime('now');
        $ahead = [];

        foreach ([Ticker::WATERMARK_PUBLISHED => 'published', Ticker::WATERMARK_EXPIRED => 'expired'] as $key => $label) {
            $watermark = $ticker->watermark($key, $now);

            if ($watermark > $now) {
                $ahead[] = sprintf('%s (%s ahead)', $label, $this->humanDuration($watermark->getTimestamp() - $now->getTimestamp()));
            }
        }

        if (!$ahead) {
            return Finding::ok('Watermarks', 'Both scan watermarks are in the past, as they should be.');
        }

        return Finding::blocker(
            'Watermarks',
            'A scan watermark is in the future: ' . implode(', ', $ahead) . '. Detection is stalled and will stay that way until the clock catches up.',
            'This means two hosts disagree about the time — one wrote the watermark, another is reading it. Fix the clocks, then run `craft alarm-clock/tick`.',
        );
    }

    /**
     * Whether the time zones line up.
     *
     * Craft stores dates in UTC and renders them in the system time zone. When PHP's default zone
     * disagrees with Craft's, dates typed into the control panel mean one thing and dates compared
     * on the front end mean another — which is the WordPress "set your timezone correctly" advice,
     * arriving in Craft by a different route.
     */
    private function checkTimeZones(): Finding
    {
        $craftZone = Craft::$app->getTimeZone();
        $phpZone = date_default_timezone_get();

        if ($craftZone === $phpZone) {
            return Finding::ok('Time zone', "Craft and PHP are both on {$craftZone}.");
        }

        $offsetCraft = (new DateTime('now', new DateTimeZone($craftZone)))->getOffset();
        $offsetPhp = (new DateTime('now', new DateTimeZone($phpZone)))->getOffset();

        if ($offsetCraft === $offsetPhp) {
            return Finding::note(
                'Time zone',
                "Craft is on {$craftZone} and PHP on {$phpZone}. They are named differently but currently agree on the offset.",
                'Worth aligning anyway — they will diverge at a daylight-saving boundary.',
            );
        }

        return Finding::warning(
            'Time zone',
            "Craft is on {$craftZone} and PHP on {$phpZone}, currently " . round(abs($offsetCraft - $offsetPhp) / 3600, 1) . ' hours apart.',
            'Set the same zone in Settings → General and in php.ini.',
        );
    }

    private function checkTicking(): Finding
    {
        $ticker = Plugin::getInstance()->ticker;
        $since = $ticker->secondsSinceLastTick();
        $settings = Plugin::getInstance()->getSettings();

        if ($since === null) {
            return Finding::warning(
                'Ticking',
                'Alarm Clock has never run a tick.',
                'Load a front-end page, run the queue, or run `craft alarm-clock/tick` to prove it works.',
            );
        }

        $source = $ticker->getState(Ticker::LAST_TICK_SOURCE) ?? 'unknown';
        $human = $this->humanDuration($since);

        // Ten intervals of silence is generous — a low-traffic site with no cron legitimately goes
        // quiet — but past that, something that should be running is not.
        if ($since > max(600, $settings->tickInterval * 10)) {
            return Finding::warning(
                'Ticking',
                "The last tick was {$human} ago, by the {$source} trigger.",
                'On a quiet site with no cron this is normal, and the next visitor will trigger one. If the site is busy, check that the front-end trigger is on and that pages are not all served from a static cache that never reaches PHP.',
            );
        }

        return Finding::ok('Ticking', "Last tick {$human} ago, by the {$source} trigger.");
    }

    private function checkTriggers(): Finding
    {
        $settings = Plugin::getInstance()->getSettings();
        $on = [];

        if ($settings->webTrigger) {
            $on[] = 'front-end requests';
        }

        if ($settings->queueTrigger) {
            $on[] = 'the queue';
        }

        if (!$on) {
            return Finding::warning(
                'Triggers',
                'Both automatic triggers are off, so only `craft alarm-clock/tick` will detect anything.',
                'That is a perfectly good setup if you have real cron. If you do not, turn the front-end trigger back on.',
            );
        }

        $message = 'Watching via ' . implode(' and ', $on) . '.';

        if ($settings->queueTrigger && !Plugin::getInstance()->ticker->hasQueuedTick()) {
            return Finding::note(
                'Triggers',
                $message . ' No tick is currently on the queue.',
                'One is pushed by the next front-end request or console tick. If the queue never runs on this site, the front-end trigger is doing all the work.',
            );
        }

        return Finding::ok('Triggers', $message);
    }

    /**
     * The one genuine missed-schedule bug Craft can have.
     */
    private function checkStaticStatuses(): Finding
    {
        if (!Craft::$app->getConfig()->getGeneral()->staticStatuses) {
            return Finding::ok(
                'Entry statuses',
                'Statuses are derived from the dates, so an entry goes live on time whether or not anything is running.',
            );
        }

        $stale = Plugin::getInstance()->ticker->staleStatusEntries(200);

        if (!$stale) {
            return Finding::ok(
                'Entry statuses',
                'The `staticStatuses` setting is on, and no entry is waiting for its stored status to catch up.',
            );
        }

        return Finding::blocker(
            'Entry statuses',
            sprintf(
                'The `staticStatuses` setting is on and %d %s past its post date but still stored as pending — genuinely not published.',
                count($stale),
                count($stale) === 1 ? 'entry is' : 'entries are',
            ),
            'Alarm Clock refreshes these itself on the next tick. If they keep piling up, the tick is not running — see the ticking check above.',
        );
    }

    private function checkAbandonedWork(): ?Finding
    {
        $counts = Plugin::getInstance()->tasks->statusCounts();
        $abandoned = $counts[TaskRecord::STATUS_ABANDONED] ?? 0;
        $failed = $counts[TaskRecord::STATUS_FAILED] ?? 0;

        if ($abandoned === 0 && $failed === 0) {
            return Finding::ok('Work queue', 'Nothing has failed.');
        }

        if ($abandoned === 0) {
            return Finding::note(
                'Work queue',
                "{$failed} task(s) have failed and are waiting to be retried.",
                'No action needed unless the number keeps climbing.',
            );
        }

        return Finding::warning(
            'Work queue',
            "{$abandoned} task(s) have been abandoned after using up their attempts" . ($failed ? ", and {$failed} more are still retrying." : '.'),
            'Look at the Problems screen for the errors, fix the cause, then retry them.',
        );
    }

    private function checkLatency(): ?Finding
    {
        $worst = Plugin::getInstance()->transitions->worstLatencySince((new DateTime('now'))->modify('-7 days'));

        if ($worst === null) {
            return Finding::note('Latency', 'Nothing has crossed a scheduled date in the past week, so there is nothing to measure yet.');
        }

        $human = $this->humanDuration($worst);

        if ($worst <= 300) {
            return Finding::ok('Latency', "The slowest detection in the past week was {$human} after the scheduled time.");
        }

        if ($worst <= 3600) {
            return Finding::note(
                'Latency',
                "The slowest detection in the past week was {$human} after the scheduled time.",
                'Fine if posts do not need to be exact. Lower the tick interval, or add real cron, if they do.',
            );
        }

        return Finding::warning(
            'Latency',
            "The slowest detection in the past week was {$human} after the scheduled time.",
            'That is long enough for a reader to notice. Add `* * * * * php craft alarm-clock/tick` to cron.',
        );
    }

    private function checkNotificationConfig(): ?Finding
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->notify && $settings->webhookUrl === '') {
            return null;
        }

        if ($settings->notify && !Plugin::getInstance()->notifier->recipients()) {
            return Finding::warning(
                'Notifications',
                'Email notifications are on but there are no recipients — no address and no user group.',
                'Add at least one, or turn notifications off so the plugin stops creating tasks that have nothing to do.',
            );
        }

        return Finding::ok('Notifications', 'Configured.');
    }

    // ------------------------------------------------------------------ single entry

    /**
     * Explains, for one entry, exactly what stands between it and being on the site.
     *
     * @return Finding[]
     */
    public function explain(Entry $entry): array
    {
        $findings = [];
        $now = new DateTime('now');

        if ($entry->getIsRevision()) {
            $findings[] = Finding::blocker('Revision', 'This is a revision — a snapshot of the past. Revisions never appear on the site.');
            return $findings;
        }

        if ($entry->getIsDraft()) {
            $findings[] = $this->explainDraft($entry);
        }

        // ---- switched on?

        if (!$entry->enabled) {
            $findings[] = Finding::blocker(
                'Disabled',
                'The entry is disabled for every site. Its post date is irrelevant until it is enabled.',
                'Switch it on in the entry’s status menu.',
            );
        } elseif (!$entry->enabledForSite) {
            $findings[] = Finding::blocker(
                'Disabled for this site',
                sprintf('The entry is enabled, but not for %s.', $entry->getSite()->name),
                'Open the status menu and enable it for this site.',
            );
        } else {
            $findings[] = Finding::ok('Enabled', 'Switched on for this site.');
        }

        // ---- the dates

        if (!$entry->postDate) {
            $findings[] = Finding::blocker(
                'No post date',
                'The entry has no post date, so Craft treats it as pending forever.',
                'Set one. Craft normally fills it in on save, so an entry without one usually arrived by import or migration.',
            );
        } elseif ($entry->postDate > $now) {
            $wait = $entry->postDate->getTimestamp() - $now->getTimestamp();

            $findings[] = Finding::note(
                'Scheduled',
                sprintf(
                    'Goes live %s, in %s.',
                    DateTimeHelper::toDateTime($entry->postDate)->format('Y-m-d H:i'),
                    $this->humanDuration($wait),
                ),
                'Nothing is wrong. Alarm Clock will clear the caches that mention it, and notify, within the tick interval of that moment.',
            );
        } else {
            $findings[] = Finding::ok(
                'Post date',
                sprintf('Passed %s ago.', $this->humanDuration($now->getTimestamp() - $entry->postDate->getTimestamp())),
            );
        }

        if ($entry->expiryDate && $entry->expiryDate <= $now) {
            $findings[] = Finding::blocker(
                'Expired',
                sprintf('The expiry date passed %s ago, so the entry is off the site again.', $this->humanDuration($now->getTimestamp() - $entry->expiryDate->getTimestamp())),
                'Clear the expiry date, or push it into the future.',
            );
        }

        // ---- can it even have a URL?

        $findings[] = $this->explainUrl($entry);

        // ---- stored status

        if (Craft::$app->getConfig()->getGeneral()->staticStatuses) {
            $findings[] = $this->explainStoredStatus($entry, $now);
        }

        // ---- what we did about it

        $findings[] = $this->explainHistory($entry);

        return array_values(array_filter($findings));
    }

    private function explainDraft(Entry $entry): Finding
    {
        $schedule = Plugin::getInstance()->schedules->forDraft((int)$entry->draftId, (int)$entry->siteId);

        if ($schedule) {
            return Finding::note(
                'Scheduled draft',
                sprintf('This draft is scheduled to be applied at %s.', $schedule->getPublishAtDate()?->format('Y-m-d H:i') ?? '?'),
                'It will replace the live content at that time, with the usual cache clearing and notification.',
            );
        }

        return Finding::blocker(
            'Draft',
            'This is a draft, so it is not on the site at all — drafts have no post date of their own.',
            'Apply it, or give it a time in the Alarm Clock panel in the sidebar.',
        );
    }

    private function explainUrl(Entry $entry): Finding
    {
        $section = $entry->getSection();

        if (!$section) {
            return Finding::note('URL', 'This entry belongs to a field, not a section, so it has no URL of its own.');
        }

        $siteSettings = $section->getSiteSettings()[$entry->siteId] ?? null;

        if (!$siteSettings) {
            return Finding::blocker(
                'Not enabled for this site',
                sprintf('The “%s” section is not enabled for %s, so this entry cannot appear there at all.', $section->name, $entry->getSite()->name),
                'Add the site under Settings → Sections.',
            );
        }

        if (!$siteSettings->hasUrls) {
            return Finding::note(
                'No URL',
                sprintf('The “%s” section has no URLs on this site, so the entry appears only where a template lists it.', $section->name),
                'This is often correct. If you expected a page, set a URI format under Settings → Sections.',
            );
        }

        return Finding::ok('URL', (string)($entry->getUrl() ?? 'set, but not resolvable right now'));
    }

    private function explainStoredStatus(Entry $entry, DateTime $now): Finding
    {
        $stored = $entry->getStatus();
        $shouldBeLive = $entry->enabled
            && $entry->enabledForSite
            && $entry->postDate
            && $entry->postDate <= $now
            && (!$entry->expiryDate || $entry->expiryDate > $now);

        if ($shouldBeLive && $stored !== Entry::STATUS_LIVE) {
            return Finding::blocker(
                'Stored status is stale',
                sprintf('This site runs with `staticStatuses` on, and the stored status is “%s” although the dates say live. The entry is genuinely not published.', $stored ?? 'unknown'),
                'Alarm Clock refreshes it on the next tick. To do it now, run `craft alarm-clock/tick` or `craft update-statuses`.',
            );
        }

        return Finding::ok('Stored status', sprintf('`staticStatuses` is on and the stored status (“%s”) matches the dates.', $stored ?? 'unknown'));
    }

    private function explainHistory(Entry $entry): Finding
    {
        $record = TransitionRecord::find()
            ->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])
            ->orderBy(['detectedAt' => SORT_DESC])
            ->one();

        if (!$record) {
            return Finding::note(
                'Alarm Clock history',
                'Alarm Clock has not recorded a crossing for this entry.',
                'Expected if it was published before the plugin was installed, or if its post date is still ahead of it.',
            );
        }

        $tasks = Plugin::getInstance()->tasks->forTransition((int)$record->id);
        $bad = array_filter($tasks, fn($t) => in_array($t->status, [TaskRecord::STATUS_FAILED, TaskRecord::STATUS_ABANDONED], true));

        if ($bad) {
            $first = reset($bad);

            return Finding::warning(
                'Alarm Clock history',
                sprintf(
                    'Recorded as %s at %s, but %d of its %d follow-up tasks failed. First error: %s',
                    $record->transition,
                    $record->detectedAt,
                    count($bad),
                    count($tasks),
                    $first->lastError ?: 'not recorded',
                ),
                'Retry it from the Problems screen once the cause is fixed.',
            );
        }

        return Finding::ok(
            'Alarm Clock history',
            sprintf('Recorded as %s at %s; all %d follow-up tasks succeeded.', $record->transition, $record->detectedAt, count($tasks)),
        );
    }

    // ------------------------------------------------------------------ helpers

    public function humanDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => $seconds . ' second' . ($seconds === 1 ? '' : 's'),
            $seconds < 3600 => round($seconds / 60) . ' minute' . (round($seconds / 60) == 1 ? '' : 's'),
            $seconds < 86400 => round($seconds / 3600, 1) . ' hours',
            default => round($seconds / 86400, 1) . ' days',
        };
    }

    /** Whether anything in a set of findings would stop content appearing. */
    public function hasProblems(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding instanceof Finding && $finding->isProblem()) {
                return true;
            }
        }

        return false;
    }
}
