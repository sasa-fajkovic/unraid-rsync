#!/bin/bash
#
# gen-plg-changes.sh - Regenerate unraid.rsync.plg's <CHANGES> block from git
# tag/commit history, so Community Applications shows a real per-release
# changelog instead of a static, hand-maintained block that inevitably goes
# stale (see PR #50 / UNR-01: a hand-written <CHANGES> block was removed
# specifically because nothing kept it in sync with actual releases).
#
# Every existing release tag (CalVer, e.g. 2026.08.07d) becomes one
# "### <tag>" section listing that release's commit subjects; the in-flight
# release being built (not tagged yet at this point in CI) becomes the newest
# section, headed by the version passed via -V. Runs on the plain checkout
# (needs `git log`/`git tag` with FULL history - the caller's checkout must
# use fetch-depth: 0), NOT inside the Slackware build container.
#
# Usage:
#   ./gen-plg-changes.sh -V <version> [-r <build_ref>] [-o <output-file>]
#
#   -V <version>      The in-flight release's version (YYYY.MM.DD or
#                      YYYY.MM.DDx). Required.
#   -r <build_ref>    Commit the in-flight release is built from. Default: HEAD.
#   -o <output-file>  Write the rewritten .plg here instead of editing
#                      unraid.rsync.plg in place (useful for local testing).
#
# Only the <CHANGES>...</CHANGES> body is replaced (wrapped in CDATA, since
# raw commit-subject text is not otherwise XML-escaped here); every other
# line of the .plg - including the version/md5 ENTITYs pkg_build.sh rewrites
# afterward - is left untouched.

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plg_filepath="$script_dir/unraid.rsync.plg"

version=""
build_ref="HEAD"
output_file=""

while getopts ":V:r:o:" opt; do
  case "$opt" in
    V) version="$OPTARG" ;;
    r) build_ref="$OPTARG" ;;
    o) output_file="$OPTARG" ;;
    \?) echo "Unknown option: -$OPTARG" >&2; exit 2 ;;
    :)  echo "Option -$OPTARG requires an argument." >&2; exit 2 ;;
  esac
done

if [[ -z "$version" ]]; then
  echo "ERROR: -V <version> is required." >&2
  exit 2
fi
if ! [[ "$version" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]]; then
  echo "ERROR: invalid version '$version' (expected YYYY.MM.DD or YYYY.MM.DDx)." >&2
  exit 2
fi

if [[ ! -f "$plg_filepath" ]]; then
  echo "ERROR: plugin manifest not found: $plg_filepath" >&2
  exit 1
fi

if [[ -z "$output_file" ]]; then
  output_file="$plg_filepath"
fi

git_dir_flag=(-C "$script_dir")

# Existing release tags, oldest first. CalVer sorts correctly as plain text
# (documented versioning rationale: zero-padded date + single-letter suffix is
# strcmp-monotonic), so a lexicographic sort is enough - no version-aware sort
# needed. Restrict to tags that actually look like a release (defensive: a
# stray non-CalVer tag must never break the walk).
# (Built with a read loop rather than `mapfile` so this also runs on bash 3.2,
# e.g. macOS's system /bin/bash, for local testing - not just CI's bash 5.)
tags=()
while IFS= read -r tag; do
  [[ -n "$tag" ]] && tags+=("$tag")
done < <(
  git "${git_dir_flag[@]}" tag --list 2>/dev/null \
    | grep -E '^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$' \
    | sort
)

first_commit="$(git "${git_dir_flag[@]}" rev-list --max-parents=0 HEAD | tail -n1)"

# Collect one "### <label>\n<bullets>" block per range into $blocks, oldest
# first: each existing tag against the tag before it (the very first tag runs
# from the repo's first commit), then the in-flight release (last tag..
# build_ref, or from the first commit if there are no tags yet). A range with
# zero commits (should not happen in practice) is skipped so no header with an
# empty body appears. Built as an array (not text reversed with `tac`, which
# is line- not block-granular and also absent on macOS) so it can be walked
# newest-first for display without disturbing bullet order within a block.
blocks=()
emit_range() { # $1=range start (exclusive)  $2=range end (inclusive)  $3=section label
  local from="$1" to="$2" label="$3" body range
  # "from..to" excludes "from" itself, which is correct for a tag boundary but
  # would silently drop the repo's actual first commit when from==first_commit
  # (git has no commit before it to exclude) - use a plain, unbounded `git log
  # <to>` for that one case so the repo's very first commit is never lost from
  # every changelog's oldest section.
  if [[ "$from" == "$first_commit" ]]; then
    range="$to"
  else
    range="${from}..${to}"
  fi
  body="$(git "${git_dir_flag[@]}" log --no-merges --pretty=format:'- %s' "$range" 2>/dev/null || true)"
  [[ -n "$body" ]] || return 0
  blocks+=("### ${label}
${body}")
}

prev="$first_commit"
# ${#tags[@]} guards the loop instead of expanding "${tags[@]}" directly on a
# possibly-empty array (the very first release, before any tag exists): older
# bash (3.2, macOS's system /bin/bash) treats that expansion as an unbound
# variable under `set -u`, even though the array itself is declared.
if [[ ${#tags[@]} -gt 0 ]]; then
  for tag in "${tags[@]}"; do
    emit_range "$prev" "$tag" "$tag"
    prev="$tag"
  done
fi
emit_range "$prev" "$build_ref" "$version"

# Newest-first for display; $blocks above was built oldest-first, so walk it
# backward, joining with a blank line between sections.
changes_body=""
i=${#blocks[@]}
while [[ "$i" -gt 0 ]]; do
  i=$((i - 1))
  if [[ -n "$changes_body" ]]; then
    changes_body="${changes_body}"$'\n\n'
  fi
  changes_body="${changes_body}${blocks[$i]}"
done

# Defensive CDATA-termination guard: a commit subject containing the literal
# sequence "]]>" would otherwise prematurely close the CDATA section.
changes_body="${changes_body//]]>/]] >}"

changes_tmp="$(mktemp)"
plg_tmp="$(mktemp)"
cleanup() { rm -f "$changes_tmp" "$plg_tmp"; }
trap cleanup EXIT

printf '%s\n' "$changes_body" > "$changes_tmp"

awk -v changes_file="$changes_tmp" '
  /<CHANGES>/ {
    print "  <CHANGES><![CDATA[";
    while ((getline line < changes_file) > 0) print line;
    close(changes_file);
    print "]]></CHANGES>";
    skip = 1;
    next;
  }
  /<\/CHANGES>/ { skip = 0; next }
  skip { next }
  { print }
' "$plg_filepath" > "$plg_tmp"

if ! grep -q '<CHANGES><!\[CDATA\[' "$plg_tmp"; then
  echo "ERROR: <CHANGES> tag not found in $plg_filepath - refusing to write an unchanged file." >&2
  exit 1
fi

mv "$plg_tmp" "$output_file"
echo "Wrote changelog (${#tags[@]} past release(s) + in-flight $version) to $output_file"
