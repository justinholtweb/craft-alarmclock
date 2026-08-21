---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and choosing which trigger this site should use.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

No runtime dependencies, and nothing to build.

## Install

```sh
composer require justinholtweb/craft-alarmclock
php craft plugin/install alarm-clock
```

Or find **Alarm Clock** in the Craft Plugin Store and install it from there.

Free, single edition. There is no Pro, no licence key and nothing switched off.

## It works immediately

The front-end trigger is on by default, so on hosting with no cron and no queue runner the plugin
starts noticing crossings on the next page view. Nothing else is required to get a working install.

That default exists because the failure this plugin is designed against is **the site owner not
knowing which trigger their host supports**. A plugin that only works once you have set up cron is
no use to the people who most need it.

## Recommended: real cron

If the site has cron, use it. It is exact, it costs your visitors nothing, and it keeps working on
a site nobody has visited since yesterday:

```cron
* * * * * cd /path/to/site && php craft alarm-clock/tick >> /dev/null 2>&1
```

Once that is in place you can turn **Check on front-end requests** off in the settings if you would
rather, though leaving it on is harmless — see below.

## The three triggers

| Trigger | Setting | When it is the right one |
|---|---|---|
| Console | `craft alarm-clock/tick` from cron | The site has real cron. Exact, and free. |
| Queue | **Keep a check on the queue** | The queue on this site actually runs — a worker, or a busy CP. |
| Front-end request | **Check on front-end requests** | Everywhere else. Works on any host that serves a page. |

**All three are safe to run at once.** A unique index on
`(elementId, siteId, transition, scheduledFor)` is what makes a crossing acted on exactly once, no
matter how many triggers notice it first. The database enforces that, not application code —
because three PHP processes checking-then-inserting is not something application code can make
safe.

## After installing

The install migration primes both scan watermarks to *now*, so the first tick looks forward rather
than backwards. Without that it would decide every entry ever published has just crossed and mail
your entire archive to the editorial team.

That means Alarm Clock does not reach backwards over content that went live before it arrived,
which is deliberate. Go to **Alarm Clock → Diagnostics** to confirm the install is healthy, or from
the console:

```sh
php craft alarm-clock/tick/status
```

It exits non-zero when something needs a person, so it is safe to put in a monitor.

## Permissions

Three, nested:

- **View the schedule, history and diagnostics**
  - **Schedule drafts**
  - **Retry failed work and run ticks by hand**

Admins have all of them. An editor who should be able to say "publish this draft at nine tomorrow"
needs the first two.
