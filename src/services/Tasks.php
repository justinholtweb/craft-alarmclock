<?php

namespace justinholtweb\alarmclock\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\alarmclock\actions\ApplyDraft;
use justinholtweb\alarmclock\actions\InvalidateCaches;
use justinholtweb\alarmclock\actions\Notify;
use justinholtweb\alarmclock\actions\SyncStatus;
use justinholtweb\alarmclock\actions\TaskHandlerInterface;
use justinholtweb\alarmclock\actions\WarmUrls;
use justinholtweb\alarmclock\actions\Webhook;
use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\TaskRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use Throwable;
use yii\base\Component;

/**
 * Runs the work a transition is owed, and keeps trying when it does not go through.
 *
 * The retry model is deliberately not Craft's queue. A queue job that fails is retried by the
 * queue on the queue's terms, disappears from view when it finally gives up, and takes its
 * siblings' fate with it when a runner dies. What people actually ask after a scheduled post goes
 * wrong is "did the email go out, and if not, why, and can I send it now" — which needs a durable
 * row per piece of work, with its own attempt count, its own error and its own retry button.
 */
class Tasks extends Component
{
    public const ACTION_SYNC_STATUS = 'sync-status';
    public const ACTION_INVALIDATE = 'invalidate-caches';
    public const ACTION_WARM = 'warm-urls';
    public const ACTION_NOTIFY = 'notify';
    public const ACTION_WEBHOOK = 'webhook';
    public const ACTION_APPLY_DRAFT = 'apply-draft';

    /**
     * Order matters, and this is the order they run in. A stored status is refreshed before
     * anything reads the entry, because on a `staticStatuses` site the entry is not actually live
     * until that has happened. Caches are cleared before anyone is told the post is live, because
     * an email that arrives before the home page updates sends the recipient to a page that does
     * not show the thing the email is about — which reads as a broken site, not a fast email.
     *
     * @var array<string, class-string<TaskHandlerInterface>>
     */
    private const HANDLERS = [
        self::ACTION_APPLY_DRAFT => ApplyDraft::class,
        self::ACTION_SYNC_STATUS => SyncStatus::class,
        self::ACTION_INVALIDATE => InvalidateCaches::class,
        self::ACTION_WARM => WarmUrls::class,
        self::ACTION_NOTIFY => Notify::class,
        self::ACTION_WEBHOOK => Webhook::class,
    ];

    // ------------------------------------------------------------------ queueing

    /**
     * Creates the tasks a newly recorded transition is owed.
     *
     * @return int How many tasks were created.
     */
    public function queueForTransition(Transition $transition): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $isExpiry = $transition->transition === TransitionRecord::TRANSITION_EXPIRED;
        $actions = [];

        // First, and only where it does anything: on a `staticStatuses` site the entry is not
        // live yet, whatever its dates say, and every action after this one would be acting on
        // content the front end still cannot see.
        if (Craft::$app->getConfig()->getGeneral()->staticStatuses) {
            $actions[] = self::ACTION_SYNC_STATUS;
        }

        if ($settings->invalidateElementCaches || $settings->invalidateTypeCaches || $settings->invalidateAllTemplateCaches) {
            $actions[] = self::ACTION_INVALIDATE;
        }

        // Warming an expired page would put the 404 into the cache instead of the page, which is
        // worse than leaving it cold.
        if ($settings->warmUrls && !$isExpiry) {
            $actions[] = self::ACTION_WARM;
        }

        if ($settings->notify && (!$isExpiry || $settings->notifyOnExpiry)) {
            $actions[] = self::ACTION_NOTIFY;
        }

        if ($settings->webhookUrl !== '') {
            $actions[] = self::ACTION_WEBHOOK;
        }

        foreach ($actions as $action) {
            $this->create($action, transitionId: $transition->id);
        }

