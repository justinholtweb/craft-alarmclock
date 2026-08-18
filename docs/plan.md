# Alarm Clock — build notes

## Scope decisions

| Decision | Choice | Why |
|---|---|---|
| Editions | Free, single | Matches Blaster/Telescope/Holmes. No `Edition` model, no gating. |
| Triggers | All three, ledger-guarded | The failure being designed against is the owner not knowing which their host supports. |
| Scheduled drafts | Included | The only genuinely missing Craft feature here, and the only place publishing can actually fail — which is what makes the retry machinery earn its keep. |

## What was researched before writing code

- WordPress's Scheduled Post Trigger: publishes missed posts on page load, no logging, no retries,
  no notifications, self-described stop-gap. The idea is worth taking; the implementation is not.
- `craft\services\Elements::setCacheExpiryDate()` / `ElementQuery::afterPopulate()` /
  `Entry::getExpiryDate()` — established that a pending entry contributes nothing to the caches it
  will invalidate. This is the actual bug.
- `EntryQuery::statusCondition()` — the :59 rounding, and `staticStatuses`.
- `UpdateStatusesController` — how Craft itself refreshes stored statuses.
- `yii\base\Application::run()` — that `EVENT_AFTER_REQUEST` precedes `$response->send()`.

## Phases

1. Records, install migration, settings ✅
2. Ledger (`Transitions`) and task runner (`Tasks`) with claim/backoff/reclaim ✅
3. Detection (`Ticker`) — watermarks, mutex, batching ✅
4. Actions — sync-status, invalidate, warm, notify, webhook, apply-draft ✅
5. Scheduled drafts (`Schedules`) ✅
6. Diagnostics — installation health and per-entry explain ✅
7. CP screens, entry sidebar, console commands, Twig variable ✅
8. Integration checks — 47, all green ✅

## Bugs the tests and CP pass actually caught

Worth keeping, because each one was silent:

1. Install migration primed watermarks as naive UTC, read back as site-local → **7 hours in the
   future → detection did nothing at all, with no error**. Caught by the watermark diagnostic,
   which was written for a different reason.
2. `setState()` update-then-insert → primary-key collision whenever a value was rewritten
   unchanged. Caught by the second scan of the same window.
3. `Schedules::schedule()` stored the element id where every reader expected the `drafts` table id
   → the schedule was written and never found again.
4. `forms.dateTime` does not exist → the draft sidebar panel silently stopped rendering.
5. Clock-drift check compared session time zones, not clocks → confident false alarm.
6. `ScheduleRecord::publishAt` read raw → 9am displayed as 4pm, and the ledger recorded the wrong
   instant.

## Not done, deliberately

- No support for categories/assets. Only entries have post and expiry dates in Craft.
- No Blitz/CDN-specific integrations — the webhook is the extension point, so the plugin does not
  need to grow one per service.
- Craft's queue is not used for retries. See CLAUDE.md.
