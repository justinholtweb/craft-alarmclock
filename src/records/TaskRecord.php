<?php

namespace justinholtweb\alarmclock\records;

use craft\db\ActiveRecord;

/**
 * A unit of work owed to a transition — clear these caches, send that email, call that webhook.
 *
 * Kept separate from the transition itself because a transition is a *fact* and cannot fail,
 * while everything we do about it can. Splitting them means a webhook that is down for an hour
 * retries on its own without the transition being re-detected, and without a failing webhook
 * taking the cache clear down with it.
 *
 * @property int $id
 * @property int|null $transitionId
 * @property int|null $scheduleId
 * @property string $action
 * @property string $status
 * @property int $attempts
 * @property int $maxAttempts
 * @property string|null $availableAt
 * @property string|null $startedAt
 * @property string|null $finishedAt
 * @property string|null $lastError
 * @property string|null $result
 * @property array|string|null $settings
 */
class TaskRecord extends ActiveRecord
{
    public const TABLE = '{{%alarmclock_tasks}}';

    /** Waiting for its turn. Only rows in this status can be claimed. */
    public const STATUS_PENDING = 'pending';

    /** Claimed by a runner. Reclaimed automatically if the runner dies. */
    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    /** Failed, and will be tried again once `availableAt` comes round. */
    public const STATUS_FAILED = 'failed';

    /** Failed `maxAttempts` times. Nothing will pick it up again without a person asking. */
    public const STATUS_ABANDONED = 'abandoned';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
