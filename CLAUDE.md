# CLAUDE.md

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
  single `unraid.rsync.cron`, and `runs/<jobid>.summary.json` last-run summaries).
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
  ALWAYS stay in tmpfs regardless.
- **UI** = a parent hub `source/UnraidRsync.page` (`Menu="Utilities"` →
  **Settings ▸ User Utilities**, `Type="xmenu"`, **empty body**) plus child tab
  pages `source/UR.*.page` (`Menu="UnraidRsync:1..7"` → **Overview / Jobs /
  Connections / Credentials / Global Settings / Status / History**), each
  including a `source/pages/*.php` body. **Connections** (host/port/user/auth
  cards) and **Credentials** (the managed SSH-key keychain) are SEPARATE
  tabs/bodies (`connections.php` / `credentials.php`); a connection references a
  managed key by id via its KEY auth method. **Overview** (`overview.php`) is a
  read-only status board that polls `getStatus`.
- **Dashboard widget:** `source/UR.Dashboard.page` uses `Menu="Dashboard"` (NOT
  the `UnraidRsync` xmenu) and sets `$mytiles['unraid.rsync']['column1']` to a
  self-contained tile string (markup + scoped CSS + a `getStatus` poller) that
  Unraid's `dynamix/DashStats.page` echoes into the dashboard. This `$mytiles`
  contract is undocumented but stable since 6.12; the body branches on the
  Unraid version for the pre-7.2 header fixup.
- **Backend classes** in `source/include/`: `Config`, `Credentials`, `Job`, `Ssh`,
  `Rsync`, `RunState`, `Runner`, `Logger`, `Cron`, `Notify`, `KeyTools`,
  `handler.php` (the POST/GET front controller). Plus
  `source/scripts/{runner,apply-cron}.php` and the `source/event/started` array-
  start hook.

## Hard conventions / invariants

- **Slug `unraid.rsync` is identical everywhere** — `.plg` name, install dir,
  `/boot` config dir, and cron pickup. Do not diverge it.
- **Build rsync/ssh/sshpass invocations as argv ARRAYS, never shell strings**, and
  run them through `proc_open` without a shell. The ONLY intentional shell uses
  are: `Notify` exec (every arg `escapeshellarg`'d, incl. `-i`), user pre/post
  **hooks** (`bash -c "$hook"`), and the detached runner launcher.
- **rsync flags are a closed whitelist** (`Rsync::BOOL_FLAGS` / `SCALAR_FLAGS` /
  `LIST_FLAGS`, mirrored by `Job`'s normalisation and the options form). There is
  **no free-form flag field** anywhere — never add one.
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
  (`ur_migrate_credentials`: copy+verify+`chmod 600`+unlink, never clobbers an
  existing dest, never `rename` across the FAT32↔ext4 device boundary). The
  **tmpfs materialisation is NOT removed** by this (still needed for a discrete
  `ssh -i` key file + per-run isolation + redaction). Per-run tmpfs secrets +
  run state ALWAYS stay in tmpfs regardless.
- **HTML-escape all output**; the log viewer renders `Logger::tail()` output, which
  is already escaped (log-XSS guard). Captured run output is also **redacted** of
  per-run tmpfs secret paths and **size-capped** before it is written
  (`Logger::setRedaction` / `Logger::sink` / `UR_MAX_RUN_LOG_BYTES`).
- **CSRF**: every state-changing POST verifies the webGui `csrf_token` via
  `hash_equals`; GET pollers are read-only. The check is **match-ANY** across all
  server-side-trusted token sources (`$GLOBALS['var']['csrf_token']`,
  `$_SESSION['csrf_token']`, and `var.ini`) — `ur_csrf_token_candidates()` /
  `ur_check_csrf()`. **Do NOT revert to "first non-empty source wins":** on the
  live box a stale `$var`/`$_SESSION` token masked the canonical `var.ini` token
  and 403'd the *correct* token; match-any is the fix (every candidate is a token
  the page legitimately echoes).
- **Client AJAX POSTs are `application/x-www-form-urlencoded`, NEVER multipart.**
  All POSTs go out as `URLSearchParams` via the shared `window.urAjax` helpers
  (`source/pages/_options_form.php`) and the per-page equivalents in
  `credentials.php` / `status.php`. **Never use a `FormData` object as a fetch
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
