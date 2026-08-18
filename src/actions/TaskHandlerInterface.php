<?php

namespace justinholtweb\alarmclock\actions;

use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;

/**
 * Something the plugin does about a transition.
 *
 * Handlers are deliberately allowed — expected, even — to throw. A thrown exception is how a
 * handler says "not this time", and the runner turns that into an attempt count and a backoff.
 * A handler that swallows its own errors and returns normally has told the runner the work
 * succeeded, and it will never be retried.
 */
interface TaskHandlerInterface
{
    /**
     * Does the work.
     *
     * @return string A short human-readable note about what happened, kept on the task row so the
     *                control panel can say "cleared 4 tags" rather than only "done".
     * @throws \Throwable to fail the attempt and schedule a retry.
     */
    public function run(Task $task, ?Transition $transition): string;
}
