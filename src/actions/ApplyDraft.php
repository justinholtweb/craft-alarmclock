<?php

namespace justinholtweb\alarmclock\actions;

use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;

/**
 * Applies a draft that was scheduled for this moment.
 *
 * The only task in the plugin that changes content rather than reporting on it, and the only one
 * that can fail for reasons a person has to fix: a draft can be invalid, its canonical entry can
 * have been deleted, another editor can have applied a conflicting draft in the meantime. That is
 * exactly why the retry machinery exists, and why this handler leaves the schedule row alone on
 * failure rather than marking it done.
 */
class ApplyDraft implements TaskHandlerInterface
{
    public function run(Task $task, ?Transition $transition): string
    {
        if ($task->scheduleId === null) {
            return 'No schedule attached.';
        }

        return Plugin::getInstance()->schedules->apply($task->scheduleId, $task->hasAttemptsLeft());
    }
}
