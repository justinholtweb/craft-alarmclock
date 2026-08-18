<?php

namespace justinholtweb\alarmclock\actions;

use Craft;
use craft\helpers\Db;
use craft\records\Element as ElementRecord;
use justinholtweb\alarmclock\models\Task;
use justinholtweb\alarmclock\models\Transition;
use justinholtweb\alarmclock\Plugin;
use yii\caching\TagDependency;

/**
 * Clears the caches that a crossing has just made wrong.
 *
 * This is the whole point of the plugin, so it is worth being precise about what is broken.
 *
 * Craft caps a `{% cache %}` block's lifetime at the soonest `getExpiryDate()` of the elements it
 * rendered (`ElementQuery::afterPopulate()` → `Elements::setCacheExpiryDate()`), and
 * `Entry::getExpiryDate()` returns only the entry's *expiry* date — never its post date. A pending
 * entry is by definition not returned by any live-status query, so it contributes neither a tag
 * nor an expiry to the caches that will need to change when it appears. The listing page therefore
 * has no idea a post is coming, and goes on serving yesterday's HTML after the post is live in the
 * database.
 *
 * Craft never calls `invalidateCachesForElement()` for a time-based transition, because from
 * Craft's point of view nothing happened — status is derived in SQL and no row changed. Calling it
 * here is the fix, and it is enough on its own: the tags it invalidates
 * (`element::craft\elements\Entry::section:5`, `entryType:9`, `element::<id>`) are exactly the ones
 * a `craft.entries.section('news')` query registered when it built the listing.
 */
class InvalidateCaches implements TaskHandlerInterface
{
    public function run(Task $task, ?Transition $transition): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $done = [];

        if ($transition === null) {
            return 'Nothing to clear — the transition is gone.';
        }

        if ($settings->invalidateElementCaches) {
            $element = $transition->getElement();

            if ($element !== null) {
                Craft::$app->getElements()->invalidateCachesForElement($element);
                $done[] = 'element caches';
            } else {
                // The element has been deleted since the crossing was recorded. Its own tags are
                // still the right ones to clear — a listing that rendered it is just as stale —
                // so rebuild them from the id rather than giving up.
                TagDependency::invalidate(Craft::$app->getCache(), [
                    sprintf('element::%s', $transition->elementId),
                    sprintf('element::%s::*', $transition->elementType),
                ]);
                $done[] = 'element caches (by id — the element is gone)';
            }
        }

        if ($settings->invalidateTypeCaches) {
            Craft::$app->getElements()->invalidateCachesForElementType($transition->elementType);
            $done[] = 'element-type caches';
        }

        if ($settings->invalidateAllTemplateCaches) {
            // Every `{% cache %}` block Craft has stored carries a `template` tag.
            TagDependency::invalidate(Craft::$app->getCache(), 'template');
            $done[] = 'all template caches';
        }

        // Bump the element's `dateUpdated` so anything watching the elements table for changes —
        // a static cache warmer, a search-index rebuild, an external sync — sees the transition
        // too. Deliberately not a full element save: re-saving would fire save events, spawn
        // revisions, and on a big site cost far more than the transition is worth.
        if ($settings->invalidateElementCaches && $transition->getElement() !== null) {
            Craft::$app->getDb()->createCommand()->update(
                ElementRecord::tableName(),
                ['dateUpdated' => Db::prepareDateForDb(new \DateTime('now'))],
                ['id' => $transition->elementId],
            )->execute();
        }

        return $done ? 'Cleared ' . implode(', ', $done) . '.' : 'Nothing enabled to clear.';
    }
}
