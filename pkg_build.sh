#!/bin/bash
#
# pkg_build.sh - Build the Unraid Rsync Slackware package (.txz) and regenerate
# the .plg manifest's version + md5 ENTITY values to match.
#
# This is modeled on the Easy Rsync plugin's build script, with two corrections:
#   1. The known missing `then` keyword in Easy Rsync's `if [[ -z "$unraidHost" ]];`
#      sideload guard is written correctly here.
#   2. All user-facing strings are about Unraid Rsync (no copy-paste boilerplate).
#
# Requires a Slackware environment for `makepkg` (that is why CI runs this inside
# the aclemons/slackware:15.0 container). It will refuse to run if makepkg is
# missing, so it fails fast on a non-Slackware host rather than producing a bad
# package.
#
# Usage:
#   ./pkg_build.sh [-V <version>] [-y] [-u <unraidHost>]
#
#   -V <version>   Override the version. Default: today's date as YYYY.MM.DD.
#   -y             Non-interactive: assume "yes" to all prompts.
#   -u <host>      Optional: sideload the freshly built .txz to a running Unraid
#                  host over SSH (scp + plugin install). For local dev only.
#
# The .plg's <!ENTITY version ...> and <!ENTITY md5 ...> lines are rewritten in
# place. Never hand-edit those two ENTITYs - regenerate them here so version and
# md5 always change together (upgradepkg verifies the md5 against the .txz).

set -euo pipefail

# --- locate ourselves (so the script works from any cwd) --------------------
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

plugin_name="unraid.rsync"
src_dir="$script_dir/source"
archive_dir="$script_dir/archive"
plg_filepath="$script_dir/$plugin_name.plg"

version="$(date +%Y.%m.%d)"
assume_yes=0
unraidHost=""

# --- arg parsing ------------------------------------------------------------
while getopts ":V:yu:" opt; do
  case "$opt" in
    V) version="$OPTARG" ;;
    y) assume_yes=1 ;;
    u) unraidHost="$OPTARG" ;;
    \?) echo "Unknown option: -$OPTARG" >&2; exit 2 ;;
    :)  echo "Option -$OPTARG requires an argument." >&2; exit 2 ;;
  esac
done

# --- input validation -------------------------------------------------------
# version is used to build filesystem paths (the archive filename) and is
# sed-substituted into the .plg, so constrain it to the documented scheme
# (YYYY.MM.DD with an optional same-day suffix letter). This rejects values
# containing slashes, quotes, or other metacharacters before they can write
# outside archive/ or corrupt the .plg rewrite.
if ! [[ "$version" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]]; then
  echo "ERROR: invalid version '$version' (expected YYYY.MM.DD or YYYY.MM.DDx)." >&2
  exit 2
fi

# A -u host beginning with '-' would be parsed as an option by ssh/scp
# (option-injection); require a plausible host/user@host token.
if [[ -n "$unraidHost" ]] && ! [[ "$unraidHost" =~ ^[A-Za-z0-9._@-]+$ && "$unraidHost" != -* ]]; then
  echo "ERROR: invalid -u host '$unraidHost'." >&2
  exit 2
fi

# --- preflight --------------------------------------------------------------
if ! command -v makepkg >/dev/null 2>&1; then
  echo "ERROR: makepkg not found. This script must run in a Slackware environment" >&2
  echo "       (e.g. the aclemons/slackware:15.0 container used by CI)." >&2
  exit 1
fi

if [[ ! -d "$src_dir" ]]; then
  echo "ERROR: source directory not found: $src_dir" >&2
  exit 1
fi

if [[ ! -f "$plg_filepath" ]]; then
  echo "ERROR: plugin manifest not found: $plg_filepath" >&2
  exit 1
fi

echo "Building $plugin_name version $version"
if [[ "$assume_yes" -ne 1 ]]; then
  read -r -p "Proceed? [y/N] " reply
  case "$reply" in
    [yY]|[yY][eE][sS]) ;;
    *) echo "Aborted."; exit 0 ;;
  esac
fi

