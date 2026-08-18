<?php

namespace justinholtweb\alarmclock\migrations;

use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\alarmclock\records\ScheduleRecord;
use justinholtweb\alarmclock\records\StateRecord;
use justinholtweb\alarmclock\records\TaskRecord;
use justinholtweb\alarmclock\records\TransitionRecord;
use justinholtweb\alarmclock\services\Ticker;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        $this->primeWatermarks();

        return true;
    }

    public function safeDown(): bool
    {
        // Tasks first — they point at both of the other two.
        $this->dropTableIfExists(TaskRecord::TABLE);
        $this->dropTableIfExists(ScheduleRecord::TABLE);
        $this->dropTableIfExists(TransitionRecord::TABLE);
        $this->dropTableIfExists(StateRecord::TABLE);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(TransitionRecord::TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'elementType' => $this->string()->notNull(),
            'transition' => $this->string(16)->notNull(),

            // The moment that was scheduled, not the moment we noticed. Part of the unique key,
            // so re-detecting the same crossing is a no-op while an entry that is re-scheduled
            // for a different time is correctly a second transition.
            'scheduledFor' => $this->dateTime()->notNull(),

            'detectedAt' => $this->dateTime()->notNull(),
            'source' => $this->string(16)->notNull(),

            // Snapshotted so history still reads sensibly after the entry is renamed or deleted.
            'title' => $this->string(),
            'url' => $this->string(500),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(TaskRecord::TABLE, [
            'id' => $this->primaryKey(),
            'transitionId' => $this->integer(),
            'scheduleId' => $this->integer(),
            'action' => $this->string(32)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue(TaskRecord::STATUS_PENDING),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'maxAttempts' => $this->integer()->notNull()->defaultValue(5),

            // When this task may next be claimed. Backoff is expressed by pushing it forward
            // rather than by sleeping, so a failing webhook never occupies a runner.
            'availableAt' => $this->dateTime(),

            'startedAt' => $this->dateTime(),
            'finishedAt' => $this->dateTime(),
            'lastError' => $this->text(),
            'result' => $this->text(),
            'settings' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(ScheduleRecord::TABLE, [
            'id' => $this->primaryKey(),
            'draftId' => $this->integer()->notNull(),
            'canonicalId' => $this->integer(),
            'siteId' => $this->integer()->notNull(),
            'publishAt' => $this->dateTime()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue(ScheduleRecord::STATUS_SCHEDULED),

            // Whether applying the draft should also enable the entry, for the common case of a
            // draft against an entry that has never been switched on.
            'enableAfter' => $this->boolean()->notNull()->defaultValue(false),

            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            'appliedElementId' => $this->integer(),
            'appliedAt' => $this->dateTime(),
            'createdBy' => $this->integer(),
            'note' => $this->string(500),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(StateRecord::TABLE, [
            'key' => $this->string(64)->notNull(),
            'value' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[key]])',
        ]);
    }

    private function createIndexes(): void
    {
        // The one that matters. Everything about running three triggers at once rests on it.
        $this->createIndex(
            null,
            TransitionRecord::TABLE,
            ['elementId', 'siteId', 'transition', 'scheduledFor'],
            true,
        );

        $this->createIndex(null, TransitionRecord::TABLE, ['detectedAt']);
        $this->createIndex(null, TransitionRecord::TABLE, ['transition', 'detectedAt']);
        $this->createIndex(null, TransitionRecord::TABLE, ['siteId']);

        // The runner's hot path: "pending or failed, and due".
        $this->createIndex(null, TaskRecord::TABLE, ['status', 'availableAt']);
        $this->createIndex(null, TaskRecord::TABLE, ['transitionId']);
        $this->createIndex(null, TaskRecord::TABLE, ['scheduleId']);

        // A draft may only be scheduled once per site, so re-scheduling updates rather than
        // stacking up two rows that will race each other to apply the same draft.
        $this->createIndex(null, ScheduleRecord::TABLE, ['draftId', 'siteId'], true);
        $this->createIndex(null, ScheduleRecord::TABLE, ['status', 'publishAt']);
        $this->createIndex(null, ScheduleRecord::TABLE, ['canonicalId']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, TransitionRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, TaskRecord::TABLE, ['transitionId'], TransitionRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, TaskRecord::TABLE, ['scheduleId'], ScheduleRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, ScheduleRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);

        // Deliberately no foreign key from `transitions.elementId` to `elements.id`. History has
        // to outlive the entry it describes — "the Christmas post went live at 06:00 and then
        // somebody deleted it" is exactly the question this table exists to answer.
    }

    /**
     * Starts both watermarks at "now".
     *
     * Without this the first tick would look back to the beginning of time, decide that every
     * entry ever published had just crossed its post date, and mail the whole archive to the
     * editorial team. An install has no history to catch up on, by definition.
     */
    private function primeWatermarks(): void
    {
        $now = new DateTime('now');
        $timestamp = Db::prepareDateForDb($now);

        foreach ([Ticker::WATERMARK_PUBLISHED, Ticker::WATERMARK_EXPIRED] as $key) {
            $this->insert(StateRecord::TABLE, [
                'key' => $key,
                // `DATE_ATOM`, matching `Ticker::setState()`. A bare `Y-m-d H:i:s` written here
                // is UTC, but `new DateTime()` reads it back in the *site's* zone — so on a site
                // west of UTC the watermark lands hours in the future, every scan finds its window
                // inverted, and detection silently does nothing until the clock catches up.
                'value' => $now->format(DATE_ATOM),
                'dateCreated' => $timestamp,
                'dateUpdated' => $timestamp,
                'uid' => StringHelper::UUID(),
            ]);
        }
    }
}
