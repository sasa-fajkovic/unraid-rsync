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
- its own cron schedule (per-job, not one global schedule), with a live
  **Next run** column;
- a curated, **whitelisted** set of rsync flags exposed as checkboxes and value
  inputs (no free-form flag string — destructive flags are gated with
  guardrails), each with native **inline help** (`?`-on-hover → blue box);
- explicit source -> destination pairs (one rsync per pair, not a cartesian product);
- pre/post hooks and a per-job log level;
- live **state badges**, a **per-run log viewer**, and last-run reporting;
- optional **notifications** through Unraid's native notification system.

### Transports

A job picks one of three transports.

- **SSH** — rsync tunnelled over an `ssh` command line. The job references a
  connection from the reusable **Connections** tab (shown by name in the UI),
  which supplies the host, port, remote user and one of three auth methods:
  **existing key file**, **managed key**, or **password**. A pair's right-hand
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

A job normally keeps **one mirror** on the destination. In **snapshot mode** it
keeps **dated, versioned copies** instead: every run writes a new directory on
the receiver, and files that did not change since the previous snapshot are
**hard links** to it (`rsync --link-dest`), so each snapshot costs only the space
of what changed while still being a complete tree you can browse and restore
from. A snapshot counts only once its run finished cleanly; old ones are
deleted by a retention policy.

### Requirements

- **SSH transport, Push direction.** rsync daemon, Local and Pull jobs cannot use
  snapshot mode.
- A receiver with **GNU coreutils and util-linux** (a normal Linux distribution;
  BusyBox, BSD and macOS receivers are not supported).
- **Root on the receiver**: either the connection logs in as `root`, or its user
  has **passwordless sudo for `sh`** (which is full root) and the connection has
  **Use sudo on the receiver** on. The transfer itself must also read the
  snapshot root as root: with a non-root login, point the job's **Remote rsync
  path** at a small sudo wrapper (see step 3 below).
- **rsync 3.2.4 or newer on both ends.**
- A **target marker** with expected content (a UUID) on the backup drive. In
  snapshot mode it is mandatory, and the snapshot root must be the marker's
  directory or lie inside it.

### Layout on the receiver

```
/mnt/backup/.unraid-rsync-target                 the target marker (holds a UUID)
/mnt/backup/snapshots/appdata/                   the job's snapshot root, 0700 root:root
/mnt/backup/snapshots/appdata/.ur-snapshot-root  sentinel: first line = the job id
/mnt/backup/snapshots/appdata/2026-10-20T030000Z/appdata/...   one snapshot, one dir per pair ("sub")
/mnt/backup/snapshots/appdata/2026-10-21T030000Z/appdata/...
/mnt/backup/snapshots/appdata/2026-10-21T030000Z.inprogress/  a run that did not commit (reused next run)
/mnt/backup/snapshots/appdata/latest -> 2026-10-21T030000Z     for humans; the plugin never reads it
/mnt/backup/snapshots/appdata/.ur-hold           deletions on hold (see below)
/mnt/backup/snapshots/appdata/<stamp>/.ur-keep   this snapshot is pinned, never pruned
```

Names are UTC stamps (`YYYY-MM-DDTHHMMSSZ`). Only a name that is exactly a stamp
is a complete snapshot. Besides pruning old snapshots, the plugin only deletes
its own leftovers: older `<stamp>.inprogress` copies of runs that did not
commit (the newest one is kept as a link basis) and `.deleting` directories of
an interrupted deletion. Nothing else in the root is ever deleted.

### Setup, step by step

The paths below are examples; use your own. The job id is in the first line of
every run log of the job (`Run started for job "Appdata" (j-appdata)`) and in
`/boot/config/plugins/unraid.rsync/config.json`.

1. **On the receiver, as root, create the target marker** (skip if the job
   already has one) and note its content:

   ```sh
   cat /proc/sys/kernel/random/uuid > /mnt/backup/.unraid-rsync-target
   cat /mnt/backup/.unraid-rsync-target
   ```

2. **Create the snapshot root (0700) and its sentinel** holding the job id. The
   plugin never creates either, so a missing drive can never be mistaken for an
   empty one:

   ```sh
   mkdir -p /mnt/backup/snapshots
   mkdir -m 0700 /mnt/backup/snapshots/appdata
   printf '%s\n' j-appdata > /mnt/backup/snapshots/appdata/.ur-snapshot-root
   ```

   The root must be on the same filesystem as the marker, and not on the
   receiver's system disk.

