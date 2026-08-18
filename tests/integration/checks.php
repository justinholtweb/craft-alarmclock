<?php
/**
 * Alarm Clock integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-alarmclock/tests/integration/checks.php
 *
 * Covers what unit fixtures cannot: real entries saved through Craft crossing real dates, the
 * ledger's exactly-once guarantee under a repeated scan, the task runner's claim and backoff, and
 * the diagnostics reading a real installation.
 *
 * Idempotent and self-cleaning — every entry, transition, task and schedule it creates is deleted
 * at the end, and the watermarks are put back where they were found.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\alarmclock\models\Finding;
use justinholtweb\alarmclock\models\Settings;
use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\ScheduleRecord;
use justinholtweb\alarmclock\records\TaskRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use justinholtweb\alarmclock\services\Tasks;
use justinholtweb\alarmclock\services\Ticker;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$site = Craft::$app->getSites()->getPrimarySite();
$suffix = substr(md5((string)microtime(true)), 0, 6);

// A channel we can put throwaway entries in.
$section = null;
foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if ($candidate->type === 'channel' && $candidate->getEntryTypes()) {
        $section = $candidate;
        break;
    }
}

if (!$section) {
    echo "No channel section to test with.\n";
    exit(1);
}

$entryType = $section->getEntryTypes()[0];
$created = [];
$createdIds = [];

/** Saves a throwaway entry with the given dates. */
function makeEntry(string $title, ?DateTime $postDate, ?DateTime $expiryDate = null, bool $enabled = true): Entry
{
    global $section, $entryType, $site, $created, $createdIds;

    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->siteId = $site->id;
    $entry->title = $title;
    $entry->slug = 'ac-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
    $entry->enabled = $enabled;
    $entry->setEnabledForSite($enabled);
    $entry->postDate = $postDate;
    $entry->expiryDate = $expiryDate;

    if (!Craft::$app->getElements()->saveElement($entry, false)) {
        throw new RuntimeException('Could not save test entry: ' . implode('; ', $entry->getErrorSummary(true)));
    }

    $created[] = $entry;
    $createdIds[] = (int)$entry->id;

    return $entry;
}

/** Rewinds both watermarks so the next scan covers a known window. */
function rewindWatermarks(DateTime $to): void
{
    global $plugin;

    $plugin->ticker->setState(Ticker::WATERMARK_PUBLISHED, $to->format(DATE_ATOM));
    $plugin->ticker->setState(Ticker::WATERMARK_EXPIRED, $to->format(DATE_ATOM));
}

// Remember the real watermarks so a test run does not make the site re-announce its archive.
$originalWatermarks = [
    Ticker::WATERMARK_PUBLISHED => $plugin->ticker->getState(Ticker::WATERMARK_PUBLISHED),
    Ticker::WATERMARK_EXPIRED => $plugin->ticker->getState(Ticker::WATERMARK_EXPIRED),
];

$originalSettings = clone $plugin->getSettings();

// ---------------------------------------------------------------------------- settings

section('Settings');

check('backoff doubles and is capped', function() {
    $settings = new Settings(['retryBaseDelay' => 60, 'retryMaxDelay' => 300]);

    $first = $settings->backoffFor(1);
    $second = $settings->backoffFor(2);
    $tenth = $settings->backoffFor(10);

    // Jitter is up to 10%, so the bands are checked rather than exact values.
    return ($first >= 60 && $first <= 66)
        && ($second >= 120 && $second <= 132)
        && ($tenth >= 300 && $tenth <= 330)
        ?: "first=$first second=$second tenth=$tenth";
});

check('backoff jitter actually varies', function() {
    $settings = new Settings(['retryBaseDelay' => 1000, 'retryMaxDelay' => 10000]);
    $seen = [];

    for ($i = 0; $i < 25; $i++) {
        $seen[$settings->backoffFor(1)] = true;
    }

    // A hundred webhooks that failed against the same dead endpoint must not all come back in the
    // same second, so identical delays every time would defeat the point.
    return count($seen) > 1 ?: 'every backoff was identical';
});

check('a retry ceiling below the floor is rejected', function() {
    $settings = new Settings(['retryBaseDelay' => 600, 'retryMaxDelay' => 60]);

    return !$settings->validate(['retryMaxDelay']) ?: 'validated a shrinking backoff';
});

