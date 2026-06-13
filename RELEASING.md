# Releasing Unraid Rsync

This document describes how a new version of the plugin is built and published.

## TL;DR

**Releases are automatic.** Every merge to `main` publishes a versioned GitHub
Release. You do not tag anything by hand for a normal release — just merge a PR
that passes CI. The only manual lever is `workflow_dispatch` with an explicit
version (see below), for the rare case where you need to pin a specific version.

## Version scheme

Versions use a **`YYYY.MM.DD`** date stamp, e.g. `2026.06.13`. This is the
convention Unraid plugins use and it has a useful property: Unraid compares
plugin versions with a **lexicographic string comparison** (`strcmp`-style), and
zero-padded `YYYY.MM.DD` sorts correctly that way (a later date is always a
larger string). The update check only offers an update when the latest release's
version string sorts **after** the installed one — so each new release must be
strictly greater than the previous under `strcmp`.

When more than one release lands **on the same day**, the workflow appends a
trailing lowercase letter to keep the ordering monotonic and lexicographically
increasing:

```
2026.06.13     # first release of the day  (bare date, index 0)
2026.06.13a    # second release the same day  (index 1, sorts after 2026.06.13)
2026.06.13b    # third  (index 2)
...
2026.06.13z    # 27th and final same-day slot (index 26)
```

Do **not** use `2026.06.13.1` — a numeric suffix added with another dot does not
sort reliably under string comparison once you cross `.9` -> `.10`.

### How the next version is auto-computed

On a push to `main` (or a blank `workflow_dispatch`), the workflow:

1. Takes `today=$(date +%Y.%m.%d)`.
2. Enumerates existing release tags for today via
   `gh release list --json tagName` and maps each to an index: bare date -> 0,
   `a` -> 1, ... `z` -> 26.
3. If **none** exist for today, the version is the bare date `<today>`.
4. Otherwise it takes the **highest** existing index and uses **max + 1**,
   appending the corresponding letter (`1` -> `a` ... `26` -> `z`).
5. If the highest is already `z` (index 26), the step **fails** with a clear
   error — cut tomorrow's date instead, or pick a version manually via
   `workflow_dispatch`.

A manual `workflow_dispatch` with a **non-empty** `version` input bypasses the
auto-compute and uses that value verbatim (after format validation). Use it when
you need an explicit/out-of-band version.

### Serialization (concurrency)

The workflow declares `concurrency: { group: release, cancel-in-progress: false }`.
Two merges landing close together therefore **queue** rather than run in
parallel, so they can't both read the same set of existing tags and compute the
same next version. Each run sees the prior run's published release and bumps past
it.

## The authoritative manifest is the Release asset (not the repo `.plg`)

`unraid.rsync.plg` carries two values that must always match the built package:

- `<!ENTITY version "...">`
- `<!ENTITY md5     "...">`

The MD5 is what `upgradepkg --install-new` verifies against the downloaded
`.txz` before installing. `pkg_build.sh` builds the `.txz`, computes its md5, and
rewrites both ENTITYs in place so they are always consistent. **Never hand-edit
either ENTITY** — they change together, in the build.

Crucially, this rewrite happens **only in the build / Release artifact**. The
repo copy of `unraid.rsync.plg` is just the **source template**: its `version`
and `md5` values are placeholders and are **not** kept current, because the
workflow no longer commits the regenerated `.plg` back to `main`. There is no
release -> commit-back -> CI loop.

The **authoritative published manifest** is the `.plg` attached to each GitHub
Release, served via the stable redirect:

```
https://github.com/sasa-fajkovic/unraid-rsync/releases/latest/download/unraid.rsync.plg
```

That is exactly where the plugin's `pluginURL` ENTITY points, so install and
auto-update both read the per-release manifest (correct version + md5) directly
from the Release — never from the branch. Because every release is published
with `make_latest: true`, the `releases/latest` redirect always resolves to the
newest release.

## Local build

A build requires a **Slackware** environment because it uses `makepkg`. The
easiest way to build locally is the same container CI uses:

```bash
docker run --rm -v "$PWD":/workspace -w /workspace \
  aclemons/slackware:15.0 \
  bash -c "./pkg_build.sh -y -V 2026.06.13"
```

This produces `archive/unraid.rsync-2026.06.13.txz` and rewrites the `version`
and `md5` ENTITYs in `unraid.rsync.plg`.

`pkg_build.sh` flags:

- `-V <version>` — override the version (default: today as `YYYY.MM.DD`).
- `-y` — non-interactive (assume "yes").
- `-u <host>` — optional: scp + `upgradepkg` the freshly built `.txz` to a
  running Unraid box over SSH (local dogfooding convenience only).

## Merge -> Action -> Release flow

Releases are automated by `.github/workflows/release.yml`, triggered on **push
to `main`** (i.e. a merged PR) and on `workflow_dispatch`:

1. **Open a PR and get it green.** CI (`lint` + `PHPUnit`) is required by branch
   protection. A PR's `pull_request` event does **not** trigger the release
   workflow, so nothing is published while the PR is open.

2. **Merge to `main`.** The push to `main` runs the release Action, which:
   - **computes the version** (auto next-same-day, or the explicit dispatch
     input — see "Version scheme" above);
   - runs `pkg_build.sh -y -V <version>` inside `aclemons/slackware:15.0` to
     build the `.txz` and rewrite the `.plg`'s version + md5 in the build
     workspace;
   - publishes a **GitHub Release** tagged with the version, attaching both
     `archive/*.txz` and the regenerated `unraid.rsync.plg`, with
     `make_latest: true`.

3. There is **no commit-back / sync PR**: the repo's `.plg` is not modified by
   the release. Users install and auto-update from the Release asset at
   `https://github.com/sasa-fajkovic/unraid-rsync/releases/latest/download/unraid.rsync.plg`.

### Manual / explicit version

For an out-of-band version, use the **Actions -> Release -> Run workflow**
dispatch and supply a `version` input (`YYYY.MM.DD[x]`). A blank input behaves
exactly like a push to `main` (auto-computes the next same-day version).

## Verifying a release

After the Action finishes:

- Install from the `releases/latest` `.plg` URL (above) via **Plugins -> Install
  Plugin** on an Unraid box.
- Confirm the "Unraid Rsync" page appears under **Settings**, loads cleanly, and
  that **Remove** uninstalls it without leaving files behind in
  `/usr/local/emhttp/plugins/unraid.rsync` or `/boot/config/plugins/unraid.rsync`.
- Confirm **Plugins -> Check for Updates** sees the new version (it compares the
  installed version against the `releases/latest` manifest).

## Community Applications (future)

The repo is kept CA-ready: a stable `releases/latest` `.plg` URL, valid
`icon`/`support`/`min` attributes, and versioned `.txz` assets on Releases.
Submitting to the CA feed is a later, mechanical step once the plugin has been
dogfooded.
