# AGENTS.md

Guidance for AI agents (and humans) working in this repository.

## What this is

A **native Unraid 7.x webGui plugin** (PHP) that schedules and monitors **rsync
backup jobs over SSH** — a multi-job rsync scheduler, not a
single-schedule rsync plugin. It is written **from scratch**; it is **not** based
on, forked from, or derived from any other plugin.

## Architecture

- **`source/`** is packaged into the `.txz` and installs to
  `/usr/local/emhttp/plugins/unraid.rsync/`.
- **Persistent config** lives on the USB flash at
  `/boot/config/plugins/unraid.rsync/` (`config.json`, `credentials.json`, the
  single `unraid.rsync.cron`, `runs/<jobid>.summary.json` last-run summaries, and
  `runs/<jobid>.inflight.json` while a run is in flight, so a run a reboot cut off
  is still reported as crashed). It is reaped by status polls, the next run, and
  `scripts/reap-crashed.php`, started detached from `event/started` after
  `UR_CRASH_REAP_DELAY` (180 s) - **never from `apply-cron.php`**: the `.plg`
  runs that at boot, before notification agents can deliver, and reaping deletes
  the record, so that alert would be lost for good.
- **Runtime state + logs** live in RAM at `/tmp/unraid.rsync/` (tmpfs, cleared on
  reboot): per-run logs under `logs/<jobid>/run-<UTCts>.log`, the rolling
  cross-job `logs/plugin.log`, run state, and materialised secrets under
  `keys/<token>`, `pass/<token>`, `known_hosts/<token>`. **Logs are RAM-only by
  default** (no flash wear, mirrors Unraid's own `/var/log` tmpfs) — only the
  small `runs/<jobid>.summary.json` is persisted to `/boot`, which is why History
  survives a reboot but full log bodies don't. **Opt-in persistence:** the
  `global.logDir` setting (validated/confined to `/mnt/<top>/<leaf>` by
  `Config::sanitizeLogDir`) relocates run logs + `plugin.log` to an array/pool
  path so they survive a reboot. It's plumbed via `Logger::$logsDirOverride`
  (Logger stays decoupled from Config — the Runner and the handler front
  controller push the validated path in); empty = tmpfs. Run state + secrets
  ALWAYS stay in tmpfs regardless. `ssh-keygen`/`ssh-keyscan` scratch dirs live
  under `keygen/` in this same 0700 runtime base (`KeyTools` no longer uses bare
  `/tmp`), and cleanup never follows symlinks. The base is `Util::runtimeBase()`
  (`UR_RUNTIME_BASE`, else `/tmp/unraid.rsync`) for every class. The runtime-dir
  creators (Ssh, RunState, Logger's tmpfs chain, Notify, `KeyTools::tempDir`) all
  go through `Util::ensurePrivateDir`: one level at a time, never through a
  symlink, chmod 0700 only on a level it created, then refuse a dir another uid
  owns or group/others can write (`Util::assertPrivateDir`): under `/tmp` a local
  user can pre-create it, and root's `chmod 0700` leaves them its owner. An
  EXISTING runtime dir is never chmodded either, so one root owns with group or
  world write is refused rather than tightened. A missing
  logDir/secretsDir level is created by `Util::mkdirs`, which refuses a
  symlinked existing ancestor below `/mnt` and chmods only the levels it made:
  an EXISTING logDir or secretsDir is never chmodded (it may be a share). The
  run state carries the run's secrets `token`, so `Runner::reportCrashed`
  sweeps a SIGKILLed run's `keys/`, `pass/`, `known_hosts/` files
  (`Ssh::sweepRuntime`, only through private runtime dirs).
- **UI** = a parent hub `source/UnraidRsync.page` (`Menu="Utilities"` →
  **Settings ▸ User Utilities**, `Type="xmenu"`, **empty body**) plus child tab
  pages `source/UR.*.page` (`Menu="UnraidRsync:1..7"` → **Overview / Jobs /
  Connections / Credentials / Global Settings / Status / History**), each
  including a `source/pages/*.php` body. **Connections** (host/port/user/auth
  cards) and **Credentials** (the managed SSH-key keychain) are SEPARATE
  tabs/bodies (`connections.php` / `credentials.php`); a connection references a
  managed key by id via its KEY auth method. **Overview** (`overview.php`) is a
  read-only status board. All seven tabs render into ONE document, so every
  tab's script runs whichever tab is shown: Overview, Jobs, Status and History subscribe
  to the ONE `getStatus` poller (`window.urStatus`, `ur_emit_status_poller()`;
  2 s while a job runs, 15 s idle, paused while the document is hidden), and
  shared CSS/JS is emitted once by the `ur_emit_*` helpers. Never add a per-tab
  polling loop or a per-tab copy of a shared helper. Page bodies read the config
  and a job's running state through `PageHelpers::config()` / `running()`,
  memoised for the request, never `Config::load()` / `RunState::isRunning()`.
- **Dashboard widget:** `source/UR.Dashboard.page` uses `Menu="Dashboard"` (NOT
  the `UnraidRsync` xmenu) and sets `$mytiles['unraid.rsync']['column1']` to a
  self-contained tile string (markup + scoped CSS + a `getStatus` poller) that
  Unraid's `dynamix/DashStats.page` echoes into the dashboard. This `$mytiles`
  contract is undocumented but stable since 6.12; the body branches on the
  Unraid version for the pre-7.2 header fixup.
- **Backend classes** in `source/include/`: `Config`, `Credentials`, `Job`, `Ssh`,
  `Rsync`, `RunState`, `Runner`, `Logger`, `Cron`, `Notify`, `KeyTools`,
  `History`, `Snapshot`, `ProcIO`, `Util`, `PageHelpers` (the pure display rules
  the pages and `getStatus` share), and `handler.php` (the POST/GET front
  controller). Plus `source/scripts/{runner,apply-cron,reap-crashed}.php`, the
  `askpass.sh` password helper and `ur-snapshot.sh` remote snapshot helper in
  `source/scripts/`, and the `source/event/started` array-start hook.

## Hard conventions / invariants

- **Slug `unraid.rsync` is identical everywhere** — `.plg` name, install dir,
  `/boot` config dir, and cron pickup. Do not diverge it.
- **Build rsync/ssh invocations as argv ARRAYS, never shell strings**, and
  run them through `proc_open` without a shell. The ONLY intentional shell uses
  are: `Notify` exec (every arg `escapeshellarg`'d, incl. `-i`), user pre/post
  **hooks** (`bash -c "$hook"`), the detached runner launcher, the detached
  **ssh-keyscan** launcher (`KeyTools::runKeyscan`, `setsid sh -c …` via `exec`,
  every element `escapeshellarg`'d), **update_cron** (`Cron::defaultRunUpdateCron`
  hands `proc_open` a STRING - the escaped fixed path - because execvp cannot
  run it on a live box; `/bin/sh` supplies the ENOEXEC fallback), and the
  **snapshot remote helper**: `Snapshot::remoteCommand()` builds the one command
  string ssh hands the receiver's shell from the shipped
  `source/scripts/ur-snapshot.sh` (sha256-pinned by `Snapshot::HELPER_SHA256`),
  every element of `[sudo -n] sh -c <script> ur-snapshot <op> <args>` passed
  through `escapeshellarg`. The ssh itself is still an argv array.
- **Password auth uses OpenSSH's `SSH_ASKPASS`, NOT `sshpass`** — the old
  dependency told users to install **NerdTools**, archived by its authors in
  March 2024 and gone from Unraid 7, so password auth was advertised in the UI
  while being impossible on a stock box. `Ssh::buildAuthEnv()` sets
  `SSH_ASKPASS` → the shipped `source/scripts/askpass.sh` (committed **0755**,
  like `source/event/started`; it lives in the install dir, NOT tmpfs, so a
  `noexec` `/tmp` cannot break it), plus `SSH_ASKPASS_REQUIRE=force` and
  `DISPLAY` for pre-8.4 OpenSSH. The helper cats the existing per-run 0600
  tmpfs passfile named by `UR_ASKPASS_FILE`, so **the password still reaches
  neither argv nor env — only its path does.** Do not re-add a wrapper binary:
  ssh is `argv[0]` for every auth method now, which is why `classifyProbe` has
  one set of ssh semantics instead of a separate sshpass exit-code table.
- **rsync flags are a closed whitelist** (`Rsync::BOOL_FLAGS` / `SCALAR_FLAGS` /
  `FILTER_FLAGS`, mirrored by `Job`'s normalisation and the options form). There is
  **no free-form flag field** anywhere — never add one.
- **Only two things in the emitted argv are order-sensitive, and both are
  load-bearing** (everything else is emitted at most once and rsync ignores its
  position — do NOT add reordering UI for other flags):
  1. **Filter rules.** rsync builds ONE ordered filter list and acts on the FIRST
     rule that matches. They are stored as an ordered `filters` list of
     `{type, pattern}` (NOT separate `excludes`/`includes` lists — that shape
     made every include inert behind a broad exclude, issue #128) and
     `Rsync::optionTokens()` emits them verbatim in stored order. **Never sort,
     group by type, or dedupe them.**
  2. **`-a` negations.** `-a` sets its implied options at PARSE TIME, so omitting
     an unticked option's positive flag does nothing — it must be negated with an
     explicit `--no-*` emitted **after** the `-a`. `Rsync::ARCHIVE_IMPLIED` maps
     each implied key to its negation; `Config::ARCHIVE_IMPLIED_KEYS` holds the
     same keys for the migration (Config cannot depend on Rsync), and `RsyncTest`
     asserts the two stay in lockstep.
- **`config.json` is schemaVersion 2, or 3 while any job has `snapshot.enabled`**
  (`Config::SCHEMA_VERSION` = 2, `MAX_SCHEMA_VERSION` = 3; `schemaVersionFor()`
  picks the stamp on every `mergeDefaults()`, so an older plugin refuses a v3 file
  instead of running a snapshot job as a mirror). `Config::migrate()` has a real
  `case 1:` arm: it folds `excludes`/`includes` into ordered `filters` (includes
  first) and, where `archive` is on, forces every `ARCHIVE_IMPLIED_KEYS` option
  true so an upgrade does not silently start emitting `--no-perms`/`--no-links`
  and change what existing backups preserve. v2 → v3 needs no transform (v3 only
  marks the snapshot block). Migration MUST keep running before
  `mergeDefaults()` (which drops unknown keys).
- **Only a MISSING `config.json` / `credentials.json` is a fresh install.** A
  present-but-empty or whitespace-only file throws like malformed JSON: a FAT32
  write cut short used to load as zero jobs, and `apply-cron.php` then deleted
  the cron file. Saves go through `Util::writeAtomic` (temp file, `fsync`,
  rename) and first keep the replaced file as `<name>.last-good` when it parses.
  The parent directory is never fsynced after the rename (PHP cannot fsync a
  directory handle), so a power cut can still lose the rename itself; the
  recovery story is the ordering (destination copy → move record → config
  pointer → source unlink) plus `.last-good`.
- **Every load-modify-save holds a lock.** `config.json` under
  `Config::withLock` (`config.lock`), `credentials.json` under
  `Credentials::withLock` (`credentials.lock`); both locks live in
  `UR_CONFIG_BASE` and never follow `secretsDir`, so a relocation and every
  writer contend on one file. Order: config, then credentials. Handler
  credential actions and `Ssh::pinHostKey` re-read the `secretsDir` pointer
  once they hold the lock (`Credentials::refreshSecretsDir`, which never takes
  the config lock). Every `Credentials::withLock` scope first settles a move a
  crash interrupted (`reconcilePendingMove`, from the `credentials.move.json`
  record in `UR_CONFIG_BASE`): pointer at the destination → remove the source's
  `.last-good`, then the source, then the record, only while both files still
  hash to the record; pointer still at the source and the source still the
  recorded copy → drop the record and the uncommitted copy; anything else
  (including a source that is gone) → keep it all and log once.
  `sanitizeMntDir` canonicalises (`Config::canonicalPath`), and the move itself
  probes for one directory under two names (`ur_dirs_alias`, BOTH ways: a
  marker made in each dir, looked for in the other - shfs `/mnt/user` vs
  `/mnt/cache` has different inodes, realpath cannot see it, and shfs may show
  only one direction). Either direction seeing it → pointer-only, never unlink;
  directions disagreeing while a destination credentials.json exists → refused.
  `config.json` carries a `revision` (absent = 0) that `Config::save()` bumps on
  every change; the Jobs/Settings forms post it as `configRevision` and
  `saveConfig` answers 409 when it is stale (absent field = old client, allowed).
- **Secrets** (SSH keys, passwords) live in `credentials.json`, by default on
  **FAT32 `/boot` (world-readable)**, so Unix perms don't protect them there. They
  are copied to **tmpfs at `chmod 600`** immediately before use (OpenSSH refuses a
  world-readable key) and cleaned up in a `finally`. Passwords are **XOR-obfuscated
  (reversible, NOT encryption)** — always document them as such. **Never return
  secrets to the browser.** **Opt-in relocation:** the `global.secretsDir` setting
  (validated/confined to `/mnt/<top>/<leaf>` by `Config::sanitizeSecretsDir`, which
  shares `Config::sanitizeMntDir` with `sanitizeLogDir`) moves `credentials.json`
  to an array/pool path where `chmod 600` actually sticks. It's plumbed via
  `Credentials::$secretsDirOverride` (Credentials stays decoupled from Config — the
  Runner and the handler front controller push the validated path in, mirroring
  `Logger::$logsDirOverride`); empty = `/boot`. Changing it migrates the file
  as one transaction, only after every validation passed
  (`ur_migrate_credentials`: private 0600 copy into a 0700 dir + sha256 verify,
  then the move record, then the `Config::save` of the new pointer, then
  `Credentials::completeMove`; a failed save removes the copy and the record;
  a migration with no source file writes no record; never clobbers a DIFFERENT
  existing dest, never `rename` across the FAT32↔ext4 device boundary). The
  **tmpfs materialisation is NOT removed** by this (still needed for a discrete
  `ssh -i` key file + per-run isolation + redaction). Per-run tmpfs secrets +
  run state ALWAYS stay in tmpfs regardless. A NEW secretsDir is refused
  (`Config::secretsDirRefusal`, save time only, so a stored pointer keeps
  loading) under `/mnt/remotes` or `/mnt/addons`, at a share or pool root
  (`/mnt/<top>/<share>`), or when it exists but another uid owns it or
  group/others can write it.
- **Host-key pinning (`accept-new`) is a real trust-on-first-use now.**
  `Ssh::harvestHostKey()` reads back the key OpenSSH appended to the per-run
  tmpfs known_hosts, and `Ssh::pinHostKey()` writes it into the connection's
  `remoteHostKey` (reload-modify-save, only while still empty) from both
  `Runner::run()`'s `finally` (before `cleanupRuntime`) and
  `Ssh::testConnection()`. Before this, nothing was ever pinned and every run
  trusted whatever key the host presented. The pin is a compare-and-set on the
  endpoint too: `pinHostKey($connId, $key, $host, $port)` takes the host:port
  the key was harvested from and refuses once the stored connection points
  elsewhere, and a save that changes host or port while posting the old pin
  back (or blank) clears it - an old pin never matches the new host, so
  accept-new would otherwise accept ANY key on every run.
- **SSH host and username are re-split by rsync**: `user@host:path` breaks at
  the first `:` and a `/` makes the operand local. `Credentials::isSafeSshHost`
  (host name / IPv4, or a BRACKETED IPv6 literal, `%scope` allowed - see
  `Util::isIpv6Address`, since FILTER_VALIDATE_IP refuses `fe80::1%eth0`) and `isSafeSshUser`
  (POSIX-style) run at save AND in `Ssh::materialize` (credentials.json is
  hand-editable). `mergeConnection` stores a bare IPv6 host bracketed; ssh
  itself (Test connection, the snapshot helper, ssh-keyscan) gets it unbracketed.
- **Probes are bounded; transfers are not.** The ssh Test connection and
  `rsync --version` run through `ProcIO::capture` (both pipes drained, 64 KiB
  per stream, TERM then KILL then a non-blocking reap within the deadline). Every
  kill goes through `ProcIO::terminate` (TERM, the caller's grace, KILL, up to
  `ProcIO::$killGraceSec`, then `proc_close` only once the child has exited; one
  that survives SIGKILL is abandoned, never waited for); hooks pass a signaller
  so their whole process group is killed. A
  DAEMON transfer whose `timeout` option is blank gets
  `--timeout=900` (`Rsync::DAEMON_DEFAULT_TIMEOUT`, emitted by `optionTokens` so
  the preview agrees; `0` still means wait forever), and the daemon marker and
  `--delete` probes get a wall clock. Never put a wall clock on a real transfer.
- **Timezone: store UTC, display system-local.** Two conventions, both
  deliberate (issue #135). **Stored/interchange** values stay UTC: the
  `startedAt`/`finishedAt` in `runs/<jobid>.summary.json` + History records
  (`gmdate('Y-m-d\TH:i:s\Z')`) and the `run-YYYYmmddTHHMMSSZ.log` filenames —
  the filename is an identifier that must sort lexically and never repeat across
  a DST fold, so **never** localise it. **Everything a user reads** is the
  server's timezone: `Logger::event()` stamps lines as `date('Y-m-d\TH:i:sP')`
  (offset kept, so it stays unambiguous) to match the rsync lines interleaved
  beside them in the same file. `Config.php` resolves the system zone with
  `Config::systemTimezone()` — `$TZ` → Unraid's `ident.cfg`/`var.ini` `timeZone`
  → the `/etc/localtime` (or `/etc/localtime-copied-from`) zoneinfo symlink →
  ambient, first one valid in `DateTimeZone::listIdentifiers()` wins, else UTC —
  and calls `date_default_timezone_set()` with it **at file load**; it is the ONLY
  place that does. **Do not drop the ident.cfg rung**: php-fpm may scrub `$TZ` and
  Slackware may write `/etc/localtime` as a plain copy rather than a zoneinfo
  symlink, so without it a stock box can miss every source and fall back to UTC —
  silently reproducing #135 while looking fixed. The Runner logs the resolved zone
  at the top of every run and the UI labels it (`ur_tz_note()`) precisely so that
  fallback is visible rather than invisible,
  and it is why `Cron::nextRun()`'s `date()` + `DateTimeImmutable` (`localTs()`)
  walk in the process zone is correct: PHP
  defaults to UTC whenever php.ini leaves `date.timezone` unset, which would
  compute every next-run a full UTC offset away from when crond actually fires.
  Config.php is required transitively by every entry point (pages, `handler.php`,
  `scripts/runner.php`, `scripts/apply-cron.php`), which is what makes one call
  enough. **Client-side, never use the browser's zone** — `new Date(...).getHours()`
  renders wherever the admin's laptop is, which disagreed with the PHP-rendered
  cells. All JS formatting goes through `window.urFmtLocal` from
  `ur_emit_time_helpers()` (`_options_form.php`), pinned to the `UR_TZ` the server
  emits.
- **`--log-file-format=` (EMPTY) is the ONLY lever on per-file log lines.**
  `-v`/`-q` control rsync's STDOUT; the `--log-file` fd has its own format
  (default `%i %n%L`) and writes a line per transferred file **regardless of
  `-v` or `-q`** — verified against rsync 3.5.0, where the `quiet` level still
  produced a full per-file listing. So the two quiet levels (`quiet`, `summary`)
  emit `--log-file-format=` in `Rsync::logLevelFlags()`; `summary` also carries
  **no `-v`** (nothing per-file on stdout either) and **no `stats2`** (the
  log-file fd already writes the one-line sent/received summary, so `stats2`
  would print the whole block a second time). Do not "fix" a noisy log by
  adding or removing `-v`. **A dry run is never quieter than `normal`**
  (`buildArgv`'s `$effectiveLevel`, the only place a level is overridden): the
  quiet levels suppress exactly the per-file lines a dry run exists to show, and
  at `summary` a `--delete` preview named neither a file nor a deletion nor even
  a count. What the quiet levels do NOT hide is errors (they arrive twice —
  rsync's own log-file line and captured stderr) or `deleting <path>` lines:
  the empty format only kills the `%i %n` per-file format, and rsync's delete
  path falls back to a plain `deleting <path>`. So there is no data-safety
  regression on `--delete`.
- **`Logger::sink()` is line-aware and throttles progress.** `--info=progress2`
  redraws one status line with a bare `\r` several times a second and the
  capture path is byte-oriented (`ProcIO` hands raw 8 KiB `fread`s), so the old
  verbatim append smeared thousands of redraws into one unreadable line. The
  sink writes `\n`-terminated lines through unchanged and keeps at most one
  `\r` redraw per **5 % step or 30 s** (`PROGRESS_MIN_PCT`/`PROGRESS_MIN_SECS`),
  plus 100 % once unconditionally (the step rule alone drops it: |100-99| < 5,
  and the line that follows supersedes whatever the throttle is holding).
  Three traps, each paid for once:
  1. **A `\r` that is the last byte held stays in the buffer.** Until the next
     byte arrives it is unknowable whether it ends a redraw or is the first half
     of a `\r\n`, and an fread cuts anywhere. Deciding early ATE THE LINE — the
     `\r` was read as a redraw and dropped by the throttle, its orphaned `\n`
     writing a blank line. `LoggerTest` replays one stream at chunk sizes
     1..8192 and asserts the log is byte-identical, so never reintroduce a
     per-chunk `str_replace("\r\n", "\n")`.
  2. **The throttle keys off the ABSOLUTE change in percentage, never a
     high-water mark.** progress2's figure is not monotonic — incremental
     recursion grows the denominator, so it drops and can touch a spurious 100 %
     early — and a high-water mark let one early 100 % kill the 5 % rule for the
     rest of the run, silently degrading the promise to "one line per 30 s".
  3. **rsync writes the `\r` as a PREFIX** (`\r<p1>\r<p2>…\r<pN>\n`, exactly one
     LF, at the very end), so the last fragment always waits in the buffer for
     it. That is why the flush is load-bearing, not belt-and-braces, and why a
     fixture modelling `\r` as a suffix tests the wrong shape — that mistake cost
     a whole release. rsync also REPEATS that final redraw, and because the last
     copy carries the only `\n` the repeat arrives on the **real-line** path, not
     the flush path. Both paths therefore drop a redraw byte-identical to the one
     just written (`$st['last']`, cleared on every real line so it can never
     swallow an unrelated later line). Byte-exact only: a repeat that differs in
     rate, ETA or `to-chk` is new information and must still land.

  Because it buffers to line boundaries, the Runner **must** call
  `Logger::flushSink($runLog)` once the child has exited — it does, after
  `Rsync::run` (before `enforceRunLogCap`), in a `finally` around the hook run,
  and at the **top** of the run's outer `finally`. That last one has to precede
  the postHook block: `runHook()` opens a new sink on the same path and `sink()`
  RESETS the per-path state, so a flush placed after the hook would find nothing
  left to rescue. Anything feeding the sink a string with no trailing newline
  and reading the file back immediately needs that flush.
- **Log bounds (all RAM by default).** One run log: `UR_MAX_RUN_LOG_BYTES`
  (16 MiB). The sink stops appending once the file is over it (one marker), and
  because rsync's `--log-file` bypasses the sink, the Runner also calls
  `Logger::enforceRunLogCap` every `Runner::$logCapCheckSec` (2 s) WHILE a pair
  runs (`Rsync::run`'s `$stopWhen`), not only after it. Trimming a live log is
  safe because rsync opens `--log-file` with `fopen("a")` (O_APPEND): its next
  line lands at the new end, not past it (confirmed on rsync 3.4.4). Hard
  ceiling: only when a trim FAILED (`enforceRunLogCap`'s `$failed`: the file
  could not be opened, locked, truncated or marked) AND the log is over
  `LOG_CEILING_FACTOR` (4) × the cap is the pair stopped; the run ends WARNING
  with reason `log-cap-exceeded`, and the next pair still runs. A log that grew
  again after a successful trim is never stopped: size alone would kill a
  healthy big backup at `normal` (one `--log-file` line per file) whenever PHP
  was descheduled after a truncate. `Logger::event` / `append` never throw
  (they return false): the Runner logs outside its `try`, and a throw there
  would skip the post-hook. One job: `UR_MAX_JOB_LOG_BYTES` (256 MiB) - `pruneRuns` deletes the
  oldest logs past it after the count prune, never the newest or the current
  run's. `plugin.log` rotates to `plugin.log.1` (one generation) past 1 MiB,
  under the sibling `plugin.log.lock` (a lock on plugin.log itself is renamed
  away with it). Run-log names are unique: a second run in the same second gets
  `run-<UTCts>-2.log` (`openRun` creates with `fopen('x')`, past the highest
  suffix in use), and `RUN_FILE_REGEX` / `compareRunNames` accept and order it.
- **History** (`runs/<jobid>.history.jsonl`): `append` and `prune` hold the
  sibling `<jobid>.history.lock` (`Util::withLock`), never the data file: prune
  replaces it by rename. `append` first ends a partial last line with `\n`.
  `writeSummary` / `History::append` return false on failure and the Runner logs
  ONE `plugin.log` line (falling back to `error_log`). A scheduled run that finds
  the job still running is recorded as state `SKIPPED` (History only, no
  summary, so `getStatus` keeps the last real outcome), notifies at normal
  importance and never under success-only.
- **The last-run summary has two slots.** `runs/<jobid>.summary.json`'s top
  level is the LAST EXECUTION of any kind, in the shape older readers know
  (`state, startedAt, finishedAt, exitCode, durationSec, dryRun, trigger`, plus
  `reason` when set and the snapshot fields); `lastReal` is the last NON-dry
  run's `{state, startedAt, finishedAt, exitCode, reason}` (null: none yet). A
  dry run rewrites only the top level; a real run both. A summary written
  before `lastReal` existed reads as its own last real run, or, when it was a
  dry run, as the newest non-dry, non-SKIPPED History record - the real
  outcome that dry run overwrote (`Runner::lastRealFor`, every retained record
  searched). That answer, null included, is written into `summary.json` under
  the summary lock the first time it is resolved (never over a summary that
  changed meanwhile), so History is read once per job, not on every poll. The
  badge, `getStatus.state`, the Dashboard and the Last-run time all come from
  `lastReal`, so a successful Dry-run can never paint a failed backup green; a
  dry run shows as a neutral second line (`PageHelpers::lastDryRun` /
  `dryRunLine`, "Dry-run OK · <time>", "Dry-run failed · …", JS mirror
  `window.urUtil.dryRunLine`, a muted suffix on the Dashboard). `getStatus`
  keeps `lastRun` (the top level) and adds `lastReal` and `lastDryRun`
  (`{label, finishedAt, state}`; the JS translates by `state`).
  `writeSummary` is a load-modify-save under `runs/<jobid>.summary.lock`.
  A runner that died (`reportCrashed`) writes state `CRASHED` (summary only;
  its History record stays `FAILED`/`crashed`) and a scheduled hard failure
  writes `FAILED` with its reason, both with `exitCode` null and `$keepNewer`:
  neither slot is replaced by a record that started earlier than what is
  there (a live or later run wins).
- **Teardown steps are independent.** `Runner::run`'s `finally` runs flush,
  post-hook, snapshot prune, host-key pin, secret cleanup, log redaction,
  summary, History, in-flight cleanup, mark-stopped and abort-flag clear each
  through its own try/catch (`$step`): a throw logs ONE line (run log +
  `plugin.log`) and the next step still runs; notify and the lock release
  never throw. A summary, History or run-state failure (thrown or `false`)
  turns a SUCCESS into FAILED with reason `teardown-failed` (other states keep
  theirs), and what was already written before that failure is rewritten to
  match while the run lock is still held: the summary again, the History
  record in place (`History::amend`, under the History lock, found
  newest-first by `startedAt` + `logRef` - never by position: a scheduled
  launch's SKIPPED record, appended without the run lock, can follow it in the
  same second). A post-hook that could not run counts as a failed post-hook. The
  flush stays first and the pin stays before cleanup. Test seam:
  `Runner::$teardownFault`.
- **Only a user's Abort for THIS launch is ABORTED.** The abort flag
  (`state/<jobid>.abort`) is JSON `{by: user|signal, at, gen}`. `runJob` takes
  the run lock, records a launch generation in the run-state record
  (`RunState::beginLaunch`) and passes it as `--gen=`; a scheduled run makes
  its own. `abortJob` writes `by: user` with the generation it reads from the
  run-state record, before it signals. The runner writes its state, THEN drops
  a flag for any other generation (`clearStaleAbort`), so an Abort clicked
  before it started is honoured (ABORTED before anything transfers) and a
  stale one is not. `clearStaleAbort` renames the flag aside and judges only
  that copy, putting ours back with `link()` (never over a newer flag): a
  read-then-unlink of the one path deleted an Abort for this launch that
  landed in between (seam `RunState::$afterStaleAbortRead`). Its SIGTERM/SIGINT trap writes `by: signal`, never over an
  existing flag. A run that ends ABORTED (flag, exit 20, or 143) without a
  user flag for its generation (`RunState::abortedByUser`; a flag with no
  generation counts) is FAILED with reason `terminated`, before the post-hook
  sees it. An abort during the pre-hook is ABORTED, not `prehook-failed`.
- **`Cron::nextRun` models dcron 4.5, not vixie-cron.** Unraid 7.3.2 runs
  dillon's cron 4.5 (Slackware's `dcron-4.5` + patches that do not touch
  parsing); source: github.com/dubiousjim/dcron tag `v4.5`, `database.c`
  `ParseField`, `FixDayDow` and the `TestJobs` match. "N/step" is N alone
  (`5/20` is :05); `*` in day-of-month starts at day 0, so `*/2` there is days
  2,4,…,30. The day rule goes by VALUES: a day-of-week covering all 7 days →
  the day-of-month set; else a day-of-month covering 0-31 or containing day 0
  (a `*`-form) → the day-of-week set; else (a numeric day-of-month with a
  restricted day-of-week, `0 3 1 * 1`) → never: `FixDayDow` shifts by
  `(0-1)%5` for the missing day 0 and clears every weekday, so `nextRun`
  returns null (em-dash). Such schedules still save. `CronTest`'s dcron cases
  were taken from those functions compiled and run verbatim.
- **A manual-only job has NO next run, and `getStatus` must say so.** `enabled`
  + `manualOnly` is exactly how "run on demand" is stored, and such a job keeps
  whatever `schedule` string it was last saved with — so anything that computes
  a next fire from that string is reporting a time that will never happen
  (`Cron::buildContent()` skips the job). `getStatus` carries `manualOnly` and a null
  `nextRun` for it, and both renderers must agree, in this clause order:
  manual-only, then disabled, then no computable next (em-dash), then the time.
  They are `PageHelpers::nextRunLabel()` (PHP, the Jobs server render) and
  `window.urUtil.nextRunLabel()` (JS, `ur_emit_page_assets()`, used by Jobs and
  Overview) — the JS one runs every second on top of the PHP one, so a clause
  missing there silently overwrites a correct cell. `PageHelpersTest` and
  `OptionsFormHelpTest` pin the order on both sides.
  `Runner::run` re-applies Cron's rule: a `--trigger=schedule` run of a disabled
  or manual-only job (a stale line after a failed `update_cron`) only logs to
  `plugin.log` and exits 1 with reason `not-scheduled` — no state, History or
  notification.
- **Delete and mount safety.** The `--delete` overlap rules compare destinations
  per resolved ENDPOINT, not per connection id: `Job::remoteScope()` is
  `host:port` for SSH and DAEMON (not the user: an absolute path is the same
  directory whoever logs in; a daemon path starts with its module), resolved through the loaded credentials - so callers pass
  `$creds` to `protectedTargets` / `ancestryErrors` / `snapshotRootErrors`; the
  connection id is only the fallback for an unresolvable one. Two pairs of one
  delete job may not share or nest destinations (`Job::pairOverlapErrors`): they
  run as separate transfers. At run time `preflight()` (before the pre-hook)
  lists every remote `--delete` destination, SSH and DAEMON push, and any listing
  failure refuses the run; `Runner::localChecks()` runs **after** the pre-hook
  (which may mount the storage) and refuses a local source or destination whose
  first existing ancestor is on the device of `/` or `/mnt` (both RAM; `/mnt`
  may be its own tmpfs) (`Util::isOnRootFs`, seam `Util::$devIdOf`): `--mkpath` is on by default, so an unmounted
  `/mnt/disks/<x>` destination was being backed up into RAM as SUCCESS, and an
  empty mountpoint is a valid source a mirror empties the backup to match. With
  a delete option on it also refuses an empty local source (`source-empty`; a
  **dry run only logs it**, so the preview still shows the damage) and a local
  destination holding a `PROTECTED_ENTRIES` name or one that exists but cannot
  be listed (`dest-probe-failed`; only a missing one is fine, seam
  `Runner::$localLister`). `tests/bootstrap.php` makes
  every path but `/` its own device, so tests that need "not mounted" set the seam.
- **NEVER name a form input `action`.** An input named `action` becomes a NAMED
  PROPERTY of its `<form>`, so `form.action` returns that input instead of the
  URL string. Unraid's own layout JS runs
  `$('form').each(function(){ $(this).prop('action').actionName(); … })` over
  every form on the page (`DefaultPageLayout/BodyInlineJS.php`, the
  escapeQuotes parser; `String.prototype.actionName` comes from
  `HeadInlineJS.php`) — with the property shadowed that throws
  `$(...).prop(...).actionName is not a function` and aborts the rest of
  Unraid's ready handler, taking its leave-confirmation guard with it. Seen on
  7.3.2 at every page load AND every Apply. The handler action therefore travels
  as `data-ur-action="<x>"` on the form and `urAjax.postFormElement()` copies it
  into the POST body, so the wire protocol is unchanged (`action=<x>`); the same
  shadowing is why that helper reads `form.getAttribute('action')` for the URL
  rather than `form.action`. `FormActionTest` guards all of it.
- **HTML-escape all output**; the log viewer renders `Logger::tail()` /
  `Logger::readFrom()` output, which is already escaped (log-XSS guard).
  **`_()` and `ur_t()` return HTML, not text**: Unraid's `_()` turns `'` into
  `&apos;` and `*x*` into `<i>x</i>`, so `ur_h(ur_t(...))` printed a literal
  `&apos;`. Emit `_()` output raw; anything that must be escaped (attributes, JS,
  a mix with user data) takes `ur_t_text()` and then `ur_h()`. **A string a
  script renders goes through `urI18n('key', 'English', ...args)`**, never a bare
  literal: the key lives in `PageHelpers::I18N` (`i18n()` adds `state.*` /
  `dryRun.*` from `STATES`), `ur_emit_i18n()` sends it once as `UR_I18N`, and the
  PHP renderers read the same entries through `PageHelpers::t()`, so on a
  translated webGui the first poll no longer repaints labels in English.
  `OptionsFormHelpTest` checks every call site against the map. Captured run output is also **redacted** of
  per-run tmpfs secret paths and **size-capped** before it is written
  (`Logger::setRedaction` / `Logger::sink` / `UR_MAX_RUN_LOG_BYTES`).
- **CSRF**: every state-changing POST verifies the webGui `csrf_token` via
  `hash_equals`; GET pollers take no CSRF token and are reads, with ONE
  intentional side effect: any GET that calls `RunState::isRunning`
  (`getStatus`, `getJobLog`, `listHistory`) → `reapStale` →
  `Runner::reportCrashed` records a dead runner exactly once (appends a
  `crashed` History record, writes the `CRASHED` summary unless a later run
  already wrote one, notifies, deletes the in-flight record) - plus, once per
  job after the upgrade, the `lastReal` write-through above. The check
  is **match-ANY** across all server-side-trusted token sources (`$GLOBALS['var']['csrf_token']`,
  `$_SESSION['csrf_token']`, and `var.ini`) — `ur_csrf_token_candidates()` /
  `ur_check_csrf()`. **Do NOT revert to "first non-empty source wins":** on the
  live box a stale `$var`/`$_SESSION` token masked the canonical `var.ini` token
  and 403'd the *correct* token; match-any is the fix (every candidate is a token
  the page legitimately echoes).
- **Client AJAX POSTs are `application/x-www-form-urlencoded`, NEVER multipart.**
  All POSTs go out as `URLSearchParams` via the shared `window.urAjax` helpers
  (`source/pages/_options_form.php`), which `credentials.php`, `status.php` and
  the other pages call too. **Never use a `FormData` object as a fetch
  `body`** (use `URLSearchParams(new FormData(form))` to serialize a form):
  multipart request bodies **stall in php-fpm** on the live box (the worker blocks
  forever in `skb_wait_for_more_packets` waiting for the body over the FastCGI
  socket), which hung *every* plugin POST; urlencoded returns in ~13ms. There are
  **no file inputs** anywhere (SSH keys are pasted into textareas), so urlencoded
  is correct and sufficient; nested names (`jobs[0][pairs][0][local]`) urlencode
  fine and PHP parses them into `$_POST` arrays.
- **Corrected root cause (live-diagnosed):** the earlier "discovery session-lock
  wedge" (PR#24) was a partial misdiagnosis. POSTs hung because of **multipart
  bodies stalling at the FastCGI layer** (+ the CSRF stale-token mismatch above),
  not session locking. PR#24's `session_write_close` / detached keyscan are kept
  as harmless hardening; the *actual* fixes are urlencoded client POSTs + CSRF
  match-any.
- **Path inputs** go through the confinement helpers (`ur_safe_job_id` / `safeId` /
  `Logger::runLogPathById`) — both the "latest" and "by id" run-log resolvers
  share `runLogPathById`.

## Release / versioning

- **CalVer**: `YYYY.MM.DD` plus a same-day lowercase suffix (`a`, `b`, …), ordered
  for Unraid's `strcmp`-based update detection. **Do NOT switch to semver** —
  `1.10.0` would sort *before* `1.9.0` and break update detection.
- Every merge to `main` **auto-publishes a GitHub Release**
  (`.github/workflows/release.yml`, built inside `aclemons/slackware:15.0`),
  attaching the `.txz` and a regenerated `.plg`. `pluginURL` points at
  `releases/latest/download/unraid.rsync.plg`. Manual releases via
  `workflow_dispatch`. The version is auto-computed — do not hand-bump it in a PR.

## Branch protection / CI

- PRs are **required** (0 approvals). Required checks: **`lint`** and **`PHPUnit`**.
- **`lint`** = `xmllint` on the `.plg`, `bash -n` + `shellcheck` on `pkg_build.sh`,
  and `php -l` on every PHP file and every `.page` PHP body.
- **`PHPUnit`** = the unit-test suite.
- **Runtime PHP = 8.4** (Unraid 7.3.1 ships PHP 8.4.21); **CI runs PHP 8.4** —
  keep the two in sync (`.github/workflows/lint.yml`). The suite runs under
  `failOnWarning` / `failOnRisky`, so fix the underlying code for any 8.4
  deprecation rather than silencing it.

## Dev / test commands

```bash
composer install --no-interaction
vendor/bin/phpunit
```

Tests are I/O-isolated via the `UR_CONFIG_BASE` / `UR_RUNTIME_BASE` env/constant
overrides plus stubs in `tests/bootstrap.php`. The `tests/` directory is **not
packaged** (excluded from the `.txz`).

## Gotchas

- **Uninstall wipes data.** Removing the `.plg` runs
  `rm -rf /boot/config/plugins/unraid.rsync`, which **deletes jobs + credentials**.
  Installing over the top preserves them.
- **Cron pickup is non-recursive.** Unraid's `update_cron` globs **top-level
  `*.cron`** in the plugin's boot dir, so the single `unraid.rsync.cron` must live
  directly there (not in a subdirectory).
- **The parent `.page` body MUST stay empty.** A non-empty `UnraidRsync.page` body
  becomes a blank phantom tab.
- **Live-box validation must not page the admin or touch accounts.** A scratch
  `UR_CONFIG_BASE` isolates config/runs/history, but `Runner::notifyHook` still
  execs the real webGui `notify`, so every throwaway job that fails lands as an
  alert in the Unraid bell (2026-09-04: five "FAILED" alerts from the rsyncd
  validation). Stub `Notify::$runner = fn() => 0` (the PHPUnit seam) or set
  `notifyMode: off` on every throwaway job. Never create webGui users or append
  to `/root/.ssh/authorized_keys` on the live box; use the existing Tailscale
  SSH access.
