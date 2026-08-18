<?php

namespace justinholtweb\alarmclock\records;

use craft\db\ActiveRecord;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * A draft that has been told when to become the entry.
 *
 * Craft can schedule an entry's *first* appearance through `postDate`, but it has no way to say
 * "replace the live copy with this draft at nine tomorrow". That is the gap this table fills, and
 * it is the one place in the plugin where publishing can genuinely fail — a draft can be invalid,
 * its canonical entry can be deleted, another editor can apply a conflicting draft first — which
 * is what the retry machinery is really for.
 *
 * @property int $id
 * @property int $draftId
 * @property int|null $canonicalId
 * @property int $siteId
 * @property string $publishAt
 * @property string $status
 * @property bool $enableAfter
 * @property int $attempts
 * @property string|null $lastError
 * @property int|null $appliedElementId
 * @property string|null $appliedAt
 * @property int|null $createdBy
 * @property string|null $note
 */
class ScheduleRecord extends ActiveRecord
{
    public const TABLE = '{{%alarmclock_schedules}}';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    /**
     * The scheduled moment, as an instant rather than a string.
     *
     * Active Record hands back exactly what the column holds — a bare `Y-m-d H:i:s` in UTC — and
     * anything that reads it with `new DateTime()` or Twig's `datetime` filter interprets it in
     * the *site's* zone instead. A draft scheduled for nine in the morning then displays as four
     * in the afternoon, and the ledger records the wrong instant entirely.
     * `DateTimeHelper::toDateTime()` assumes UTC for a string with no zone, which is the contract
     * the column was written under.
     */
    public function getPublishAtDate(): ?DateTime
    {
        return DateTimeHelper::toDateTime($this->publishAt) ?: null;
    }

    public function getAppliedAtDate(): ?DateTime
    {
        return $this->appliedAt ? (DateTimeHelper::toDateTime($this->appliedAt) ?: null) : null;
    }
}
