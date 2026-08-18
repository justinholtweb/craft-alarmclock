<?php

namespace justinholtweb\alarmclock\records;

use craft\db\ActiveRecord;

/**
 * One recorded crossing of a scheduled moment.
 *
 * This table is the plugin's correctness guarantee, not a log. Three independent triggers (cron,
 * queue, front-end request) can all be looking at the same window at the same time; the unique
 * index on `(elementId, siteId, transition, scheduledFor)` is what makes a transition happen
 * exactly once no matter how many of them get there first.
 *
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $elementType
 * @property string $transition
 * @property string $scheduledFor
 * @property string $detectedAt
 * @property string $source
 * @property string|null $title
 * @property string|null $url
 */
class TransitionRecord extends ActiveRecord
{
    public const TABLE = '{{%alarmclock_transitions}}';

    /** An element's post date passed, so it is live where it was pending. */
    public const TRANSITION_PUBLISHED = 'published';

    /** An element's expiry date passed, so it is gone where it was live. */
    public const TRANSITION_EXPIRED = 'expired';

    /** A draft this plugin was holding was applied to its canonical entry. */
    public const TRANSITION_APPLIED = 'applied';

    public const SOURCE_CONSOLE = 'console';
    public const SOURCE_QUEUE = 'queue';
    public const SOURCE_WEB = 'web';
    public const SOURCE_MANUAL = 'manual';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
