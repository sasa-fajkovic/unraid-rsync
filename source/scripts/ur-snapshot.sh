#!/bin/sh
# ur-snapshot - the receiver-side helper of snapshot mode
# (docs/design/snapshot-mode.md section 5).
#
# Never run on the Unraid host. Snapshot::helperScript() reads this file, checks
# its sha256 against Snapshot::HELPER_SHA256, and sends the exact bytes over ssh
# as one command string, every element escapeshellarg'd:
#
#   [sudo -n] sh -c '<this file>' ur-snapshot <op> <root> <marker> <markerContent> <jobId> [op args]
#
# probe takes no arguments. stdout is "ur-snapshot <API>", then "key value"
# records, then "ok"; Snapshot::parseHelper() rejects anything else. Exit codes:
# 10 usage, 11 root, 12 marker, 13 filesystem, 14 sentinel, 15 name exists or
# not monotonic, 16 protected, 17 not root or a tool missing, 18 I/O.
#
# No operation creates the root or the sentinel, and none removes .ur-hold or
# .ur-keep: those belong to the admin.

set -eu
LC_ALL=C
export LC_ALL
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH
umask 077

API=1
CR=$(printf '\r')
TMPD=
trap 'if [ -n "$TMPD" ]; then rm -rf -- "$TMPD"; fi' EXIT

die() {
    code=$1
    shift
    printf 'ur-snapshot: %s\n' "$*" >&2
    exit "$code"
}

is_stamp() {
    case $1 in
        [0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) return 0 ;;
    esac
    return 1
}

# Sets KIND to complete, inprogress, deleting or rolledback (BASE = the stamp),
# or to '' for any other name.
kind_of() {
    case $1 in
        *.inprogress) BASE=${1%.inprogress} k=inprogress ;;
        *.deleting) BASE=${1%.deleting} k=deleting ;;
        *.rolledback) BASE=${1%.rolledback} k=rolledback ;;
        *) BASE=$1 k=complete ;;
    esac
    KIND=
    if is_stamp "$BASE"; then
        KIND=$k
    fi
}

# Sets PREFIX to the stamp a name starts with (any suffix), or ''.
prefix_of() {
    PREFIX=${1%"${1#??????????????????}"}
    if ! is_stamp "$PREFIX"; then
        PREFIX=
    fi
}

check_text() {
    case $2 in
        '') die 10 "$1 is empty" ;;
        *[[:cntrl:]]*) die 10 "$1 contains a control character" ;;
    esac
}

check_path() {
    check_text "$1" "$2"
    case $2 in
        /*) ;;
        *) die 10 "$1 is not an absolute path" ;;
    esac
    case $2 in
        */|*//*|*/./*|*/../*|*/.|*/..) die 10 "$1 is not a normalised path" ;;
    esac
}

exists() {
    [ -e "$1" ] || [ -L "$1" ]
}

real_dir() {
    [ -d "$1" ] && [ ! -L "$1" ]
}

# Sets LINE to the first line of a file, without a trailing CR.
first_line() {
    LINE=
    IFS= read -r LINE <"$1" || :
    LINE=${LINE%"$CR"}
}

guard() {
    real_dir "$ROOT" || die 11 "snapshot root is missing or not a directory: $ROOT"
    canon=$(readlink -f -- "$ROOT") || die 11 "cannot resolve the snapshot root: $ROOT"
    [ "$canon" = "$ROOT" ] || die 11 "snapshot root is not canonical: $ROOT resolves to $canon"

    if [ -L "$MARKER" ] || [ ! -f "$MARKER" ]; then
        die 12 "target marker is missing or not a regular file: $MARKER"
    fi
    first_line "$MARKER"
    [ "$LINE" = "$CONTENT" ] || die 12 "target marker does not hold the expected content: $MARKER"

    dev_root=$(stat -c %d -- "$ROOT") || die 13 "cannot stat the snapshot root"
    dev_marker=$(stat -c %d -- "$MARKER") || die 13 "cannot stat the target marker"
    dev_sys=$(stat -c %d -- /) || die 13 "cannot stat /"
    [ "$dev_marker" = "$dev_root" ] || die 13 "the target marker and the snapshot root are on different filesystems"
    [ "$dev_root" != "$dev_sys" ] || die 13 "the snapshot root is on the filesystem of /; is the drive mounted?"

    sentinel=$ROOT/.ur-snapshot-root
    if [ -L "$sentinel" ] || [ ! -f "$sentinel" ]; then
        die 14 "sentinel $sentinel is missing or not a regular file"
    fi
    first_line "$sentinel"
    [ "$LINE" = "$JOBID" ] || die 14 "sentinel $sentinel names another job"
}