check('list settings survive both the CP table and a config file', function() {
    $fromTable = Settings::flattenRows([['url' => '/news'], ['url' => ''], ['url' => '/blog']]);
    $fromConfig = Settings::flattenRows(['/news', '', '/blog']);

    return $fromTable === ['/news', '/blog'] && $fromConfig === ['/news', '/blog']
        ?: json_encode([$fromTable, $fromConfig]);
});

check('a bad email address is caught, a good one is not', function() {
    $settings = new Settings(['notifyEmails' => ['desk@example.com', 'not-an-address']]);
    $settings->validate(['notifyEmails']);

    return $settings->hasErrors('notifyEmails') ?: 'accepted “not-an-address”';
});

// ---------------------------------------------------------------------------- watermarks

section('Watermarks — the bug that stops everything silently');

check('a bare UTC timestamp is read as UTC, not as site time', function() use ($plugin) {
    // The install migration used to write `Db::prepareDateForDb()` here. Read back with a plain
    // `new DateTime()`, that lands hours in the future on any site west of UTC — every scan then
    // finds its window inverted and returns immediately, and detection does nothing at all
    // without a word anywhere.
    $utc = new DateTime('now', new DateTimeZone('UTC'));
    $plugin->ticker->setState('test.watermark', $utc->format('Y-m-d H:i:s'));

    $read = $plugin->ticker->watermark('test.watermark');
    $drift = abs($read->getTimestamp() - $utc->getTimestamp());

    return $drift <= 2 ?: "read back {$drift}s away from what was written";
});

check('an ISO-8601 timestamp round-trips exactly', function() use ($plugin) {
    $when = new DateTime('2026-03-01 09:30:00');
    $plugin->ticker->setState('test.watermark', $when->format(DATE_ATOM));

    return $plugin->ticker->watermark('test.watermark')->getTimestamp() === $when->getTimestamp()
        ?: 'round trip changed the instant';
});

check('the installed watermark is not in the future', function() use ($plugin) {
    // The regression this whole section exists for, asserted against what the migration actually
    // wrote on this site.
    $now = new DateTime('now');

    foreach ([Ticker::WATERMARK_PUBLISHED, Ticker::WATERMARK_EXPIRED] as $key) {
        if ($plugin->ticker->watermark($key) > $now) {
            return "$key is ahead of now";
        }
    }

    return true;
});

check('writing the same state value twice does not blow up', function() use ($plugin) {
    // MySQL reports zero affected rows for an update that sets a column to the value it already
    // holds, so an update-then-insert-if-nothing-changed falls through to an insert and dies on
    // the primary key. Two ticks in the same second do exactly that.
    $value = (new DateTime('2026-05-05 05:05:05'))->format(DATE_ATOM);

    $plugin->ticker->setState('test.watermark', $value);
    $plugin->ticker->setState('test.watermark', $value);
    $plugin->ticker->setState('test.watermark', $value);

    return $plugin->ticker->getState('test.watermark') === $value ?: 'the value did not survive being written three times';
});

check('an unreadable watermark falls back rather than scanning from the epoch', function() use ($plugin) {
    $plugin->ticker->setState('test.watermark', 'not a date at all');
    $default = new DateTime('2020-01-01 00:00:00');

    return $plugin->ticker->watermark('test.watermark', $default)->getTimestamp() === $default->getTimestamp()
        ?: 'did not fall back to the default';
});

// ---------------------------------------------------------------------------- the ledger

section('The ledger — exactly once, whoever gets there first');

check('the same crossing cannot be recorded twice', function() use ($plugin, $site) {
    $when = new DateTime('2026-01-01 09:00:00');
    $id = 999000001;

    $first = $plugin->transitions->record($id, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, $when, 'console', 'Test');
    $second = $plugin->transitions->record($id, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, $when, 'web', 'Test');
    $third = $plugin->transitions->record($id, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, $when, 'queue', 'Test');

    $ok = $first !== null && $second === null && $third === null;

    if ($first) {
        TransitionRecord::deleteAll(['id' => $first->id]);
    }

    return $ok ?: 'the ledger let a crossing through more than once';
});

check('the same entry rescheduled to a different time is a different crossing', function() use ($plugin, $site) {
    $id = 999000002;

    $a = $plugin->transitions->record($id, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, new DateTime('2026-01-01 09:00:00'), 'console');
    $b = $plugin->transitions->record($id, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, new DateTime('2026-01-02 09:00:00'), 'console');

    $ok = $a !== null && $b !== null;

    TransitionRecord::deleteAll(['elementId' => $id]);

    return $ok ?: 'rescheduling did not produce a second crossing';
});

