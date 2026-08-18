<?php

namespace justinholtweb\alarmclock\twig;

use craft\elements\Entry;
use DateTime;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\ScheduleRecord;

/**
 * `craft.alarmClock.…`
 *
 * Read-only. Everything that changes anything goes through a controller with a permission check on
 * it; a template is not the place from which a draft gets published.
 */
class AlarmClockVariable
{
    /**
     * Entries whose post date is still ahead of them.
     *
     * @return Entry[]
     */
    public function upcoming(int $limit = 20): array
    {
        return Plugin::getInstance()->ticker->upcoming($limit);
    }

    /**
     * Recent crossings, newest first.
     *
     * @return Transition[]
     */
    public function recent(int $limit = 20, ?string $transition = null): array
    {
        return Plugin::getInstance()->transitions->recent($limit, $transition);
    }

    /**
     * The last crossing recorded for an entry, if there is one.
     *
     * Handy for "updated 20 minutes ago" bylines that should reflect when the piece actually went
     * live rather than when it was last saved — which on a scheduled post is usually days earlier.
     */
    public function lastTransitionFor(Entry $entry): ?Transition
    {
        $matches = Plugin::getInstance()->transitions->recent(1, null, (int)$entry->siteId);

        foreach ($matches as $match) {
            if ($match->elementId === (int)$entry->id) {
                return $match;
            }
        }

        return null;
    }

    /** When an entry is due to go live, or null if it already is. */
    public function goesLiveAt(Entry $entry): ?DateTime
    {
        return $entry->postDate && $entry->postDate > new DateTime('now') ? $entry->postDate : null;
    }

    /** @return ScheduleRecord[] */
    public function scheduledDrafts(int $limit = 20): array
    {
        return Plugin::getInstance()->schedules->upcoming($limit);
    }

    /** Seconds since the last tick, or null if there has never been one. */
    public function secondsSinceLastTick(): ?int
    {
        return Plugin::getInstance()->ticker->secondsSinceLastTick();
    }

    public function pendingCount(): int
    {
        return Plugin::getInstance()->ticker->pendingCount();
    }
}