print_df() {
    out=$(df -Pk -- "$1" | awk 'NR == 2 {
        for (i = 4; i <= NF; i++)
            if ($i ~ /^[0-9]+%$/ && $(i-1) ~ /^[0-9]+$/ && $(i-2) ~ /^[0-9]+$/ && $(i-3) ~ /^[0-9]+$/) {
                print "df " $(i-3) " " $(i-2) " " $(i-1)
                exit
            }
    }') || die 18 "df failed"
    [ -n "$out" ] || die 18 "df printed nothing usable"
    printf '%s\n' "$out"
}

newest_complete() {
    for e in "$ROOT"/*; do
        n=${e##*/}
        if is_stamp "$n" && real_dir "$e"; then
            printf '%s\n' "$n"
        fi
    done | sort | tail -n 1
}

newest_any() {
    for e in "$ROOT"/*; do
        prefix_of "${e##*/}"
        if [ -n "$PREFIX" ]; then
            printf '%s\n' "$PREFIX"
        fi
    done | sort | tail -n 1
}

selftest() {
    TMPD=$(mktemp -d) || die 17 "mktemp -d failed"
    t=$TMPD
    mkdir -- "$t/a" || die 17 "mkdir failed"
    mv -T -- "$t/a" "$t/b" || die 17 "mv -T failed"
    mkdir -- "$t/c" || die 17 "mkdir failed"
    ln -s b "$t/l1" || die 17 "ln -s failed"
    mv -T -- "$t/l1" "$t/latest" || die 17 "mv -T of a symlink failed"
    ln -s c "$t/l2" || die 17 "ln -s failed"
    mv -T -- "$t/l2" "$t/latest" || die 17 "symlink swap failed"
    [ "$(readlink -- "$t/latest")" = c ] || die 17 "symlink swap did not replace the link"
    [ "$(readlink -f -- "$t/latest")" = "$(readlink -f -- "$t")/c" ] || die 17 "readlink -f failed"
    stat -c %d -- "$t/b" >/dev/null || die 17 "stat -c failed"
    df -Pk -- "$t" >/dev/null || die 17 "df -Pk failed"
    printf '{}\n' >"$t/b/f" || die 17 "writing a file failed"
    sync -f -- "$t/b/f" || die 17 "sync -f failed"
    [ "$(printf 'ur-snapshot' | base64 | base64 -d)" = ur-snapshot ] || die 17 "base64 failed"
    [ "$(printf 'b\na\n' | sort | tail -n 1)" = b ] || die 17 "sort or tail failed"
    nice -n 19 true || die 17 "nice failed"
    rm -rf --one-file-system -- "$t/b" "$t/c" "$t/latest" || die 17 "rm --one-file-system failed"
    ! exists "$t/b" || die 17 "rm left the directory behind"
    rm -rf -- "$TMPD"
    TMPD=
}

op_probe() {
    uid=$(id -u) || die 17 "id failed"
    printf 'uid %s\n' "$uid"
    [ "$uid" = 0 ] || die 17 "not running as root (uid $uid); log in as root or allow passwordless sudo for sh"
    now=$(date -u +%s) || die 17 "date failed"
    printf 'now %s\n' "$now"
    ntp=unknown
    if command -v timedatectl >/dev/null 2>&1; then
        case $(timedatectl show -p NTPSynchronized --value 2>/dev/null || :) in
            yes) ntp=yes ;;
            no) ntp=no ;;
        esac
    fi
    printf 'ntp %s\n' "$ntp"
    selftest
    if command -v ionice >/dev/null 2>&1; then
        printf 'ionice yes\n'
    else
        printf 'ionice no\n'
    fi
}

