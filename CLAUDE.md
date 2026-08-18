# Alarm Clock — Craft CMS 5 Plugin

## Project Overview

Alarm Clock makes Craft's time-based content transitions observable, cache-correct and reliable:
it notices `postDate` and `expiryDate` crossings, clears the caches they invalidate, tells people,
retries what fails, and can apply a draft at a set time. Distributed as
`justinholtweb/craft-alarmclock`. **Free, single edition**, everything switched on. In the spirit
of WordPress's Scheduled Post Trigger, but the Craft problem is a different problem — see below.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies. The CP script in `src/web/assets/cp/dist` is a plain IIFE.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\alarmclock`
- Package: `justinholtweb/craft-alarmclock`
- Handle: `alarm-clock` (kebab-case, so translation category, template root and console command
  are all `alarm-clock` while the namespace stays `alarmclock`)

### The load-bearing idea: Craft does not miss scheduled posts

Entry status is derived in SQL from the dates, so an entry becomes live the instant the clock
passes it, with or without cron. There is nothing to trigger. **What Craft misses is that it
happened** — no event, no invalidation, no log, because no row changed.

Concretely: Craft caps a `{% cache %}` block's lifetime at the soonest `getExpiryDate()` of the
elements it rendered (`ElementQuery::afterPopulate()` → `Elements::setCacheExpiryDate()`), and
`Entry::getExpiryDate()` returns only `expiryDate`, never `postDate`. A pending entry is returned
by no live-status query, so it contributes neither a tag nor an expiry to the pages that will need
to change. The fix is one call — `Elements::invalidateCachesForElement()` — which Craft never makes
for a time-based transition. Its tags (`element::craft\elements\Entry::section:5`, `entryType:9`)
are exactly the ones a `craft.entries.section('news')` query registered building the listing.

**The exception**: with the `staticStatuses` config setting (Craft 5.7+), `entries.status` is a
stored column that only changes on save or on `craft update-statuses`. On those sites an entry
really does stay pending past its post date — a genuine missed schedule. `actions\SyncStatus`
resaves it.

### Detection asks about dates, never about status

`services\Ticker::scan()` uses `status(null)` plus explicit `elements.enabled`,
`elements_sites.enabled` and date conditions. Two reasons, both non-negotiable:

- `EntryQuery::statusCondition()` rounds "now" up to :59 of the current minute for cacheability, so
  status and post date disagree by up to a minute at exactly the boundary this scan lives on.
- On a `staticStatuses` site the status column is the very thing that has not caught up, so a
  status-based scan sees nothing at all on precisely the sites that need it most.

`pendingQuery()` and `staleStatusEntries()` follow the same rule.

### Three triggers, one ledger

Console, queue and front-end request all tick. The correctness guarantee is **not** in PHP: it is
the unique index on `{{%alarmclock_transitions}} (elementId, siteId, transition, scheduledFor)`.
Three processes checking-then-inserting cannot be made safe in application code; making the insert
the thing that has to be won means the losers are told so by an `IntegrityException`.

The web trigger hangs off `Response::EVENT_AFTER_SEND`. **Not**
`Application::EVENT_AFTER_REQUEST`, which Yii fires at `Application::run()` line 385 — three lines
*before* `$response->send()`. Everything done there is done while the visitor waits.

### Watermarks

`{{%alarmclock_state}}` holds one per transition type. A capped batch advances the watermark only
as far as the last crossing actually handled, never to "now" — advancing to now would mark the
untouched remainder as covered and lose it, which is how a site restored from backup quietly skips
a week of posts.

A watermark **in the future** stalls detection permanently and silently (`$from >= $now` returns
immediately). `Diagnostics::checkWatermarks()` exists for exactly that, and it caught the bug that
shipped in the first draft of the install migration.

### Transitions vs tasks

A transition is a *fact* and cannot fail. Everything done about it can. So they are separate
tables: a webhook that is down for an hour retries on its own without holding up the cache clear,
and a person can see which half went wrong. Craft's queue is deliberately not used for this — what
people ask after a scheduled post goes wrong is "did the email go out, and can I send it now",
which needs a durable row per piece of work with its own attempt count, error and retry button.

Task order matters and is the order of `Tasks::HANDLERS`: apply-draft → sync-status →
invalidate-caches → warm-urls → notify → webhook. Caches are cleared before anyone is told,
because an email that arrives before the home page updates sends the reader to a page that does not
show the thing the email is about.

