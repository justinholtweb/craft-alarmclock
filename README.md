# Alarm Clock for Craft CMS

Scheduled publishing that tells you it happened — cache clearing, notifications, retries,
scheduled drafts, and a straight answer to “why isn’t my post live?”

---

## The problem this actually solves

If you have arrived here from WordPress, you are probably looking for the thing that publishes
missed scheduled posts. **Craft does not miss them.** Entry status is derived in SQL from
`postDate` and `expiryDate`, so an entry becomes live the instant the clock passes it, cron or no
cron. There is nothing to trigger.

What Craft misses is that it *happened*.

No event fires. No cache is invalidated. Nothing is logged, and nobody is told — because as far as
Craft is concerned, no row changed. Specifically:

> Craft caps a `{% cache %}` block’s lifetime at the soonest `getExpiryDate()` of the elements it
> rendered (`ElementQuery::afterPopulate()` → `Elements::setCacheExpiryDate()`), and
> `Entry::getExpiryDate()` returns only the entry’s **expiry** date, never its post date. A pending
> entry is by definition not returned by any live-status query, so it contributes neither a cache
> tag nor an expiry to the pages that will need to change when it appears.

So your 9am post goes live in the database at 9am, and the cached home page keeps serving the 8am
HTML until something unrelated happens to save an entry. The editor sees a live entry and a stale
site, and every explanation on the internet is about cron, which was never involved.

Alarm Clock watches those moments go by and does the rest.

### The one case where Craft really does miss it

If your site runs with the `staticStatuses` config setting (Craft 5.7+), `entries.status` is a
**stored column** that only changes on save or when `craft update-statuses` is run. On a host with
no cron, scheduled entries then sit at `pending` past their post date indefinitely — a genuine
missed schedule, same cause and same shape as the WordPress one.

Alarm Clock detects by dates rather than by status, so it sees these, and refreshes them itself.

---

## What it does

| | |
|---|---|
| **Notices** | Watches `postDate` and `expiryDate` crossings via cron, the queue, and front-end requests — deduplicated so a crossing is acted on exactly once |
| **Clears caches** | Invalidates the same tags an ordinary save would, so listings, feeds and home pages update |
| **Warms pages** | Optionally re-requests the pages that list the new post, so the first real visitor gets a warm one |
| **Tells people** | Email to addresses or user groups, and a signed webhook (JSON or Slack) |
| **Retries** | Every piece of follow-up work is a durable row with its own attempts, backoff and retry button |
| **Schedules drafts** | “Replace what is live with this draft at nine tomorrow” — something Craft cannot do |
| **Explains** | A diagnostics screen for the installation, and a per-entry “why isn’t this live?” |

Free, single edition. Everything is switched on.

---

## Requirements

Craft CMS 5.3+, PHP 8.2+. No runtime dependencies.

## Installation

```sh
composer require justinholtweb/craft-alarmclock
php craft plugin/install alarm-clock
```

Nothing else is required. The front-end trigger is on by default, so it works on hosting with no
cron and no queue runner.

## Recommended: real cron

If the site has cron, use it — it is exact, and it costs your visitors nothing:

```cron
* * * * * cd /path/to/site && php craft alarm-clock/tick
```

You can then turn the front-end trigger off in the settings if you would rather.

---

## Why three triggers

The right trigger depends on hosting that cannot be seen from inside the plugin, and the failure
being designed against is **the site owner not knowing which one their host supports**.

- **Console** (`craft alarm-clock/tick`) — exact, free, and needs real cron.
- **Queue** — a job that reschedules itself. Only useful if the queue on this site actually runs.
- **Front-end requests** — the trigger that works everywhere. Runs *after* the response has been
  sent, at most once per tick interval, doing a strictly bounded amount of work.

All three are safe to run together. A unique index on
`(elementId, siteId, transition, scheduledFor)` is what makes a crossing happen exactly once, no
matter how many of them notice it first — the database enforces it, not application code, because
three PHP processes checking-then-inserting is not something application code can make safe.

### About the front-end trigger

