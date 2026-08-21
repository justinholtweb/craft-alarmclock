---
title: Usage
slug: usage
order: 30
summary: What a crossing does, scheduled drafts, webhooks, Twig, PHP events and the console.
---

## What happens when something crosses

A crossing is recorded once, and then six pieces of follow-up work are queued in this order:

1. **apply-draft** — if a scheduled draft is due
2. **sync-status** — refresh the stored status, on `staticStatuses` sites
3. **invalidate-caches** — clear the tags an ordinary save would have cleared
4. **warm-urls** — request the pages that list the new content
5. **notify** — email
6. **webhook** — the signed POST

The order matters and is not alphabetical. **Caches are cleared before anyone is told**, because an
email that arrives before the home page updates sends the reader to a page that does not show the
thing the email is about.

Each of those is its own durable row with its own attempt count. A crossing is a *fact* and cannot
fail; everything done about it can. So a webhook that is down for an hour retries on its own
without holding up the cache clear, and a person can see which half went wrong.

## Scheduled drafts

Craft can schedule an entry's *first* appearance with `postDate`. There is no way to say "replace
what is currently live with this draft, later" — editors work around it by holding the draft open
and applying it by hand at the appointed hour.

Open any draft and use the **Alarm Clock** panel in its sidebar: pick a date and a time, optionally
tick *enable the entry when it is applied*, and press **Schedule**. Until then it stays an ordinary
draft.

From the console:

```sh
php craft alarm-clock/schedule/list
php craft alarm-clock/schedule/draft <draftId> "2026-09-01 09:00" --enable
php craft alarm-clock/schedule/draft <draftId> "2026-09-01 09:00" --site=de
php craft alarm-clock/schedule/cancel <id>
```

`<draftId>` is the **draft's** id — the number in the `drafts` table, which is what the CP's
`?draftId=` query string carries. It is not the entry's element id.

This is the only part of the plugin where publishing can genuinely fail rather than merely go
unnoticed: a draft can be invalid, its canonical entry can be deleted, another editor can apply a
conflicting draft first. It is validated before it is applied so the failure says something you can
act on, and it gets the full retry treatment.

## The control panel

**Alarm Clock → Upcoming** — everything with a post or expiry date still ahead of it, plus the
drafts that are scheduled.

**Alarm Clock → History** — the ledger. Every crossing, when it was scheduled, when it was actually
noticed, how long that took and which trigger saw it first.

**Alarm Clock → Problems** — everything that failed, with its error and a retry button. The nav item
carries a badge when there is anything here, and only then; a badge permanently showing a count is
a badge people stop reading.

**Alarm Clock → Diagnostics** — see [Troubleshooting](troubleshooting).

Every entry also gets a panel in its own sidebar saying what Alarm Clock expects to do with it and
when.

## Webhooks

Set a URL and pick `json` or `slack`.

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

With a signing secret set, the body is signed as `sha256=<hmac>` in `X-AlarmClock-Signature`, over
the exact bytes sent. Verify against the raw request body — not against a re-encoding of the parsed
JSON, which requires your encoder to agree with PHP's about key order and spacing.

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($expected, $signatureHeader)) { /* reject */ }
```

The webhook is the extension point for anything this plugin deliberately does not integrate with —
a CDN purge, Blitz, a static host's rebuild hook, a Slack channel. That is why there is no
per-service integration to configure.

## Twig

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
{{ craft.alarmClock.goesLiveAt(entry)|datetime }}
{{ craft.alarmClock.secondsSinceLastTick() }}
```

| Method | Returns |
|---|---|
| `upcoming(limit)` | Entries with a post or expiry date still ahead |
| `recent(limit, transition)` | Ledger rows, newest first; `transition` is `published` or `expired` |
| `lastTransitionFor(entry)` | The most recent crossing for that entry, or `null` |
| `goesLiveAt(entry)` | When Alarm Clock expects it to appear, or `null` |
| `scheduledDrafts(limit)` | Drafts with a scheduled apply time |
| `secondsSinceLastTick()` | `null` if nothing has ever ticked |
| `pendingCount()` | How many entries are waiting |

## Reacting in PHP

The element event Craft does not have:

```php
use justinholtweb\alarmclock\services\Ticker;
use justinholtweb\alarmclock\events\TransitionEvent;
use yii\base\Event;

Event::on(Ticker::class, Ticker::EVENT_AFTER_TRANSITION, function(TransitionEvent $event) {
    // Fires once, the first time a crossing is noticed — whichever trigger saw it.
    // $event->transition, $event->element (null if it has since been deleted)
});
```

Register it from a module's `init()`, the same as any other Craft event.

## Console reference

```sh
php craft alarm-clock/tick                  # detect crossings and do what they are owed
php craft alarm-clock/tick --detect-only    # detect only, run no follow-up work
php craft alarm-clock/tick --verbose        # print each transition as it is recorded
php craft alarm-clock/tick/status           # health report; non-zero exit if something needs you
php craft alarm-clock/tick/work             # run waiting tasks without scanning
php craft alarm-clock/tick/work 25             # …at most 25 of them
php craft alarm-clock/tick/retry-failed     # retry everything that was given up on
php craft alarm-clock/tick/prune            # delete history past retention
php craft alarm-clock/tick/prune 30         # …or past 30 days, just this once

php craft alarm-clock/schedule/list
php craft alarm-clock/schedule/draft <draftId> <when> [--enable] [--site=handle]
php craft alarm-clock/schedule/cancel <id>
```

## Privacy

Alarm Clock records what your content did, never what your visitors did. There is no visitor
identifier, address, session or event row anywhere in it.