3. **Non-root login only.** Give the user passwordless sudo for `sh` and for
   rsync through a wrapper, then set the job's **Remote rsync path** to
   `/usr/local/bin/rsync-sudo` and tick **Use sudo on the receiver** on the
   connection (Connections tab):

   ```sh
   printf '%s\n' 'backup ALL=(root) NOPASSWD: /bin/sh, /usr/bin/rsync' > /root/unraid-rsync.sudoers
   visudo -cf /root/unraid-rsync.sudoers && install -m 0440 /root/unraid-rsync.sudoers /etc/sudoers.d/unraid-rsync
   printf '%s\n' '#!/bin/sh' 'exec sudo -n /usr/bin/rsync "$@"' > /usr/local/bin/rsync-sudo
   chmod 0755 /usr/local/bin/rsync-sudo
   ```

   Check the paths of `sh` and `rsync` on your receiver (`command -v sh rsync`).

4. **In the job** (Jobs tab): Transport **SSH**, Direction **Push**, the target
   marker path and its content, then turn **Snapshot mode** on and fill in the
   **snapshot root** and the retention. In each pair the right box becomes a
   **sub-directory name** (`appdata`, `docs`, ...). Tick **seed** on every pair
   for the first snapshot. Apply.

5. **Dry-run, then Run.** A dry run checks everything on the receiver and lists
   what would be copied, and creates nothing. The first real run is a full copy;
   every later run links unchanged files to the previous snapshot. Once a pair
   is in a snapshot its **seed** flag has no effect (the log says so) and can be
   cleared. A pair added later needs **seed** for its first run.

6. **Pruning starts as a dry run.** With **Prune dry run** on (the default) every
   run logs its plan (`Prune plan: KEEP …` / `Prune plan: DELETE …`) and deletes
   no snapshot. Review those plans for a couple of weeks, then turn **Prune dry
   run** off and Apply. At most **Max deletions per run** snapshots are deleted
   per run; the rest wait for later runs. Prune dry run does not cover the
   cleanup of leftovers: older unfinished `<stamp>.inprogress` copies and
   `.deleting` directories are still removed by every real run (unless
   `.ur-hold` exists).

### Holds and pins

- **`<root>/.ur-hold`** puts every deletion for this job on hold. The plugin
  creates it when a run looks **suspect** (far fewer files or bytes than the
  previous snapshot, runaway growth, a missing link basis): the snapshot is still
  committed, the previous one is pinned, and you get an alert. Look at the run
  log and the data first; once you are satisfied, clear it on the receiver with
  `rm /mnt/backup/snapshots/appdata/.ur-hold`.
- **`<root>/<stamp>/.ur-keep`** pins that snapshot: it is never pruned. The plugin
  sets it on the snapshot before a suspect one; you can set your own with
  `touch`. Unpin with `rm /mnt/backup/snapshots/appdata/2026-10-20T030000Z/.ur-keep`.

The plugin never removes either file.

### Restoring

List the snapshots on the receiver with `ls /mnt/backup/snapshots/appdata/`. Then,
on the Unraid server, preview with `-n -i` first and run the same command without
`-n` once the preview is right. With a non-root login add the job's
`--rsync-path=/usr/local/bin/rsync-sudo`.

```sh
# one file
rsync -n -i -a backup@backup.example:/mnt/backup/snapshots/appdata/2026-10-20T030000Z/appdata/app/config.yml /mnt/user/appdata/app/
# a directory (trailing slashes: copy the CONTENTS of the snapshot dir into the target dir)
rsync -n -i -a backup@backup.example:/mnt/backup/snapshots/appdata/2026-10-20T030000Z/appdata/app/ /mnt/user/appdata/app/
```

Without `--delete` a restore only adds and overwrites; nothing else in the target
is removed.

### Migrating an existing mirror

> **Adapt the paths; read before running.** This moves your existing backup. It
> runs on the receiver as root and stops at the first error.

1. **Disable the job and Apply**, and do not press Run (a disabled job can still
   be run by hand). Check that no rsync is running on the receiver
   (`pgrep -a rsync`).
2. **Take the stamp from the job's last successful real run** (UTC, as History
   stores it). Do not type one by hand. On the Unraid server:

   ```sh
   grep '"state":"SUCCESS"' /boot/config/plugins/unraid.rsync/runs/j-appdata.history.jsonl \
     | grep '"dryRun":false' | tail -n 1 \
     | sed -E 's/.*"startedAt":"([0-9-]+)T([0-9]{2}):([0-9]{2}):([0-9]{2})Z".*/\1T\2\3\4Z/'
   ```

