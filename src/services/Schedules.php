<?php

namespace justinholtweb\alarmclock\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\ScheduleRecord;
use justinholtweb\alarmclock\records\TaskRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use RuntimeException;
use Throwable;
use yii\base\Component;

/**
 * Scheduled drafts — "make this draft the entry at nine tomorrow".
 *
 * Craft can schedule an entry's *first* appearance with `postDate`, but there is no way to say
 * "replace what is currently live with this draft, later". Editors work around it by holding the
 * draft open and applying it by hand at the appointed hour, which is exactly the job a computer
 * should be doing, and exactly the job that gets forgotten on a Friday afternoon.
 *
 * This is also the only place in the plugin where publishing can genuinely fail rather than merely
 * be unnoticed: a draft can be invalid, its canonical entry can have been deleted, another editor
 * can have applied a conflicting draft in the meantime. So it is the part that most needs the
 * retries, and the part that most needs to say clearly what went wrong when it runs out of them.
 */
class Schedules extends Component
{
    /**
     * Schedules a draft, or reschedules it if it already had a time.
     *
     * Rescheduling updates the existing row rather than adding a second one — the unique index on
     * `(draftId, siteId)` sees to that — because two rows for one draft is two runners racing to
     * apply the same draft, and the loser fails with a conflict that is nobody's fault.
     */
    public function schedule(
        ElementInterface $draft,
        DateTime $publishAt,
        bool $enableAfter = false,
        ?string $note = null,
    ): ScheduleRecord {
        if (!$draft->getIsDraft()) {
            throw new RuntimeException('Only a draft can be scheduled.');
        }

        // `draftId`, not `id`. A draft has both: `id` is its own element row, `draftId` is the
        // `drafts` table row that `Entry::find()->draftId()` looks up. Storing the element id here
        // reads back as no draft at all, so the schedule is written and then never found again.
        $draftId = (int)$draft->draftId;

        if (!$draftId) {
            throw new RuntimeException('That draft has no draft ID, so it cannot be scheduled.');
        }

        $record = ScheduleRecord::findOne(['draftId' => $draftId, 'siteId' => $draft->siteId])
            ?? new ScheduleRecord([
                'draftId' => $draftId,
                'siteId' => (int)$draft->siteId,
            ]);

        $record->canonicalId = $draft->getCanonicalId();
        $record->publishAt = Db::prepareDateForDb($publishAt);
        $record->status = ScheduleRecord::STATUS_SCHEDULED;
        $record->enableAfter = $enableAfter;
        $record->note = $note !== null ? StringHelper::safeTruncate($note, 490) : null;
        $record->createdBy = Craft::$app->getUser()->getIdentity()?->id;

        // A reschedule after a failure gets a clean slate. The alternative — carrying the old
        // attempt count forward — means a draft that failed four times yesterday gets one attempt
        // today, which is not what "schedule it again" means to the person who asked.
        $record->attempts = 0;
        $record->lastError = null;
        $record->appliedElementId = null;
        $record->appliedAt = null;

        if (!$record->save()) {
            throw new RuntimeException('Could not schedule the draft: ' . implode('; ', $record->getErrorSummary(true)));
        }

        // Any task left over from a previous schedule for this draft is now about the wrong time.
        Craft::$app->getDb()->createCommand()->delete(TaskRecord::TABLE, [
            'and',
            ['scheduleId' => $record->id],
            ['status' => [TaskRecord::STATUS_PENDING, TaskRecord::STATUS_FAILED]],
        ])->execute();

        return $record;
    }

    public function cancel(int $id): bool
    {
        $record = ScheduleRecord::findOne($id);

        if (!$record || $record->status === ScheduleRecord::STATUS_APPLIED) {
            return false;
        }

        $record->status = ScheduleRecord::STATUS_CANCELED;
        $record->save(false);

        Craft::$app->getDb()->createCommand()->delete(TaskRecord::TABLE, [
            'and',
            ['scheduleId' => $record->id],
            ['status' => [TaskRecord::STATUS_PENDING, TaskRecord::STATUS_FAILED]],
        ])->execute();

        return true;
    }

    /**
     * Creates an apply task for every schedule whose moment has come.
     *
     * Only creates the task; applying happens in the task runner, so a draft that will not apply
     * gets the same backoff, attempt count and visible error as everything else rather than
     * failing invisibly inside a detection scan.
     *
     * @return int How many were queued.
     */
    public function queueDue(): int
    {
        $now = Db::prepareDateForDb(new DateTime('now'));

        $records = ScheduleRecord::find()
            ->where(['status' => ScheduleRecord::STATUS_SCHEDULED])
            ->andWhere(['<=', 'publishAt', $now])
            ->orderBy(['publishAt' => SORT_ASC])
            ->limit(Plugin::getInstance()->getSettings()->maxTransitionsPerTick)
            ->all();

        $queued = 0;

        foreach ($records as $record) {
            // Belt and braces against a schedule being queued twice by two ticks that both got
            // past the mutex on different servers: an unfinished task for this schedule means it
            // is already in hand.
            $existing = TaskRecord::find()
                ->where(['scheduleId' => $record->id])
                ->andWhere(['status' => [TaskRecord::STATUS_PENDING, TaskRecord::STATUS_RUNNING, TaskRecord::STATUS_FAILED]])
                ->exists();

            if ($existing) {
                continue;
            }

            Plugin::getInstance()->tasks->create(Tasks::ACTION_APPLY_DRAFT, scheduleId: $record->id);
            $queued++;
        }

        return $queued;
    }

