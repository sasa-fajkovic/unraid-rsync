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
- **Notifications for runs that never did their job.** A run that could not
  start (configuration unreadable, job not found, lock or run-state error) sends
  an *alert* `<job>: run did not start`. A scheduled run skipped because the
  previous one is still running sends a *warning*; the same case started by hand
  stays silent (the UI already says so). A run whose runner died sends an
  *alert* `<job>: previous run died without finishing` once, noting that the
  post-hook did not run. That includes a run cut off by a reboot or power loss,
  detected from a small in-flight record on `/boot` and reported a few minutes
  after the array starts (`UR_CRASH_REAP_DELAY`, default 180 s, so notification
  agents are up first), or earlier by the next status poll or run of the job.
  `notifyMode: off` is honoured whenever
  the job can be read. Scheduled hard failures and crashed runs appear in
  History as FAILED rows.
- **Target marker** (per job, optional). Name a small file on the destination
  side, e.g. `<drive>/.unraid-rsync-target`, optionally with the content it must
  hold (a UUID). Before the pre-run hook the run fetches it over the job's own
  transport; if it is missing, unreadable or different, the run fails with reason
  `dest-not-mounted` and nothing is written. Protects against writing to an
  unmounted drive. Empty (the default) = off.

- **Hook timeouts** (Global Settings): pre-run hook 30 minutes and post-run hook
  60 minutes by default, `0` = no limit. A hook past its deadline is sent SIGTERM
  (its whole process group), then SIGKILL 10 s later. A timed-out pre-run hook
  fails the run (`prehook-timeout`); a timed-out post-run hook keeps the run's
  result and turns a clean run into a WARNING (`posthook-timeout`).

- **Host key pinning.** The first successful connection on an `accept-new`
  Connection (a scheduled run or **Test connection**) now pins the host key it
  saw into the Connection, making `accept-new` a real trust-on-first-use
  instead of trusting whatever key the host presents on every run. A
  subsequently changed key then fails closed instead of being silently
  re-accepted.

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

- **`Summary` log level** — a middle setting between *Quiet* and *Normal*, and
  the default for a **newly created** job. It logs one **overall progress line
  every 5% or 30 seconds** (whichever comes first) plus the end-of-run summary,
  and **no per-file lines**, so a nightly backup of a large tree no longer
  buries its own errors under a file listing. Existing jobs keep the level they
  were saved with — change it on the job if you want the quieter log. rsync
  redraws its progress line several times a second with a bare carriage return;
  the plugin now collapses those redraws instead of writing every one, so the
  log viewer shows readable lines at **every** level rather than one enormous
  smeared line. Errors and every `deleting <path>` are still logged at *every*
  level — a quiet level never hides what a `--delete` job removed — and a
  **Dry-run is never quieter than *Normal***, so the preview always names the
  files and deletions it would have made.

#### Snapshot mode
- **Snapshot mode** (per job, SSH + Push). Instead of one mirror, every run
  writes a new dated copy on the receiver, with unchanged files hard-linked to
  the previous snapshot (`rsync --link-dest`), so each snapshot costs only the
  space of what changed. Turn it on in the job card: a snapshot root inside the
  target marker's directory (the marker and its content become mandatory), and
  a retention of daily / weekly / monthly snapshots. Each pair gets a
  sub-directory name instead of a remote path, plus a **seed** flag that allows
  its first, full copy.
- **Only complete runs count.** A snapshot is committed only when every pair
  finished cleanly; otherwise it stays `<stamp>.inprogress` and is reused as a
  link basis by the next run. Every operation on the receiver re-checks the
  marker, the root and a `.ur-snapshot-root` sentinel naming the job, and a
  missing or wrong one fails the run before the pre-run hook.
- **Careful pruning.** Pruning starts as a dry run that only logs its plan; turn
  **Prune dry run** off once the plans look right. At most **Max deletions per
  run** snapshots are deleted per run, the newest snapshot and any pinned one
  (`.ur-keep`) are never deleted, and a run that looks suspect (far fewer files
  or bytes, runaway growth) still commits but puts deletions on hold
  (`.ur-hold`), pins the previous snapshot and alerts.
- **Use sudo on the receiver** (Connections tab, SSH only) for a non-root login
  with passwordless sudo for `sh`; only the snapshot helper uses it.
- **Status everywhere.** Jobs, Overview and the Dashboard tile show the last
  snapshot and whether it was committed; History shows each run's snapshot,
  committed, suspect and pruned count. Alerts name the reason, a run that keeps
  failing says `has not committed a snapshot for N runs`, and a crash report
  names the `.inprogress` it left.
- **Back up the flash drive.** A snapshot job (SSH + Push) may use exactly
  `/boot/` as a source; `/boot` stays forbidden everywhere else, including any
  folder inside it and as a destination on this server.
- Mirror jobs are unchanged; `config.json` only moves to schema 3 while a
  snapshot job is enabled, so an older release refuses it rather than running a
  snapshot job as a mirror. The README covers setup, restores, migrating an
  existing mirror and rolling back.

