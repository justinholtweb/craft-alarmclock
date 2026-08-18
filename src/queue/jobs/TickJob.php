<?php

namespace justinholtweb\alarmclock\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\alarmclock\Plugin;
use justinholtweb\alarmclock\records\TransitionRecord;

/**
 * A tick, on the queue, that puts the next one back on the queue.
 *
 * Self-rescheduling because Craft's queue has no repeating jobs. The re-push happens whether or
 * not the tick did anything, and whether or not it threw — a chain that breaks on the first
 * failure is a chain that stops silently, which is the failure this whole plugin exists to stop
 * happening to other people's posts.
 */
class TickJob extends BaseJob
{
    /** Seconds until the next tick is pushed. */
    public int $interval = 60;

    /** Set false for a one-off run, e.g. from the "run now" button. */
    public bool $reschedule = true;

    public function execute($queue): void
    {
        try {
            $result = Plugin::getInstance()->ticker->tick(TransitionRecord::SOURCE_QUEUE);

            $this->setProgress($queue, 1, $result->getSummary());
        } finally {
            if ($this->reschedule && Plugin::getInstance()->getSettings()->queueTrigger) {
                Plugin::getInstance()->ticker->scheduleNext($this->interval);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('alarm-clock', 'Checking for scheduled content');
    }
}
