# Snapshot mode — design spec

Status: design for the `feat/snapshot-mode` branch (PRs E–J). This file is the branch's first commit.
Audience: whoever implements PRs E–J, and the maintainer reviewing them.
Baseline: `main` at 868bc17 (Phase 2 PRs #177–#180 merged). Function names below were checked against that commit.
Where an earlier planning note disagrees with the code, this spec follows the code and says so (marked **[code]**).

---

## 1. Goal, non-goals, terminology

**Goal.** A job can keep dated, versioned copies on an SSH receiver instead of one mirror. Each run writes a new directory. Unchanged files are hard links to the previous copy (`rsync --link-dest`). A copy only counts once it is complete, and old copies are pruned by a retention policy. Existing mirror jobs and existing `config.json` files are not affected.

**Non-goals (v1).**
- LOCAL and DAEMON transports, and PULL. v1 supports only SSH + PUSH.
- Receivers without GNU coreutils/util-linux (BusyBox, BSD, macOS).
- Database dumps (use the job's pre-hook), filesystem snapshots (ZFS/btrfs), and a per-connection run queue (PR-C, deferred; nothing here depends on one).
- Protecting the snapshots from a compromised Unraid host. The host can already run root commands on the receiver; snapshot mode needs that and adds no new privilege.
- Offsite copies, encryption at rest, deduplication across jobs.

**Terms.**

| Term | Meaning |
|---|---|
| receiver | the SSH host the job pushes to |
| root | per-job directory on the receiver that holds that job's snapshots, e.g. `/mnt/backup/snapshots/<job>` (example) |
| stamp | UTC name of one snapshot, `YYYY-MM-DDTHHMMSSZ` |
| sub | per-pair directory name inside a stamp: `<root>/<stamp>/<sub>/` |
| complete snapshot | a directory in the root whose name is exactly a stamp (no suffix) |
| helper | `source/scripts/ur-snapshot.sh`, sent over SSH and run as root on the receiver |
| commit | renaming `<stamp>.inprogress` to `<stamp>` |

## 2. Data model

### 2.1 Job: `snapshot` block (config.json)

```json
"snapshot": {
  "enabled": true,
  "root": "/mnt/backup/snapshots/appdata",
  "keepDaily": 7,
  "keepWeekly": 4,
  "keepMonthly": 0,
  "maxPrunePerRun": 3,
  "pruneDryRun": true
}
```

| Key | Type | Default | Rule |
|---|---|---|---|
| enabled | bool | false | Only a JSON `true` enables. Anything else counts as off. |
| root | string | '' | Absolute; no `..` or control characters; at least 2 segments deep, or 3 under `/mnt` (same test as `Job::isDeepRemoteDeleteTarget`). Stored normalised (`Job::normalizePath`, no trailing slash). Must equal, or lie inside, `dirname(targetMarker)`. |
| keepDaily | int | 7 | 0–366 |
| keepWeekly | int | 4 | 0–260 |
| keepMonthly | int | 0 | 0–120. Monthly is off by default, but the setting stays generic. |
| maxPrunePerRun | int | 3 | 1–20 |
| pruneDryRun | bool | true | When true, prune is planned and logged but nothing is deleted. |

- `keepDaily + keepWeekly + keepMonthly` must be ≥ 1.
- The block is **serialised only while `enabled` is true**, using the same pattern as `Config::OPTIONAL_JOB_KEYS`. It is appended after those keys so key order stays stable. Turning snapshot mode off drops the block and its settings.
- `Config::defaultJob()` keeps its 15 keys, so `ConfigTest::testDefaultJobKeySetIsUnchangedAtFifteenKeys` stays as it is. (The spike put the block into `defaultJob()`; this spec does not.)
- Normalisation (`Config::normalizeSnapshot`) never clamps a value silently. A non-integer or out-of-range value is kept as given (an invalid integer becomes `-1`) so that validation rejects it at save time and again at run time. Silently clamping a keep count could delete more than the admin intended.

### 2.2 Guard marker: reuse `targetMarker` / `targetMarkerContent`

**Decision:** snapshot mode reuses the per-job target marker from PR #177 instead of adding a `guardUuid` field. **[code]** The earlier plan specified a `guardUuid`.

- In snapshot mode both fields are **required**. `targetMarkerContent` should be a UUID.
- `Runner::checkTargetMarker` already fetches the marker over the job's transport with `Rsync::auxArgv` and fails with `dest-not-mounted`. The Jobs form already has both fields. A second field would duplicate the concept.
- The helper re-checks the same marker as root on every operation (§5). Both values are passed to it as arguments.
- Extra save-time rule: the root must equal, or lie inside, `dirname(targetMarker)`, which ties the root to the marked drive.

### 2.3 Pairs: `sub` and `seed`

`{"local": "/mnt/user/appdata/", "remote": "", "sub": "appdata", "seed": true}`

- **Decision:** `sub` is its own key. In snapshot mode `remote` must be `''`. **[code]** The earlier plan reused `remote` to hold the sub name.
- Why: `Job::destinations`, `Runner::resolvePair`, `probeDeleteDestinations` and `guardrailErrors` all treat `remote` as a path. An empty `remote` makes any code path that does not know about snapshots fail closed ("remote path is required"), instead of treating `appdata` as a path.
- Old plugins cannot misread `sub`: they cannot load a v3 config at all (§2.5).
- `sub` rules:
  - regex `^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$` (no dots, so `.` and `..` are impossible);
  - reserved: `latest`, compared case-insensitively;
  - unique within the job, case-insensitively (receivers may be case-insensitive).
- `seed` (bool, written only when true) is the admin's explicit consent that this pair may run **without a link-dest basis**, which means a full copy.
  - It is required for every pair on a job's first snapshot, and for any pair added later.
  - It has no effect once `<prev>/<sub>` exists; the run logs that the flag can be cleared.
  - The Runner never edits `config.json`, so it does not clear the flag itself.
- `sub` and `seed` are serialised only while the job's snapshot block is enabled. Key order within a pair: `local, remote, sub, seed`.
- `Job::normalizePairs` drops a pair only when `local`, `remote` and `sub` are all empty.

### 2.4 Connection: `useSudo` (credentials.json)

- Connections live in `credentials.json` (`Credentials`, schema 1), not in `config.json`. **[code]**
- `useSudo` (bool) is added within schema 1. This follows the precedent of `transport` and `keyFilePath` (see the comment in `Credentials::defaultConnection`).
- It is written **only when true**, so existing files round-trip unchanged.
- It is ignored for, and forced off on, DAEMON connections.
- In `ur_normalize_connection_for_save`, an absent posted field means "unchanged" (keep the stored value), the same as the existing transport and port rule. This protects stale tabs, and covers PR-E, which has no UI.
- An older plugin drops `useSudo` when it saves credentials. Snapshot mode then fails closed in `probe` with a clear message.

### 2.5 Schema version

- `Config::SCHEMA_VERSION` stays **2**. It is the baseline this code writes, and `ConfigTest` asserts it.
- New `Config::MAX_SCHEMA_VERSION = 3`: the newest version `migrate()` accepts.
- New `Config::schemaVersionFor(array $config): int` returns 3 iff at least one job has `snapshot.enabled === true`, and 2 otherwise.
  - `save()` writes that value. `mergeDefaults()` sets it from the merged jobs, so the stored version always matches the content.
  - **[code]** `migrate()` currently re-stamps `SCHEMA_VERSION`. Instead it must accept versions ≤ `MAX_SCHEMA_VERSION` and leave the version to `mergeDefaults()`.
  - v2 → v3 needs no transform, because v3 is a superset.
- Consequences:
  - A v2 config with no snapshot job round-trips byte-identical. `testExistingV2ConfigOnDiskRoundTripsCompletelyUnchanged` stays green, unmodified.
  - Enabling the first snapshot job writes v3. Disabling the last one writes v2.
  - A v3 config with no enabled block loads as 2 and saves as 2.
  - An older plugin (MAX = 2) throws on load. Every run then hard-fails with `config-unreadable` (an alert), and saves return 409. This fails closed and loudly; nothing silently runs as a mirror over the snapshots.
- The feature branch merges to `main` once (§10), so no published release writes v3 without the code that runs it.

### 2.6 Effective options: forced and forbidden

- All checks use `Rsync::effectiveOptions($job, $global)`, at save time (`Job::validate` with `$global`), when Global Settings are saved, and at run time.
  - Most jobs use global defaults (`useGlobalDefaults`), and `Config::defaultRsyncOptions()` ships with `mkpath` and `partial` **on**.
  - `ur_validate_jobs` already re-validates every job when Global Settings are saved. A global change that breaks a snapshot job is therefore a 422.
- **Forced** means: removed from a copy of the effective options right before `Rsync::buildArgv`, logged once per run, and reported as a save-time warning. Stored values are never rewritten.
- **Rejected** means: a validation error at save time and `snapshot-preflight` at run time.

| Option | Rule | Why |
|---|---|---|
| `inplace` | rejected; also forced off as a second guard | rewrites inodes shared with older snapshots |
| append / append-verify | cannot be emitted (not in the `Rsync::BOOL_FLAGS` whitelist); a test asserts it | corrupts or skips files |
| `backupDir` | rejected | meaningless in a fresh directory |
| `mkpath` | forced off | the stamp is created one level deep by the helper; a missing root must fail, not be created on the system disk |
| `delete`, `deleteExcluded`, `maxDelete` | forced off | nothing to delete in a fresh directory; a bad path would otherwise wipe the root |
| `checksum`, `sizeOnly`, `ignoreExisting`, `update` | rejected | stale content or full reads of the basis |
| `times` | **required** (rejected when off) | without mtimes `--link-dest` never matches, so every run would be a full copy |
| `hardlinks` (`-H`) | forced off | memory cost; correctness with `--link-dest` is not verified |
| `partial`, `tempDir`, `compress`, `bwlimit`, filters, … | allowed | harmless in a never-resumed fresh directory |
| transport / direction | SSH + PUSH only; also checked at run time (the connection can change after the job is saved) | DAEMON and LOCAL are not verified |
| extra tokens | only `--info=stats2` and `--link-dest=<abs>`, computed by code through `Rsync::extraTokens` (PR #180) | the whitelist stays closed |

`RSYNC_OLD_ARGS` and `RSYNC_PROTECT_ARGS` are already stripped by `Ssh::childEnv` (PR #177).

## 3. Layout on the receiver

```
<marker-dir>/.unraid-rsync-target      targetMarker (contains targetMarkerContent)
<root>/                                0700 root:root, created by the admin
<root>/.ur-snapshot-root               sentinel; first line = job id
<root>/<stamp>/<sub>/…                 complete snapshot (the name is the only proof of completeness)
<root>/<stamp>/.ur-snapshot.json       metadata, 0600; optional for readers (a migrated seed may lack it)
<root>/<stamp>/.ur-keep                pin: never pruned (set by suspect handling or by the admin)
<root>/<stamp>.inprogress/             running, failed or aborted run; never committed later
<root>/<name>.deleting/                deletion in progress
<root>/<stamp>.rolledback/             left by a manual rollback; never a candidate, never deleted by the plugin
<root>/.ur-hold                        blocks every deletion for this job; only the admin removes it
<root>/latest -> <stamp>               relative symlink, for humans; the plugin never reads it to choose a basis
```

**Stamp.**
- Regex `^\d{4}-\d{2}-\d{2}T\d{6}Z$`. It must be a real calendar time: `DateTimeImmutable::createFromFormat('!Y-m-d\THis\Z', …, UTC)` round-trips to the same string.
- It is generated and parsed **only in PHP on the Unraid host**. GNU `date -d` cannot parse the format.
- It is taken from the host clock **right after the per-job lock is acquired** (`RunState::acquireLock`). **[code]** There is no queue lock (PR-C is deferred).

**Monotonic rule.**
- The new stamp must sort strictly after every stamp-shaped name in the root, whatever the suffix (`.inprogress`, `.deleting`, `.rolledback`). Plain string comparison works because the format has fixed width.
- Otherwise the run fails with `snapshot-clock`. This stops a clock error from producing a "new" snapshot that sorts as old and is pruned first, or from producing a far-future name that would block every later run.
- Names more than 1 day ahead of the host clock are logged as future-dated, then the same rule fails the run.

**Clock skew.**
- `probe` reports the receiver's `date -u +%s`. If it differs from the host's clock by more than 120 s, the run fails with `snapshot-clock`. This is a cross-check on the host clock.
- The receiver's NTP status (`timedatectl`, when present) is logged only. **Change from the earlier plan:** it is not a gate, because generic receivers may lack systemd.

## 4. Run sequence inside `Runner::run`

Line references are to the current `Runner::run()`. Steps marked **new** are added by PR-G. Mirror jobs skip every new step, and their run is byte-for-byte unchanged (RunnerTest asserts the argv and the hook env).

| # | Where | Step |
|---|---|---|
| 1 | existing §1–3 | Load config, `findJob`, `RunState::acquireLock` (per job), open run log, `RunState::write` + `writeInflight`. |
| 2 | **new**, right after state init | `$stamp = Snapshot::stamp(Snapshot::now())` (seam `Snapshot::$nowProvider`). Log it. Add `snapshot` to the in-flight record (PR-I) so a crash report can name the `.inprogress` it left. |
| 3 | existing §4–5 | `materializeSsh`, `Rsync::rsyncAvailable`. |
| 4 | `preflight()`, existing part | Same calls with snapshot-aware branches: `guardrailErrors` (per pair: local checks as today, `remote === ''`, `sub` rules, the `/boot` exception from §8); `Job::optionErrors` + `Job::snapshotOptionErrors` (effective options); `Job::markerErrors` (marker required); `Job::ancestryErrors` + `Job::snapshotRootErrors` (§7); `checkTargetMarker` (mandatory). `probeDeleteDestinations` is skipped for snapshot jobs: delete is forced off, and the branch tests `snapshot.enabled` rather than the unforced `$opts`. Any failure returns the existing reasons (`guardrail`, `dest-not-mounted`). |
| 5 | `preflight()`, **new** `snapshotPreflight()` | (a) load and verify the helper file (§5.4); (b) remote `probe` (tools, uid 0, API version, clock skew); (c) remote `check` (root, marker, filesystem, sentinel); (d) remote `list`, parsed and re-validated in PHP; (e) free space: available ≥ max(5 % of the filesystem, 10 GiB); (f) monotonic and future-name rules → `snapshot-clock`; (g) the transfer identity can read the root (`Rsync::auxArgv` with `--list-only --dirs <root>/`). This catches a non-root SSH user with no root `--rsync-path` wrapper; (h) every pair's local source exists, is a directory, and is not empty (PHP `scandir` on the host). This runs before the pre-hook, so a source the pre-hook fills must not be emptied by the post-hook; (i) basis per pair: `<prev>/<sub>` must appear in the listing, where `prev` is the newest complete stamp. Otherwise the pair needs `seed` ("new pair: full copy expected" is logged), else the run fails. Failures return `snapshot-preflight`, or `snapshot-clock` for (b)-skew and (f). |
| 6 | **new**, after preflight, before the pre-hook | Cleanup, real runs only, skipped while `.ur-hold` exists: remove every leftover `.deleting` and every stale `.inprogress` **except the newest one** with helper `delete`. All `.inprogress` names are stale, because the per-job lock is held and the sentinel ties the root to this job. Failures do not fail the run; they set `prune-failed` (WARNING). Running this before the pre-hook keeps it out of the service-downtime window. |
| 7 | existing §5c | Pre-hook. Its env adds `UR_SNAPSHOT=<stamp>` and `UR_SNAPSHOT_STATUS=''` (snapshot jobs only). Pre-hook timeout as today. |
| 8 | **new**, after a successful pre-hook | Real runs: helper `mkstamp` → `mkdir <stamp>.inprogress` (no `-p`; it fails if the name exists). Failure → FAILED `snapshot-mkstamp-failed`, pairs skipped. Dry runs do nothing here. |
| 9 | existing §6 pair loop | Per pair: dest operand `user@host:<root>/<stamp>.inprogress/<sub>/`; options = `Snapshot::runOptions($opts)` (forced offs applied); `$extraTokens` = `--info=stats2`, then `--link-dest=<root>/<prev>/<sub>` (when it has a basis), then `--link-dest=<root>/<newestStale>.inprogress/<sub>` (when a stale one was kept). The output goes to `Logger::sink` **and** a bounded capture (last 64 KiB) for `Snapshot::parseStats`: `Number of files … reg:` and `Total file size`, with commas and `-h` suffixes handled. It also detects `--link-dest arg does not exist: <path>` per path. Abort polling is unchanged. A dry run uses the same dest; rsync `-n` into a missing directory exits 0 and lists only the delta against the link-dest. |
| 10 | **new**, after the loop (inside `try`) | **Completion gate** (§4.1). If it passes: post-transfer `check` (re-verifies root, marker and sentinel, and returns `df`), then the **anomaly check** (§4.2). If suspect: helper `hold`, then helper `pin <prev>`. Then helper `commit` with the metadata JSON (base64): it writes `.ur-snapshot.json`, `sync -f`, `test ! -e <stamp>`, `mv -T`, swaps `latest` atomically, and `sync -f` again. Failure → FAILED `snapshot-commit-failed`. The `.inprogress` stays and becomes next run's extra link-dest. |
| 11 | existing `finally`, §7 | Post-hook as today. Its env adds `UR_SNAPSHOT=<stamp>` (or `''` when no stamp was created) and `UR_SNAPSHOT_STATUS` = `committed` / `not-committed` / `dry-run` / `none` (`none` means it failed before `mkstamp`). |
| 12 | **new**, in `finally` **after the post-hook and before** the host-key pin (§7b) and `Ssh::cleanupRuntime` (§8) | **Prune** (§6). It runs only if the run committed, was not a dry run, and `RunState::abortRequested` is false. It needs the materialised SSH pieces, which is why it must sit before cleanup. Wrapped in `try/catch`; it never throws. The abort flag is polled before each deletion. |
| 13 | existing §9 | Summary, History (new fields §4.3), `clearInflight`, `markStopped`, `notifyHook` (reasons §4.4), lock release. |

**Dry run.**
- Steps 1–5 and 9 run. Steps 6, 8, 10 and 12 do not, except that the prune **plan** for the current listing is logged.
- A dry run never creates a stamp and never counts toward "not committed".
- The current Runner also runs the pre-hook and post-hook on a dry run **[code]**. That is unchanged and documented.

**Helper timeouts.** `delete` has no deadline, but it is stoppable by abort. Every other helper call has a 120 s deadline, except `commit`, which gets 600 s because `sync -f` after a large transfer can be slow on USB disks. All use `ProcIO::drainPipes` with a deadline and `stopWhen`.

### 4.1 Completion gate (worst exit code over all pairs)

The snapshot commits only if: the run is not a dry run, it was not aborted, every pair ran, every pair exit code is 0 or 24, and no earlier step set FAILED.

| Worst exit | State (`Rsync::worstOutcome`) | Commit | Reason / importance |
|---|---|---|---|
| 0 | SUCCESS | yes | — / normal |
| 24 | WARNING | yes | — / normal (the existing "only vanished" rule; it needs `reason === ''`) |
| 23 | PARTIAL | **no**, stays `.inprogress` | `snapshot-not-committed` / alert |
| 30, 35 | TIMEOUT | no | `snapshot-not-committed` / alert |
| 20, 143 (abort) | ABORTED | no | none set: existing rule (manual → normal, scheduled → warning) |
| 25 | — | cannot happen (`maxDelete` is forced off) | — |
| any other | FAILED | no | `snapshot-not-committed` / alert |

### 4.2 Anomaly ("suspect") rule

The rule runs only when the gate passes. Thresholds are constants in `Snapshot.php`, marked `[PROPOSAL; tune]`. A run is suspect if any of these hold:
1. **Files:** a pair's regular-file count is < 50 % of the same sub in the previous snapshot's `.ur-snapshot.json`. Checked only when the previous count is ≥ 100.
2. **Bytes:** a pair's total file size is < 50 % of the previous one. Checked only when the previous size is ≥ 1 GiB.
3. **Growth:** filesystem growth (`df` used after the transfer minus before it) is > max(3 × the median growth of the last 7 committed runs, 5 GiB). Checked only once ≥ 3 earlier growth values exist in History.
4. **Missing basis:** rsync reported `--link-dest arg does not exist` for the `<prev>/<sub>` path of a pair that had a basis.

With no baseline (first run, or a seed without metadata), checks 1–3 are skipped and the skip is logged.

A suspect run **still commits**. It creates `.ur-hold`, pins the previous complete snapshot with `.ur-keep`, sets state WARNING with reason `snapshot-suspect` (an alert), and records `suspect: true`. Check 3 is the only one that catches files encrypted in place, where counts and sizes stay the same.

### 4.3 History and summary fields (snapshot jobs only)

`History::normalizeRecord` gets optional keys, written only for snapshot jobs so mirror records keep their shape:
- `snapshot` (stamp or ''), `committed` (bool), `suspect` (bool), `pruned` (int), `growthKB` (int, or absent).

The summary file (`Runner::writeSummary`) gets `snapshot` and `committed`. `getStatus` shows the last committed stamp (PR-I).

### 4.4 Notification reasons (mapped into `Runner::notifyImportance`)

Two new lists sit beside the existing reason rules. An alert reason always wins. A warning reason applies when the state is not FAILED. `$reason` stays first-set-wins as it is today, except that an alert-class reason replaces a warning-class one (`Runner::setReason`).

| Reason | Set when | State | Importance |
|---|---|---|---|
| `snapshot-not-implemented` | PR-E/F only: a snapshot job is run | FAILED | alert |
| `snapshot-preflight` | step 5 or a rejected option | FAILED | alert |
| `snapshot-clock` | skew, monotonic or future-name rule | FAILED | alert |
| `snapshot-mkstamp-failed` | step 8 | FAILED | alert |
| `snapshot-not-committed` | the gate failed (not an abort) | PARTIAL / TIMEOUT / FAILED | alert |
| `snapshot-commit-failed` | step 10 | FAILED | alert |
| `snapshot-suspect` | §4.2 | WARNING | alert |
| `prune-sanity` | a prune sanity abort (§6) | WARNING | alert |
| `snapshot-held` | `.ur-hold` present, so prune and cleanup are skipped | WARNING | warning |
| `prune-backlog` | deletions carried over past `maxPrunePerRun` | WARNING | warning |
| `prune-failed` | a helper `delete` failed (prune or cleanup) | WARNING | warning |

- Existing `dest-not-mounted` and `guardrail` stay alerts (state FAILED).
- **Not committed twice:** when the previous non-dry run of the job also has `committed: false` in History, the alert text says "has not committed a snapshot for N runs" (PR-I).
- A clean commit never sets a reason, so exit 24 stays a normal notification.

## 5. Helper `ur-snapshot.sh`

### 5.1 Invocation

- The script is POSIX `sh` (`#!/bin/sh`), `set -eu`, `LC_ALL=C`, and a fixed `PATH`.
- It ships 0644 in `source/scripts/`. It is never executed locally.
- PHP reads the file and runs it on the receiver:
  `ssh <Ssh::materialize sshArgv> -o LogLevel=ERROR -- user@host '<cmd>'`
  where `<cmd>` is one string built by `escapeshellarg` on each element of
  `['sudo','-n','sh','-c',$script,'ur-snapshot',$op, ...$args]` (without the `sudo -n` part when `useSudo` is false), joined with spaces.
- Receiver requirements: a POSIX-compatible login shell; with `useSudo`, passwordless sudo for `sh` (full root); without it, the job connects as root.
- `LogLevel=ERROR` is added only to helper calls; it keeps "Permanently added" noise out of the parsed output. Transfers keep their argv.
- Common arguments, positional and in this order: `<op> <root> <marker> <markerContent> <jobId> [op args]`. `probe` takes none.
- The script validates every argument again with `case` patterns:
  - absolute paths;
  - no `/../`, `/./` or `//`;
  - no control characters;
  - stamp-shaped names only, matching the glob `[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z` with an optional `.inprogress` or `.deleting` suffix.

### 5.2 Operations and the re-checks each one performs

`guard` = all of the following, re-run inside each operation that names it:
- `root` is a directory and not a symlink;
- `readlink -f -- root` equals `root`;
- the marker is a regular file, not a symlink, and its first line equals `markerContent`;
- `stat -c %d` of the marker equals that of the root, and differs from that of `/`;
- `<root>/.ur-snapshot-root` is a regular file whose first line equals `jobId`.

| Op | Extra args | Does | Re-checks |
|---|---|---|---|
| probe | — | Prints the API version, `uid` (must be 0, else exit 17), `now` (epoch) and `ntp` (`yes`/`no`/`unknown`). Runs a functional self-test of every primitive in a `mktemp -d` directory, then removes it: `mkdir`, `mv -T`, `ln -s` + `mv -T` swap, `stat -c`, `df -Pk`, `readlink -f`, `sync -f`, `base64`/`base64 -d`, `rm -rf --one-file-system`. `ionice` is optional and reported. | — |
| check | — | Prints `df` (size/used/avail in KB). | guard |
| list | — | Prints depth-1 entries matching stamp shapes (with type and `keep`/`meta` flags), `latest <target>`, `hold 0/1`, `foreign <count>` (other names are counted, never printed), `sub <name>` for the depth-2 directories of the newest complete stamp that match the sub regex, `meta <stamp> <base64>` of the newest complete stamp's `.ur-snapshot.json` (≤ 64 KiB), and `df`. | guard |
| mkstamp | `<stamp>` | `mkdir -m 0700 <stamp>.inprogress` (no `-p`). Refuses (15) if the stamp is not later than every existing stamp-shaped name (`sort` under `LC_ALL=C`). | guard, monotonic |
| commit | `<stamp> <metaB64>` | Writes the meta to a temp file, then `mv` to `.ur-snapshot.json` (0600); `sync -f`; refuses if `<stamp>` exists or if `latest` exists and is not a symlink; `mv -T <stamp>.inprogress <stamp>`; `ln -s <stamp> .latest.$$` + `mv -T .latest.$$ latest`; `sync -f`. Prints `df`. | guard, `.inprogress` is a real directory |
| delete | `<name>` | Refuses (16): the `latest` target; anything with `.ur-keep`; the newest complete stamp; anything while `.ur-hold` exists; `.rolledback` names. Refuses (15) if `<base>.deleting` already exists (PHP deletes leftovers first). `mv -T <name> <base>.deleting` (skipped when the name already ends in `.deleting`), then `ionice -c3 nice -n19 rm -rf --one-file-system -- <root>/<base>.deleting`, without `ionice` when it is absent. | guard, all refusals above |
| hold | `<reason-stamp>` | Creates `.ur-hold` holding the stamp and the UTC time. Idempotent. | guard |
| pin | `<stamp>` | `touch <stamp>/.ur-keep` on a complete stamp only. Idempotent. | guard |

- No operation creates the root or the sentinel. The admin creates them (the documented commands are in the user docs).
- No operation clears `.ur-hold` or `.ur-keep`; only the admin removes them.

### 5.3 Output and exit codes

**Output.**
- stdout is line-oriented: the first line is `ur-snapshot <API>` (API = 1), then `key value…` records, then a final `ok`.
- PHP (`Snapshot::parseHelper`) rejects output that is missing the header or the trailer, or has an unknown API version. It re-validates every name with the PHP regex and the calendar check.
- stderr goes to the run log through the redacting sink. Its first line is quoted in the failure message.

**Exit codes.**

| Exit | Meaning |
|---|---|
| 0 | ok |
| 10 | usage or invalid argument |
| 11 | root missing, not a directory, or not canonical |
| 12 | marker missing or content mismatch |
| 13 | marker or root on a different filesystem, or on `/`'s filesystem |
| 14 | sentinel missing or mismatch |
| 15 | name exists or monotonic refusal |
| 16 | protected (delete refusals) |
| 17 | not root, or a tool or primitive is missing |
| 18 | I/O failure (`mkdir`, `mv`, `rm`, `sync`) |
| 19 | API version mismatch |
| anything else (1 = `sudo -n` needs a password, 126/127 = shell, 255 = ssh) | transport failure |

### 5.4 Shipping and verification

- PR-F adds `source/scripts/ur-snapshot.sh` to the CI shellcheck loop (`.github/workflows/lint.yml`). It is checked with `shellcheck -s sh` and `sh -n`; `bash -n` is not used.
- AGENTS.md gets a new entry in "the ONLY intentional shell uses": the remote helper, built from the shipped file with every element passed through `escapeshellarg`.
- Before each run, `Snapshot::helperScript()` reads the installed file and checks that:
  - it is a regular file, not a symlink, and ≤ 32 KiB;
  - it starts with `#!/bin/sh`;
  - its sha256 equals `Snapshot::HELPER_SHA256`. A unit test keeps the constant in step with the file, in the same way RsyncTest keeps the archive keys in step.
- If any check fails, the run fails closed with `snapshot-preflight` before any remote call.
- The exact bytes read are the bytes sent. A test drives the helper through real `sh -c` with the exact command string ssh would receive.

## 6. Retention and prune

**Planner.** `Snapshot::planPrune(list, policy, now, currentStamp)` is pure PHP, has no I/O, and is unit-tested. The helper only ever receives exact names.

1. Prune runs only when this run committed, is not a dry run, and was not aborted.
   - `.ur-hold` present → skip, log, reason `snapshot-held`.
   - `pruneDryRun` → log the plan, delete nothing.
2. Work from a **fresh** `list` taken after the commit.
3. **Candidates** are depth-1 names that are real directories, match the strict regex exactly (no suffix), are valid calendar times, and are ≤ now + 1 day.
   - Future names are neither kept nor deleted; they are logged.
   - Everything else is ignored: suffixed names, `latest`, files, foreign names.
4. **Keep set** is the union of:
   - the newest snapshot in each of the newest `keepDaily` UTC days, `keepWeekly` ISO weeks and `keepMonthly` months **that contain a snapshot** (a gap does not use up quota);
   - the newest complete snapshot;
   - the `latest` target;
   - the current stamp;
   - every `.ur-keep`.
5. **Delete set** = candidates − keep set, oldest first.
   - **Floor:** never take the complete count below min(current count, keepDaily + keepWeekly + keepMonthly). The newest names in the batch are dropped to respect it. This is normal, not an anomaly.
   - **Rate limit:** at most `maxPrunePerRun` deletions this run. The rest carry over (WARNING `prune-backlog`).
   - Leftover `.deleting` names are deleted first and count toward the cap.
6. **Sanity aborts** (nothing is deleted; WARNING `prune-sanity`, alert):
   - the batch contains the newest complete snapshot, the `latest` target, the current stamp or a pin (an internal error);
   - the batch is > 50 % of complete snapshots;
   - a listed candidate fails re-validation;
   - the post-commit listing lacks the current stamp;
   - the root, marker or sentinel check fails.
7. Each deletion is a helper `delete`, with the abort flag polled between deletions.
   - The first failure stops the batch (WARNING `prune-failed`).
   - Every KEEP and DELETE is logged with its reason.
   - Job rsync options are **never** used for pruning: the helper uses `rm`, not rsync.

**Safety rules (each one is a PHPUnit test in PR-F; the shell side is tested through `sh -c` in PR-F/G).**
- Selection is never by position in a listing. The old `ls | sort | head -n -N` pattern selects a mirror directory; a regression test reproduces that.
- Never delete the newest complete snapshot, the `latest` target, a pinned snapshot, the current stamp, or everything.
- Decoys are never candidates:
  - invalid dates (`2026-02-30`), hour 25, minute 60, wrong length, a leading or trailing space, a `~` suffix, an embedded newline;
  - `.inprogress`, `.partial`, `.rolledback`;
  - mirror-like names (`appdata`, `lost+found`, `snapshots`);
  - a file or symlink carrying a valid stamp name (a symlink's target survives);
  - every lookalike from the live test of the earlier plan.
- Abort (nothing deleted) when the root is missing, recreated on the system filesystem, symlinked, given as relative, or given with `..`/`//`, and when the marker or sentinel is wrong.
- Keep 7d/4w/0m over 108 daily snapshots, and keep 7d/4w/3m: exactly the expected sets remain. Weekly-only schedules are covered too.
- Dry-run plan == real plan. Running prune twice deletes nothing the second time.
- Convergence: 15 snapshots, cap 3 → the backlog clears in the expected number of runs, and the protected entries are never touched.
- Floor, and the > 50 % abort.
- `rm --one-file-system` does not cross a filesystem mounted inside an old snapshot.
- A kept snapshot that shares hard links with a pruned one keeps its data.

## 7. Interaction with the Phase 2 guards

- **`Job::protectedTargets`** (built in PR #177 to be extended) appends `{scope: 'SSH:<connId>', path: root, label: 'the snapshot root of job "x"'}` for every other job with snapshots enabled. A `--delete` mirror whose destination equals, contains or lies inside a snapshot root is then refused by `Job::ancestryErrors`, both at save time (`ur_validate_jobs`) and at run time (`Runner::preflight`).
- **New `Job::snapshotRootErrors($job, $jobs)`** covers two further cases in the same scope, regardless of `--delete`:
  - any destination of any other job that equals or lies inside a snapshot root (a non-delete mirror there would write into stamps);
  - snapshot roots that equal, contain or lie inside each other.

  It is called from `ur_validate_jobs` and from `Runner::preflight`. Scope is per connection id, so two connections to the same host are not compared. The sentinel catches that case at run time (exit 14).
- **`Runner::PROTECTED_ENTRIES`** already lists `.ur-snapshot-root` and `snapshots`. A `--delete` mirror aimed at a root, or at a drive root that holds `snapshots/`, is refused with `dest-is-protected` even on another connection. Snapshot jobs never run that probe, because delete is forced off.
- **Target marker:** mandatory in snapshot mode. It is checked from the host side by `checkTargetMarker` and as root by the helper's `guard`.
- **Crash detection** (PR #178): a runner that dies leaves `<stamp>.inprogress`.
  - The existing `RunState` in-flight record and `reap-crashed.php` report it, with reason `crashed`.
  - The next run treats the folder as stale: it keeps it as an extra link-dest, or deletes it when it is not the newest stale one.
  - The post-hook did not run in that case; the crash alert already says so.
- **Hook timeouts** (PR #177) are unchanged. Cleanup (step 6) and prune (step 12) sit outside both hook windows. Prune holds the per-job lock but not the services.
- **Cron runs** get `posix_setsid` (PR #177), so Abort reaches rsync and the helper's `ssh`.
- **No queue** (PR-C deferred): two snapshot jobs on one receiver may run at the same time. Their roots are disjoint (enforced above), so the only cost is I/O contention.

## 8. `/boot` as a push source (PR-J)

- `Job::checkLocalPath` rejects `/boot` (`FORBIDDEN_LOCAL_EXACT`, outside `/mnt`). PR-J adds one exception, in both `Job::validate` and `Runner::guardrailErrors` (parity test). Exactly `/boot` or `/boot/` is allowed as a pair's **`local` side** only when all of these hold:
  - transport SSH;
  - direction PUSH;
  - `snapshot.enabled`.
  
  Subdirectories of `/boot` stay forbidden.
- LOCAL transport ignores direction, so `/boot` must never be accepted there. A test enumerates every transport × direction × snapshot combination and asserts `/boot` can never be a destination.
- **Recommended setup** (user docs; the plugin does not hard-code these):
  - a dedicated job, local `/boot/` → sub `boot`, with its own root;
  - plain `-a` (`times` on), no `--modify-window`;
  - filters `exclude /previous/` (the previous OS copy) and `exclude /config/plugins/tailscale/state/` (any VPN identity whose copy would let the receiver impersonate the host; re-authenticate after a restore).
- FAT32 notes: the 2-second mtime granularity is harmless; if `/boot` is not mounted with a fixed timezone, a DST change can shift mtimes by 1 h and cost one full copy (~1–2 GB), which is accepted; the job's own history file on `/boot` changing mid-run is harmless.
- **Security:** the snapshot contains the license key, password hashes, SSH keys and `credentials.json`. The root is 0700 root. The user docs say never to copy it offsite unencrypted.
- Restore goes onto a new stick, from another machine, before first boot, and never writes `config/` under a running webGui. The procedure lives in the maintainer's runbook.

## 9. Migration and rollback (outline)

The plugin never migrates data. The user docs (PR-H) provide one fail-fast `sh -eu` script per job, with explicit paths and no globs:

**Migration.**
1. Disable the job and save. **[code]** `Runner::run` has no `enabled` check, so the Run button still works; also confirm that no rsync is running on the receiver.
2. Take the stamp from the last SUCCESS run's `startedAt` in History, already UTC, reformatted. Do not type a stamp by hand.
3. Create the marker (if new), the root (0700) and the sentinel (the job id).
4. Seed:
   - `mkdir <stamp>.inprogress`;
   - `mv -T <mirror> <stamp>.inprogress/<sub>` per pair, after checking that the mirror and the root share a filesystem;
   - recreate an empty, immutable tombstone directory at each old mirror path (`chattr +i`) so that a stale mirror job cannot recreate it;
   - `sync -f`;
   - `mv -T` to `<stamp>`;
   - `latest` swap;
   - `touch <stamp>/.ur-keep` (pins the pre-migration copy);
   - `sync -f`.
5. Optionally write seed metadata (`.ur-snapshot.json` with per-sub file and byte counts) so the anomaly check has a baseline. Without it, the first run logs "no baseline".
6. Switch the job to snapshot mode: root, marker, retention, `pruneDryRun` on, no `seed` (the basis exists). Saving writes v3.
7. Dry run: expect a link-dest for every sub, no `does not exist` warning, and only the delta listed. Then a real run, and verify hard links, `latest` and the `df` delta.

**Rollback (per job).**
1. Disable the job.
2. `mv -T` each `<latest>/<sub>` back to its mirror path, removing the tombstone first.
3. Rename the emptied stamp to `<stamp>.rolledback`.
4. Edit the job: snapshot mode off, absolute remote paths back in place.
5. When no job has snapshots enabled, the next save writes v2. Only then may an older release be installed.

The operational detail (backups first, verification commands, restore drills) lives in the maintainer's runbook, not in this spec.

## 10. PR breakdown (all into `feat/snapshot-mode`; the branch merges to `main` once)

**Gates per PR:** `vendor/bin/phpunit` (failOnWarning/Risky), PHPStan, php-cs-fixer, `php -l`, shellcheck, a conventional-commit title, and a CHANGELOG `[Unreleased]` line under a "Snapshot mode" heading. The final wording lands in PR-H.

**PR-E `feat: per-job snapshot settings`**
- Scope:
  - §2 data model (Config, Job, Credentials, handler carry-over);
  - validation (§2.6 table, sub rules, root rules, `snapshotRootErrors`, the `protectedTargets` extension);
  - schema v2/v3;
  - the run-time refusal `snapshot-not-implemented` at the top of `Runner::preflight`.
- No UI and no snapshot runtime.
- Tests:
  - an existing v2 config round-trips byte-identical, with the existing test unmodified;
  - enabling one job writes v3, and disabling the last writes v2;
  - v3 loads; v4 throws;
  - defaultJob stays at 15 keys;
  - every option-table row, at save time and via global save (422);
  - sub regex, reserved and case-insensitive duplicate cases;
  - root rules, root inside the marker directory, root overlap, a mirror inside or over a root;
  - `useSudo` round-trip and stale-tab carry-over;
  - Jobs-save carry-over of `snapshot`/`sub`/`seed`, and refusal when pairs changed;
  - a snapshot job is refused at run time: pre-hook not run, post-hook run, History row FAILED with `snapshot-not-implemented`, alert.

**PR-F `feat: snapshot planner and remote helper`**
- Scope:
  - pure `Snapshot.php`: stamp, regexes, calendar check, monotonic and future rules, `planPrune`, `parseStats`, `parseHelper`, `runOptions`, the remote command builder;
  - the `SnapshotRemote` exec seam (`Snapshot::$remoteRunner`, because `Ssh::runProbe` discards stdout);
  - `ur-snapshot.sh` with all operations;
  - shellcheck loop entry, AGENTS.md exception, `HELPER_SHA256` lockstep test.
- The refusal stays in place.
- Tests:
  - all §6 safety rules, decoys, `.`/`..`/`latest` subs, convergence, floor, clock skew and future names;
  - quoting (names with spaces, `'`, `$`, `;`, backticks, non-ASCII) through real `sh -c`;
  - every helper exit code;
  - a helper file that is unreadable, modified or a symlink → fail closed.

**PR-G `feat: run jobs in snapshot mode`**
- Scope: removes the refusal; §4 steps 2–13, the gate, the anomaly check, commit, prune, dry-run semantics; History fields `snapshot`/`committed`/`suspect`/`pruned`/`growthKB` (moved here from PR-I because the growth median needs them); `UR_SNAPSHOT*` env; `notifyImportance` lists.
- Tests (SnapshotRunnerTest):
  - exact call order: probe, check, list, cleanup, pre-hook, mkstamp, rsync × n, check, commit, post-hook, list, delete;
  - missing root, marker or sentinel → FAILED before the pre-hook;
  - PARTIAL, TIMEOUT and FAILED → no commit, no prune, alert;
  - abort → no commit;
  - dry run with the root missing → FAILED and no pre-hook;
  - dry run with everything fine → no mkstamp, commit or delete;
  - a new pair with and without `seed`;
  - 3 runs that do not commit leave exactly one `.inprogress`;
  - halved source → suspect, hold, pin, no prune;
  - empty source → `snapshot-preflight`;
  - seed without metadata → "no baseline";
  - exit 24 commits with a normal notification;
  - mirror job argv and env unchanged;
  - a real-rsync E2E with the helper through `sh -c`, 4+ runs, inode and link-count assertions, prune and leftover cleanup;
  - stats capture at every log level.

**PR-H `feat: snapshot fields in the Jobs form`**
- Scope:
  - the job-level block (enable, root, retention, prune dry-run);
  - pair-row `sub` and `seed` inputs, with `remote` hidden, emptied and not `required` in snapshot mode;
  - the Connections `useSudo` checkbox;
  - PHP and JS templates kept in step; help text; the README section with migration and rollback scripts. This folds in the old PR-K docs.
- Tests: FormActionTest, OptionsFormHelpTest, and form → handler → stored-shape round-trips.

**PR-I `feat: snapshot state in status, history and alerts`**
- Scope: `writeSummary`, `getStatus`/Overview/Jobs/History columns (stamp, committed, suspect, pruned); the stamp in the in-flight record and crash alert; the "not committed for N runs" text.
- Tests: HistoryTest, HandlerStatusTest, RunnerNotifyTest.

**PR-J `feat: allow /boot as a snapshot push source`**
- Scope: §8. It is in the branch because it requires snapshot mode.
- Tests: JobTest/RunnerTest parity, and the transport × direction × snapshot matrix.

**G3 lab verification before the branch merges** (a real receiver with GNU userland, a non-root SSH user with `useSudo`, a root `--rsync-path` wrapper, and `notifyMode` off on throwaway jobs):
1. Three runs of a test job whose tree has spaces, apostrophes and non-ASCII names. Check:
   - unchanged files are hard links (`find <new> -xdev -type f -links 1` ≈ changed files);
   - `latest`, stamps and metadata counts are right.
2. Abort a manual run and a cron-started run mid-transfer. Expect:
   - no commit;
   - rsync and ssh gone on both sides;
   - the next run creates a fresh stamp, reuses the stale data (small "sent"), and the older stale folder is removed.
3. Each of these fails before the pre-hook (proven by a pre-hook that touches a file): root missing, marker missing or wrong, sentinel wrong, root symlinked, root on the system filesystem, source empty, `sudo` needing a password.
4. A future-named directory in the root → `snapshot-clock`.
5. Delete half of a source sub → suspect, `.ur-hold`, previous pinned, alert. The next run warns `snapshot-held` and does not prune.
6. An unreadable source file (exit 23) → not committed, alert. A second time → "2 runs" text.
7. Prune: dry-run plan with keep 2, a backlog of 5 and cap 2, then real runs. It converges, the floor holds, and the protected entries remain.
8. A `--delete` mirror job pointed at the root's parent → refused at save time (ancestry), and at run time when hand-edited (`dest-is-protected`).
9. Downgrade drill:
   - with a snapshot job enabled, the previous release refuses the config loudly and runs nothing;
   - reinstalling the branch build restores everything;
   - with all snapshot jobs disabled (v2), the previous release works.
10. `/boot` job: two runs; the excludes are honoured; `diff -r` of `config/` from the newest stamp against `/boot/config` is clean.
11. Time a cold prune of a large hard-linked tree, and time the extra directory pass per run.
12. Restore drill: list versions and restore a single file and a directory from a stamp with `rsync -n -i`, then for real.

## 11. Decisions on the open questions (maintainer, 2026-10-21)

1. **Data on the system filesystem:** refused, no opt-out in v1. Writing into an unmounted mountpoint is the failure this feature must never repeat.
2. **Quiet log level:** snapshot jobs always run at least at `summary` (lift `quiet` like a dry run), so stats capture never depends on it. PR-G asserts stats capture at every configured level.
3. **Thresholds:** constants in `Snapshot.php` in v1, marked `[PROPOSAL; tune]`.
4. **Initialising a root:** manual, documented commands in v1. No `init` operation.
5. **Skew limit:** 120 s.
6. **`pruneDryRun` default:** stays true. No automatic reminder in v1; the admin switches it off after reviewing about 2 weeks of prune plans.
7. **`-H`:** forced off in v1.
8. **Same receiver, two connections:** scope stays per connection id; the sentinel catches the rest at run time.
9. **Plugin version in metadata:** omitted in v1.
10. **Older release after downgrade:** covered by the G3 downgrade drill (item 9); the rollback outline is only valid once that drill passes.
