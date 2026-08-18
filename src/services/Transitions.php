<?php

namespace justinholtweb\alarmclock\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\TaskRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * The ledger.
 *
 * Every crossing the plugin acts on passes through `record()` exactly once, and the database — not
 * PHP — is what enforces the "once". Three triggers can be scanning the same window concurrently
 * on three different PHP processes; no amount of checking-then-inserting in application code makes
 * that safe, and the unique index does.
 */
class Transitions extends Component
{
    /**
     * Records a crossing, or returns null if it had already been recorded.
     *
     * A null return is the normal, expected outcome most of the time — it is what stops the same
     * post being announced by cron, by the queue and by a visitor's page load.
     */
    public function record(
        int $elementId,
        int $siteId,
        string $elementType,
        string $transition,
        DateTime $scheduledFor,
        string $source,
        ?string $title = null,
        ?string $url = null,
    ): ?Transition {
        $now = new DateTime('now');

        $row = [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'elementType' => $elementType,
            'transition' => $transition,
            'scheduledFor' => Db::prepareDateForDb($scheduledFor),
            'detectedAt' => Db::prepareDateForDb($now),
            'source' => $source,
            'title' => $title !== null ? StringHelper::safeTruncate($title, 250) : null,
            'url' => $url !== null ? StringHelper::safeTruncate($url, 490) : null,
            'dateCreated' => Db::prepareDateForDb($now),
            'dateUpdated' => Db::prepareDateForDb($now),
            'uid' => StringHelper::UUID(),
        ];

        try {
            Craft::$app->getDb()->createCommand()
                ->insert(TransitionRecord::TABLE, $row)
                ->execute();
        } catch (IntegrityException) {
            // Someone else got here first. That is the design working, not a failure.
            return null;
        }

        $model = new Transition([
            'id' => (int)Craft::$app->getDb()->getLastInsertID(TransitionRecord::TABLE),
            'elementId' => $elementId,
            'siteId' => $siteId,
            'elementType' => $elementType,
            'transition' => $transition,
            'scheduledFor' => $scheduledFor,
            'detectedAt' => $now,
            'source' => $source,
            'title' => $row['title'],
            'url' => $row['url'],
            'isNew' => true,
        ]);

        if (Plugin::getInstance()->getSettings()->verboseLogging) {
            Craft::info(
                sprintf(
                    'Recorded %s of element %d (site %d), scheduled for %s, detected by %s.',
                    $transition,
                    $elementId,
                    $siteId,
                    $scheduledFor->format(DATE_ATOM),
                    $source,
                ),
                Plugin::LOG_CATEGORY,
            );
        }

        return $model;
    }

    /** Convenience wrapper that fills the snapshot fields off an element. */
    public function recordForElement(
        ElementInterface $element,
        string $transition,
        DateTime $scheduledFor,
        string $source,
    ): ?Transition {
        return $this->record(
            (int)$element->id,
            (int)$element->siteId,
            $element::class,
            $transition,
            $scheduledFor,
            $source,
            (string)$element->title,
            $element->getUrl(),
        );
    }

    public function getById(int $id): ?Transition
    {
        $record = TransitionRecord::findOne($id);

        return $record ? Transition::fromRecord($record) : null;
    }

    /**
     * Recent history, newest first.
     *
     * @return Transition[]
     */
    public function recent(int $limit = 100, ?string $transition = null, ?int $siteId = null): array
    {
        $query = TransitionRecord::find()
            ->orderBy(['detectedAt' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if ($transition !== null) {
            $query->andWhere(['transition' => $transition]);
        }

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return array_map(fn(TransitionRecord $r) => Transition::fromRecord($r), $query->all());
    }

    /** Whether a given crossing has already been recorded. Used by the tests and the diagnostics. */
    public function exists(int $elementId, int $siteId, string $transition, DateTime $scheduledFor): bool
    {
        return (new Query())
            ->from(TransitionRecord::TABLE)
            ->where([
                'elementId' => $elementId,
                'siteId' => $siteId,
                'transition' => $transition,
                'scheduledFor' => Db::prepareDateForDb($scheduledFor),
            ])
            ->exists();
    }

    /**
     * Deletes history past the retention setting.
     *
     * Rows whose tasks have not finished are kept regardless of age. Deleting a transition
     * cascades to its tasks, and throwing away work that is still waiting to be retried would
     * turn a temporary outage into permanently lost notifications.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->historyRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime('now'))->modify("-$days days");

        $unfinished = (new Query())
            ->select(['transitionId'])
            ->from(TaskRecord::TABLE)
            ->where(['not', ['transitionId' => null]])
            ->andWhere(['status' => [TaskRecord::STATUS_PENDING, TaskRecord::STATUS_RUNNING, TaskRecord::STATUS_FAILED]]);

        return Craft::$app->getDb()->createCommand()->delete(TransitionRecord::TABLE, [
            'and',
            ['<', 'detectedAt', Db::prepareDateForDb($cutoff)],
            ['not in', 'id', $unfinished],
        ])->execute();
    }

    /**
     * Counts by transition type over a window, for the dashboard.
     *
     * @return array<string, int>
     */
    public function countsSince(DateTime $since): array
    {
        $rows = (new Query())
            ->select(['transition', 'total' => 'COUNT(*)'])
            ->from(TransitionRecord::TABLE)
            ->where(['>=', 'detectedAt', Db::prepareDateForDb($since)])
            ->groupBy(['transition'])
            ->all();

        $counts = [
            TransitionRecord::TRANSITION_PUBLISHED => 0,
            TransitionRecord::TRANSITION_EXPIRED => 0,
            TransitionRecord::TRANSITION_APPLIED => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['transition']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * The worst detection lag over a window, in seconds.
     *
     * The single most useful health number there is: it answers "how long after a post is due
     * does anything actually happen", which is the question a stale home page really asks.
     */
    public function worstLatencySince(DateTime $since): ?int
    {
        $rows = (new Query())
            ->select(['scheduledFor', 'detectedAt'])
            ->from(TransitionRecord::TABLE)
            ->where(['>=', 'detectedAt', Db::prepareDateForDb($since)])
            ->all();

        $worst = null;

        foreach ($rows as $row) {
            $lag = strtotime($row['detectedAt']) - strtotime($row['scheduledFor']);

            if ($lag > ($worst ?? -1)) {
                $worst = $lag;
            }
        }

        return $worst !== null ? max(0, $worst) : null;
    }
}
