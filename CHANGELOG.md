# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
