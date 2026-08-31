#!/bin/sh
# OpenSSH SSH_ASKPASS helper for the plugin's PASSWORD auth method.
#
# ssh execs this with the prompt text as $1 and reads the password from our
# stdout. The password is NOT in this file: it stays in the per-run tmpfs file
# named by $UR_ASKPASS_FILE (written mode 600 by Ssh::writePassFile), so it
# never reaches a command line or an environment variable - only its PATH does,
# which is not a secret and is root-only readable anyway.
#
# This lives in the plugin's install dir rather than tmpfs deliberately: it is
# executable at install time, and /tmp may be mounted noexec.
#
# Replaces the old sshpass dependency, which required the NerdTools plugin -
# archived by its owner in March 2024 and unavailable on Unraid 7.
[ -n "$UR_ASKPASS_FILE" ] || exit 1
exec cat -- "$UR_ASKPASS_FILE"
