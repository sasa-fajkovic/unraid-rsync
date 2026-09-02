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

- **Remote rsync path** (rsync options): `--rsync-path`, the absolute path to
  the rsync binary on the remote host. Set it when the remote keeps rsync off
  its default SSH PATH (common on NAS appliances) and a run fails with
  "rsync: command not found". Constrained to a bare absolute path.

### Fixed
- A job's remote path that is really an **rsync daemon module name** is now
  called out at save time instead of failing the first run with an opaque
  `link_stat ... No such file or directory`. `host::module` and `rsync://` are
  rejected with an explanation, a bare module name is named as such, and a
  single top-level path like `/backup` gets a non-blocking advisory. The
  source/destination pairs row also gained the help text it never had.
- Validation warnings are no longer discarded when a save fails for some other
  reason (they were already in the response, just never rendered).
- Dashboard tile "open plugin" link now points at the canonical
  `/Settings/UnraidRsync` (restores the highlighted Settings nav).

### Changed (internal)
- Added a php-cs-fixer formatting gate, `.editorconfig`, commitlint on PR titles,
  and a PHPStan level bump + coverage reporting in CI.
