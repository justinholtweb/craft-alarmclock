---
title: Configuration
slug: configuration
order: 20
summary: Every setting, what it costs, and the config file.
---

Settings live at **Settings → Plugins → Alarm Clock**, and every one of them can be overridden from
`config/alarm-clock.php`.

## Detection

| Setting | Default | Notes |
|---|---|---|
| **Enabled** | on | The master switch. Off means nothing is detected and no task ever runs. |
| **Check on front-end requests** | on | The trigger that works everywhere. Runs *after* the response has been sent. |
| **Keep a check on the queue** | on | A self-rescheduling job. Only useful if this site's queue actually runs. |
| **Check at most every** | 60s | Throttle for the front-end trigger only. Cron and the queue are not affected. |
| **Look back** | 600s | How far behind the watermark each scan reaches. |
| **Watch expiry dates too** | on | Treat `expiryDate` passing as a crossing worth acting on. |
| **Only these sections** | all | Leave empty to watch everything. |
| **Crossings per check** | 250 | Batch ceiling, so a catch-up cannot turn into a timeout. |

**Look back** is belt and braces. The watermark should already be exact, but a clock nudged by NTP,
or a database and web server that disagree by a few seconds, can drop a crossing into the gap.
Re-scanning a window costs one indexed query, and the ledger throws the duplicates away.

**Crossings per check** matters more than it looks. A site restored from a backup, or switched on
after a fortnight off, can have thousands of post dates in its past. A capped batch advances the
watermark only as far as the last crossing it actually handled — never to "now", which would mark
the untouched remainder as covered and quietly lose a week of posts.

## Caches

| Setting | Default | Notes |
|---|---|---|
| **Clear the caches that mention it** | on | The reason the plugin exists. Clears the same tags an ordinary save would. |
| **Clear caches for the whole element type** | off | A much bigger hammer. |
| **Clear every template cache** | off | The biggest hammer there is. |
| **Warm the pages afterwards** | off | Requests the element's own URL once it is live. |
| **Also warm these** | — | Extra URLs, one per row. |
| **Warm request timeout** | 10s | |

The default clears the element's own tag plus its section and entry-type tags —
`element::craft\elements\Entry::section:5`, `entryType:9` — which are exactly the tags a
`craft.entries.section('news')` query registered when it built your listing.

Switch on **whole element type** for sites that build listings with raw SQL, a search index, or
anything else Craft's tag collection cannot see. **Every template cache** is almost never needed.

**Also warm these** is nearly always the pages that *list* the new post rather than the post
itself — the home page, the section index, an RSS feed. Those are the ones a reader notices are
stale.

## Notifications

| Setting | Default | Notes |
|---|---|---|
| **Email when something goes live** | off | |
| **Email addresses** | — | One per row. |
| **User groups** | — | Every member of the group is emailed. |
| **Email about expiries too** | off | |
| **Subject line** | built-in | `{title}`, `{site}` and `{transition}` are substituted. |
| **Body template** | built-in | A Twig template path. Leave blank for the one that ships. |
| **Email when Alarm Clock gives up on something** | **on** | |

The failure email is on by default and the success email is off, deliberately. Plenty of sites do
not want an email every time a post appears. Every site wants to know when the plugin has stopped
being able to do its job.

## Webhook

| Setting | Default | Notes |
|---|---|---|
| **POST every crossing to** | — | |
| **Body format** | `json` | Or `slack`, which wraps it in Slack's incoming-webhook shape. |
| **Signing secret** | — | When set, the body is signed as `sha256=<hmac>` in `X-AlarmClock-Signature`. |
| **Webhook timeout** | 10s | |

The signature is computed over the exact bytes sent, so a receiver can verify without re-encoding
the JSON and hoping its encoder agrees with PHP's about key order.

See [Usage](usage#webhooks) for the body shape.

## Retries

| Setting | Default | Notes |
|---|---|---|
| **Attempts** | 5 | Before a task is given up on. |
| **First retry after** | 60s | Doubles each attempt. |
| **Longest retry delay** | 3600s | The ceiling on the backoff. |
| **Assume a runner has died after** | 300s | A task still marked running past this is returned to the pool. |
| **Tasks per front-end check** | 3 | |
| **Seconds per front-end check** | 3 | |
| **Tasks per console or queue check** | 100 | |

Backoff is exponential, capped **and jittered**. The jitter is not decoration: without it, a
hundred webhooks that all failed against the same downed endpoint come back in the same second and
knock it over again the moment it recovers.

**Assume a runner has died after** exists because a runner killed mid-task — a deploy, an OOM, a
timeout — leaves its row claimed forever otherwise. Work that is neither finished nor waiting is
the most confusing state there is: nothing is broken and nothing is happening.

The two **front-end check** ceilings are what keep a visitor from paying for a slow webhook.
Whatever does not fit is left for the next tick, which is the whole reason tasks are durable rows
rather than something held in memory for the length of one request.

## Housekeeping

| Setting | Default | Notes |
|---|---|---|
| **Keep history for** | 180 days | `0` keeps everything. |
| **Log everything** | off | A log line for every detection and every task outcome. |

Pruning runs with Craft's garbage collection, or on demand with
`php craft alarm-clock/tick/prune`.

## config/alarm-clock.php

Anything set here wins over the stored value at runtime, the same as any other Craft plugin. The
control panel fields still show and save what is in the database, so a setting pinned in the config
file will look editable there and quietly have no effect. Pin a setting in one place or the other,
not both.

```php
<?php

return [
    'enabled' => true,

    // The site has cron, so the visitor-facing trigger is not needed.
    'webTrigger' => false,
    'queueTrigger' => false,

    'watchExpiry' => true,
    'invalidateElementCaches' => true,

    'warmUrls' => true,
    'extraWarmUrls' => [
        'https://example.com/',
        'https://example.com/news',
        'https://example.com/news.rss',
    ],

    'notify' => true,
    'notifyEmails' => ['editorial@example.com'],
    'notifyOnFailure' => true,

    'webhookUrl' => 'https://hooks.example.com/alarm-clock',
    'webhookFormat' => 'json',
    'webhookSecret' => '$ALARMCLOCK_WEBHOOK_SECRET',

    'historyRetentionDays' => 180,
];
```

The list settings — `sections`, `extraWarmUrls`, `notifyEmails`, `notifyUserGroups` — take a plain
list of strings here, and a table of rows when they come from the control panel. Both end up as the
same list.

`sections` and `notifyUserGroups` take **UIDs**, not handles, because that is what survives a
project-config sync between environments.