### Fixed
- **Snapshot mode: an unfinished snapshot older than the last commit is now
  removed** by the next real run instead of being kept forever as a link-dest.
- **Two notifications in the same second no longer drop one.** Unraid's
  `notify` names each notification after its event and unix second and skips a
  send whose name is taken, so e.g. a "scheduled run skipped" warning was lost
  when the same job's finishing run notified in that second. The plugin's sends
  are now serialised and spaced at least a second apart; a send may wait up to
  1 s.
- **A JavaScript error on the plugin's pages is gone.** Each form carried a
  hidden input named `action`, which shadows the form element's own `action`
  property — and Unraid's page-layout script inspects `form.action` on every
  form it finds, so it threw `actionName is not a function` on every page load
  and every Apply, and stopped before installing its "you have unsaved changes"
  leave confirmation. The handler action now travels as a data attribute
  instead; nothing about saving changes.
- **A manual-only job no longer shows a next run time.** Such a job is still
  *enabled* (that is how "run on demand" is stored) and keeps whatever schedule
  it was last saved with, so the 1-second status poll was overwriting the correct
  **manual (on demand)** cell with a cron time that will never fire — on both the
  **Overview** and **Jobs** tabs. crond never saw the job either way.
- **A run log no longer ends on a duplicated progress line.** rsync repeats its
  final redraw, and the last copy carries the stream's only newline, so the same
  `100%` line was written twice — with the end-of-run summary wedged between
  them. An identical repeat is now dropped; one that differs (a moved rate, ETA
  or file count) still lands.
- **`Quiet` was not quiet.** rsync's `--log-file` has its own line format and
  wrote one line per transferred file *regardless* of `-q`, so the level that
  promised the least output still produced a full per-file listing. Both
  *Quiet* and the new *Summary* level now suppress those lines.
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

### Changed
- **Notification importance is chosen by cause**, not by state alone:
  *alert* = act now, *warning* = look today, *normal* = FYI. Exit 24 (files
  vanished during transfer) alone is now *normal*: still sent under
  `success-only`/`always`, no longer under `failure-only`. Exit 25 (`--max-delete`
  cap hit), a failed or timed-out post-hook, and a scheduled abort are
  *warnings*; a manual abort stays *normal*. `failure-only` now sends every
  non-normal case; exit 25 and post-hook failures no longer go to
  `success-only`. Every notification lists state, reason, exit code with its
  meaning, duration, trigger and dry-run.
- History records the run's **reason**, shown as the state badge tooltip.
- **Fail early and loudly on a bad destination.** Every pair's path guardrails,
  the effective rsync options, the target marker and the `--delete` checks now
  run *before* the pre-run hook, so a rejected pair 3 no longer lets pair 1 copy
  (or the pre-run hook stop containers) first. The post-run hook still runs.
- **`--delete` guardrails.** A remote SSH `--delete` destination must be at least
  two directories deep and not a bare `/mnt/<disk>` (`/srv/backup`,
  `/mnt/disk1/appdata` are fine; `/srv`, `/mnt/disk1` are refused). A `--delete`
  destination may not equal, contain or lie inside another job's destination on
  the same connection. Before an SSH mirror runs, its destination is listed one
  level deep and the run is refused (`dest-is-protected`) if it holds
  `.unraid-rsync-target`, `.ur-snapshot-root` or `snapshots`. The rsync daemon
  rule is unchanged.
- **Validation uses the effective options.** A job on "use global config" is
  validated against the global options (no more false "no max delete cap"
  warnings, and problems in the globals are caught), at save and at run time.
  Saving Global Settings re-validates every job against the new values. Global
  `--temp-dir`/`--backup-dir` are now checked too, and a `..` segment is refused
  in both everywhere.
- **SSH keep-alive.** Every ssh the plugin starts adds
  `ServerAliveInterval=30` / `ServerAliveCountMax=6`, so a destination that
  vanishes mid-run fails after about three minutes instead of hanging the job.
- `RSYNC_OLD_ARGS` / `RSYNC_PROTECT_ARGS` are no longer passed on to rsync.
- Scheduled runs are now their own session, so **Abort** reaches their rsync and
  ssh the same way it does for runs started from the UI.
- A `TIMEOUT` run now renders as a warning badge on every tab and the
  dashboard tile. History, Overview and the Dashboard previously showed it as
  a failure.
- History's failed log-download now reports inline instead of in a popup.

### Security
- The CSRF token is read from the POST body only, never from the query string.
- `ssh-keygen`/`ssh-keyscan` scratch directories are confined to the plugin's
  0700 runtime dir, with symlink-safe cleanup.

### Removed
- Unused backend methods with no user-facing effect: `Config::retention`,
  `Notify::init`/`buildInitCommand`, `History::listAll`/`delete`.

### Changed (internal)
- Added a php-cs-fixer formatting gate, `.editorconfig`, commitlint on PR titles,
  and a PHPStan level bump + coverage reporting in CI.
