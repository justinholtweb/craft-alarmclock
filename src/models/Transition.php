<?php

namespace justinholtweb\alarmclock\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use justinholtweb\alarmclock\records\TransitionRecord;

/**
 * A crossing that has been recorded, in a shape templates and emails can read.
 */
class Transition extends Model
{
    public ?int $id = null;
    public int $elementId;
    public int $siteId;
    public string $elementType;
    public string $transition;
    public ?DateTime $scheduledFor = null;
    public ?DateTime $detectedAt = null;
    public string $source = TransitionRecord::SOURCE_CONSOLE;
    public ?string $title = null;
    public ?string $url = null;

    /** True when this row was inserted by the tick that returned it, rather than already present. */
    public bool $isNew = false;

    public static function fromRecord(TransitionRecord $record): self
    {
        return new self([
            'id' => $record->id,
            'elementId' => $record->elementId,
            'siteId' => $record->siteId,
            'elementType' => $record->elementType,
            'transition' => $record->transition,
            'scheduledFor' => DateTimeHelper::toDateTime($record->scheduledFor) ?: null,
            'detectedAt' => DateTimeHelper::toDateTime($record->detectedAt) ?: null,
            'source' => $record->source,
            'title' => $record->title,
            'url' => $record->url,
        ]);
    }

    /**
     * The element this describes, or null if it has since been deleted.
     *
     * Nullable on purpose, and every caller has to cope: the whole value of keeping history is
     * that it survives the thing it is about.
     */
    public function getElement(): ?ElementInterface
    {
        return Craft::$app->getElements()->getElementById($this->elementId, $this->elementType, $this->siteId);
    }

    public function getSite(): ?\craft\models\Site
    {
        return Craft::$app->getSites()->getSiteById($this->siteId);
    }

    /** How late the detection was, in seconds — the number that tells you if the ticker is healthy. */
    public function getLatency(): ?int
    {
        if (!$this->scheduledFor || !$this->detectedAt) {
            return null;
        }

        return max(0, $this->detectedAt->getTimestamp() - $this->scheduledFor->getTimestamp());
    }

    public function getLabel(): string
    {
        return match ($this->transition) {
            TransitionRecord::TRANSITION_PUBLISHED => Craft::t('alarm-clock', 'Published'),
            TransitionRecord::TRANSITION_EXPIRED => Craft::t('alarm-clock', 'Expired'),
            TransitionRecord::TRANSITION_APPLIED => Craft::t('alarm-clock', 'Draft applied'),
            default => $this->transition,
        };
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->id,
            'transition' => $this->transition,
            'elementId' => $this->elementId,
            'elementType' => $this->elementType,
            'siteId' => $this->siteId,
            'siteHandle' => $this->getSite()?->handle,
            'title' => $this->title,
            'url' => $this->url,
            'scheduledFor' => $this->scheduledFor?->format(DATE_ATOM),
            'detectedAt' => $this->detectedAt?->format(DATE_ATOM),
            'latencySeconds' => $this->getLatency(),
            'source' => $this->source,
        ];
    }
}