op_list() {
    foreign=0
    for e in "$ROOT"/*; do
        exists "$e" || continue
        n=${e##*/}
        [ "$n" != latest ] || continue
        kind_of "$n"
        if [ -z "$KIND" ]; then
            foreign=$((foreign + 1))
            prefix_of "$n"
            if [ -n "$PREFIX" ]; then
                printf 'stampish %s\n' "$PREFIX"
            fi
            continue
        fi
        if [ -L "$e" ]; then
            type=l
        elif [ -d "$e" ]; then
            type=d
        elif [ -f "$e" ]; then
            type=f
        else
            type=o
        fi
        keep=0
        meta=0
        if [ "$type" = d ]; then
            if exists "$e/.ur-keep"; then
                keep=1
            fi
            if [ -f "$e/.ur-snapshot.json" ] && [ ! -L "$e/.ur-snapshot.json" ]; then
                meta=1
            fi
        fi
        printf 'entry %s %s %s %s\n' "$n" "$type" "$keep" "$meta"
    done

    if [ -L "$ROOT/latest" ]; then
        target=$(readlink -- "$ROOT/latest") || target=
        if is_stamp "$target"; then
            printf 'latest %s\n' "$target"
        else
            printf 'latest invalid\n'
        fi
    elif exists "$ROOT/latest"; then
        printf 'latest invalid\n'
    else
        printf 'latest none\n'
    fi

    if exists "$ROOT/.ur-hold"; then
        printf 'hold 1\n'
    else
        printf 'hold 0\n'
    fi
    printf 'foreign %s\n' "$foreign"

    newest=$(newest_complete)
    if [ -n "$newest" ]; then
        for e in "$ROOT/$newest"/*; do
            s=${e##*/}
            real_dir "$e" || continue
            case $s in
                [A-Za-z0-9]*) ;;
                *) continue ;;
            esac
            case $s in
                *[!A-Za-z0-9_-]*) continue ;;
            esac
            [ "${#s}" -le 64 ] || continue
            printf 'sub %s\n' "$s"
        done
        m=$ROOT/$newest/.ur-snapshot.json
        if [ -f "$m" ] && [ ! -L "$m" ]; then
            size=$(stat -c %s -- "$m") || die 18 "cannot stat $m"
            if [ "$size" -gt 0 ] && [ "$size" -le 65536 ]; then
                b64=$(base64 <"$m" | tr -d '\n') || die 18 "cannot read $m"
                printf 'meta %s %s\n' "$newest" "$b64"
            fi
        fi
    fi
    print_df "$ROOT"
}

op_mkstamp() {
    stamp=$1
    last=$(newest_any)
    if [ -n "$last" ]; then
        top=$(printf '%s\n%s\n' "$last" "$stamp" | sort | tail -n 1)
        if [ "$top" != "$stamp" ] || [ "$last" = "$stamp" ]; then
            die 15 "stamp $stamp does not sort after the existing $last; check the clock"
        fi
    fi
    ! exists "$ROOT/$stamp.inprogress" || die 15 "$stamp.inprogress already exists"
    mkdir -m 0700 -- "$ROOT/$stamp.inprogress" || die 18 "mkdir $stamp.inprogress failed"
}

op_commit() {
    stamp=$1
    ip=$ROOT/$stamp.inprogress
    real_dir "$ip" || die 10 "$stamp.inprogress is not a directory"
    ! exists "$ROOT/$stamp" || die 15 "$stamp already exists"
    if exists "$ROOT/latest" && [ ! -L "$ROOT/latest" ]; then
        die 15 "$ROOT/latest exists and is not a symlink"
    fi

    printf '%s' "$2" | base64 -d >"$ip/.ur-snapshot.json.tmp" || die 10 "metadata is not valid base64"
    chmod 0600 -- "$ip/.ur-snapshot.json.tmp" || die 18 "chmod of the metadata failed"
    mv -T -f -- "$ip/.ur-snapshot.json.tmp" "$ip/.ur-snapshot.json" || die 18 "writing the metadata failed"
    sync -f -- "$ip/.ur-snapshot.json" || die 18 "sync failed"

    mv -T -- "$ip" "$ROOT/$stamp" || die 18 "committing $stamp failed"
    rm -f -- "$ROOT/.latest.$$"
    ln -s -- "$stamp" "$ROOT/.latest.$$" || die 18 "creating the latest link failed"
    mv -T -- "$ROOT/.latest.$$" "$ROOT/latest" || die 18 "replacing the latest link failed"
    sync -f -- "$ROOT" || die 18 "sync failed"
    print_df "$ROOT"
}

