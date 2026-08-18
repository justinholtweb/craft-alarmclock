<?php

namespace justinholtweb\alarmclock\services;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\queue\Queue as CraftQueue;
use DateTime;
use DateTimeZone;
use justinholtweb\alarmclock\events\TransitionEvent;
use justinholtweb\alarmclock\models\TickResult;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\queue\jobs\TickJob;
use justinholtweb\alarmclock\records\StateRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use Throwable;
use yii\base\Component;

/**
 * Detection.
 *
 * The thing worth understanding before changing anything here: **Craft does not miss scheduled
 * posts**. Entry status is derived in SQL from `postDate` and `expiryDate`, so an entry becomes
 * live the instant the clock passes it, with or without cron. There is nothing to "trigger".
 *
 * What Craft misses is that it *happened*. No event fires, no cache is invalidated, nothing is
 * logged, and nobody is told — because from Craft's point of view no row changed. So a cached
 * listing goes on serving the pre-publication HTML, the editor sees a live entry and a stale home
 * page, and every explanation on offer is about cron, which was never involved.
 *
 * This service is therefore not a publisher. It is a *noticer*: it watches two dates go by and
 * hands what it finds to the ledger, which decides whether anyone has noticed already.
 *
 * ## Why three triggers
 *
 * A console command is the right answer and many sites do not have one. A queue job is the right
 * answer and plenty of sites never run their queue. A front-end sweep works absolutely everywhere
 * and is the crudest of the three. Running all three and letting the ledger deduplicate is worth
 * more than picking the best one, because the failure being designed against is *the site owner
 * not knowing which of the three their host actually supports*.
 */
class Ticker extends Component
{
    /** @event TransitionEvent Fired after a crossing is recorded for the first time. */
    public const EVENT_AFTER_TRANSITION = 'afterTransition';

    public const WATERMARK_PUBLISHED = 'watermark.published';
    public const WATERMARK_EXPIRED = 'watermark.expired';
    public const LAST_TICK = 'lastTick';
    public const LAST_TICK_SOURCE = 'lastTickSource';

    private const MUTEX_NAME = 'alarm-clock:tick';

    /**
     * Runs one tick.
     *
     * @param string $source Which trigger asked, for the history and for the budgets.
     * @param bool $runTasks Whether to also work the task queue. The web trigger does, within a
     *                       tight budget, because on a site with no cron and no queue runner it is
     *                       the only thing that ever will.
     */
    public function tick(string $source, bool $runTasks = true): TickResult
    {
        $started = microtime(true);
        $result = new TickResult(['source' => $source]);
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled) {
            $result->notes[] = 'Alarm Clock is switched off in its settings.';
            return $result;
        }

        $mutex = Craft::$app->getMutex();

        // Zero timeout, not a wait. A tick that queues behind another tick is a tick doing nothing
        // useful while holding a PHP process open — and on the web trigger, a visitor waiting.
        if (!$mutex->acquire(self::MUTEX_NAME, 0)) {
            $result->duration = microtime(true) - $started;
            return $result;
        }

        $result->ran = true;

        try {
            $published = $this->detectPublished($source, $settings->maxTransitionsPerTick);
            $result->published = $published['count'];
            $result->more = $result->more || $published['more'];

            if ($settings->watchExpiry) {
                $expired = $this->detectExpired($source, $settings->maxTransitionsPerTick);
                $result->expired = $expired['count'];
                $result->more = $result->more || $expired['more'];
            }

            $result->applied = Plugin::getInstance()->schedules->queueDue();

            if ($runTasks) {
                $isWeb = $source === TransitionRecord::SOURCE_WEB;

                $ran = Plugin::getInstance()->tasks->runDue(
                    $isWeb ? $settings->maxTasksPerWebTick : $settings->maxTasksPerRun,
                    $isWeb ? (float)$settings->webTickBudget : null,
                );

                $result->tasksRun = $ran['run'];
                $result->tasksFailed = $ran['failed'];
                $result->tasksReclaimed = $ran['reclaimed'];
            }

            $this->setState(self::LAST_TICK, (new DateTime('now'))->format(DATE_ATOM));
            $this->setState(self::LAST_TICK_SOURCE, $source);
        } finally {
            // In a `finally` so a thrown exception cannot leave the lock held. A stuck mutex means
            // every subsequent tick returns "already running" forever, which looks exactly like
            // the plugin having quietly stopped.
            $mutex->release(self::MUTEX_NAME);
        }