check('publishing and expiring the same entry are different crossings', function() use ($plugin, $site) {
    $id = 999000003;
    $when = new DateTime('2026-01-01 09:00:00');

    $a = $plugin->transitions->record($id, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, $when, 'console');
    $b = $plugin->transitions->record($id, $site->id, Entry::class, TransitionRecord::TRANSITION_EXPIRED, $when, 'console');

    $ok = $a !== null && $b !== null;

    TransitionRecord::deleteAll(['elementId' => $id]);

    return $ok ?: 'the transition type is not part of the key';
});

// ---------------------------------------------------------------------------- detection

section('Detection');

check('an entry whose post date has just passed is detected', function() use ($plugin, $site) {
    $entry = makeEntry('AC published ' . uniqid(), (new DateTime('now'))->modify('-30 seconds'));

    rewindWatermarks((new DateTime('now'))->modify('-5 minutes'));
    $result = $plugin->ticker->detectPublished('console', 100);

    $recorded = $plugin->transitions->exists((int)$entry->id, (int)$entry->siteId, TransitionRecord::TRANSITION_PUBLISHED, $entry->postDate);

    return ($result['count'] >= 1 && $recorded) ?: 'count=' . $result['count'] . ' recorded=' . var_export($recorded, true);
});

check('scanning the same window again records nothing new', function() use ($plugin) {
    rewindWatermarks((new DateTime('now'))->modify('-5 minutes'));
    $result = $plugin->ticker->detectPublished('web', 100);

    // The ledger, not the watermark, is what makes this true — the window is deliberately rewound
    // to exactly what was just scanned.
    return $result['count'] === 0 ?: 'recorded ' . $result['count'] . ' crossings twice';
});

check('an entry still waiting on its post date is not detected', function() use ($plugin) {
    $entry = makeEntry('AC future ' . uniqid(), (new DateTime('now'))->modify('+2 hours'));

    rewindWatermarks((new DateTime('now'))->modify('-5 minutes'));
    $plugin->ticker->detectPublished('console', 100);

    return !$plugin->transitions->exists((int)$entry->id, (int)$entry->siteId, TransitionRecord::TRANSITION_PUBLISHED, $entry->postDate)
        ?: 'announced a post before its time';
});

check('a disabled entry past its post date is not detected', function() use ($plugin) {
    $entry = makeEntry('AC disabled ' . uniqid(), (new DateTime('now'))->modify('-30 seconds'), null, false);

    rewindWatermarks((new DateTime('now'))->modify('-5 minutes'));
    $plugin->ticker->detectPublished('console', 100);

    return !$plugin->transitions->exists((int)$entry->id, (int)$entry->siteId, TransitionRecord::TRANSITION_PUBLISHED, $entry->postDate)
        ?: 'announced an entry nobody switched on';
});

check('an entry that went live and expired inside the same window is not announced', function() use ($plugin) {
    // Announcing this would send readers to a 404 — it has been and gone, not published.
    $entry = makeEntry(
        'AC brief ' . uniqid(),
        (new DateTime('now'))->modify('-120 seconds'),
        (new DateTime('now'))->modify('-30 seconds'),
    );

    rewindWatermarks((new DateTime('now'))->modify('-5 minutes'));
    $plugin->ticker->detectPublished('console', 100);

    return !$plugin->transitions->exists((int)$entry->id, (int)$entry->siteId, TransitionRecord::TRANSITION_PUBLISHED, $entry->postDate)
        ?: 'announced a post that had already expired';
});

check('an expiry that has just passed is detected', function() use ($plugin) {
    $entry = makeEntry(
        'AC expired ' . uniqid(),
        (new DateTime('now'))->modify('-2 hours'),
        (new DateTime('now'))->modify('-30 seconds'),
    );

    rewindWatermarks((new DateTime('now'))->modify('-5 minutes'));
    $plugin->ticker->detectExpired('console', 100);

    return $plugin->transitions->exists((int)$entry->id, (int)$entry->siteId, TransitionRecord::TRANSITION_EXPIRED, $entry->expiryDate)
        ?: 'an expiry went unnoticed';
});

