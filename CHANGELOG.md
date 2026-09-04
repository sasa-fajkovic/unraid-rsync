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
- **rsync daemon (rsyncd) transport.** A Connection now has a **Transport** —
  *SSH* or *rsync daemon (rsyncd)* — and a job a matching `DAEMON` transport,
  which talks rsync's own wire protocol straight to a TCP port (873 by default)
  instead of tunnelling over SSH. This is the "Rsync Server" a NAS exposes. A
  daemon job's pair remote side is a **module reference** (`rsync_bkp`, or
  `rsync_bkp/photos`), not a filesystem path; the plugin builds the operand
  `user@host::module` and always passes an explicit `--port`. An authenticated
  module's secret is handed to rsync through a RAM `--password-file` at mode
  `600` (never a command line, never an environment variable); a module with no
  `auth users` needs no secret at all. **Test connection** on a daemon
  connection lists the daemon's public modules — a listing is answered *before*
  authentication, so it deliberately does not claim to verify the username or
  the secret. Note the daemon protocol is **not encrypted**: only a
  challenge/response (MD4 with old peers) protects the secret, and file names
  and contents travel in clear, so SSH stays the recommended transport on any
  untrusted network. Existing SSH and Local jobs, connections and credentials
  are untouched. Two notes if you switch a Connection's Transport: any stored
  password or module secret is cleared (a secret typed for one protocol is
  never reused for the other — the save says so, retype it), and jobs pointing
  at that Connection must have their own Transport switched to match. And if
  you ever downgrade the plugin, an older build cannot see the `transport`
  field: a daemon Connection reads back as SSH on port 873, and saving
  credentials on that older build makes it permanent.

- **Secrets directory** (Global Settings): optionally store `credentials.json`
  on an array/pool path under `/mnt` (real `chmod 600` at rest) instead of the
  world-readable FAT32 flash. Empty (default) keeps the existing `/boot`
  behaviour. Changing it migrates the file.

- **Remote rsync path** (rsync options): `--rsync-path`, the absolute path to
  the rsync binary on the remote host. Set it when the remote keeps rsync off
  its default SSH PATH (common on NAS appliances) and a run fails with
  "rsync: command not found". Constrained to a bare absolute path.

### Fixed
- `--contimeout` is no longer sent on SSH and Local transfers. rsync rejects it
  outright there (*"may only be used when connecting to an rsync daemon"*,
  exit 1), so any job that set it failed before transferring a single file.
  **Such a job starts working on this upgrade** — if it also has `--delete`,
  check its paths and `--max-delete` before its next scheduled run, because it
  will now actually connect and delete. The
  option still applies to the new rsync daemon transport, and setting it on an
  SSH or Local job now raises a save-time warning that it is ignored — on the
  job, and on **Global Settings**, which a job on *Use global config* inherits
  it from. The live *rsync options preview* is transport-aware for the same
  reason: it no longer shows a `--contimeout` the run will drop.
- A Connection with an empty host or username now fails a run **immediately**
  with a clear reason instead of handing rsync the operand `:/path` and failing
  opaquely part-way through.
- `--temp-dir` / `--backup-dir` are no longer told they "look like an rsync
  daemon module name" — those fields are never a module name, so a non-absolute
  value there now simply reports that it must be an absolute path.
- rsync's own `--log-file` output is now redacted of the per-run secret paths,
  not just the output the plugin captures itself. rsync names a password file
  verbatim in its own error messages, and that log is rendered in the browser.
- A job's remote path that is really an **rsync daemon module name** is now
  called out at save time instead of failing the first run with an opaque
  `link_stat ... No such file or directory`. `host::module` and `rsync://` are
  rejected with an explanation, a bare module name is named as such, and a
  single top-level path like `/backup` gets a non-blocking advisory. The
  source/destination pairs row also gained the help text it never had.
- Validation warnings are no longer discarded when a save fails for some other
  reason (they were already in the response, just never rendered), and a save
  that produced one no longer reloads the page out from under it.
- Global Settings rsync option values are now validated on save, like a job's
  own. A job left on "use global config" takes them verbatim, so an invalid
  global value previously reached rsync unchecked.
- Dashboard tile "open plugin" link now points at the canonical
  `/Settings/UnraidRsync` (restores the highlighted Settings nav).

### Changed (internal)
- Added a php-cs-fixer formatting gate, `.editorconfig`, commitlint on PR titles,
  and a PHPStan level bump + coverage reporting in CI.
