# Release Notes for Alarm Clock

## 5.0.1 - 2026-09-24

### Fixed

- The entry sidebar panel crammed its note, date and time inputs, checkbox, button and link into a
  single row beside the “Alarm Clock” label. They now stack beneath a title.

## 5.0.0

Initial release. Numbered to match the Craft major version, in line with the rest of the family.

### Added

- Detection of `postDate` and `expiryDate` crossings, by date rather than by status — so it works
  identically whether statuses are derived or stored via `staticStatuses`.
- Three triggers — console, queue, and front-end request — deduplicated by a unique index so a
  crossing is acted on exactly once however many of them notice it.
- Cache invalidation on every crossing, clearing the same tags an ordinary save would. This is the
  gap the plugin exists for: Craft caps a `{% cache %}` block at the soonest `getExpiryDate()` of
  the elements it rendered, and a pending entry is returned by no live query, so a cached listing
  has no idea a post is coming.
- Stored-status refresh for `staticStatuses` sites, where an entry past its post date genuinely is
  not published until something resaves it.
- Optional URL warming, for the listing pages a reader notices are stale.
- Email notifications to addresses and user groups, with a customisable subject and body template.
- Signed webhooks in JSON or Slack format.
- Durable per-task retries with exponential, capped, jittered backoff; reclaim of tasks whose
  runner died; a Problems screen with per-task errors and retry.
- Scheduled drafts — apply a draft at a set time, from the entry sidebar or the console.
- Diagnostics: an installation health report and a per-entry “why isn’t this live?” explanation.
- `Ticker::EVENT_AFTER_TRANSITION`, the element event Craft does not fire for time-based changes.
- `craft.alarmClock` Twig variable.
- 47 integration checks.
