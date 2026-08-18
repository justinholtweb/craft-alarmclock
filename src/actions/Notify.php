<?php

namespace justinholtweb\alarmclock\actions;

use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use RuntimeException;

/**
 * Emails the people who asked to hear about it.
 */
class Notify implements TaskHandlerInterface
{
    public function run(Task $task, ?Transition $transition): string
    {
        if ($transition === null) {
            return 'Nothing to announce — the transition is gone.';
        }

        $sent = Plugin::getInstance()->notifier->sendTransitionEmail($transition);

        if ($sent === 0) {
            // Not an error: a site can legitimately have notifications on and no recipients yet.
            // Throwing here would retry five times and then abandon, which reads in the control
            // panel as a broken plugin rather than an empty address list.
            return 'No recipients configured.';
        }

        if ($sent < 0) {
            throw new RuntimeException('The mailer refused the message.');
        }

        return sprintf('Emailed %d recipient%s.', $sent, $sent === 1 ? '' : 's');
    }
}
