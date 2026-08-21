---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: The diagnostics screen, the checks it runs, and what to do about each one.
---

Start at **Alarm Clock → Diagnostics**. It answers two different questions, and which one you want
depends on whether the problem is one entry or the whole site.

## "Why isn't *this* entry live?"

Open the entry and look at its Alarm Clock panel, or go to **Diagnostics** and search for it. It
works down the list Craft itself uses to decide whether something appears:

- Disabled, or disabled for this site
- No post date at all
- A post date still in the future — with the exact instant, in the site's time zone
- Expired
- It is a draft, or a revision
- A section not enabled for this site
- A section with no URLs
- A stored status that has fallen behind the dates

…and then what Alarm Clock already did about it, and whether any of that failed.

Most "it isn't live" reports end at the third or fourth line, and usually at a time zone.

## "Is scheduled publishing working here at all?"

The installation report, from the CP or from `php craft alarm-clock/tick/status`.

### Clock

Compares PHP's clock to the database's, both in UTC. A drift of more than a few seconds means one
of the two hosts is not running NTP, and every latency figure on the History screen is measuring
that gap rather than the plugin.

The check is deliberately UTC-to-UTC. `SELECT NOW()` against `time()` measures the difference
between the *session time zones*, which are routinely different by design — that comparison once
reported a confident three-hour drift between two clocks that were identical.

### Watermarks

Both scan watermarks must be in the **past**. A watermark in the future stalls detection
permanently and silently: the scan sees `$from >= $now` and returns immediately, every time, for as
long as it takes the clock to catch up.

If this one is red, nothing is being detected at all, and nothing in the log will say so.

### Time zone

Craft and PHP should agree. When they do not, dates you type in the CP and dates the plugin reads
back are not the same instant, and a 9am schedule shows up as 4pm.

### Ticking

When the last tick was and which trigger ran it. If this says nothing has ever ticked:

1. Is **Enabled** on?
2. Is at least one trigger on? All three off is a valid, silent configuration.
3. If you are relying on cron, does the cron user have a working `php` and the right path? Run the
   command by hand first.
4. If you are relying on the queue, does this site's queue actually run? Many do not without a
   worker.
5. If you are relying on front-end requests, has anyone visited the site since the last tick? The
   trigger is front-end only — control panel requests deliberately do not tick, because an editor's
   own page load should not be what sends the email announcing their post.

### Triggers

Warns when nothing is switched on, and notes when the front-end trigger is doing all the work on a
site that looks like it has cron available.

### Stored statuses

On a site with the `staticStatuses` config setting (Craft 5.7+), `entries.status` is a stored
column that only changes on save or on `craft update-statuses`. This is the one case where Craft
genuinely does miss a schedule: the entry sits at `pending` past its post date indefinitely.

Alarm Clock detects by **dates**, not by status, so it sees these — and its `sync-status` task
resaves the entry to bring the column into line. If this check is red, that resave is failing;
check **Problems** for the error.

### Work queue

Anything abandoned after its last attempt. **Alarm Clock → Problems** lists each one with its error
and a retry button, or:

```sh
php craft alarm-clock/tick/retry-failed
```

### Latency

The worst gap between a scheduled time and the moment it was noticed, over the past week. On cron
this should be under a minute. On the front-end trigger it is however long the site went without a
visitor, which is the honest answer and the reason the report shows it.

## Common situations

### The post is live but the home page is stale

That is the bug the plugin exists for, so first check that a crossing was actually recorded — look
in **History** for the entry. If it is not there, the problem is detection: see *Ticking* above. If
it is there, look at **Problems** for a failed `invalidate-caches` task.

If the crossing was recorded, the cache clear succeeded, and the page is still stale, the cache in
front of Craft is not Craft's. A CDN, a static-file host or a full-page reverse proxy will not be
touched by `invalidateCachesForElement()`. Use the webhook to purge it.

### Everything was announced at once

The watermarks were rewound, or a fresh install was primed to the epoch rather than to now. The
install migration primes them to *now* for exactly this reason. If you have restored a database
over an existing install, check **Watermarks** before the next tick.

### Two emails for the same post

Should not be possible — the unique index on
`(elementId, siteId, transition, scheduledFor)` is what prevents it. If it happens, the entry's
post date was **changed** after the first crossing: a different `scheduledFor` is a different row,
and by design, because moving a post date to next week and back really is a second publication.

### A task is stuck at "running"

Its runner died — a deploy, an out-of-memory kill, a request timeout. It is reclaimed automatically
once **Assume a runner has died after** (300s by default) has passed. Setting that very low will
eventually reclaim a task that was merely slow, and run it twice.

### Nothing happens on a site nobody visits

With only the front-end trigger on, that is correct behaviour: there is no process to run. Add
cron, or accept that the first visitor of the day pays a few milliseconds and triggers the
catch-up.

## Logging

Switch on **Log everything** to get a line for every detection and every task outcome, under the
`alarm-clock` category. Turn it back off afterwards — on a busy site it is a lot of lines.
