# Unraid Rsync

[![Install from Community Applications](https://img.shields.io/badge/Unraid-Install%20from%20Community%20Applications-f15a2c?logo=unraid&logoColor=white)](https://ca.unraid.net/apps/unraid-rsync-1q5v71x1vub5v5)
[![GitHub release](https://img.shields.io/github/v/release/sasa-fajkovic/unraid-rsync)](https://github.com/sasa-fajkovic/unraid-rsync/releases/latest)

A native Unraid webGui plugin for scheduling and monitoring **rsync backup
jobs** — **over SSH** to/from a remote host, straight to an **rsync daemon**
(`rsyncd`, the "Rsync Server" a NAS exposes), or **locally** between two paths on
the server — a multi-job scheduler rather than the usual single-schedule rsync
plugins.

> **Validate with dry-runs first.** rsync moves (and can delete) real data, so
> exercise a new job with a **Dry-run** and inspect the per-run log before you
> trust it for unattended backups. That is ordinary rsync hygiene, not a
> disclaimer of missing features — everything described below ships today.

## What it does

Run multiple **independent** rsync jobs, each with:

- a **transport**: **SSH** (push to or pull from a remote host), **rsync
  daemon** (an `rsyncd` module on a NAS or another server), or **Local** (both
  sides are paths on this server, confined under `/mnt`);
- its own cron schedule (per-job, not one global schedule), with its live
  **Next run** shown on the job;
- a curated, **whitelisted** set of rsync flags exposed as checkboxes and value
  inputs (no free-form flag string — destructive flags are gated with
  guardrails), each with native **inline help** (`?`-on-hover → blue box);
- explicit source -> destination pairs (one rsync per pair, not a cartesian product);
- pre/post hooks and a per-job log level;
- live **state badges**, a **per-run log viewer**, and last-run reporting;
- optional **notifications** through Unraid's native notification system;
- optional **snapshot mode** (SSH push): dated, hard-linked versioned copies
  with retention instead of one mirror (see [Snapshot mode](#snapshot-mode)).

### Transports

A job picks one of three transports.

- **SSH** — rsync tunnelled over an `ssh` command line. The job references a
  connection from the reusable **Connections** tab (shown by name in the UI),
  which supplies the host, port, remote user and one of three auth methods:
  **key file on disk**, **managed key**, or **password**. A pair's right-hand
  box is an **absolute filesystem path on the remote host**.
- **rsync daemon (`rsyncd`)** — rsync's own wire protocol, spoken directly to a
  TCP port (**873** unless the daemon was configured otherwise). This is the
  "Rsync Server" that Synology, QNAP, TrueNAS and friends expose. The job
  references a connection whose **Transport** is *rsync daemon (rsyncd)*; the
  username and secret on it are that daemon's **module** credentials (its
  `auth users` / secrets-file entry), **not** an SSH account. A module with no
  `auth users` is anonymous — leave the secret blank. A pair's right-hand box is
  a **module reference**: relative, no host, no leading slash — `rsync_bkp`, or
  `rsync_bkp/photos` for a folder inside the module. The plugin builds the
  operand `user@host::module` and always passes an explicit `--port`.
- **Local** — both sides are paths on this server, confined under `/mnt`. No
  connection and no credentials.

> **The rsync daemon protocol is not encrypted.** Only a challenge/response
> (the digest is negotiated — **MD4** with an old peer) protects the module
> secret, and **file names and file contents travel in clear** over the network.
> Use it only on a network you trust — **SSH remains the recommended transport**
> everywhere else.

**Test connection** on a daemon connection lists the daemon's public modules. A
module listing is answered *before* authentication, so a green result proves the
daemon is reachable and nothing more — it does **not** verify the module user or
the secret. Use a **Dry-run** to test those.

The **connect timeout** rsync option (`--contimeout`) applies to this transport
only: rsync refuses it outright on SSH and Local transfers, so the plugin does
not send it there and warns when one is set.

Managed keys live in their own **Credentials** keychain tab.

## Snapshot mode

> **Full guide: [docs/snapshot-mode.md](docs/snapshot-mode.md)** — setup, how a
> run works, retention, safety nets, alerts, restoring, migrating a mirror,
> backing up `/boot`, and troubleshooting.

A job normally keeps **one mirror** on the destination. In **snapshot mode** it
keeps **dated, versioned copies** instead: every run writes a new directory on
the receiver, and files that did not change since the previous snapshot are
**hard links** to it (`rsync --link-dest`), so each snapshot costs only the space
of what changed while still being a complete tree you can browse and restore
from. A snapshot counts only once its run finished cleanly; old ones are
deleted by a retention policy (daily / weekly / monthly). A run that looks
wrong (far fewer files, runaway growth) still commits but puts all deletions on
hold and alerts you.

**Requirements:** SSH transport and Push direction; a receiver with GNU
coreutils and util-linux; root on the receiver (log in as root, or passwordless
sudo plus **Use sudo on the receiver** on the connection); rsync 3.2.4 or newer
on both ends; **Preserve times** on; and a target marker with expected content
(a UUID) on the backup drive.

**Quick start** (example paths; the guide explains each step):

1. On the receiver, as root, create the target marker:
   `cat /proc/sys/kernel/random/uuid > /mnt/backup/.unraid-rsync-target`.
2. Create the snapshot root (mode 0700) and its sentinel holding the job id:
   `mkdir -m 0700 /mnt/backup/snapshots/appdata` and
   `printf '%s\n' j-appdata > /mnt/backup/snapshots/appdata/.ur-snapshot-root`.
   The plugin never creates either, so a missing drive is never mistaken for an
   empty one.
3. Non-root login only: allow passwordless sudo for `sh` and rsync, set the
   job's **Remote rsync path** to a sudo wrapper, and tick **Use sudo on the
   receiver** ([details](docs/snapshot-mode.md#setup-step-by-step)).
4. In the job: SSH, Push, the marker and its content, **Snapshot mode** on, the
   snapshot root and the retention; give each pair a sub-directory name and
   tick **seed** for the first run. Apply.
5. **Dry-run**, then **Run**. Leave **Prune dry run** on until the logged
   `Prune plan:` lines look right, then turn it off.

## What ships today

- An installable `.plg` packaged in the standard Unraid way (a Slackware `.txz`
  built by `pkg_build.sh`, released via GitHub Actions; **CalVer** auto-releases
  on every merge to `main`).
- A tabbed **Unraid Rsync** page under **Settings → User Utilities** with
  **Overview**, **Jobs**, **Connections**, **Credentials**, **Global Settings**,
  **Status** and **History** tabs.
- A **Jobs** tab with **one list**: each job is a card whose header row is its
  status (state badge, enabled · transport · schedule, **Last run**, **Next
  run**) with **Logs / Run / Dry-run / Abort** beside it; click the header to
  edit the job in place. Run and Dry-run stay disabled on a card with unsaved
  edits until you Apply.
- An **Overview** tab (a read-only status board of every job) and a
  **Dashboard** tile on Unraid's dashboard, both updated automatically.
- A **History** tab: every execution of every job, real and dry runs, filtered
  by job (deleted jobs included), status and type and paged; a failed run's
  badge tooltip names its reason (or rsync's last error), the exit code's
  tooltip its meaning, and **View** / **Download** open or save its log. How
  many executions are kept per job is the **Keep last N executions** setting.
- **A dry run never paints the status.** The badge, **Last run** and the
  Dashboard come from the last *real* run; when the newest execution was a dry
  run it shows as a neutral second line (`Dry-run OK · <time>`), so a green
  preview can never hide a failed backup.
- Jobs CRUD + Global Settings (config persisted to `config.json`).
- A **Connections** tab (see [Credentials](#credentials)) in two flavours:
  **SSH** connections with three auth methods — **key file on disk** (default),
  a **managed key** (from the Credentials tab), and **password** (no extra
  packages needed) — plus **Discover host key** and selectable strict-host-key
  modes; and **rsync daemon** connections with a port, a module user and an
  optional module secret. Both have a per-connection **Test connection** probe
  (an SSH login probe, or a module listing for a daemon — note a listing is
  answered *before* authentication, so it does not verify the secret).
- A **Credentials** tab: a managed SSH key keychain you can **generate** or
  **import**, referenced by connections that use managed-key auth.
- A safe rsync **execution engine** (whitelisted flags built as an argv array,
  path guardrails) with manual **Run / Dry-run / Abort** per job, and native
  **inline help** on every rsync flag and option.
- **Per-job cron scheduling**: each enabled job runs on its own 5-field cron
  schedule, and its **Next run** is shown on the job (`manual (on demand)` for
  a run-on-demand job).
- **Live status badges** in clear status colors —
  **success** (green), **warning** (orange; also partial and timeout),
  **failed** (red; also crashed), **aborted** (grey), **skipped** and **never
  run** (grey), **running** (blue, pulsing).
- A **per-run log viewer**: pick any past run from a selector and watch the log
  tail update live (1-second poll while a run is in progress). When the log
  holds rsync's overall progress line (`--info=progress2`), a note explains
  that its percentage can read 0% for most of a run, because rsync keeps
  growing the total while it scans; only the final line is reliable.
- A **per-job log level**, so a nightly backup of a large tree does not bury its
  own errors under a file listing:

  | Level | What the run log gets |
  | --- | --- |
  | `Quiet` | errors, every `deleting <path>`, and the end-of-run summary |
  | `Summary` | *(default for a new job)* + one overall **progress line every 5% or 30s** — no per-file lines |
  | `Normal` | + a line per transferred file |
  | `Verbose` | + itemised changes (`--itemize-changes`) and the full stats block |
  | `Debug` | + rsync protocol debugging (`--debug=all`) — troubleshooting only, and large |

  rsync redraws its progress line several times a second with a bare `\r`, so
  the plugin collapses those redraws to one line per 5% step or 30 seconds
  (whichever comes first) instead of writing every one. Existing jobs keep the
  level they were saved with. Note that **errors and deletions are logged at
  every level** — a quiet level never hides what a `--delete` job removed — and
  a **Dry-run is never quieter than `Normal`**, so the preview always names the
  files and deletions it would have made.
  **Detailed statistics** is configurable in Global Settings or per job:
  Current preserves the existing `--info=stats2` output; Older rsync uses
  `--stats` for peers such as rsync 3.0.9; No explicit detailed stats omits both.
  Normal and Verbose still use `--info=progress2`, and dry runs use the selected
  statistics mode when raised to Normal. On an older receiving host, also turn
  off **Create destination path** (`--mkpath`) if its rsync predates 3.2.3.
  **Protocol version** (`--protocol=N`, 20–32) caps the negotiated rsync
  protocol for a peer that misbehaves at the version it advertises; leave it
  blank otherwise. ACL and extended-attribute preservation over SSH or rsync
  daemon need 30 or higher.
- A **Status** tab showing the rolling cross-job plugin log (filter by job,
  wrap, copy, download) and an **rsync-binary presence indicator** (detected
  path + the first line of `rsync --version`, or a clear warning if rsync is
  somehow absent). Overview, Jobs, Status and History share one status poll:
  every 2 seconds while a job runs, every 15 seconds otherwise.
- **Notifications** through Unraid's native `notify`, with a per-job
  **notify mode** — `off`, `success-only`, `failure-only`, or `always` — and
  importance chosen by cause, so it can be routed to agents: *alert* = act now
  (failed, pre-hook timeout, run did not start, runner died mid-run), *warning*
  = look today (partial, timeout, `--max-delete` cap hit, post-hook failed or
  timed out, scheduled run aborted), *normal* = FYI (success, files vanished
  during transfer (exit 24), manual abort, scheduled run skipped because the
  previous one still runs). `failure-only` sends every non-normal one;
  `success-only` every normal one except aborts and skips. `notify init` is run
  on install so notifications work out of the box.
- **Snapshot mode** for SSH push jobs: dated, hard-linked versioned copies
  with daily / weekly / monthly retention, a completion gate, a suspect-run hold,
  and `/boot` (flash drive) backup. See the
  [snapshot mode guide](docs/snapshot-mode.md).
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
schedule behind. Each line invokes the runner directly, with an absolute php
path (normally `/usr/bin/php`):

```
<schedule> /usr/bin/php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=<id> --trigger=schedule >/dev/null 2>&1
```

After rewriting the file (atomically: temp + rename) the plugin runs
`update_cron` via its absolute path to rebuild the live crontab. Schedules are
re-applied automatically:

- on **every config change** that affects a job's schedule or enabled state;
- on **plugin install/upgrade** (the `.plg` runs `scripts/apply-cron.php`), so a
  configured schedule is live immediately without waiting for a reboot;
- on **array start** (the `event/started` hook re-applies), which works around a
  known Unraid 7.x bug where the boot-time `update_cron` may not run.

### Delete safety

A mirror with `--delete` makes the destination match the source, so an empty or
wrong path on either side can wipe a backup. The plugin refuses those runs:

- **At save:** two pairs of one delete job may not write to the same or nested
  directories, and a `--delete` destination may not be, contain or lie inside
  another job's destination on the same host and port.
- **Target marker** (optional per job): a file on the destination drive, with
  the content it must hold. Missing or different → the run fails with
  `dest-not-mounted` before the pre-run hook and before anything is written.
- **Before the pre-run hook**, every remote `--delete` destination (SSH or rsync
  daemon, push) is listed one level deep. One that holds
  `.unraid-rsync-target`, `.ur-snapshot-root` or `snapshots` is a drive root or
  a snapshot store and is refused (`dest-is-protected`); one that exists but
  cannot be listed is refused too (`dest-probe-failed`).
- **After the pre-run hook** (which may mount the drive), a local source or
  destination on Unraid's RAM root filesystem is refused
  (`source-not-mounted` / `dest-not-mounted`): an unmounted drive is never
  backed up into RAM, and an empty mountpoint never empties a backup. With a
  delete option on, an empty local source is refused (`source-empty`; a
  dry run only reports it) and a local destination gets the same protected-name
  check.

### Where things are stored

- **Configuration** (`config.json`, `credentials.json`) lives in
  `/boot/config/plugins/unraid.rsync/`. Every save writes a temp file, fsyncs it
  and renames it over the old one, keeping the previous parsable copy as
  `<name>.last-good`. A present but empty or unreadable file is refused, never
  treated as a fresh install. A Jobs or Global Settings save from a page loaded
  before another change was saved is refused (HTTP 409, *Configuration changed
  since this page loaded; reload and reapply.*) instead of undoing that change.
- **Run logs** live in RAM (`/tmp/unraid.rsync`) by default: no flash wear, but
  gone after a reboot (History survives; only the log bodies are lost). Set
  **Persistent log directory** on the Global Settings tab to a path under
  `/mnt` to keep them across reboots. One run log is capped at 16 MiB, a job's
  logs at 256 MiB, and `plugin.log` rotates past 1 MiB.
- **Credentials** can move off the flash with **Secrets directory** (see
  [below](#storing-credentials-on-the-array-instead-of-the-flash)).

### Credentials

**SSH** and **rsync daemon** jobs reference a connection by name, so an
endpoint's details are defined once and shared. (**Local** transport jobs use no
connection or credentials.) Two tabs cover this:

- **Connections** — an endpoint plus its **Transport**. An **SSH** connection is
  host, port and remote user plus an **auth method**, a **Discover host key**
  action and a selectable **strict host-key checking** mode (`accept-new` — the
  default —, `yes`, or `no`). An **rsync daemon** connection is host, port (873),
  the module user and an optional module secret; the SSH-only controls are
  hidden on it. Both have a per-connection **Test connection** probe.
- **Credentials** — a managed SSH key keychain you can **generate** or
  **import**, referenced by connections that use managed-key auth.

An **SSH** connection picks one of three **auth methods**:

- **Key file on disk** (the default) — point the connection at a key already on
  the server, e.g. `/root/.ssh/id_ed25519`. Nothing is uploaded or copied into
  the plugin's store; it uses the file in place.
- **Managed key** — use a key from the plugin's **Keys** keychain (generated or
  imported through the UI).
- **Password** — authenticate with a stored password (see below). Works on a
  stock Unraid box; nothing to install.

An **rsync daemon** connection has no auth method. It either sends a **module
secret** — the daemon's secrets-file entry for its `auth users` name — or, for a
module with no `auth users`, nothing at all.

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
- **rsync daemon module secrets** are the fourth case, and they are stored
  exactly like passwords: **obfuscated (reversible), not encrypted**, on the same
  world-readable flash. At run time the secret is written to a RAM file at mode
  `600` and handed to rsync as `--password-file` (rsync refuses a password file
  any other user can read), so the secret itself never reaches a command line or
  an environment variable — only that file's path does. Use a **dedicated,
  least-privileged module user**, and remember the daemon protocol is
  **unencrypted on the wire** as well.
- **Host key pinning:** on `accept-new`, the first successful connection pins
  the host key it saw to the connection; a later, changed key then fails
  closed instead of being silently re-accepted. Run **Discover host key**
  ahead of time to pin it explicitly rather than trusting whatever the first
  scheduled run sees. The connection card shows the pinned key's fingerprint,
  with **Clear pin on Apply** and **Replace…**; changing the connection's host
  or port clears the old pin, so the next connection pins the new host's key.

#### Storing credentials on the array instead of the flash

By default `credentials.json` lives on `/boot` for the reasons above. If you want
real at-rest protection, set a **Secrets directory** on the **Global Settings**
tab to an absolute path under `/mnt` (an array share or pool — ideally one that
is **encrypted** and **not** exported over SMB/NFS), e.g.
`/mnt/user/system/unraid.rsync`. The plugin then stores `credentials.json` there
with real `chmod 600` permissions (ext4/xfs/btrfs/zfs honour Unix perms; FAT32
does not). Changing the setting **moves** the existing file to the new location:
it is copied, verified by checksum and only then removed from the old place, and a
move cut short is completed or undone the next time credentials are written. A path under
`/mnt/remotes` or `/mnt/addons`, a share or pool root itself (use a directory
inside it), or an existing directory another user owns or others can write is
refused.

This does **not** remove the run-time copy-to-`tmpfs` step — `ssh -i` still needs
a discrete key file extracted from the JSON at mode `600`, and the materialised
copy isolates concurrent runs and is path-redacted from logs. It changes the
**at-rest** threat surface (root-only array file vs. world-readable flash).
Caveats: the **array must be started** before any job can read its credentials;
the standard **USB flash backup will no longer include them**; and **uninstalling
the plugin does not delete** credentials stored under `/mnt` — remove them
manually. Leave the field **empty** to keep the backward-compatible `/boot`
behaviour.

**Password auth needs nothing installed.** It uses OpenSSH's own
`SSH_ASKPASS` mechanism: ssh runs the plugin's `scripts/askpass.sh`, which reads
the password from a per-run RAM file created at mode `600` and deleted when the
run ends. The password never appears in a command line, in an environment
variable, or in `ps` — only the path of that file does.

> Earlier versions shelved this out to `sshpass` and told you to install the
> **NerdTools** plugin. NerdTools was archived by its authors in March 2024 and
> is unavailable on Unraid 7, which left password auth impossible to use on a
> stock box. That dependency is gone.

Key auth is still the recommended method.

- **Pre/post hooks run as `root`** (via `bash -c`) before/after the transfer,
  and their stdout/stderr is **captured into the per-run log, which is rendered
  in the browser**. Do **not** echo secrets in a hook — they would land in a
  root-written, browser-visible log. A hook still running after its timeout
  (Global Settings: 30 / 60 minutes by default, `0` = no limit) is killed.

### rsync binary

`rsync` ships in **Unraid's base OS** at `/usr/bin/rsync`, so it is always
present on a healthy system. The plugin therefore **does not install rsync** —
there is no clean Slackware artifact to pin, and a bundled copy would shadow the
base binary. Instead it performs a **defensive presence check**: before a job
runs, it verifies the binary is executable, and the **Status** tab shows the
detected rsync path plus the first line of `rsync --version` (or a clear warning
if it is somehow absent — a sign your system is misconfigured).

## Install

> Requires **Unraid 7.0.0 or newer**.

1. In the Unraid webGui go to **Plugins -> Install Plugin**.
2. Paste the `.plg` URL:

   ```
   https://github.com/sasa-fajkovic/unraid-rsync/releases/latest/download/unraid.rsync.plg
   ```

   This is the **latest release's** manifest. It always carries the correct
   version + md5/sha256 for the newest published `.txz`, and it is also the URL the
   installed plugin checks for updates.

3. Click **Install**. When it finishes, open
   **Settings → User Utilities → Unraid Rsync**.

To remove it: **Plugins -> Installed Plugins -> Unraid Rsync -> Remove**.

## Support

- **Bugs / feature requests:** open an issue at
  <https://github.com/sasa-fajkovic/unraid-rsync/issues>.
- **Unraid forum thread:** <https://forums.unraid.net/topic/199541-plugin-unraid-rsync-multi-job-rsync-backup-scheduler/>
  (the Community Applications support thread; this is also the `support=` URL CA links to).

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
- ✅ Snapshot mode (versioned backups with retention) + `/boot` backup.
- ✅ Listed in [Community Applications](https://ca.unraid.net/apps/unraid-rsync-1q5v71x1vub5v5).

## Building / releasing

See [RELEASING.md](RELEASING.md) for the full pipeline. In short:

- **CI** runs on every PR (and is **required** by branch protection on `main`):
  - **`lint`** — `xmllint` on the `.plg`, `ca_profile.xml` and
    `unraid.rsync.xml`; `bash -n` + `shellcheck` on `pkg_build.sh`,
    `gen-plg-changes.sh`, `source/event/started` and
    `source/scripts/askpass.sh`; `sh -n` + `shellcheck -s sh` on
    `source/scripts/ur-snapshot.sh`; and `php -l` on every PHP file and `.page`
    body;
  - **`PHPUnit`** — `composer validate`, the `php-cs-fixer` style check, the
    unit-test suite (with a coverage report) and `phpstan` static analysis.

  A PR cannot merge until both checks are green.

- **Releasing is automatic.** Merging to `main` builds the `.txz` inside
  `aclemons/slackware:15.0` via `pkg_build.sh` (which rewrites the `.plg`'s
  version, md5 and sha256 to match the package), then publishes a versioned GitHub Release
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