check('a capped batch leaves the watermark on the last crossing handled, not on now', function() use ($plugin) {
    // Advancing to "now" after a capped batch would mark the untouched remainder as covered and
    // lose it — which is how a site restored from backup quietly skips a week of posts.
    $base = (new DateTime('now'))->modify('-40 minutes');

    for ($i = 0; $i < 3; $i++) {
        makeEntry('AC batch ' . $i . ' ' . uniqid(), (clone $base)->modify('+' . ($i * 5) . ' minutes'));
    }

    rewindWatermarks((new DateTime('now'))->modify('-60 minutes'));
    $result = $plugin->ticker->detectPublished('console', 2);

    $watermark = $plugin->ticker->watermark(Ticker::WATERMARK_PUBLISHED);

    return ($result['more'] === true && $watermark < new DateTime('now'))
        ?: 'more=' . var_export($result['more'], true) . ' watermark=' . $watermark->format(DATE_ATOM);
});

check('the rest of a capped batch is picked up by the next scan', function() use ($plugin) {
    $result = $plugin->ticker->detectPublished('console', 100);

    return $result['count'] >= 1 ?: 'the remainder of the backlog was lost';
});

check('a watermark in the future stops the scan instead of inverting the window', function() use ($plugin) {
    rewindWatermarks((new DateTime('now'))->modify('+1 hour'));
    $result = $plugin->ticker->detectPublished('console', 100);

    return ($result['count'] === 0 && $result['more'] === false) ?: 'scanned an inverted window';
});

// ---------------------------------------------------------------------------- tasks

section('Tasks — claiming, backoff and giving up');

check('a task can only be claimed once', function() use ($plugin) {
    $id = $plugin->tasks->create(Tasks::ACTION_INVALIDATE);

    $first = $plugin->tasks->claim($id);
    $second = $plugin->tasks->claim($id);

    TaskRecord::deleteAll(['id' => $id]);

    return ($first instanceof Task && $second === null) ?: 'two runners both claimed the same task';
});

check('claiming counts the attempt', function() use ($plugin) {
    $id = $plugin->tasks->create(Tasks::ACTION_INVALIDATE);
    $task = $plugin->tasks->claim($id);

    TaskRecord::deleteAll(['id' => $id]);

    return $task->attempts === 1 ?: 'attempts was ' . $task->attempts;
});

check('a failing task is pushed into the future rather than retried on the spot', function() use ($plugin) {
    // The webhook action with no URL configured returns rather than throwing, so this uses a
    // deliberately unreachable one to make a real failure.
    $settings = $plugin->getSettings();
    $settings->webhookUrl = 'http://127.0.0.1:9/never';
    $settings->webhookTimeout = 1;
    $settings->maxAttempts = 3;

    $transition = $plugin->transitions->record(999000010, Craft::$app->getSites()->getPrimarySite()->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, new DateTime('2026-01-01 09:00:00'), 'console', 'Webhook test');
    $id = $plugin->tasks->create(Tasks::ACTION_WEBHOOK, transitionId: $transition->id, maxAttempts: 3);

    $task = $plugin->tasks->claim($id);
    $ok = $plugin->tasks->attempt($task);

    $record = TaskRecord::findOne($id);
    $available = $record->availableAt ? strtotime($record->availableAt) : 0;

    $result = (!$ok
        && $record->status === TaskRecord::STATUS_FAILED
        && $record->lastError !== null
        && $available > strtotime(Db::prepareDateForDb(new DateTime('now'))))
        ?: 'ok=' . var_export($ok, true) . ' status=' . $record->status . ' availableAt=' . $record->availableAt;

    TransitionRecord::deleteAll(['id' => $transition->id]);

    return $result;
});

check('a task that runs out of attempts is abandoned, not retried forever', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->webhookUrl = 'http://127.0.0.1:9/never';
    $settings->webhookTimeout = 1;
    $settings->notifyOnFailure = false;

    $transition = $plugin->transitions->record(999000011, Craft::$app->getSites()->getPrimarySite()->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, new DateTime('2026-01-01 09:00:00'), 'console', 'Give up test');
    $id = $plugin->tasks->create(Tasks::ACTION_WEBHOOK, transitionId: $transition->id, maxAttempts: 2);

    for ($i = 0; $i < 2; $i++) {
        Craft::$app->getDb()->createCommand()->update(TaskRecord::TABLE, ['availableAt' => null], ['id' => $id])->execute();
        $task = $plugin->tasks->claim($id);
        $plugin->tasks->attempt($task);
    }

    $record = TaskRecord::findOne($id);
    $result = $record->status === TaskRecord::STATUS_ABANDONED ?: 'ended as ' . $record->status;

    TransitionRecord::deleteAll(['id' => $transition->id]);

    return $result;
});

