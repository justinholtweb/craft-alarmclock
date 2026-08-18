<?php

namespace justinholtweb\alarmclock\models;

use craft\base\Model;

/**
 * What one run of the ticker did.
 *
 * Returned rather than logged so the console command, the queue job and the tests can all report
 * on the same run without any of them having to read the log back.
 */
class TickResult extends Model
{
    /** False when another tick already held the lock, which is normal and not an error. */
    public bool $ran = false;

    public string $source = '';
    public int $published = 0;
    public int $expired = 0;
    public int $applied = 0;
    public int $tasksRun = 0;
    public int $tasksFailed = 0;
    public int $tasksReclaimed = 0;

    /** True when the batch limit was hit, so there is more waiting for the next tick. */
    public bool $more = false;

    public float $duration = 0.0;

    /** @var string[] */
    public array $notes = [];

    public function getTransitionCount(): int
    {
        return $this->published + $this->expired + $this->applied;
    }

    public function getSummary(): string
    {
        if (!$this->ran) {
            return 'Skipped — another tick was already running.';
        }

        return sprintf(
            '%d published, %d expired, %d drafts applied; %d tasks run, %d failed%s',
            $this->published,
            $this->expired,
            $this->applied,
            $this->tasksRun,
            $this->tasksFailed,
            $this->more ? ' (more waiting)' : '',
        );
    }
}
