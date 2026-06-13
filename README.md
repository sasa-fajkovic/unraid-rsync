# Unraid Rsync

A native Unraid webGui plugin for scheduling and monitoring **rsync backup
jobs** — closer in spirit to TrueNAS's Rsync Tasks than to existing single-
schedule rsync plugins.

> **Pre-release / work in progress.** Jobs, credentials, the rsync execution
> engine, and per-job scheduling are in place; the live status UI, per-run log
> viewer, and notifications are still landing across later phases (see
> [Roadmap](#roadmap)). Validate with dry-runs before relying on it for real
> backups.

## What it will do

The goal is multiple **independent** rsync jobs, each with:

- its own cron schedule (per-job, not one global schedule);
- a curated, **whitelisted** set of rsync flags exposed as checkboxes and value
  inputs (no free-form flag string — destructive flags are gated with guardrails);
- explicit source -> destination pairs (one rsync per pair, not a cartesian product);
- pre/post hooks, per-job log level, live state and per-run logs;
- a reusable, TrueNAS-style **Credentials** keychain (SSH keys + connections)
  that jobs reference by connection id (shown by name in the UI), supporting
  key and password auth.

## Status

What ships today:

- An installable `.plg` packaged in the standard Unraid way (a Slackware `.txz`
  built by `pkg_build.sh`, released via GitHub Actions).
- A tabbed **Unraid Rsync** page under **Settings** with **Jobs**,
  **Credentials**, **Global Settings** and **Status** tabs.
- Jobs CRUD + Global Settings (config persisted to `config.json`).
- A two-tier **Credentials** keychain: reusable **SSH Keys** (generate or
  import) + **Connections** (key or password auth), with referential integrity
  and a per-connection **Test connection** probe.
- A safe rsync **execution engine** (whitelisted flags built as an argv array,
  path guardrails) with manual **Run / Dry-run / Abort** per job.
- **Per-job cron scheduling**: each enabled job runs on its own 5-field cron
  schedule, plus a **Next run** column on the Jobs list.
- Clean uninstall (removes both the runtime `emhttp` tree and the persistent
  `/boot` config dir, and clears the plugin's cron lines from the live crontab).

Live status badges, the per-run log viewer, and notifications still land in
later phases.

### Scheduling (how it works)

Each **enabled** job contributes one line to a single cron file that the plugin
regenerates from `config.json` on every relevant change:

```
/boot/config/plugins/unraid.rsync/unraid.rsync.cron
```

The file lives **directly** in the plugin's flash config dir (not a `cron/`
subdirectory) because Unraid's `/usr/local/sbin/update_cron` concatenates each
plugin's cron files with a **non-recursive, top-level `*.cron` glob** — a
subdirectory would never be scanned. A **single** regenerated file (rather than
one file per job) means deleting or disabling a job can never leave an orphaned
schedule behind. Each line invokes the runner directly:

```
<schedule> php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=<id> >/dev/null 2>&1
```

After rewriting the file (atomically: temp + rename) the plugin runs
`update_cron` via its absolute path to rebuild the live crontab. Schedules are
re-applied automatically:

- on **every config change** that affects a job's schedule or enabled state;
- on **plugin install/upgrade** (the `.plg` runs `scripts/apply-cron.php`), so a
  configured schedule is live immediately without waiting for a reboot;
- on **array start** (the `event/started` hook re-applies), which works around a
  known Unraid 7.x bug where the boot-time `update_cron` may not run.

### Credential security (read this)

Credentials are stored in `/boot/config/plugins/unraid.rsync/credentials.json`
on the USB flash, which is **FAT32 and world-readable** — Unix file permissions
do not apply there. Consequences:

- **Private keys** are copied to RAM (`tmpfs`) at mode `600` only at run time
  (OpenSSH refuses a world-readable key), and are **never shown again** in the
  UI after they are saved — only the fingerprint and public key are displayed.
- **Passwords** are stored **obfuscated (reversible), not encrypted**. Anyone
  with access to the flash drive can recover them. Prefer **key authentication**
  (the primary, tested path) and, when password auth is unavoidable, use a
  **dedicated low-privilege remote account**.

**Password auth requires `sshpass`**, which is not part of Unraid's base OS.
The plugin **detects it at runtime**: if it is missing, the Credentials tab and
the connection test say so and point you at the **NerdTools** plugin (install
it and enable its `sshpass` package). Key auth works regardless.

## Install

> Requires **Unraid 7.0.0 or newer**.

1. In the Unraid webGui go to **Plugins -> Install Plugin**.
2. Paste the raw `.plg` URL:

   ```
   https://raw.githubusercontent.com/sasa-fajkovic/unraid-rsync/main/unraid.rsync.plg
   ```

3. Click **Install**. When it finishes, open **Settings -> Unraid Rsync**.

To remove it: **Plugins -> Installed Plugins -> Unraid Rsync -> Remove**.

## Roadmap

The plugin is delivered in sequential phases, each one PR:

1. **Skeleton + packaging + CI** — installable `.plg`, build script, release
   workflow, empty tabbed Settings page.
2. **Config core + Jobs CRUD + Global Settings.**
3. **Credentials tab (two-tier SSH keys + connections) + secure storage.**
4. Rsync execution engine (safe argv, path guardrails).
5. **Per-job cron scheduling + next-run display.** ✅
6. Status/state UI + log viewer + last-run reporting.
7. Notifications.

## Building / releasing

See [RELEASING.md](RELEASING.md). In short: builds run inside
`aclemons/slackware:15.0` via `pkg_build.sh`, and tagging a `YYYY.MM.DD` version
triggers the release workflow, which regenerates the `.plg` (version + md5) and
publishes a GitHub Release with the `.txz`.

## License

See [LICENSE](LICENSE).
