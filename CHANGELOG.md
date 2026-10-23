# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [11.1.8] - 2026-10-23

### Fixed

- Exception occurrences of a repeating event that failed to upload are no longer treated as synced. The next pull keeps them and they are uploaded again, even when the Google master is newer.

## [11.1.7] - 2026-10-23

### Fixed

- A contact photo saved from an old thumbnail URL is fetched again even when the card etag has not changed. A failed download keeps the previous URL and is retried. `--force-photos` does not upload that thumbnail over the original still on Google.

## [11.1.6] - 2026-10-23

### Fixed

- Before the first sync of a calendar finishes, local events that are not linked to Google are left in place. Contacts and tasks already waited. The first run was copying them to Google, or deleting them when the calendar is read-only.

## [11.1.5] - 2026-10-23

### Fixed

- A calendar in the trash stays unlinked. Nextcloud reports that with `{http://nextcloud.com/ns}deleted-at`.

## [1.1.4] - 2026-10-22

### Added
- `occ googletwowaysync:sync --force-photos` uploads every Nextcloud contact photo to Google once, even where the photo looks synced. A run cut short by the rate limit continues where it stopped.

### Changed
- The per-run write limit for contacts is 200 instead of 40.

## [1.1.3] - 2026-10-22

### Fixed
- A contact photo changed in Nextcloud is no longer replaced by Google's older photo. When both sides changed, or the photo upload failed earlier, the Nextcloud photo is kept and uploaded again.
- Contacts changed only in Nextcloud are read from Google with `people:batchGet` in batches of 200 instead of the first 40. Only a real rate limit (429) defers the rest, and the message now says which case it was.

## [1.1.2] - 2026-10-22

### Fixed
- A photo added or changed on a Nextcloud contact is now uploaded to Google (`updateContactPhoto`, a PATCH), and removing it deletes the Google photo. Contacts that already had a Google photo before this version keep it until their Nextcloud photo changes.

## [1.1.1] - 2026-10-22

### Fixed
- Contact photos were saved as the 100px thumbnail Google lists. They are now downloaded at the original size (=s0), and cards whose stored photo URL differs are pulled again.

## [1.1.0] - 2026-10-21

### Fixed

- A pull that fails on the first full sync no longer stores the sync token, so the item is fetched again on the next run.

### Changed

- Calendars, contacts and tasks share one sync decision (SyncDecision), one plan runner (PlanRunner) and one name/color logic (Appearance, CalendarAppearance).
- Google list paging goes through GoogleClient::listAll.
- The task due-date repair reads local tasks once, in batches.
- Unit tests in tests/Unit run without a Nextcloud instance (tests/run-unit.sh).

## [1.0.9] - 2026-10-21

### Fixed

- The next task sync starts from the time the list was read (minus a minute), not from the end of the run, so a task changed on Google during a sync is no longer skipped.

## [1.0.8] - 2026-10-21

### Fixed

- Tasks pulled from Google are merged into the existing VTODO. Alarms (including iPhone location reminders), priority, start date, categories and the UID are kept, and a timed due date keeps its time.
- The first name and color check no longer overwrites a calendar or task list that was renamed or recolored in Nextcloud before this app tracked the values.

## [1.0.7] - 2026-10-21

### Fixed

- When the contacts address book or a task list calendar is replaced, the old sync state is cleared and a full sync runs. Before, every Google item looked deleted locally and deletes were pushed to Google.
- A task list calendar in the Nextcloud trash is no longer used for syncing; a new calendar is created.

## [1.0.6] - 2026-10-20

### Fixed

- After uploading a task to Google, the local iCalendar object is left as the CalDAV client wrote it. Rewriting it from the Google payload changed the ETag and made iOS Reminders fail later PUTs with 412.

## [1.0.5] - 2026-10-19

### Changed

- Calendar and task list names and colors no longer follow Google after you change them in Nextcloud. Google updates apply only while the Nextcloud value still matches the last value this app wrote.
- Personal settings show per-item Match Google name and Match Google color buttons when the local value differs from Google.

## [1.0.4] - 2026-10-18

### Fixed

- Task due dates are written as iCalendar `DATE` values so they show on the Calendar app. Existing bad `DUE:YYYY-MM-DD` values are rewritten on the next sync.
- Nextcloud calendar (and task list) display names are no longer reset on every sync. They update only when Google renames the calendar or list.

## [1.0.3] - 2026-10-18

### Fixed

- Contact sync rebinds a missing or stale Nextcloud address book id. Collections created before the user had a contacts book no longer stay stuck with `nc_id=0`.

## [1.0.2] - 2026-10-16

### Added

- Admin settings for this app's own Google OAuth client ID and secret. Empty fields still fall back to the Google Synchronization client.

### Fixed

- Contact sync no longer calls People API `people.get` once per locally changed card. Those reads are batched, at most 40 people per run, and a 429 waits once for `Retry-After`. CardDAV etags that differ only by quotes are treated as unchanged.
- The settings page no longer defines its own `t()`, which replaced Nextcloud's translator and recursed until the call stack overflowed.

## [1.0.0] - 2026-10-14

### Added

- First release