# --- stage source/ into the emhttp plugin tree ------------------------------
# Layout inside the package mirrors the install location:
#   usr/local/emhttp/plugins/unraid.rsync/<everything under source/>
# tests/ and this build script are NOT packaged.
tmpdir="$(mktemp -d)"
archive_file="$archive_dir/$plugin_name-${version}.txz"

cleanup() { rm -rf "$tmpdir"; }
trap cleanup EXIT

mkdir -p "$tmpdir/usr/local/emhttp/plugins/$plugin_name"
mkdir -p "$archive_dir"

# Copy every file under source/, preserving relative paths, while excluding the
# build script and any editor/sync cruft. (tests/ lives outside source/, so it
# is excluded by construction.) Word-splitting on the find output is intentional
# here (cp --parents takes a list of paths); our packaged files have no spaces.
cd "$src_dir"
# shellcheck disable=SC2046  # intentional word-splitting of the file list
cp --parents -f $(find . -type f \
    ! -iname "pkg_build.sh" \
    ! -iname "sftp-config.json" \
    ! -iname ".DS_Store" ) \
  "$tmpdir/usr/local/emhttp/plugins/$plugin_name/"

# --- build the package ------------------------------------------------------
cd "$tmpdir"
makepkg --linkadd y --chown y "$archive_file"

# --- checksum + .plg rewrite ------------------------------------------------
hash="$(md5sum "$archive_file" | awk '{print $1}')"
echo "Built $archive_file"
echo "md5: $hash"

# Rewrite the version and md5 ENTITY declarations. The match is keyed on the
# ENTITY name and tolerates any amount of whitespace before the value, so
# reformatting/realigning the .plg cannot silently break the rewrite (which
# would publish a .txz against a stale manifest). The replacement re-pads to the
# project's standard column alignment.
update_entity() { # $1=entity name  $2=new value
  local name="$1" value="$2"
  if ! grep -Eq "<!ENTITY[[:space:]]+${name}[[:space:]]+\"[^\"]*\">" "$plg_filepath"; then
    echo "ERROR: could not find <!ENTITY $name ...> in $plg_filepath" >&2
    exit 1
  fi
  # Re-pad the ENTITY name to a fixed field so the opening quote stays aligned
  # with the rest of the DOCTYPE block regardless of name length.
  local padded
  padded="$(printf '%-15s' "$name")"
  sed -i -E "s|<!ENTITY[[:space:]]+${name}[[:space:]]+\"[^\"]*\">|<!ENTITY ${padded}\"$value\">|" "$plg_filepath"
}

update_entity md5 "$hash"
update_entity version "$version"

echo "Updated $plg_filepath (version=$version, md5=$hash)"

# --- optional sideload to a live Unraid host (local dev convenience) --------
# NOTE: the correct shell syntax is `if [[ ... ]]; then` - Easy Rsync's script
# is missing the `then` keyword here, which is the bug we deliberately avoid.
if [[ -z "$unraidHost" ]]; then
  echo "Done."
  exit 0
fi

# Accept either "host" (defaults to the root user, as Unraid uses) or an
# explicit "user@host". Only prefix root@ when no user was given, so a value
# like "user@host" doesn't become "root@user@host".
case "$unraidHost" in
  *@*) ssh_target="$unraidHost" ;;
  *)   ssh_target="root@$unraidHost" ;;
esac

echo "Sideloading to $ssh_target ..."
remote_dir="/boot/config/plugins/$plugin_name"
# `--` ends option parsing so the destination can never be read as a flag, and
# the host was validated above (no leading '-', restricted charset).
# Ensure the destination exists on the host: on a box that has never had the
# plugin installed the dir is absent, and scp would fail (aborting under set -e).
# shellcheck disable=SC2029  # we intend $remote_dir to expand locally
ssh -- "$ssh_target" "mkdir -p '$remote_dir'"
scp -- "$archive_file" "$ssh_target:$remote_dir/"
# shellcheck disable=SC2029  # we intend the paths to expand locally
ssh -- "$ssh_target" "upgradepkg --install-new '$remote_dir/$plugin_name-${version}.txz'"
echo "Sideload complete."