op_delete() {
    name=$1
    kind_of "$name"
    [ "$KIND" != rolledback ] || die 16 "$name was left by a rollback and is never deleted by the plugin"
    ! exists "$ROOT/.ur-hold" || die 16 "deletions are on hold: $ROOT/.ur-hold exists"
    p=$ROOT/$name
    real_dir "$p" || die 16 "$name is not a snapshot directory"
    if [ -L "$ROOT/latest" ]; then
        target=$(readlink -- "$ROOT/latest") || target=
        [ "${target%/}" != "$name" ] || die 16 "$name is the target of latest"
    fi
    ! exists "$p/.ur-keep" || die 16 "$name is pinned (.ur-keep)"
    if [ "$KIND" = complete ] && [ "$name" = "$(newest_complete)" ]; then
        die 16 "$name is the newest complete snapshot"
    fi

    d=$ROOT/$BASE.deleting
    if [ "$KIND" != deleting ]; then
        ! exists "$d" || die 15 "$BASE.deleting already exists"
        mv -T -- "$p" "$d" || die 18 "renaming $name failed"
    fi
    if command -v ionice >/dev/null 2>&1; then
        ionice -c3 -t nice -n 19 rm -rf --one-file-system -- "$d" || die 18 "removing $BASE.deleting failed"
    else
        nice -n 19 rm -rf --one-file-system -- "$d" || die 18 "removing $BASE.deleting failed"
    fi
}

op_hold() {
    h=$ROOT/.ur-hold
    if exists "$h"; then
        return 0
    fi
    printf '%s %s\n' "$1" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"$h.$$" || die 18 "writing $h failed"
    mv -T -- "$h.$$" "$h" || die 18 "writing $h failed"
}

op_pin() {
    p=$ROOT/$1
    real_dir "$p" || die 10 "$1 is not a complete snapshot"
    if ! exists "$p/.ur-keep"; then
        touch -- "$p/.ur-keep" || die 18 "creating $p/.ur-keep failed"
    fi
}

[ $# -ge 1 ] || die 10 "usage: ur-snapshot <op> [args]"
OP=$1
shift
case $OP in
    probe) want=0 ;;
    check | list) want=4 ;;
    mkstamp | delete | hold | pin) want=5 ;;
    commit) want=6 ;;
    *) die 10 "unknown operation" ;;
esac
[ $# -eq "$want" ] || die 10 "$OP takes $want arguments, got $#"

if [ "$want" -gt 0 ]; then
    ROOT=$1
    MARKER=$2
    CONTENT=$3
    JOBID=$4
    shift 4
    check_path "snapshot root" "$ROOT"
    check_path "target marker" "$MARKER"
    check_text "marker content" "$CONTENT"
    check_text "job id" "$JOBID"
fi
for a in "$@"; do
    check_text "argument" "$a"
done
case $OP in
    mkstamp | commit | hold | pin)
        is_stamp "$1" || die 10 "not a stamp: $1"
        ;;
    delete)
        kind_of "$1"
        [ -n "$KIND" ] || die 10 "not a snapshot name: $1"
        ;;
esac
if [ "$OP" = commit ]; then
    case $2 in
        '' | *[!A-Za-z0-9+/=]*) die 10 "metadata is not base64" ;;
    esac
fi

printf 'ur-snapshot %s\n' "$API"
if [ "$OP" != probe ]; then
    guard
fi
case $OP in
    probe) op_probe ;;
    check) print_df "$ROOT" ;;
    list) op_list ;;
    mkstamp) op_mkstamp "$1" ;;
    commit) op_commit "$1" "$2" ;;
    delete) op_delete "$1" ;;
    hold) op_hold "$1" ;;
    pin) op_pin "$1" ;;
esac
printf 'ok\n'
