#!/usr/bin/env bash
#
# Deploy this plugin to a Moodle site, safely.
#
#   tools/deploy.sh --moodle=/path/to/moodle [options]
#
# Options:
#   --moodle=PATH     Moodle directory holding config.php          (required)
#   --ref=REF         Git ref to deploy                            (default: HEAD)
#   --as-user=USER    OS user to run Moodle's CLI as               (default: owner of moodledata)
#   --dry-run         Print what would happen, change nothing
#   --no-maintenance  Skip maintenance mode (not recommended)
#   --yes             Do not ask for confirmation
#   --rollback=FILE   Restore a backup tarball produced by an earlier run
#
# Run as root: replacing plugin files needs write access to the Moodle tree, and
# chown is used to keep the ownership Moodle expects. Moodle's own CLI is then run
# as the web server user, so nothing in moodledata ends up owned by root.
#
# On failure the site is deliberately LEFT in maintenance mode, so users do not
# reach a half-upgraded site. The rollback command is printed.
set -euo pipefail

PLUGIN_DIR_NAME='n3xtopenrouter'
COMPONENT='aiprovider_n3xtopenrouter'

moodle=''
ref='HEAD'
asuser=''
dryrun=0
maintenance=1
assumeyes=0
rollback=''

for arg in "$@"; do
    case "$arg" in
        --moodle=*)    moodle="${arg#*=}" ;;
        --ref=*)       ref="${arg#*=}" ;;
        --as-user=*)   asuser="${arg#*=}" ;;
        --rollback=*)  rollback="${arg#*=}" ;;
        --dry-run)     dryrun=1 ;;
        --no-maintenance) maintenance=0 ;;
        --yes)         assumeyes=1 ;;
        -h|--help)     sed -n '2,25p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

repo="$(cd "$(dirname "$0")/.." && pwd)"

die()  { echo "ERROR: $*" >&2; exit 1; }
step() { echo; echo "== $*"; }
run()  { if [ "$dryrun" = 1 ]; then echo "   [dry-run] $*"; else eval "$@"; fi; }

[ -n "$moodle" ] || die "--moodle=PATH is required"
moodle="${moodle%/}"
[ -d "$moodle" ] || die "not a directory: $moodle"

# Work out the layout. Moodle 5 can serve from a public/ subdirectory, in which
# case the code (and this plugin) lives in public/ while admin/cli and the real
# config.php stay at the project root. Accept either root and sort it out here,
# because getting this wrong points the upgrade at a path that does not exist.
if [ -f "$moodle/version.php" ] && [ -d "$moodle/admin/cli" ]; then
    coderoot="$moodle"; cliroot="$moodle"                       # classic layout
elif [ -f "$moodle/public/version.php" ] && [ -d "$moodle/admin/cli" ]; then
    coderoot="$moodle/public"; cliroot="$moodle"                # public/ layout, project root given
elif [ -f "$moodle/version.php" ] && [ -d "$(dirname "$moodle")/admin/cli" ]; then
    coderoot="$moodle"; cliroot="$(dirname "$moodle")"          # public/ layout, code root given
else
    die "cannot work out the Moodle layout from $moodle (no version.php + admin/cli pair)"
fi
[ -f "$coderoot/config.php" ] || die "no config.php in $coderoot"

plugin="$coderoot/ai/provider/$PLUGIN_DIR_NAME"
# Read config.php as text rather than bootstrapping Moodle. Bootstrapping here
# would run as root and can leave root-owned files in moodledata that the web
# server then cannot rewrite - the exact hazard this script exists to avoid. Both
# candidate paths are searched because Moodle 5's public/config.php is only a shim
# that includes the real one at the project root.
read_cfg() {
    grep -h -oE "\\\$CFG->$1[[:space:]]*=[[:space:]]*'[^']+'" \
        "$cliroot/config.php" "$coderoot/config.php" 2>/dev/null \
        | head -1 | sed -e "s/.*=[[:space:]]*'//" -e "s/'.*//"
}

