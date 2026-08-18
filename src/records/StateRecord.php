<?php

namespace justinholtweb\alarmclock\records;

use craft\db\ActiveRecord;

/**
 * Small durable key/value store for the ticker's watermarks.
 *
 * Deliberately a table and not the cache. A watermark that vanishes when someone clears caches
 * would make the plugin re-scan from nothing — which, on a site with years of archives, means
 * re-announcing every post ever published.
 *
 * @property string $key
 * @property string|null $value
 */
class StateRecord extends ActiveRecord
{
    public const TABLE = '{{%alarmclock_state}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