## Traps found while building this

- **`Application::EVENT_AFTER_REQUEST` fires before the response is sent.** See above. Use
  `Response::EVENT_AFTER_SEND`, plus `fastcgi_finish_request()` to release the connection.
- **`Db::prepareDateForDb()` output must never go to an element query *date param*** — it is read
  as system time and converted to UTC a second time, so the window matches nothing. It is correct
  in a raw `andWhere()` column condition, which is what the scan uses. (Family-wide; see
  `[[craft-abacus-gotchas]]`.)
- **The same trap in reverse when reading.** A bare `Y-m-d H:i:s` from a column is UTC, but
  `new DateTime()` reads it in the *site's* zone. Three separate bugs from this one shape: the
  install migration primed both watermarks 7 hours in the future (detection silently did nothing
  until the clock caught up), `Schedules::recordAppliedTransition()` recorded the wrong instant,
  and the CP showed a 9am schedule as 4pm. Read through `DateTimeHelper::toDateTime()`, which
  assumes UTC for a zoneless string, or write `DATE_ATOM` and parse it back.
- **MySQL reports zero affected rows for an update that changes nothing**, so
  update-then-insert-if-nothing-changed falls through to an insert and dies on the primary key.
  Two ticks in the same second write the same watermark and do exactly that. `setState()` is an
  upsert — and `Db::upsert()`'s fourth argument is `$params`, not more columns.
- **`Craft::$app->getElements()->getElementById()` does return drafts**, contrary to the obvious
  assumption — but the *canonical* entry's id resolves to a real element that is not a draft, so a
  controller taking both a draft ID and an element ID has a wrong-but-plausible pairing available
  to it. `Schedules` keys on `draftId` (the `drafts` table row, not the element row) throughout, and
  the controller looks the draft up the same way. Storing `$draft->id` there writes a schedule that
  is never found again.
- **Craft 5's `forms.twig` has no `dateTime` macro** — it is `dateTimeField` (there are bare `date`
  and `time` macros, which is what makes `dateTime` look plausible). An undefined macro throws at
  *render* time, so with the sidebar's try/catch around it the panel simply stopped appearing with
  nothing but a log line. The date/time inputs come out as `{id}-date` and `{id}-time` with a
  `{name}[timezone]` hidden input, which is what the CP script targets.
- **The entry-editor sidebar renders inside Craft's element-editor form**, so everything in it is a
  `<div>` and the schedule button posts with `Craft.sendActionRequest`. A nested `<form>` does not
  fail cleanly — the parser drops the tag and keeps the children, leaving a second `action` input in
  the page form, and Craft takes the last one. (Family-wide; see `[[craft-plugin-gotchas]]`.)
- **A clock-drift check must compare UTC to UTC.** `SELECT NOW()` against `time()` measures the gap
  between the database's *session* time zone and PHP's default zone, which are routinely different
  by design. On the test container that reported a confident three-hour drift between two clocks
  that were identical. Use `UTC_TIMESTAMP()` / `timezone('UTC', now())`.
- **The install migration must prime the watermarks to "now".** Without it the first tick looks back
  to the epoch, decides every entry ever published has just crossed, and mails the whole archive to
  the editorial team.

See also `[[craft-plugin-gotchas]]` and `[[craft-abacus-gotchas]]` in the shared memory.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-alarmclock/tests/integration/checks.php   # 47 checks
ddev exec bash -c 'find /var/www/craft-alarmclock/src -name "*.php" -print0 | xargs -0 -n1 php -l'
ddev exec php craft alarm-clock/tick/status
```

The checks are idempotent and self-cleaning, and they put both watermarks back where they found
them — a run that left them rewound would make the site re-announce its archive.

`ddev exec php craft clear-caches/cp-resources` after editing anything under
`src/web/assets/*/dist`, or Craft keeps serving the published copy.

**Note**: the test site's first channel section is `liveTest`, which belongs to craft-live; opening
a *draft* there 500s inside craft-peek's `DiffService`, unrelated to this plugin. Use
`reminderTestSection` for manual draft testing in the CP.

## Coding conventions

- `Craft::t('alarm-clock', '…')` for user-facing strings
- Business logic in services; controllers stay thin
- Handlers throw to fail an attempt — a handler that swallows its own error has told the runner the
  work succeeded, and it is never retried
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required`
