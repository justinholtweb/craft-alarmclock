<?php

namespace justinholtweb\alarmclock\events;

use craft\base\ElementInterface;
use justinholtweb\alarmclock\models\Transition;
use yii\base\Event;

/**
 * Fired once, the first time a crossing is noticed.
 *
 * This is the event Craft does not have. An entry becoming live because the clock moved fires no
 * save event, no status event and no element event of any kind — nothing changed, so there is
 * nothing for Craft to announce. Anything that wants to act on scheduled content, from pinging a
 * search engine to posting to social, has had nowhere to hang itself until now.
 *
 * Handlers should be quick and must not assume they can stop anything: the transition has already
 * happened and already been recorded by the time this fires. For work that can fail and should be
 * retried, add a task instead.
 */
class TransitionEvent extends Event
{
    public Transition $transition;

    /** Null when the element has been deleted between the crossing and the event. */
    public ?ElementInterface $element = null;
}