        return count($actions);
    }

    /** Creates a task row. */
    public function create(
        string $action,
        ?int $transitionId = null,
        ?int $scheduleId = null,
        ?DateTime $availableAt = null,
        array $settings = [],
        ?int $maxAttempts = null,
    ): int {
        $now = new DateTime('now');

        Craft::$app->getDb()->createCommand()->insert(TaskRecord::TABLE, [
            'transitionId' => $transitionId,
            'scheduleId' => $scheduleId,
            'action' => $action,
            'status' => TaskRecord::STATUS_PENDING,
            'attempts' => 0,
            'maxAttempts' => $maxAttempts ?? Plugin::getInstance()->getSettings()->maxAttempts,
            'availableAt' => Db::prepareDateForDb($availableAt ?? $now),
            'settings' => $settings ? Json::encode($settings) : null,
            'dateCreated' => Db::prepareDateForDb($now),
            'dateUpdated' => Db::prepareDateForDb($now),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID(TaskRecord::TABLE);
    }

    // ------------------------------------------------------------------ running

    /**
     * Runs due tasks.
     *
     * @param int $limit Most tasks to attempt.
     * @param float|null $budget Seconds to spend before stopping and leaving the rest. Null for
     *                           no limit — correct on the console, wrong on a web request, where
     *                           a visitor is waiting on the other end of it.
     * @return array{run: int, failed: int, reclaimed: int}
     */
    public function runDue(int $limit, ?float $budget = null): array
    {
        $reclaimed = $this->reclaimStale();
        $started = microtime(true);
        $run = 0;
        $failed = 0;

        foreach ($this->dueIds($limit) as $id) {
            if ($budget !== null && (microtime(true) - $started) >= $budget) {
                break;
            }

            $task = $this->claim($id);

            if ($task === null) {
                // Another runner took it between the query and the claim. Normal under load.
                continue;
            }

            $run++;

            if (!$this->attempt($task)) {
                $failed++;
            }
        }

        return ['run' => $run, 'failed' => $failed, 'reclaimed' => $reclaimed];
    }

    /**
     * @return int[]
     */
    public function dueIds(int $limit): array
    {
        return (new Query())
            ->select(['id'])
            ->from(TaskRecord::TABLE)
            ->where(['status' => [TaskRecord::STATUS_PENDING, TaskRecord::STATUS_FAILED]])
            ->andWhere(['or', ['availableAt' => null], ['<=', 'availableAt', Db::prepareDateForDb(new DateTime('now'))]])
            ->orderBy(['availableAt' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->column();
    }

    /**
     * Takes ownership of a task, or returns null if somebody else already has.
     *
     * The status check lives in the `WHERE` clause rather than in a preceding `SELECT` on purpose.
     * Two runners reading "pending" and both deciding to proceed is the default outcome of doing
     * this in application code; making the transition to `running` the thing that has to be won
     * means the loser is told so by the affected-row count.
     */
    public function claim(int $id): ?Task
    {
        $now = new DateTime('now');

        $affected = Craft::$app->getDb()->createCommand()->update(
            TaskRecord::TABLE,
            [
                'status' => TaskRecord::STATUS_RUNNING,
                'startedAt' => Db::prepareDateForDb($now),
                'dateUpdated' => Db::prepareDateForDb($now),
            ],
            [
                'and',
                ['id' => $id],
                ['status' => [TaskRecord::STATUS_PENDING, TaskRecord::STATUS_FAILED]],
            ],
        )->execute();

        if ($affected !== 1) {
            return null;
        }

        // Increment separately so the count is expressed as an increment rather than a value read
        // in one statement and written in another.
        Craft::$app->getDb()->createCommand(
            'UPDATE ' . Craft::$app->getDb()->quoteTableName(TaskRecord::TABLE) .
            ' SET ' . Craft::$app->getDb()->quoteColumnName('attempts') . ' = ' .
            Craft::$app->getDb()->quoteColumnName('attempts') . ' + 1 WHERE ' .
            Craft::$app->getDb()->quoteColumnName('id') . ' = :id',
            [':id' => $id],
        )->execute();

        $record = TaskRecord::findOne($id);

        return $record ? Task::fromRecord($record) : null;
    }

    /**
     * Runs one claimed task and records the outcome.
     *
     * @return bool Whether it succeeded.
     */
    public function attempt(Task $task): bool
    {
        $plugin = Plugin::getInstance();
        $transition = $task->transitionId !== null ? $plugin->transitions->getById($task->transitionId) : null;

        try {
            $handler = $this->handler($task->action);
            $result = $handler->run($task, $transition);
            $this->markDone($task, $result);

            if ($plugin->getSettings()->verboseLogging) {
                Craft::info("Task $task->id ($task->action): $result", Plugin::LOG_CATEGORY);
            }

            return true;
        } catch (Throwable $e) {
            $this->markFailed($task, $e);

            Craft::warning(
                sprintf(
                    'Task %d (%s) failed on attempt %d of %d: %s',
                    $task->id,
                    $task->action,
                    $task->attempts,
                    $task->maxAttempts,
                    $e->getMessage(),
                ),
                Plugin::LOG_CATEGORY,
            );

            return false;
        }
    }

    private function markDone(Task $task, string $result): void
    {
        $now = new DateTime('now');

        Craft::$app->getDb()->createCommand()->update(TaskRecord::TABLE, [
            'status' => TaskRecord::STATUS_DONE,
            'finishedAt' => Db::prepareDateForDb($now),
            'result' => StringHelper::safeTruncate($result, 500),
            'lastError' => null,
            'dateUpdated' => Db::prepareDateForDb($now),
        ], ['id' => $task->id])->execute();
    }

    private function markFailed(Task $task, Throwable $e): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $now = new DateTime('now');
        $giveUp = !$task->hasAttemptsLeft();

        $values = [
            'status' => $giveUp ? TaskRecord::STATUS_ABANDONED : TaskRecord::STATUS_FAILED,
            'lastError' => StringHelper::safeTruncate($e->getMessage(), 2000),
            'dateUpdated' => Db::prepareDateForDb($now),
        ];

        if ($giveUp) {
            $values['finishedAt'] = Db::prepareDateForDb($now);
        } else {
            // Backoff is a future `availableAt`, never a sleep. A runner that sleeps through a
            // retry delay is a runner not doing the other nine things that are due.
            $values['availableAt'] = Db::prepareDateForDb(
                (clone $now)->modify('+' . $settings->backoffFor($task->attempts) . ' seconds'),
            );
        }

        Craft::$app->getDb()->createCommand()->update(TaskRecord::TABLE, $values, ['id' => $task->id])->execute();

        if ($giveUp && $settings->notifyOnFailure) {
            // Deliberately direct rather than as another task: a task that exists to report that
            // tasks are failing must not be able to fail silently in the same way.
            $plugin->notifier->sendFailureEmail($task, $e);
        }
    }

    /**
     * Returns tasks stuck in `running` to the pool.
     *
     * A runner killed mid-task — a deploy, an out-of-memory kill, a PHP timeout — leaves its row
     * claimed forever otherwise. Work that is neither finished nor waiting is the single most
     * confusing state to debug, because nothing is obviously broken and nothing is happening.
     *
     * The attempt has already been counted, so a task that reliably kills its runner still runs
     * out of attempts rather than looping forever.
     */
    public function reclaimStale(): int
    {
        $ttr = Plugin::getInstance()->getSettings()->taskTtr;
        $cutoff = (new DateTime('now'))->modify("-$ttr seconds");

        return Craft::$app->getDb()->createCommand()->update(
            TaskRecord::TABLE,
            [
                'status' => TaskRecord::STATUS_FAILED,
                'lastError' => 'The runner did not finish; the task was reclaimed after ' . $ttr . ' seconds.',
                'dateUpdated' => Db::prepareDateForDb(new DateTime('now')),
            ],
            [
                'and',
                ['status' => TaskRecord::STATUS_RUNNING],
                ['<', 'startedAt', Db::prepareDateForDb($cutoff)],
            ],
        )->execute();
    }

    // ------------------------------------------------------------------ operator actions

    /**
     * Puts a task back in the pool by hand, whatever state it is in.
     *
     * `resetAttempts` exists because the common case is that somebody has just fixed the thing
     * that was broken — a wrong webhook URL, a mail server that was down — and wants the full
     * complement of attempts back, not the one that was left.
     */
    public function retry(int $id, bool $resetAttempts = true): bool
    {
        $now = new DateTime('now');

        $values = [
            'status' => TaskRecord::STATUS_PENDING,
            'availableAt' => Db::prepareDateForDb($now),
            'startedAt' => null,
            'finishedAt' => null,
            'dateUpdated' => Db::prepareDateForDb($now),
        ];

        if ($resetAttempts) {
            $values['attempts'] = 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->update(TaskRecord::TABLE, $values, ['id' => $id])
            ->execute() > 0;
    }

    /** Retries every abandoned task at once, for after a mail server or endpoint comes back. */
    public function retryAllAbandoned(): int
    {
        $ids = (new Query())
            ->select(['id'])
            ->from(TaskRecord::TABLE)
            ->where(['status' => TaskRecord::STATUS_ABANDONED])
            ->column();

        foreach ($ids as $id) {
            $this->retry((int)$id);
        }

        return count($ids);
    }

    // ------------------------------------------------------------------ reading

    /**
     * @return Task[]
     */
    public function forTransition(int $transitionId): array
    {
        return array_map(
            fn(TaskRecord $r) => Task::fromRecord($r),
            TaskRecord::find()->where(['transitionId' => $transitionId])->orderBy(['id' => SORT_ASC])->all(),
        );
    }

    /**
     * @return Task[]
     */
    public function problems(int $limit = 100): array
    {
        return array_map(
            fn(TaskRecord $r) => Task::fromRecord($r),
            TaskRecord::find()
                ->where(['status' => [TaskRecord::STATUS_FAILED, TaskRecord::STATUS_ABANDONED]])
                ->orderBy(['dateUpdated' => SORT_DESC])
                ->limit($limit)
                ->all(),
        );
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from(TaskRecord::TABLE)
            ->groupBy(['status'])
            ->all();

        $counts = array_fill_keys([
            TaskRecord::STATUS_PENDING,
            TaskRecord::STATUS_RUNNING,
            TaskRecord::STATUS_DONE,
            TaskRecord::STATUS_FAILED,
            TaskRecord::STATUS_ABANDONED,
        ], 0);

        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    public function handler(string $action): TaskHandlerInterface
    {
        $class = self::HANDLERS[$action] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException("No handler for “{$action}”.");
        }

        return new $class();
    }

    /**
     * Deletes finished tasks whose transition has already been pruned, plus finished tasks past
     * retention. Unfinished work is never touched, whatever its age.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->historyRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime('now'))->modify("-$days days");

        return Craft::$app->getDb()->createCommand()->delete(TaskRecord::TABLE, [
            'and',
            ['status' => TaskRecord::STATUS_DONE],
            ['<', 'finishedAt', Db::prepareDateForDb($cutoff)],
        ])->execute();
    }
}