dataroot="$(read_cfg dataroot)"
[ -n "$dataroot" ] || die "could not read \$CFG->dataroot from config.php"
[ -d "$dataroot" ] || die "dataroot $dataroot does not exist"

# Moodle's CLI must run as whoever owns moodledata, or it will leave files the
# web server cannot rewrite.
[ -n "$asuser" ] || asuser="$(stat -c '%U' "$dataroot")"
id "$asuser" >/dev/null 2>&1 || die "user $asuser does not exist"

moosh() {
    if [ "$dryrun" = 1 ]; then
        echo "   [dry-run] runuser -u $asuser -- php $cliroot/$1 ${*:2}"
        return 0
    fi
    runuser -u "$asuser" -- php "$cliroot/$1" "${@:2}"
}

# ---------------------------------------------------------------- rollback mode
if [ -n "$rollback" ]; then
    [ "$(id -u)" = 0 ] || die "run as root (needs to replace files)"
    [ -f "$rollback" ] || die "backup not found: $rollback"
    step "Rolling back $plugin from $rollback"
    [ "$maintenance" = 1 ] && moosh admin/cli/maintenance.php --enable
    run "rm -rf '$plugin'"
    run "tar xzf '$rollback' -C '$(dirname "$plugin")'"
    moosh admin/cli/upgrade.php --non-interactive
    moosh admin/cli/purge_caches.php
    [ "$maintenance" = 1 ] && moosh admin/cli/maintenance.php --disable
    echo; echo "Rolled back. Note Moodle refuses a lower \$plugin->version: if the"
    echo "upgrade step failed, restore the database from backup as well."
    exit 0
fi

# ------------------------------------------------------------------- preflight
[ "$(id -u)" = 0 ] || die "run as root (needs to replace files and chown)"
git -C "$repo" rev-parse --verify "$ref" >/dev/null 2>&1 || die "unknown git ref: $ref"

installed_version=''
if [ -f "$plugin/version.php" ]; then
    installed_version="$(grep '\$plugin->release' "$plugin/version.php" | sed -e "s/.*= *'//" -e "s/'.*//" || true)"
fi
new_version="$(git -C "$repo" show "$ref:version.php" | grep '\$plugin->release' | sed -e "s/.*= *'//" -e "s/'.*//")"

echo "Deploying $COMPONENT"
echo "  repository:   $repo @ $ref ($(git -C "$repo" rev-parse --short "$ref"))"
echo "  code root:    $coderoot"
echo "  cli root:     $cliroot   ($([ "$cliroot" = "$coderoot" ] && echo 'classic layout' || echo 'public/ layout'))"
echo "  plugin path:  $plugin"
echo "  installed:    ${installed_version:-(not installed)}"
echo "  deploying:    $new_version"
echo "  cli user:     $asuser   (owner of $dataroot)"
echo "  maintenance:  $([ "$maintenance" = 1 ] && echo 'yes' || echo 'NO')"
[ "$dryrun" = 1 ] && echo "  MODE:         dry run, nothing will change"

if [ "$assumeyes" != 1 ] && [ "$dryrun" != 1 ]; then
    printf '\nProceed? [y/N] '
    read -r reply
    case "$reply" in [yY]*) ;; *) echo 'Aborted.'; exit 1 ;; esac
fi

backup=''
cleanup_on_failure() {
    local status=$?
    [ "$status" = 0 ] && return 0
    echo >&2
    echo "DEPLOY FAILED (exit $status)." >&2
    if [ "$maintenance" = 1 ] && [ "$dryrun" != 1 ]; then
        echo "The site has been LEFT IN MAINTENANCE MODE on purpose, so nobody" >&2
        echo "reaches a half-upgraded site." >&2
    fi
    if [ -n "$backup" ]; then
        echo >&2
        echo "Roll back with:" >&2
        echo "  $0 --moodle=$moodle --rollback=$backup" >&2
    fi
    exit "$status"
}
trap cleanup_on_failure EXIT

# ------------------------------------------------------------------ 1. maintenance
if [ "$maintenance" = 1 ]; then
    step "1. Maintenance mode on"
    moosh admin/cli/maintenance.php --enable