    /**
     * Applies one scheduled draft.
     *
     * @param bool $canRetry Whether the caller still has attempts left. Decides whether a failure
     *                       leaves the schedule waiting or marks it failed for good — the row has
     *                       to say "gave up" once nothing is going to try again, or the control
     *                       panel shows a draft that is forever about to publish.
     * @throws Throwable to fail the attempt.
     */
    public function apply(int $scheduleId, bool $canRetry = true): string
    {
        $record = ScheduleRecord::findOne($scheduleId);

        if (!$record) {
            return 'The schedule has been deleted.';
        }

        if ($record->status !== ScheduleRecord::STATUS_SCHEDULED) {
            return 'Already ' . $record->status . '.';
        }

        $record->attempts = (int)$record->attempts + 1;

        try {
            $applied = $this->applyDraft($record);
        } catch (Throwable $e) {
            $record->lastError = StringHelper::safeTruncate($e->getMessage(), 2000);

            if (!$canRetry) {
                $record->status = ScheduleRecord::STATUS_FAILED;
            }

            $record->save(false);

            throw $e;
        }

        $record->status = ScheduleRecord::STATUS_APPLIED;
        $record->appliedElementId = (int)$applied->id;
        $record->appliedAt = Db::prepareDateForDb(new DateTime('now'));
        $record->lastError = null;
        $record->save(false);

        $this->recordAppliedTransition($record, $applied);

        return sprintf('Applied draft to entry %d.', $applied->id);
    }

    private function applyDraft(ScheduleRecord $record): ElementInterface
    {
        $draft = Entry::find()
            ->draftId($record->draftId)
            ->siteId($record->siteId)
            ->status(null)
            ->drafts(true)
            ->provisionalDrafts(null)
            ->one();

        if (!$draft) {
            throw new RuntimeException('The draft no longer exists. It may have been applied or deleted by hand.');
        }

        $canonical = $draft->getCanonical(true);

        if (!$canonical || $canonical->id === null) {
            throw new RuntimeException('The entry this draft belongs to no longer exists.');
        }

        // Validate before applying, and say what is wrong in words. `applyDraft()` on an invalid
        // draft leaves the canonical entry half-updated on some field types, and "could not save
        // element" tells an editor nothing they can act on.
        $draft->setScenario(Element::SCENARIO_LIVE);

        if (!$draft->validate()) {
            throw new RuntimeException('The draft is not valid: ' . implode('; ', $draft->getErrorSummary(true)));
        }

        $newAttributes = [];

        if ($record->enableAfter) {
            $newAttributes['enabled'] = true;
            $newAttributes['enabledForSite'] = true;
        }

        return Craft::$app->getDrafts()->applyDraft($draft, $newAttributes);
    }

    /**
     * Puts the application in the ledger, so it shows up in history alongside ordinary crossings
     * and gets the same cache clearing and notifications.
     */
    private function recordAppliedTransition(ScheduleRecord $record, ElementInterface $applied): void
    {
        $transitions = Plugin::getInstance()->transitions;

        $transition = $transitions->record(
            elementId: (int)$applied->id,
            siteId: (int)$record->siteId,
            elementType: $applied::class,
            transition: TransitionRecord::TRANSITION_APPLIED,
            // The moment that was asked for, not the moment it happened, so the unique key means
            // "this scheduled application" and a retry cannot record it twice.
            //
            // Read through `getPublishAtDate()`, never `new DateTime($record->publishAt)`: the
            // column is a bare UTC string, and `new DateTime()` would read it in the site's zone
            // and record an instant off by the whole UTC offset.
            scheduledFor: $record->getPublishAtDate() ?? new DateTime('now'),
            source: TransitionRecord::SOURCE_QUEUE,
            title: (string)$applied->title,
            url: $applied->getUrl(),
        );

        if ($transition instanceof Transition) {
            Plugin::getInstance()->ticker->afterTransition($transition, $applied instanceof Entry ? $applied : null);
        }
    }

    // ------------------------------------------------------------------ reading

    public function getById(int $id): ?ScheduleRecord
    {
        return ScheduleRecord::findOne($id);
    }

    public function forDraft(int $draftId, int $siteId): ?ScheduleRecord
    {
        return ScheduleRecord::findOne([
            'draftId' => $draftId,
            'siteId' => $siteId,
            'status' => ScheduleRecord::STATUS_SCHEDULED,
        ]);
    }

    /**
     * @return ScheduleRecord[]
     */
    public function upcoming(int $limit = 50): array
    {
        return ScheduleRecord::find()
            ->where(['status' => ScheduleRecord::STATUS_SCHEDULED])
            ->orderBy(['publishAt' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    /**
     * @return ScheduleRecord[]
     */
    public function failed(int $limit = 50): array
    {
        return ScheduleRecord::find()
            ->where(['status' => ScheduleRecord::STATUS_FAILED])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Removes schedules whose draft has disappeared.
     *
     * There is no foreign key to hang this on: a draft is an element, and a scheduled draft that
     * is deleted takes its element row with it but leaves this one behind pointing at nothing.
     */
    public function collectGarbage(): int
    {
        $orphans = ScheduleRecord::find()
            ->where(['status' => ScheduleRecord::STATUS_SCHEDULED])
            ->all();

        $deleted = 0;

        foreach ($orphans as $record) {
            $exists = Entry::find()
                ->draftId($record->draftId)
                ->siteId($record->siteId)
                ->status(null)
                ->drafts(true)
                ->provisionalDrafts(null)
                ->exists();

            if (!$exists) {
                $record->delete();
                $deleted++;
            }
        }

        return $deleted;
    }
}
