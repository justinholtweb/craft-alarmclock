---
title: FAQ
slug: faq
order: 50
summary: Common questions about scheduled publishing, missed posts and caching in Craft.
---

## Does Craft actually miss scheduled posts?

Almost never. Entry status is derived in SQL from `postDate` and `expiryDate`, so an entry becomes
live the instant the clock passes it, cron or no cron. There is nothing to trigger.

What Craft misses is that it *happened*: no event fires, no cache is invalidated, nothing is logged
and nobody is told — because as far as Craft is concerned, no row changed.

The one real exception is a site running with the `staticStatuses` config setting (Craft 5.7+),
where `entries.status` is a stored column that only changes on save. There an entry genuinely does
sit at `pending` past its post date. Alarm Clock detects by dates rather than by status, so it sees
those, and resaves them.

## So why is my scheduled post not showing up?

Usually a cached page. Craft caps a `{% cache %}` block's lifetime at the soonest
`getExpiryDate()` of the elements it rendered, and `Entry::getExpiryDate()` returns only
`expiryDate`, never `postDate`. A pending entry is returned by no live-status query, so it
contributes neither a cache tag nor an expiry to the pages that will need to change when it
appears.

Your 9am post goes live in the database at 9am, and the cached home page keeps serving the 8am HTML
until something unrelated happens to save an entry. Every explanation on the internet is about
cron, which was never involved.

## Is this the WordPress "Scheduled Post Trigger" plugin for Craft?

The idea is borrowed; the implementation is not. That plugin checks on the way *in*, publishes
inline, and says in its own description that it is a stop-gap not to be used on a busy site —
because the visitor pays for the work.

Alarm Clock's front-end trigger hangs off `Response::EVENT_AFTER_SEND`, which fires after the bytes
have gone out, releases the connection with `fastcgi_finish_request()` where the SAPI allows it,
and is bounded twice over: at most three tasks and at most three seconds of them by default.
Whatever does not fit is left for the next tick.

## Do I need cron?

No. The front-end trigger is on by default and works on any host that serves a page.

Use cron if you have it — it is exact, it costs your visitors nothing, and it keeps working on a
site nobody has visited since yesterday. One line:

```cron
* * * * * cd /path/to/site && php craft alarm-clock/tick
```

## Is it safe to run all three triggers at once?

Yes, and that is the intended configuration on a host you are not sure about. A unique index on
`(elementId, siteId, transition, scheduledFor)` makes a crossing act exactly once no matter how
many triggers notice it. The database enforces that, not application code — three PHP processes
checking-then-inserting is not something application code can make safe.

## Will the front-end trigger slow my site down?

No. It runs after the response has been sent, at most once per **Check at most every** interval
(60 seconds by default), and stops after three tasks or three seconds. On FPM it releases the
connection first, so the work happens on time you have already been paid for.

## Is it free?

Yes. Free, single edition, and everything described in these docs is switched on — there is no Pro
tier, no key to enter and no feature held back.

It is distributed under
[the Craft License](https://github.com/justinholtweb/craft-alarmclock/blob/main/LICENSE.md), the
standard Craft Plugin Store licence, rather than MIT.

## What happens to posts that went live before I installed it?

Nothing. The install migration primes the scan watermarks to *now*, so the first tick looks forward
rather than backwards. Without that it would decide every entry ever published had just crossed and
mail your entire archive to the editorial team.

## Does it work with Blitz, a CDN or a static host?

Craft's own template caches, yes, automatically. Anything in front of Craft — a CDN, a reverse
proxy, a static-file host — is not something `invalidateCachesForElement()` can reach.

Use the webhook. It is the deliberate extension point, which is why there is no per-service
integration to configure and keep up to date.

## Can it schedule a draft to replace what is live?

Yes, and this is the one genuinely missing Craft feature the plugin adds. Craft can schedule an
entry's *first* appearance with `postDate`; it cannot say "replace what is currently live with this
draft at nine tomorrow". Set it from the draft's own sidebar, or from the console.

## Does it work with categories or assets?

No, deliberately. Only entries have post and expiry dates in Craft, so there is nothing to watch on
anything else.

## Does it use Craft's queue for retries?

No. What people ask after a scheduled post goes wrong is "did the email go out, and can I send it
now" — which needs a durable row per piece of work, with its own attempt count, its own error and
its own retry button. A queue job that has failed and been cleared cannot answer that.

The queue *is* used as one of the three detection triggers, which is a different job.

## What does it record about my visitors?

Nothing. Alarm Clock records what your content did, never what your visitors did. There is no
visitor identifier, address, session or event row anywhere in it.

## Which versions are supported?

Craft CMS 5.3+ and PHP 8.2+. No runtime dependencies.