check('an abandoned task can be retried by hand, with its attempts restored', function() use ($plugin) {
    $id = $plugin->tasks->create(Tasks::ACTION_INVALIDATE);
    Craft::$app->getDb()->createCommand()->update(TaskRecord::TABLE, [
        'status' => TaskRecord::STATUS_ABANDONED,
        'attempts' => 5,
    ], ['id' => $id])->execute();

    $plugin->tasks->retry($id);
    $record = TaskRecord::findOne($id);

    $result = ($record->status === TaskRecord::STATUS_PENDING && (int)$record->attempts === 0)
        ?: 'status=' . $record->status . ' attempts=' . $record->attempts;

    TaskRecord::deleteAll(['id' => $id]);

    return $result;
});

check('a task whose runner died is reclaimed', function() use ($plugin) {
    // Work that is neither finished nor waiting is the worst state to debug: nothing is broken and
    // nothing is happening.
    $id = $plugin->tasks->create(Tasks::ACTION_INVALIDATE);

    Craft::$app->getDb()->createCommand()->update(TaskRecord::TABLE, [
        'status' => TaskRecord::STATUS_RUNNING,
        'startedAt' => Db::prepareDateForDb((new DateTime('now'))->modify('-1 day')),
    ], ['id' => $id])->execute();

    $reclaimed = $plugin->tasks->reclaimStale();
    $record = TaskRecord::findOne($id);

    $result = ($reclaimed >= 1 && $record->status === TaskRecord::STATUS_FAILED)
        ?: "reclaimed=$reclaimed status=" . $record->status;

    TaskRecord::deleteAll(['id' => $id]);

    return $result;
});

check('a task not yet due is not picked up', function() use ($plugin) {
    $id = $plugin->tasks->create(Tasks::ACTION_INVALIDATE, availableAt: (new DateTime('now'))->modify('+1 hour'));

    $due = $plugin->tasks->dueIds(100);
    $result = !in_array($id, array_map('intval', $due), true) ?: 'ran a task before its backoff had elapsed';

    TaskRecord::deleteAll(['id' => $id]);

    return $result;
});

// ---------------------------------------------------------------------------- cache clearing

section('Cache clearing — the reason the plugin exists');

check('a crossing invalidates the tags a listing query registered', function() use ($plugin, $site) {
    $entry = makeEntry('AC cache ' . uniqid(), (new DateTime('now'))->modify('-30 seconds'));

    $cache = Craft::$app->getCache();
    $key = 'ac-test-' . uniqid();

    // The tag a `craft.entries.section(...)` query would have registered while building a listing.
    $tag = sprintf('element::%s::section:%s', Entry::class, $entry->sectionId);
    $cache->set($key, 'stale listing', 0, new yii\caching\TagDependency(['tags' => [$tag]]));

    if ($cache->get($key) !== 'stale listing') {
        return 'could not seed the cache';
    }

    $transition = $plugin->transitions->record(
        (int)$entry->id,
        (int)$entry->siteId,
        Entry::class,
        TransitionRecord::TRANSITION_PUBLISHED,
        new DateTime('2026-02-02 02:02:02'),
        'console',
        $entry->title,
    );

    $handler = $plugin->tasks->handler(Tasks::ACTION_INVALIDATE);
    $handler->run(new Task(['action' => Tasks::ACTION_INVALIDATE]), $transition);

    $stillThere = $cache->get($key);

    TransitionRecord::deleteAll(['id' => $transition->id]);

    return $stillThere === false ?: 'the stale listing survived the crossing';
});

check('a crossing for a deleted element still clears its tags', function() use ($plugin, $site) {
    $cache = Craft::$app->getCache();
    $key = 'ac-test-gone-' . uniqid();
    $elementId = 999000020;

    $cache->set($key, 'stale', 0, new yii\caching\TagDependency(['tags' => ["element::$elementId"]]));

    $transition = $plugin->transitions->record($elementId, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, new DateTime('2026-02-02 03:03:03'), 'console', 'Gone');

    $handler = $plugin->tasks->handler(Tasks::ACTION_INVALIDATE);
    $handler->run(new Task(['action' => Tasks::ACTION_INVALIDATE]), $transition);

    $result = $cache->get($key) === false ?: 'gave up because the element was gone';

    TransitionRecord::deleteAll(['id' => $transition->id]);

    return $result;
});