This is the idea borrowed from WordPress’s
[Scheduled Post Trigger](https://wordpress.org/plugins/scheduled-post-trigger/), and the borrowing
stops at the idea. That plugin checks on the way *in*, publishes inline, and says in its own
description that it is a stop-gap not to be used on a busy site — because the visitor pays for the
work.

Here it hangs off `Response::EVENT_AFTER_SEND`, which fires after the bytes have gone out, releases
the connection with `fastcgi_finish_request()` where the SAPI allows it, and is bounded twice over:
at most `maxTasksPerWebTick` tasks and at most `webTickBudget` seconds of them. Whatever does not
fit is left for the next tick — which is the whole reason tasks are durable rows rather than
something held in memory for the length of a request.

---

## Scheduled drafts

Craft can schedule an entry’s *first* appearance with `postDate`, but there is no way to say
“replace what is currently live with this draft, later”. Editors work around it by holding the
draft open and applying it by hand at the appointed hour.

Open any draft and use the **Alarm Clock** panel in the sidebar, or:

```sh
php craft alarm-clock/schedule/draft <draftId> "2026-09-01 09:00" --enable
php craft alarm-clock/schedule/list
php craft alarm-clock/schedule/cancel <id>
```

This is the only part of the plugin where publishing can genuinely fail rather than merely go
unnoticed — a draft can be invalid, its canonical entry can be deleted, another editor can apply a
conflicting draft first. It is validated before it is applied so the failure says something you can
act on, and it gets the full retry treatment.

---

## Diagnostics

**Alarm Clock → Diagnostics** answers two different questions.

*Is scheduled publishing working here at all?* — clock drift between the PHP and database hosts,
time-zone agreement, whether anything is ticking, whether a watermark has ended up in the future,
whether statuses are stored statically and falling behind, whether work is piling up, and the worst
detection latency of the past week.

*Why isn’t this one entry live?* — disabled, disabled for this site, no post date, post date still
ahead, expired, a draft, a section not enabled for the site, a section with no URLs, a stale stored
status, plus what Alarm Clock already did about it and whether any of it failed.

From the console:

```sh
php craft alarm-clock/tick/status
```

Exits non-zero when something needs a person, so it is safe to put in a monitor.

---

## Retries

Follow-up work is split from the crossing itself, because a crossing is a *fact* and cannot fail
while everything done about it can. Each task is its own row with its own attempt count, so a
webhook that is down for an hour retries on its own without holding up the cache clear or the
email.

Backoff is exponential, capped, and jittered — without the jitter, a hundred webhooks that all
failed against the same dead endpoint come back in the same second and knock it over again the
moment it recovers.

A task still marked running after `taskTtr` seconds is assumed dead and returned to the pool. A
runner killed by a deploy or an out-of-memory kill would otherwise leave its row claimed forever,
and work that is neither finished nor waiting is the most confusing state there is: nothing is
broken and nothing is happening.

**Alarm Clock → Problems** lists everything that failed, with its error and a retry button.

```sh
php craft alarm-clock/tick/work          # run waiting tasks without scanning
php craft alarm-clock/tick/retry-failed  # retry everything that was given up on
```

---

## Templating

```twig
{% for entry in craft.alarmClock.upcoming(5) %}
    {{ entry.title }} — {{ entry.postDate|datetime }}
{% endfor %}

{# When the piece actually went live, rather than when it was last saved —
   on a scheduled post those are usually days apart. #}
{% set live = craft.alarmClock.lastTransitionFor(entry) %}
{% if live %}Published {{ live.detectedAt|datetime }}{% endif %}

{{ craft.alarmClock.pendingCount() }}
{{ craft.alarmClock.recent(10, 'published')|length }}
```

## Reacting in PHP

The event Craft does not have:

```php
use justinholtweb\alarmclock\services\Ticker;
use justinholtweb\alarmclock\events\TransitionEvent;
use yii\base\Event;

Event::on(Ticker::class, Ticker::EVENT_AFTER_TRANSITION, function(TransitionEvent $event) {
    // Fires once, the first time a crossing is noticed.
    // $event->transition, $event->element (null if it has since been deleted)
});
```

## Webhooks

Set a URL and pick `json` or `slack`. With a signing secret set, the body is signed as
`sha256=<hmac>` in `X-AlarmClock-Signature`, over the exact bytes sent — so a receiver can verify
without re-encoding the JSON and hoping its encoder agrees with PHP’s about key order.

```json
{
  "event": "alarmclock.published",
  "sentAt": "2026-09-01T09:00:12+00:00",
  "transition": {
    "transition": "published",
    "elementId": 1234,
    "siteHandle": "default",
    "title": "The post",
    "url": "https://example.com/news/the-post",
    "scheduledFor": "2026-09-01T09:00:00+00:00",
    "detectedAt": "2026-09-01T09:00:12+00:00",
    "latencySeconds": 12,
    "source": "console"
  }
}
```

## Console reference

```sh
php craft alarm-clock/tick                  # detect crossings and do what they are owed
php craft alarm-clock/tick --detect-only    # detect only
php craft alarm-clock/tick/status           # health report; non-zero exit if something needs you
php craft alarm-clock/tick/work             # run waiting tasks
php craft alarm-clock/tick/retry-failed     # retry abandoned work
php craft alarm-clock/tick/prune            # delete history past retention
php craft alarm-clock/schedule/list
php craft alarm-clock/schedule/draft <draftId> <when> [--enable] [--site=handle]
php craft alarm-clock/schedule/cancel <id>
```

## Permissions

- **View the schedule, history and diagnostics**
  - **Schedule drafts**
  - **Retry failed work and run ticks by hand**

## Privacy

Alarm Clock records what your content did, never what your visitors did. There is no visitor
identifier, address, session or event row anywhere in it.

## Licence

MIT.
