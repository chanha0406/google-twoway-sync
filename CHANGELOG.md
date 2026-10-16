# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Contact sync no longer calls People API `people.get` once per locally changed card. Those reads are batched, at most 40 people per run, and a 429 waits once for `Retry-After`. CardDAV etags that differ only by quotes are treated as unchanged.
- The settings page no longer defines its own `t()`, which replaced Nextcloud's translator and recursed until the call stack overflowed.

### Added

- First release