// ---------------------------------------------------------------------------- scheduled drafts

section('Scheduled drafts');

check('a draft can be scheduled, and rescheduling replaces rather than stacks', function() use ($plugin, $site) {
    $entry = makeEntry('AC draft target ' . uniqid(), (new DateTime('now'))->modify('-1 hour'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId() ?? 1, 'AC test draft');

    $plugin->schedules->schedule($draft, (new DateTime('now'))->modify('+1 hour'));
    $plugin->schedules->schedule($draft, (new DateTime('now'))->modify('+2 hours'));

    $count = ScheduleRecord::find()->where(['draftId' => $draft->draftId, 'siteId' => $draft->siteId])->count();

    // Two rows would be two runners racing to apply the same draft, and the loser failing with a
    // conflict that is nobody's fault.
    return (int)$count === 1 ?: "$count schedule rows for one draft";
});

check('a schedule is keyed on the draft ID, so it can be found again', function() use ($plugin) {
    // `schedule()` used to store the draft's *element* id while every reader looked it up as a
    // `drafts` table id, so the row was written and then never found again — the draft simply
    // never published, and nothing anywhere said why.
    $entry = makeEntry('AC key ' . uniqid(), (new DateTime('now'))->modify('-1 hour'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId() ?? 1, 'AC key draft');

    $record = $plugin->schedules->schedule($draft, (new DateTime('now'))->modify('+1 hour'));

    $found = $plugin->schedules->forDraft((int)$draft->draftId, (int)$draft->siteId);

    return ((int)$record->draftId === (int)$draft->draftId && $found !== null && (int)$found->id === (int)$record->id)
        ?: 'stored ' . $record->draftId . ' but the draft ID is ' . $draft->draftId;
});

check('the draft ID finds the draft, where the canonical entry ID finds something else', function() {
    // Exactly what the control panel's schedule button relies on. The canonical entry's ID is the
    // one that is easy to post by mistake — it resolves to a perfectly real element that is not a
    // draft, so taking both identifiers would mean a wrong-but-plausible pairing was possible.
    $entry = makeEntry('AC lookup ' . uniqid(), (new DateTime('now'))->modify('-1 hour'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId() ?? 1, 'AC lookup draft');

    $byDraftId = Entry::find()
        ->draftId($draft->draftId)
        ->siteId($draft->siteId)
        ->status(null)
        ->drafts(true)
        ->provisionalDrafts(null)
        ->one();

    $canonical = Craft::$app->getElements()->getElementById((int)$entry->id, Entry::class, (int)$entry->siteId);

    return ($byDraftId !== null && $byDraftId->getIsDraft() && (int)$byDraftId->id === (int)$draft->id
        && $canonical !== null && !$canonical->getIsDraft())
        ?: 'draft lookup did not behave as the controller assumes';
});

check('a scheduled time survives the round trip through the database', function() use ($plugin) {
    // The column holds a bare UTC string. Read back with `new DateTime()` it is interpreted in the
    // site's zone, so nine in the morning displays as four in the afternoon and the ledger records
    // an instant off by the whole UTC offset.
    $entry = makeEntry('AC tz ' . uniqid(), (new DateTime('now'))->modify('-1 hour'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId() ?? 1, 'AC tz draft');

    $wanted = (new DateTime('now'))->modify('+3 hours');
    $record = $plugin->schedules->schedule($draft, $wanted);
    $record->refresh();

    $readBack = $record->getPublishAtDate();
    $drift = abs($readBack->getTimestamp() - $wanted->getTimestamp());

    return $drift <= 1 ?: "read back {$drift}s away from the time that was asked for";
});

check('a due draft is applied, and its content reaches the entry', function() use ($plugin) {
    $entry = makeEntry('AC apply target ' . uniqid(), (new DateTime('now'))->modify('-1 hour'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId() ?? 1, 'AC apply draft');
    $draft->title = 'AC applied title ' . uniqid();
    Craft::$app->getElements()->saveElement($draft, false);

    $record = $plugin->schedules->schedule($draft, (new DateTime('now'))->modify('-1 minute'));

    $queued = $plugin->schedules->queueDue();

    // This schedule's own task, run directly. `runDue()` with a limit would have its budget eaten
    // by any other work the site happens to have waiting, which makes the test fail for reasons
    // that have nothing to do with applying drafts.
    $taskId = (int)TaskRecord::find()->select(['id'])->where(['scheduleId' => $record->id])->scalar();
    $task = $plugin->tasks->claim($taskId);
    $ok = $task ? $plugin->tasks->attempt($task) : false;

    $record->refresh();
    $fresh = Entry::find()->id($entry->id)->status(null)->one();

    return ($queued >= 1 && $ok && $record->status === ScheduleRecord::STATUS_APPLIED && $fresh->title === $draft->title)
        ?: "queued=$queued ok=" . var_export($ok, true) . " status={$record->status} title={$fresh->title}"
            . ' err=' . (TaskRecord::findOne($taskId)?->lastError ?? '—');
});

check('applying a draft is recorded in the ledger like any other crossing', function() use ($plugin) {
    $applied = TransitionRecord::find()->where(['transition' => TransitionRecord::TRANSITION_APPLIED])->count();

    return (int)$applied >= 1 ?: 'a draft was applied without being recorded';
});

check('a schedule that is due twice is only queued once', function() use ($plugin) {
    $entry = makeEntry('AC double queue ' . uniqid(), (new DateTime('now'))->modify('-1 hour'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId() ?? 1, 'AC double draft');
    $record = $plugin->schedules->schedule($draft, (new DateTime('now'))->modify('-1 minute'));

    $plugin->schedules->queueDue();
    $plugin->schedules->queueDue();

    $tasks = TaskRecord::find()->where(['scheduleId' => $record->id])->count();

    return (int)$tasks === 1 ?: "$tasks apply tasks for one schedule";
});

check('a canceled schedule stops being due', function() use ($plugin) {
    $entry = makeEntry('AC cancel ' . uniqid(), (new DateTime('now'))->modify('-1 hour'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, Craft::$app->getUser()->getId() ?? 1, 'AC cancel draft');
    $record = $plugin->schedules->schedule($draft, (new DateTime('now'))->modify('-1 minute'));

    $plugin->schedules->cancel((int)$record->id);
    $queued = $plugin->schedules->queueDue();

    $record->refresh();

    return ($record->status === ScheduleRecord::STATUS_CANCELED && (int)TaskRecord::find()->where(['scheduleId' => $record->id])->count() === 0)
        ?: "status={$record->status} queued=$queued";
});

// ---------------------------------------------------------------------------- diagnostics

section('Diagnostics');

check('a disabled entry is explained as disabled', function() use ($plugin) {
    $entry = makeEntry('AC diag disabled ' . uniqid(), (new DateTime('now'))->modify('-1 hour'), null, false);
    $findings = $plugin->diagnostics->explain($entry);

    foreach ($findings as $finding) {
        if ($finding->level === Finding::LEVEL_BLOCKER && str_contains(strtolower($finding->label), 'disabled')) {
            return true;
        }
    }

    return 'no blocker mentioned being switched off';
});

check('a pending entry is explained as waiting, not as broken', function() use ($plugin) {
    $entry = makeEntry('AC diag pending ' . uniqid(), (new DateTime('now'))->modify('+3 hours'));
    $findings = $plugin->diagnostics->explain($entry);

    $hasSchedule = false;

    foreach ($findings as $finding) {
        if ($finding->label === 'Scheduled') {
            $hasSchedule = true;
        }

        // Waiting for a post date is the system working. Calling it a problem would send people
        // looking for a fault that is not there.
        if ($finding->level === Finding::LEVEL_BLOCKER) {
            return 'called a correctly scheduled entry broken: ' . $finding->message;
        }
    }

    return $hasSchedule ?: 'never said when it goes live';
});

check('an entry with no post date at all is called out', function() use ($plugin) {
    $entry = makeEntry('AC diag nodate ' . uniqid(), null);

    // Craft fills a post date in on save for enabled entries, so this has to be cleared behind it
    // — which is exactly how an imported entry ends up in this state.
    Craft::$app->getDb()->createCommand()->update('{{%entries}}', ['postDate' => null], ['id' => $entry->id])->execute();
    $fresh = Entry::find()->id($entry->id)->status(null)->one();

    foreach ($plugin->diagnostics->explain($fresh) as $finding) {
        if ($finding->level === Finding::LEVEL_BLOCKER && str_contains($finding->label, 'No post date')) {
            return true;
        }
    }

    return 'said nothing about a missing post date';
});

check('the health report answers without throwing', function() use ($plugin) {
    $findings = $plugin->diagnostics->health();

    return count($findings) >= 6 ?: 'only ' . count($findings) . ' checks ran';
});

check('the clock check compares UTC to UTC', function() use ($plugin) {
    // The naive version of this check reported a confident three-hour drift between two containers
    // whose clocks were identical, because it was really measuring session time zones.
    foreach ($plugin->diagnostics->health() as $finding) {
        if ($finding->label === 'Clock') {
            return $finding->level === Finding::LEVEL_OK ?: 'clock check says: ' . $finding->message;
        }
    }

    return 'no clock check ran';
});

// ---------------------------------------------------------------------------- pruning

section('Housekeeping');

check('pruning keeps history whose work has not finished', function() use ($plugin, $site) {
    $transition = $plugin->transitions->record(999000030, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, new DateTime('2020-01-01 09:00:00'), 'console', 'Old');

    Craft::$app->getDb()->createCommand()->update(TransitionRecord::TABLE, [
        'detectedAt' => Db::prepareDateForDb(new DateTime('2020-01-01 09:00:00')),
    ], ['id' => $transition->id])->execute();

    $taskId = $plugin->tasks->create(Tasks::ACTION_NOTIFY, transitionId: $transition->id);

    $plugin->transitions->prune(30);

    // Deleting this would cascade to the task and throw away a notification that is still owed.
    $survived = TransitionRecord::findOne($transition->id) !== null;

    TaskRecord::deleteAll(['id' => $taskId]);
    TransitionRecord::deleteAll(['id' => $transition->id]);

    return $survived ?: 'threw away history that still had work waiting on it';
});

check('pruning removes history whose work is done', function() use ($plugin, $site) {
    $transition = $plugin->transitions->record(999000031, $site->id, Entry::class, TransitionRecord::TRANSITION_PUBLISHED, new DateTime('2020-01-01 09:00:00'), 'console', 'Old done');

    Craft::$app->getDb()->createCommand()->update(TransitionRecord::TABLE, [
        'detectedAt' => Db::prepareDateForDb(new DateTime('2020-01-01 09:00:00')),
    ], ['id' => $transition->id])->execute();

    $plugin->transitions->prune(30);

    return TransitionRecord::findOne($transition->id) === null ?: 'kept history well past its retention';
});

// ---------------------------------------------------------------------------- cleanup

section('Cleanup');

check('everything this run created is removed', function() use (&$created, &$createdIds, $plugin, $originalWatermarks, $originalSettings) {
    foreach ($created as $entry) {
        try {
            Craft::$app->getElements()->deleteElement($entry, true);
        } catch (Throwable) {
            // Already gone via a draft application.
        }
    }

    if ($createdIds) {
        TransitionRecord::deleteAll(['elementId' => $createdIds]);
    }

    TransitionRecord::deleteAll(['elementId' => range(999000001, 999000040)]);

    // Schedules point at drafts, and a draft is an element — deleting the entry takes the draft
    // with it and leaves this row pointing at nothing. There is no foreign key that could catch
    // that, so orphans have to be swept explicitly or they pile up across runs and eat the next
    // run's task budget.
    foreach (ScheduleRecord::find()->all() as $orphan) {
        $stillThere = Entry::find()
            ->draftId($orphan->draftId)
            ->siteId($orphan->siteId)
            ->status(null)
            ->drafts(true)
            ->provisionalDrafts(null)
            ->exists();

        if (!$stillThere) {
            $orphan->delete();
        }
    }
    Craft::$app->getDb()->createCommand()->delete('{{%alarmclock_state}}', ['key' => 'test.watermark'])->execute();

    foreach ($originalWatermarks as $key => $value) {
        $plugin->ticker->setState($key, $value);
    }

    // Settings were changed in memory only (never saved), but put them back so anything reading
    // the plugin afterwards in this process sees the real ones.
    Craft::configure($plugin->getSettings(), $originalSettings->toArray());

    return true;
});

echo "\n";
echo $failed === 0
    ? "\033[32mAll $passed checks passed.\033[0m\n"
    : "\033[31m$failed failed, $passed passed.\033[0m\n";

exit($failed === 0 ? 0 : 1);