else
    step "1. Maintenance mode skipped (--no-maintenance)"
fi

# ---------------------------------------------------------------------- 2. backup
step "2. Backing up the current plugin"
if [ -d "$plugin" ]; then
    backup="${TMPDIR:-/tmp}/${PLUGIN_DIR_NAME}-backup-$(date +%Y%m%d-%H%M%S).tgz"
    run "tar czf '$backup' -C '$(dirname "$plugin")' '$PLUGIN_DIR_NAME'"
    echo "   backup: $backup"
else
    echo "   nothing installed yet, no backup needed"
fi

# --------------------------------------------------------------------- 3. install
step "3. Installing $new_version"
staging="$(mktemp -d)"
run "git -C '$repo' archive --format=tar --prefix='$PLUGIN_DIR_NAME/' '$ref' | tar -x -C '$staging'"
run "rm -rf '$plugin'"
run "mv '$staging/$PLUGIN_DIR_NAME' '$plugin'"
run "rmdir '$staging'"

# Match the ownership and modes Moodle code is normally installed with.
step "4. Setting ownership and permissions"
run "chown -R root:'$(stat -c '%G' "$dataroot")' '$plugin'"
run "find '$plugin' -type d -exec chmod 755 {} +"
run "find '$plugin' -type f -exec chmod 644 {} +"

# --------------------------------------------------------------------- 5. upgrade
step "5. Running the Moodle upgrade"
moosh admin/cli/upgrade.php --non-interactive

step "6. Purging caches"
moosh admin/cli/purge_caches.php

# ---------------------------------------------------------------------- 7. verify
step "7. Verifying what landed"
if [ "$dryrun" != 1 ]; then
    "$repo/tools/verify_untouched.sh" "$plugin" "$ref"
else
    echo "   [dry-run] verify_untouched.sh $plugin $ref"
fi

# --------------------------------------------------------- 8. installed check
step "8. Checking the installed copy"
if [ "$dryrun" = 1 ]; then
    echo "   [dry-run] runuser -u $asuser -- php <staged verify_installed.php> --moodle=$coderoot"
else
    # Copied out because the repository may sit somewhere $asuser cannot read,
    # and because tools/ is export-ignored so it is not in the installed copy.
    checkscript="$(mktemp --suffix=.php)"
    install -m 644 "$repo/tools/verify_installed.php" "$checkscript"
    runuser -u "$asuser" -- php "$checkscript" --moodle="$coderoot"
    rm -f "$checkscript"
fi

# ----------------------------------------------------------------- 9. maintenance
if [ "$maintenance" = 1 ]; then
    step "9. Maintenance mode off"
    moosh admin/cli/maintenance.php --disable
fi

# -------------------------------------------------------------- 10. site responds
step "10. Checking the site responds"
wwwroot="$(read_cfg wwwroot)"
if [ "$dryrun" = 1 ]; then
    echo "   [dry-run] curl -o /dev/null $wwwroot/"
else
    status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$wwwroot/" || echo 'no response')"
    echo "   $wwwroot/ -> HTTP $status"
    case "$status" in
        200|303|302) ;;
        *) die "the site did not answer normally (HTTP $status)" ;;
    esac
fi

trap - EXIT
echo
echo "Deployed $new_version."
[ -n "$backup" ] && echo "Backup kept at: $backup"
cat <<TXT

Next:
  1. The only thing left that this cannot prove is a real, billed request. When you
     want it (the repository may not be readable by $asuser, so copy the one file):
       install -m 644 $repo/tools/smoke_test.php /tmp/smoke_test.php
       runuser -u $asuser -- php /tmp/smoke_test.php --moodle=$coderoot
     Add --images to also generate one image.
  2. Site administration -> AI -> AI providers -> your instance -> Actions:
     review the model on each action. A site upgrading from 1.0.x never actually
     used the model it had configured, so the effective model changes.
  3. Generate image arrives disabled on existing instances, because their stored
     action config predates it. Enable it there, then configure its model.
TXT
