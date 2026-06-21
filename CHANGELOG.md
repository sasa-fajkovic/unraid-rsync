# Changelog

Notable changes to the Unraid Rsync plugin. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning is **CalVer**
(`YYYY.MM.DD` with a same-day `a`/`b`/… suffix).

Every merge to `main` auto-publishes a GitHub Release with notes generated from
the merged PR titles — see the [Releases page](https://github.com/sasa-fajkovic/unraid-rsync/releases)
for the authoritative, per-version history. This file curates the notable,
user-facing highlights.

## [Unreleased]

### Added
- **Secrets directory** (Global Settings): optionally store `credentials.json`
  on an array/pool path under `/mnt` (real `chmod 600` at rest) instead of the
  world-readable FAT32 flash. Empty (default) keeps the existing `/boot`
  behaviour. Changing it migrates the file.

### Fixed
- Dashboard tile "open plugin" link now points at the canonical
  `/Settings/UnraidRsync` (restores the highlighted Settings nav).

### Changed (internal)
- Added a php-cs-fixer formatting gate, `.editorconfig`, commitlint on PR titles,
  and a PHPStan level bump + coverage reporting in CI.