        $result->duration = microtime(true) - $started;

        return $result;
    }

    // ------------------------------------------------------------------ detection

    /**
     * Finds entries that have become live since the watermark.
     *
     * @return array{count: int, more: bool}
     */
    public function detectPublished(string $source, int $limit): array
    {
        $now = Db::prepareDateForDb(new DateTime('now'));

        return $this->scan(
            watermark: self::WATERMARK_PUBLISHED,
            transition: TransitionRecord::TRANSITION_PUBLISHED,
            dateColumn: 'postDate',
            // Something that went live and expired inside the same window has not published, it
            // has been and gone; announcing it would send readers to a 404.
            extraCondition: [
                'or',
                ['entries.expiryDate' => null],
                ['>', 'entries.expiryDate', $now],
            ],
            source: $source,
            limit: $limit,
        );
    }

    /**
     * @return array{count: int, more: bool}
     */
    public function detectExpired(string $source, int $limit): array
    {
        return $this->scan(
            watermark: self::WATERMARK_EXPIRED,
            transition: TransitionRecord::TRANSITION_EXPIRED,
            dateColumn: 'expiryDate',
            extraCondition: null,
            source: $source,
            limit: $limit,
        );
    }

    /**
     * The scan both detections share.
     *
     * ## Why this asks about dates and not about status
     *
     * The obvious query is `status('live')` plus a post-date window, and it is wrong twice over.
     *
     * With Craft's default derived statuses, `statusCondition()` rounds "now" up to :59 of the
     * current minute for cacheability, so status and post date disagree by up to a minute at
     * exactly the boundary this scan lives on.
     *
     * Worse, with the `staticStatuses` config setting (Craft 5.7+) `entries.status` is a *stored
     * column* that only changes on save or when `craft update-statuses` is run. On those sites an
     * entry really does stay pending after its post date — a genuine missed schedule, the same
     * shape as the WordPress problem this plugin's name comes from — and a status-based scan would
     * see nothing at all, on precisely the sites that need it most.
     *
     * Asking about the dates and the enabled flags directly is true under both settings, matches
     * `EntryQuery::statusCondition()`'s own definition of live, and is indexed either way.
     *
     * @return array{count: int, more: bool}
     */
    private function scan(
        string $watermark,
        string $transition,
        string $dateColumn,
        ?array $extraCondition,
        string $source,
        int $limit,
    ): array {
        $settings = Plugin::getInstance()->getSettings();
        $now = new DateTime('now');
        $from = $this->watermark($watermark, $now);

        // Reach back a little further than the watermark says is necessary. Clocks get nudged by
        // NTP, and a database server and a web server can disagree by a second or two; either can
        // drop a crossing into a gap the watermark believes it has already covered. Re-scanning is
        // one indexed query and the ledger throws the duplicates away.
        $from = (clone $from)->modify('-' . $settings->lookback . ' seconds');

        if ($from >= $now) {
            return ['count' => 0, 'more' => false];
        }

        $query = Entry::find()
            ->siteId('*')
            ->unique(false)
            // `null`, not a status. See the note above: the status column cannot be trusted to
            // have caught up, and on `staticStatuses` sites it is the very thing that has not.
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->provisionalDrafts(false)
            ->andWhere([
                'elements.enabled' => true,
                'elements_sites.enabled' => true,
            ])
            // Raw column conditions, so `Db::prepareDateForDb()` is right here — unlike an element
            // query *date param*, which reads a bare `Y-m-d H:i:s` as system time and converts it
            // to UTC a second time, silently matching nothing.
            ->andWhere(['>=', "entries.$dateColumn", Db::prepareDateForDb($from)])
            ->andWhere(['<', "entries.$dateColumn", Db::prepareDateForDb($now)])
            ->orderBy(["entries.$dateColumn" => SORT_ASC, 'elements.id' => SORT_ASC])
            ->limit($limit + 1);

        if ($extraCondition !== null) {
            $query->andWhere($extraCondition);
        }

        if ($sectionIds = $this->watchedSectionIds()) {
            $query->sectionId($sectionIds);
        }

        $entries = $query->all();
        $more = count($entries) > $limit;

        if ($more) {
            array_pop($entries);
        }

        $count = 0;
        $furthest = null;

        foreach ($entries as $entry) {
            $when = $entry->$dateColumn;

            if (!$when instanceof DateTime) {
                continue;
            }

            $furthest = $when;

            $recorded = Plugin::getInstance()->transitions->recordForElement($entry, $transition, $when, $source);

            if ($recorded === null) {
                continue;
            }

            $count++;
            $this->afterTransition($recorded, $entry);
        }

        // When the batch was capped, the watermark advances only as far as the last crossing that
        // was actually handled — not to "now". Moving it to now would mark the remainder of the
        // backlog as covered and lose it. A site coming back after a fortnight off therefore
        // catches up over several ticks instead of trying to do a fortnight in one request.
        $this->setState($watermark, ($more && $furthest ? $furthest : $now)->format(DATE_ATOM));

        return ['count' => $count, 'more' => $more];
    }

    /**
     * Records the tasks a transition is owed and tells the rest of the site about it.
     */
    public function afterTransition(Transition $transition, ?Entry $entry = null): void
    {
        Plugin::getInstance()->tasks->queueForTransition($transition);

        if ($this->hasEventHandlers(self::EVENT_AFTER_TRANSITION)) {
            try {
                $this->trigger(self::EVENT_AFTER_TRANSITION, new TransitionEvent([
                    'transition' => $transition,
                    'element' => $entry ?? $transition->getElement(),
                ]));
            } catch (Throwable $e) {
                // Somebody else's event handler is not allowed to stop the rest of the batch, and
                // certainly not allowed to take a front-end page down.
                Craft::error('A transition event handler threw: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }
    }

    // ------------------------------------------------------------------ watched sections

    /**
     * @return int[]
     */
    public function watchedSectionIds(): array
    {
        $uids = Plugin::getInstance()->getSettings()->sections;

        if (!$uids) {
            return [];
        }

        $ids = [];

        foreach ($uids as $uid) {
            $section = Craft::$app->getEntries()->getSectionByUid($uid);

            if ($section) {
                $ids[] = $section->id;
            }
        }

        // A configured-but-unresolvable list would otherwise fall through to "watch everything",
        // which is the opposite of what was asked for. Guarantee a non-matching id instead.
        return $ids ?: [0];
    }

    // ------------------------------------------------------------------ state

    public function watermark(string $key, ?DateTime $default = null): DateTime
    {
        return $this->parseState($this->getState($key)) ?? $default ?? new DateTime('now');
    }

    /**
     * Reads a stored instant back.
     *
     * Watermarks are written as `DATE_ATOM`, which carries its own offset and cannot be
     * misread. A bare `Y-m-d H:i:s` can only have come from somewhere that meant UTC — that is
     * what `Db::prepareDateForDb()` produces — and it is read as such, because `new DateTime()`
     * would otherwise interpret it in the *site's* zone. West of UTC that puts the watermark
     * hours into the future, every scan finds its window inverted and returns immediately, and
     * detection stops without a word.
     */
    private function parseState(?string $value): ?DateTime
    {
        if (!$value) {
            return null;
        }

        try {
            $hasZone = (bool)preg_match('/(Z|[+-]\\d{2}:?\\d{2})$/', trim($value));

            return new DateTime($value, $hasZone ? null : new DateTimeZone('UTC'));
        } catch (Throwable) {
            // A default beats scanning from the epoch and announcing the entire archive.
            return null;
        }
    }

    public function getState(string $key): ?string
    {
        return StateRecord::findOne(['key' => $key])?->value;
    }

    /**
     * Writes a state value.
     *
     * An upsert, and it has to be. The obvious update-then-insert-if-nothing-changed is wrong on
     * MySQL, which reports **zero affected rows for an update that sets a column to the value it
     * already holds** — so writing the same watermark twice falls through to an insert and dies on
     * the primary key. Two ticks in the same second do exactly that.
     *
     * `Db::upsert()`'s fourth argument is `$params`, not more columns: the whole row goes in the
     * second argument, and only what should change on a conflict goes in the third.
     */
    public function setState(string $key, ?string $value): void
    {
        $now = Db::prepareDateForDb(new DateTime('now'));

        Db::upsert(
            StateRecord::TABLE,
            [
                'key' => $key,
                'value' => $value,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ],
            [
                'value' => $value,
                'dateUpdated' => $now,
            ],
        );
    }

    public function lastTickAt(): ?DateTime
    {
        return $this->parseState($this->getState(self::LAST_TICK));
    }

    /** Seconds since the last tick, or null if there has never been one. */
    public function secondsSinceLastTick(): ?int
    {
        $last = $this->lastTickAt();

        return $last ? max(0, time() - $last->getTimestamp()) : null;
    }

    /**
     * Whether the web trigger is allowed to run right now.
     *
     * Checked against the cache first so the overwhelming majority of page loads — the ones that
     * are inside the throttle window — cost one cache read and no database query at all.
     */
    public function webTickIsDue(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled || !$settings->webTrigger) {
            return false;
        }

        $cache = Craft::$app->getCache();

        if ($cache->get('alarm-clock:throttle') !== false) {
            return false;
        }

        $since = $this->secondsSinceLastTick();

        if ($since !== null && $since < $settings->tickInterval) {
            // Re-arm the cache guard for whatever is left of the window, so a cache that was just
            // cleared does not mean every page load goes back to the database.
            $cache->set('alarm-clock:throttle', 1, $settings->tickInterval - $since);
            return false;
        }

        $cache->set('alarm-clock:throttle', 1, $settings->tickInterval);

        return true;
    }

    // ------------------------------------------------------------------ queue trigger

    public const QUEUE_JOB_ID = 'queueJobId';

    /**
     * Puts the next tick on the queue, unless one is already there.
     *
     * The guard is not decoration. Without it, every request that noticed the chain was missing
     * would push another link, and a site under load would build a queue of thousands of ticks
     * that each do nothing but push another one.
     */
    public function scheduleNext(?int $delay = null): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled || !$settings->queueTrigger) {
            return null;
        }

        if ($this->hasQueuedTick()) {
            return null;
        }

        $id = Queue::push(
            job: new TickJob(['interval' => $settings->tickInterval]),
            // Below Craft's default of 1024, so a tick goes ahead of the resaves and index
            // rebuilds it may be sitting behind. A tick is cheap and time-critical; those are
            // neither.
            priority: 512,
            delay: $delay ?? $settings->tickInterval,
        );

        $this->setState(self::QUEUE_JOB_ID, $id);

        return $id;
    }

    public function hasQueuedTick(): bool
    {
        $id = $this->getState(self::QUEUE_JOB_ID);

        if (!$id) {
            return false;
        }

        try {
            // An unknown id reports as done rather than throwing, so a job that has been run,
            // released or cleared correctly reads as "no tick queued".
            $status = Craft::$app->getQueue()->status($id);
        } catch (Throwable) {
            return false;
        }

        return in_array($status, [CraftQueue::STATUS_WAITING, CraftQueue::STATUS_RESERVED], true);
    }

    /** How many entries are waiting on a post date right now, across every site. */
    public function pendingCount(): int
    {
        return (int)$this->pendingQuery()->count();
    }

    /**
     * The next few entries due to go live.
     *
     * @return Entry[]
     */
    public function upcoming(int $limit = 50): array
    {
        return $this->pendingQuery()
            ->orderBy(['entries.postDate' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    /**
     * Entries whose post date is still ahead of them.
     *
     * Expressed as dates rather than `status('pending')` for the same reason the scan is: on a
     * `staticStatuses` site the stored status is exactly the thing that may be stale, and a
     * dashboard that reads it would confidently show nothing pending while a backlog sat there.
     */
    public function pendingQuery(): EntryQuery
    {
        return Entry::find()
            ->siteId('*')
            ->unique(false)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->provisionalDrafts(false)
            ->andWhere([
                'elements.enabled' => true,
                'elements_sites.enabled' => true,
            ])
            ->andWhere(['>', 'entries.postDate', Db::prepareDateForDb(new DateTime('now'))]);
    }

    /**
     * Entries whose post date has passed but whose stored status has not caught up.
     *
     * Only ever non-empty on a `staticStatuses` site, where this is the set that is genuinely
     * failing to publish — the closest thing Craft has to WordPress's missed schedule.
     *
     * @return Entry[]
     */
    public function staleStatusEntries(int $limit = 100): array
    {
        if (!Craft::$app->getConfig()->getGeneral()->staticStatuses) {
            return [];
        }

        $now = Db::prepareDateForDb(new DateTime('now'));

        return Entry::find()
            ->siteId('*')
            ->unique(false)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->provisionalDrafts(false)
            ->andWhere([
                'elements.enabled' => true,
                'elements_sites.enabled' => true,
                'entries.status' => Entry::STATUS_PENDING,
            ])
            ->andWhere(['<=', 'entries.postDate', $now])
            ->andWhere([
                'or',
                ['entries.expiryDate' => null],
                ['>', 'entries.expiryDate', $now],
            ])
            ->limit($limit)
            ->all();
    }
}
