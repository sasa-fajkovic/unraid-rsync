# Unraid Rsync

A native Unraid webGui plugin for scheduling and monitoring **rsync backup
jobs** — closer in spirit to TrueNAS's Rsync Tasks than to existing single-
schedule rsync plugins.

> **Pre-release / work in progress.** This is an early skeleton. It installs a
> tabbed **Unraid Rsync** page under **Settings**, but the actual backup
> functionality is not implemented yet — it lands across later phases (see
> [Roadmap](#roadmap)). Do not rely on it for real backups yet.

## What it will do

The goal is multiple **independent** rsync jobs, each with:

- its own cron schedule (per-job, not one global schedule);
- a curated, **whitelisted** set of rsync flags exposed as checkboxes and value
  inputs (no free-form flag string — destructive flags are gated with guardrails);
- explicit source -> destination pairs (one rsync per pair, not a cartesian product);
- pre/post hooks, per-job log level, live state and per-run logs;
- a reusable, TrueNAS-style **Credentials** keychain (SSH keys + connections)
  that jobs reference by name, supporting key and password auth.

## Status (Phase 1)

What ships today:

- An installable `.plg` packaged in the standard Unraid way (a Slackware `.txz`
  built by `pkg_build.sh`, released via GitHub Actions).
- A tabbed **Unraid Rsync** page under **Settings** with one placeholder
  **Status** tab that loads cleanly.
- Clean uninstall (removes both the runtime `emhttp` tree and the persistent
  `/boot` config dir).

No jobs, credentials, scheduling, execution, logs, or notifications yet.

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

1. **Skeleton + packaging + CI** (this release) — installable `.plg`, build
   script, release workflow, empty tabbed Settings page.
2. Config core + Jobs CRUD + Global Settings.
3. Credentials tab (two-tier SSH keys + connections) + secure storage.
4. Rsync execution engine (safe argv, path guardrails).
5. Per-job cron scheduling + next-run display.
6. Status/state UI + log viewer + last-run reporting.
7. Notifications.

## Building / releasing

See [RELEASING.md](RELEASING.md). In short: builds run inside
`aclemons/slackware:15.0` via `pkg_build.sh`, and tagging a `YYYY.MM.DD` version
triggers the release workflow, which regenerates the `.plg` (version + md5) and
publishes a GitHub Release with the `.txz`.

## License

See [LICENSE](LICENSE).