3. **Run this on the receiver** with your values. It creates the root and
   sentinel, moves each mirror into one snapshot named after that stamp, leaves
   an empty, immutable directory at each old mirror path (so a stale mirror job
   cannot quietly fill it again), and pins that snapshot:

   ```sh
   #!/bin/sh
   # Adapt the paths; read before running.
   set -eu
   JOB=j-appdata
   STAMP=2026-10-20T030000Z
   MARKER=/mnt/backup/.unraid-rsync-target
   ROOT=/mnt/backup/snapshots/appdata
   MIRROR1=/mnt/backup/mirror/appdata
   SUB1=appdata
   MIRROR2=/mnt/backup/mirror/docs
   SUB2=docs

   die() { echo "migration: $*" >&2; exit 1; }
   case "$STAMP" in
     [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
     *) die "not a stamp: $STAMP" ;;
   esac
   [ -f "$MARKER" ] || die "no target marker at $MARKER"
   [ ! -e "$ROOT" ] || die "$ROOT already exists"
   [ -d "$MIRROR1" ] && [ ! -L "$MIRROR1" ] || die "$MIRROR1 is not a directory"
   [ -d "$MIRROR2" ] && [ ! -L "$MIRROR2" ] || die "$MIRROR2 is not a directory"

   mkdir -p -- "${ROOT%/*}"
   mkdir -m 0700 -- "$ROOT"
   printf '%s\n' "$JOB" > "$ROOT/.ur-snapshot-root"
   DEV=$(stat -c %d -- "$ROOT")
   [ "$(stat -c %d -- "$MARKER")" = "$DEV" ] || die "$MARKER is not on the filesystem of $ROOT"
   [ "$(stat -c %d -- "$MIRROR1")" = "$DEV" ] || die "$MIRROR1 is not on the filesystem of $ROOT"
   [ "$(stat -c %d -- "$MIRROR2")" = "$DEV" ] || die "$MIRROR2 is not on the filesystem of $ROOT"
   # chattr must work here before anything is moved.
   mkdir -- "$ROOT/.chattr-test"
   chattr +i -- "$ROOT/.chattr-test"
   chattr -i -- "$ROOT/.chattr-test"
   rmdir -- "$ROOT/.chattr-test"

   mkdir -m 0700 -- "$ROOT/$STAMP.inprogress"
   mv -T -- "$MIRROR1" "$ROOT/$STAMP.inprogress/$SUB1"
   mkdir -- "$MIRROR1"
   chattr +i -- "$MIRROR1"
   mv -T -- "$MIRROR2" "$ROOT/$STAMP.inprogress/$SUB2"
   mkdir -- "$MIRROR2"
   chattr +i -- "$MIRROR2"
   sync -f "$ROOT"
   mv -T -- "$ROOT/$STAMP.inprogress" "$ROOT/$STAMP"
   ln -s -- "$STAMP" "$ROOT/.latest.tmp"
   mv -T -- "$ROOT/.latest.tmp" "$ROOT/latest"
   touch -- "$ROOT/$STAMP/.ur-keep"
   sync -f "$ROOT"
   echo "migrated: $ROOT/$STAMP"
   ```

   One `MIRROR`/`SUB` pair per job pair; add or remove lines to match.
4. **Switch the job to snapshot mode**: root, marker and content, retention,
   **Prune dry run** on, each pair's sub name, and **no seed** (the snapshot you
   just made is the basis). Apply.
5. **Dry-run.** Each pair should link against the migrated snapshot (no "new pair"
   and no `--link-dest arg does not exist` line), and only the changes since the
   last mirror run should be listed. Then Run, and check on the receiver that
   unchanged files are hard links (`stat -c %h` shows a link count above 1),
   `latest` points at the new stamp, and `df` grew by about the size of the
   changes. The first snapshot has no metadata, so the first run logs that the
   anomaly check has no baseline.

**Rollback (per job).** Disable the job and Apply. Then on the receiver, as root
(adapt the paths; read before running):

