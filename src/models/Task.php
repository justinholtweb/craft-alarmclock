<?php

namespace justinholtweb\alarmclock\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use justinholtweb\alarmclock\records\TaskRecord;

/**
 * A pending, running or finished unit of work.
 */
class Task extends Model
{
    public ?int $id = null;
    public ?int $transitionId = null;
    public ?int $scheduleId = null;
    public string $action = '';
    public string $status = TaskRecord::STATUS_PENDING;
    public int $attempts = 0;
    public int $maxAttempts = 5;
    public ?DateTime $availableAt = null;
    public ?DateTime $startedAt = null;
    public ?DateTime $finishedAt = null;
    public ?string $lastError = null;
    public ?string $result = null;
    public array $settings = [];

    public static function fromRecord(TaskRecord $record): self
    {
        return new self([
            'id' => $record->id,
            'transitionId' => $record->transitionId,
            'scheduleId' => $record->scheduleId,
            'action' => $record->action,
            'status' => $record->status,
            'attempts' => (int)$record->attempts,
            'maxAttempts' => (int)$record->maxAttempts,
            'availableAt' => $record->availableAt ? DateTimeHelper::toDateTime($record->availableAt) ?: null : null,
            'startedAt' => $record->startedAt ? DateTimeHelper::toDateTime($record->startedAt) ?: null : null,
            'finishedAt' => $record->finishedAt ? DateTimeHelper::toDateTime($record->finishedAt) ?: null : null,
            'lastError' => $record->lastError,
            'result' => $record->result,
            'settings' => is_string($record->settings) ? (Json::decodeIfJson($record->settings) ?: []) : [],
        ]);
    }

    /** Whether another attempt is still owed after the one that just failed. */
    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function getLabel(): string
    {
        return Craft::t('alarm-clock', match ($this->action) {
            'sync-status' => 'Refresh stored status',
            'invalidate-caches' => 'Clear caches',
            'warm-urls' => 'Warm URLs',
            'notify' => 'Send notification',
            'webhook' => 'Call webhook',
            'apply-draft' => 'Apply draft',
            default => $this->action,
        });
    }
}
