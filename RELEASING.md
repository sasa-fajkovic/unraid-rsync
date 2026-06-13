# Releasing Unraid Rsync

This document describes how a new version of the plugin is built and published.

## Version scheme

Versions use a **`YYYY.MM.DD`** date stamp, e.g. `2026.06.13`. This is the
convention Unraid plugins use and it has a useful property: Unraid compares
plugin versions with a **lexicographic string comparison** (`strcmp`-style), and
zero-padded `YYYY.MM.DD` sorts correctly that way (a later date is always a
larger string).

If you need to cut **more than one release on the same day**, append a trailing
lowercase letter to keep the ordering monotonic and still lexicographically
increasing:

```
2026.06.13     # first release of the day
2026.06.13a    # second release the same day  (sorts after 2026.06.13)
2026.06.13b    # third, etc.
```

Do **not** use `2026.06.13.1` — a numeric suffix added with another dot does not
sort reliably under string comparison once you cross `.9` -> `.10`.

## Version + checksum are regenerated together (never hand-edited)

`unraid.rsync.plg` carries two values that must always match the built package:

- `<!ENTITY version "...">`
- `<!ENTITY md5     "...">`

The MD5 is what `upgradepkg --install-new` verifies against the downloaded
`.txz` before installing. **Never hand-edit either ENTITY.** `pkg_build.sh`
builds the `.txz`, computes its md5, and rewrites both ENTITYs in place so they
are always consistent. If you edit one by hand you will almost certainly break
install verification.

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

## Tag -> Action -> Release flow

Releases are automated by `.github/workflows/release.yml`:

1. **Tag** a commit with the version, e.g.:

   ```bash
   git tag 2026.06.13
   git push origin 2026.06.13
   ```

   The workflow also accepts a manual `workflow_dispatch` with a `version` input.

2. The **Action** checks out the repo, runs `pkg_build.sh -y -V <version>`
   inside `aclemons/slackware:15.0` to build the `.txz` and regenerate the
   `.plg`, then commits the regenerated `unraid.rsync.plg` back to the branch.

3. It publishes a **GitHub Release** tagged with the version, attaching
   `archive/*.txz`. The raw `.plg` on the default branch
   (`https://raw.githubusercontent.com/sasa-fajkovic/unraid-rsync/main/unraid.rsync.plg`)
   then points users at that release's `.txz`.

## Verifying a release

After the Action finishes:

- Install from the public `.plg` URL via **Plugins -> Install Plugin** on an
  Unraid box.
- Confirm the "Unraid Rsync" page appears under **Settings**, loads cleanly, and
  that **Remove** uninstalls it without leaving files behind in
  `/usr/local/emhttp/plugins/unraid.rsync` or `/boot/config/plugins/unraid.rsync`.

## Community Applications (future)

The repo is kept CA-ready: stable raw `.plg` URL, valid `icon`/`support`/`min`
attributes, and versioned `.txz` assets on Releases. Submitting to the CA feed is
a later, mechanical step once the plugin has been dogfooded.
