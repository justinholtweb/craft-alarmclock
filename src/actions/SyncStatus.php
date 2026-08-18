<?php

namespace justinholtweb\alarmclock\actions;

use Craft;
use craft\elements\Entry;
use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use RuntimeException;

/**
 * Makes a statically-stored entry status catch up with the dates.
 *
 * Only does anything on sites running with the `staticStatuses` config setting (Craft 5.7+), and
 * on those sites it is the one action here that genuinely *publishes* something rather than
 * reporting that something published itself.
 *
 * With `staticStatuses` on, `entries.status` is a stored column that changes on save or when
 * `craft update-statuses` is run — nothing else. A site whose host has no cron therefore has
 * scheduled entries that sit at `pending` past their post date indefinitely: the actual missed
 * schedule, with the same cause and the same shape as the WordPress problem this plugin is named
 * after. Craft's own command resaves the affected entries, and so does this, one entry at a time
 * so a single invalid entry cannot take the batch down with it.
 */
class SyncStatus implements TaskHandlerInterface
{
    public function run(Task $task, ?Transition $transition): string
    {
        if (!Craft::$app->getConfig()->getGeneral()->staticStatuses) {
            return 'Statuses are derived, not stored — nothing to sync.';
        }

        if ($transition === null) {
            return 'Nothing to sync — the transition is gone.';
        }

        $element = $transition->getElement();

        if (!$element instanceof Entry) {
            return 'Nothing to sync — the entry is gone.';
        }

        $before = $element->getStatus();

        // Validation off: the entry is already saved and live-by-the-dates, and refusing to update
        // its status because an unrelated field is now invalid would leave the site with content
        // that is due but unreachable. Search index off because no indexed value has changed.
        $saved = Craft::$app->getElements()->saveElement(
            $element,
            runValidation: false,
            updateSearchIndex: false,
        );

        if (!$saved) {
            throw new RuntimeException(sprintf(
                'Could not resave entry %d to refresh its status: %s',
                $element->id,
                implode('; ', $element->getErrorSummary(true)) ?: 'no reason given',
            ));
        }

        return sprintf('Status refreshed (%s → %s).', $before ?? 'unknown', $element->getStatus() ?? 'unknown');
    }
}
