# Unraid Rsync

A native Unraid webGui plugin for scheduling and monitoring **rsync backup
jobs over SSH** — closer in spirit to TrueNAS's Rsync Tasks than to existing
single-schedule rsync plugins.

> **Validate with dry-runs first.** rsync moves (and can delete) real data, so
> exercise a new job with a **Dry-run** and inspect the per-run log before you
> trust it for unattended backups. That is ordinary rsync hygiene, not a
> disclaimer of missing features — everything described below ships today.

## What it does

Run multiple **independent** rsync jobs, each with:

- its own cron schedule (per-job, not one global schedule), with a live
  **Next run** column;
- a curated, **whitelisted** set of rsync flags exposed as checkboxes and value
  inputs (no free-form flag string — destructive flags are gated with
  guardrails), each with native **inline help** (`?`-on-hover → blue box);
- explicit source -> destination pairs (one rsync per pair, not a cartesian product);
- pre/post hooks and a per-job log level;
- live **state badges**, a **per-run log viewer**, and last-run reporting;
- optional **notifications** through Unraid's native notification system;
- a reusable, TrueNAS-style **Credentials** keychain (SSH connections + a
  managed key keychain) that jobs reference by connection (shown by name in the
  UI), supporting **existing key file**, **managed key**, and **password** auth.

## What ships today

- An installable `.plg` packaged in the standard Unraid way (a Slackware `.txz`
  built by `pkg_build.sh`, released via GitHub Actions; **CalVer** auto-releases
  on every merge to `main`).
- A tabbed **Unraid Rsync** page under **Settings → User Utilities** with
  **Jobs**, **Credentials**, **Global Settings** and **Status** tabs.