```sh
#!/bin/sh
set -eu
ROOT=/mnt/backup/snapshots/appdata
MIRROR1=/mnt/backup/mirror/appdata
SUB1=appdata
MIRROR2=/mnt/backup/mirror/docs
SUB2=docs

die() { echo "rollback: $*" >&2; exit 1; }
LATEST=$(readlink -- "$ROOT/latest")
case "$LATEST" in
  [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;;
  *) die "latest does not name a snapshot: $LATEST" ;;
esac
[ -d "$ROOT/$LATEST/$SUB1" ] || die "no $SUB1 in $ROOT/$LATEST"
[ -d "$ROOT/$LATEST/$SUB2" ] || die "no $SUB2 in $ROOT/$LATEST"
[ ! -e "$ROOT/$LATEST.rolledback" ] || die "$ROOT/$LATEST.rolledback already exists"
# Each old mirror path must be the empty tombstone the migration left.
[ -d "$MIRROR1" ] && [ ! -L "$MIRROR1" ] && [ -z "$(ls -A -- "$MIRROR1")" ] || die "$MIRROR1 is not an empty directory"
[ -d "$MIRROR2" ] && [ ! -L "$MIRROR2" ] && [ -z "$(ls -A -- "$MIRROR2")" ] || die "$MIRROR2 is not an empty directory"
chattr -i -- "$MIRROR1"
rmdir -- "$MIRROR1"
mv -T -- "$ROOT/$LATEST/$SUB1" "$MIRROR1"
chattr -i -- "$MIRROR2"
rmdir -- "$MIRROR2"
mv -T -- "$ROOT/$LATEST/$SUB2" "$MIRROR2"
mv -T -- "$ROOT/$LATEST" "$ROOT/$LATEST.rolledback"
sync -f "$MIRROR1"
echo "rolled back from $LATEST"
```

Then edit the job: **Snapshot mode** off and the absolute remote paths back in
the pairs, and Apply. The plugin never touches a `.rolledback` directory. Only
when **no** job has snapshot mode on does `config.json` go back to the format an
older plugin release can read (schema 2); install an older release only after
that. With a snapshot job enabled, an older release refuses the configuration and
runs nothing.

### Security note

A snapshot holds whatever the job copies. Snapshots of `/boot` (coming in a later
release) hold the license key, password hashes, SSH keys and this plugin's
`credentials.json`: keep the root 0700 root, and never copy it offsite
unencrypted. **Use sudo on the receiver** means the Unraid host can run any
command as root there; snapshot mode needs that, and protects nothing against a
compromised Unraid host.

## What ships today

- An installable `.plg` packaged in the standard Unraid way (a Slackware `.txz`
  built by `pkg_build.sh`, released via GitHub Actions; **CalVer** auto-releases
  on every merge to `main`).
- A tabbed **Unraid Rsync** page under **Settings → User Utilities** with
  **Jobs**, **Connections**, **Credentials**, **Global Settings**, **Status**
  and **History** tabs.
- Jobs CRUD + Global Settings (config persisted to `config.json`).
- A **Connections** tab (see [Credentials](#credentials)) in two flavours:
  **SSH** connections with three auth methods — **existing key file** (default),
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
  schedule, plus a **Next run** column on the Jobs list.
- **Live status badges** in clear status colors —
  **success** (green), **warning** (orange), **failed** (red),
  **aborted** (grey), **pending** (grey), **running** (blue, pulsing).
- A **per-run log viewer**: pick any past run from a selector and watch the log
  tail update live (1-second poll while a run is in progress).
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
- A **Status** tab showing the rolling cross-job plugin log and an
  **rsync-binary presence indicator** (detected path + the first line of
  `rsync --version`, or a clear warning if rsync is somehow absent).
- **Notifications** through Unraid's native `notify`, with a per-job
  **notify mode** — `off`, `success-only`, `failure-only`, or `always` — and
  importance chosen by cause, so it can be routed to agents: *alert* = act now
  (failed, pre-hook timeout, run did not start, runner died mid-run), *warning*
  = look today (partial, timeout, `--max-delete` cap hit, post-hook failed or
  timed out, scheduled run aborted or skipped because the previous one still
  runs), *normal* = FYI (success, files vanished during transfer (exit 24),
  manual abort). `failure-only` sends every non-normal one. `notify init` is run
  on install so notifications work out of the box.
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

- **Existing key file** (the default) — point the connection at a key already on
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
  scheduled run sees.

#### Storing credentials on the array instead of the flash

By default `credentials.json` lives on `/boot` for the reasons above. If you want
real at-rest protection, set a **Secrets directory** on the **Global Settings**
tab to an absolute path under `/mnt` (an array share or pool — ideally one that
is **encrypted** and **not** exported over SMB/NFS), e.g.
`/mnt/user/system/unraid.rsync`. The plugin then stores `credentials.json` there
with real `chmod 600` permissions (ext4/xfs/btrfs/zfs honour Unix perms; FAT32
does not). Changing the setting **moves** the existing file to the new location.

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
   version + md5 for the newest published `.txz`, and it is also the URL the
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