- Jobs CRUD + Global Settings (config persisted to `config.json`).
- A **Credentials** keychain (see [Credentials](#credentials)): SSH
  **Connections** with three auth methods — **existing key file** (default),
  a **managed key** keychain (generate or import), and **password** (via
  `sshpass`) — plus **Discover host key**, selectable strict-host-key modes,
  and a per-connection **Test connection** probe.
- A safe rsync **execution engine** (whitelisted flags built as an argv array,
  path guardrails) with manual **Run / Dry-run / Abort** per job, and native
  **inline help** on every rsync flag and option.
- **Per-job cron scheduling**: each enabled job runs on its own 5-field cron
  schedule, plus a **Next run** column on the Jobs list.
- **Live status badges** in TrueNAS-style colors —
  **success** (green), **warning** (orange), **failed** (red),
  **aborted** (grey), **pending** (grey), **running** (blue, pulsing).
- A **per-run log viewer**: pick any past run from a selector and watch the log
  tail update live (1-second poll while a run is in progress).
- A **Status** tab showing the rolling cross-job plugin log and an
  **rsync-binary presence indicator** (detected path + the first line of
  `rsync --version`, or a clear warning if rsync is somehow absent).
- **Notifications** through Unraid's native `notify`, with a per-job
  **notify mode** — `off`, `success-only`, `failure-only`, or `always` —
  mapped to the correct webGui importance (success → *normal*, warning/partial/
  timeout → *warning*, failure → *alert*). `notify init` is run on install so
  notifications work out of the box.
- Clean uninstall (removes both the runtime `emhttp` tree and the persistent
  `/boot` config dir, and clears the plugin's cron lines from the live crontab).

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

### Credentials

The **Credentials** tab is a reusable keychain that jobs reference by
connection, so a host's details are defined once and shared. It has two layers:

- **Connections** — an SSH endpoint (host, port, remote user) plus an **auth
  method**. Each connection has a **Discover host key** action and a selectable
  **strict host-key checking** mode (`accept-new` — the default —, `yes`, or
  `no`), and a per-connection **Test connection** probe.
- **Keys** — a managed SSH key keychain you can **generate** or **import**,
  referenced by connections that use managed-key auth.

Each connection picks one of three **auth methods**:

- **Existing key file** (the default) — point the connection at a key already on
  the server, e.g. `/root/.ssh/id_ed25519`. Nothing is uploaded or copied into
  the plugin's store; it uses the file in place.
- **Managed key** — use a key from the plugin's **Keys** keychain (generated or
  imported through the UI).
- **Password** — authenticate with a stored password via `sshpass` (see below).

### Credential security (read this)

Credentials are stored in `/boot/config/plugins/unraid.rsync/credentials.json`
on the USB flash, which is **FAT32 and world-readable** — Unix file permissions
do not apply there. Consequences:

- **Existing-key-file** auth keeps the private key entirely outside the plugin's
  store — only the path is recorded — so it never lands on the world-readable
  flash. This is the **default and recommended** method.
- **Managed private keys** are copied to RAM (`tmpfs`) at mode `600` only at run
  time (OpenSSH refuses a world-readable key), and are **never shown again** in
  the UI after they are saved — only the fingerprint and public key are
  displayed.
- **Passwords** are stored **obfuscated (reversible), not encrypted**. Anyone
  with access to the flash drive can recover them. Prefer **key authentication**
  and, when password auth is unavoidable, use a **dedicated low-privilege remote
  account**.

**Password auth requires `sshpass`**, which is not part of Unraid's base OS.
The plugin **detects it at runtime**: if it is missing, the Credentials tab and
the connection test say so and point you at the **NerdTools** plugin (install
it and enable its `sshpass` package). Key auth works regardless.

- **Pre/post hooks run as `root`** (via `bash -c`) before/after the transfer,
  and their stdout/stderr is **captured into the per-run log, which is rendered
  in the browser**. Do **not** echo secrets in a hook — they would land in a
  root-written, browser-visible log.

### rsync binary

`rsync` ships in **Unraid's base OS** at `/usr/bin/rsync`, so it is always
present on a healthy system. The plugin therefore **does not install rsync** —
there is no clean Slackware artifact to pin, and a bundled copy would shadow the
base binary. Instead it performs a **defensive presence check**: before a job
runs, it verifies the binary is executable, and the **Status** tab shows the
detected rsync path plus the first line of `rsync --version` (or a clear warning
if it is somehow absent — a sign your system is misconfigured). This mirrors the
`sshpass` detect-and-degrade approach, except rsync is expected to be present.

## Install

> Requires **Unraid 7.0.0 or newer**.

1. In the Unraid webGui go to **Plugins -> Install Plugin**.
2. Paste the `.plg` URL:

   ```
   https://github.com/sasa-fajkovic/unraid-rsync/releases/latest/download/unraid.rsync.plg
   ```

   This is the **latest release's** manifest. It always carries the correct
   version + md5 for the newest published `.txz`, and it is also the URL the
   installed plugin checks for updates.

3. Click **Install**. When it finishes, open
   **Settings → User Utilities → Unraid Rsync**.

To remove it: **Plugins -> Installed Plugins -> Unraid Rsync -> Remove**.

### Updating

Updates are automatic: **Plugins -> Check for Updates** compares your installed
version against the manifest at the `releases/latest` URL above and offers an
**Update** when a newer release has been published. Releases use **CalVer**
(`YYYY.MM.DD`, with a same-day lowercase suffix) and are published
automatically on every merge to `main`, so the update check always sees the
newest build.

> **Migration (one-time):** if you installed an older build whose update URL
> pointed at the raw `.plg` on `main`, **re-install once** from the
> `releases/latest` URL above to switch to the new auto-update source. After
> that single re-install, updates are picked up automatically as before.

## Roadmap

The plugin is **feature-complete**. The full build landed across these areas,
all of which now ship:

- ✅ Skeleton + packaging + CI (installable `.plg`, build script, release
  workflow, tabbed Settings page).
- ✅ Config core + Jobs CRUD + Global Settings.
- ✅ Credentials keychain (SSH connections + managed keys) + secure storage.
- ✅ Rsync execution engine (safe argv, path guardrails) + inline help.
- ✅ Per-job cron scheduling + next-run display.
- ✅ Status/state UI + per-run log viewer + last-run reporting.
- ✅ Notifications via Unraid's native `notify`.

Genuinely still ahead:

- **Community Applications submission** — the repo is kept CA-ready (stable
  `releases/latest` `.plg` URL, valid plugin attributes, versioned `.txz`
  assets); listing it in the CA feed is a later, mechanical step.

## Building / releasing

See [RELEASING.md](RELEASING.md) for the full pipeline. In short:

- **CI** runs on every PR (and is **required** by branch protection on `main`):
  - **`lint`** — `xmllint` on the `.plg`, `bash -n` + `shellcheck` on
    `pkg_build.sh`, and `php -l` on every PHP file and `.page` body;
  - **`PHPUnit`** — the unit-test suite.

  A PR cannot merge until both checks are green.

- **Releasing is automatic.** Merging to `main` builds the `.txz` inside
  `aclemons/slackware:15.0` via `pkg_build.sh` (which rewrites the `.plg`'s
  version + md5 to match the package), then publishes a versioned GitHub Release
  with both the `.txz` and the regenerated `.plg` attached and marks it
  `latest`. The version is auto-computed (`YYYY.MM.DD`, with a same-day
  lowercase suffix for additional releases on the same day).

- **Updates** are served from `releases/latest`: the installed plugin's
  `pluginURL` points at
  `https://github.com/sasa-fajkovic/unraid-rsync/releases/latest/download/unraid.rsync.plg`,
  so **Plugins -> Check for Updates -> Update** always sees the newest release.
  The repo's `.plg` is the source template and is **not** synced back on
  release — the GitHub Release asset is the authoritative manifest.

## License

See [LICENSE](LICENSE).
