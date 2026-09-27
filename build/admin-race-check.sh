#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# GM-01 regression: two admins removing each other from "admin" at the same
# instant must not both succeed. AUDIT_REPORT.md's admin-race.php reproduced
# this against the pre-fix code using a manual event barrier (both processes
# had to be stopped mid-call and released together) because nothing
# serialized the two removals. GroupService::removeMember() now takes an
# exclusive OCP\Lock\ILockingProvider lock before touching "admin", so a
# barrier is no longer needed to force the interleaving: two real, unbarriered
# OS processes launched together are enough, because the fix's whole point is
# that they can no longer race regardless of scheduling. Losing that race
# (LAST_ADMIN_PROTECTED, not silent success) is the expected, correct result.
#
# Needs a running build/nc-instance.sh instance:
#   build/nc-instance.sh up <version>
#   build/admin-race-check.sh <version>
set -euo pipefail
cd "$(dirname "$0")/.."

v="${1:?usage: $0 <nc-version>}"
container="groupmgr-nc$v-app"
docker inspect "$container" > /dev/null 2>&1 \
    || { echo "not running: $container (try: build/nc-instance.sh up $v)" >&2; exit 2; }

occ() { docker exec -u www-data "$container" php occ "$@"; }

# Calls GroupService::removeMember('admin', $target) as $actor, directly —
# bypassing the HTTP/controller admin-required check (every other test in
# this repo already covers that separately; this one isolates the service's
# own locking). Prints one JSON line.
remove_as() {
    local actor="$1" target="$2"
    docker exec -u www-data -e GMRACE_ACTOR="$actor" -e GMRACE_TARGET="$target" -i "$container" php <<'PHP'
<?php
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
$actor = getenv('GMRACE_ACTOR');
$target = getenv('GMRACE_TARGET');
try {
    $um = \OCP\Server::get(\OCP\IUserManager::class);
    \OCP\Server::get(\OCP\IUserSession::class)->setUser($um->get($actor));
    \OCP\Server::get(\OCA\GroupManager\Service\GroupService::class)->removeMember('admin', $target);
    echo json_encode(['actor' => $actor, 'removed' => $target, 'success' => true]) . "\n";
} catch (\Throwable $e) {
    echo json_encode([
        'actor' => $actor, 'removed' => $target, 'success' => false,
        'error' => get_class($e) . ': ' . $e->getMessage(),
    ]) . "\n";
}
PHP
}

# Current members of "admin", one per line.
admin_members() {
    docker exec -u www-data -i "$container" php <<'PHP'
<?php
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
foreach (\OCP\Server::get(\OCP\IGroupManager::class)->get('admin')->getUsers() as $u) {
    echo $u->getUID() . "\n";
}
PHP
}

a=gmrace_a
b=gmrace_b
pass="Gmrace-$$-${RANDOM}${RANDOM}"

cleanup() {
    # ncadmin is this instance's only way back into occ/HTTP admin actions —
    # restored unconditionally, whatever else in this script failed.
    occ group:adduser admin ncadmin > /dev/null 2>&1 || true
    occ user:delete "$a" > /dev/null 2>&1 || true
    occ user:delete "$b" > /dev/null 2>&1 || true
}
trap cleanup EXIT

echo "=== NC $v admin race check ==="
# --password-from-env reads NC_PASS, which must reach the container itself
# (setting it only in this shell before calling the occ() wrapper would not
# propagate through its own `docker exec`).
docker exec -u www-data -e NC_PASS="$pass" "$container" php occ user:add --password-from-env -g admin -n "$a" > /dev/null
docker exec -u www-data -e NC_PASS="$pass" "$container" php occ user:add --password-from-env -g admin -n "$b" > /dev/null
# Exactly two admins for the race, same as the audit's scenario: with a
# third (ncadmin) still present, both removals could legitimately succeed
# without proving anything about the lock (3 - 2 = 1 either way).
occ group:removeuser admin ncadmin > /dev/null

before="$(admin_members | sort | tr '\n' ' ')"
echo "before: $before"

# Command substitution in a backgrounded assignment runs the substitution in
# a subshell whose result is lost when it exits, so output goes to temp
# files instead, read back after both processes finish.
out_a_file="$(mktemp)"
out_b_file="$(mktemp)"
trap 'rm -f "$out_a_file" "$out_b_file"; cleanup' EXIT

remove_as "$a" "$b" > "$out_a_file" 2>&1 &
pid_a=$!
remove_as "$b" "$a" > "$out_b_file" 2>&1 &
pid_b=$!
wait "$pid_a"
wait "$pid_b"

out_a="$(cat "$out_a_file")"
out_b="$(cat "$out_b_file")"
echo "$a -> tried removing $b: $out_a"
echo "$b -> tried removing $a: $out_b"

# Read before restoring ncadmin: this is the exact invariant the fix exists
# for, on the exact two-admin scenario the audit used.
after="$(admin_members | sort)"
after_count=0
[ -n "$after" ] && after_count="$(grep -c . <<< "$after")"
echo "after (ncadmin not yet restored): $(tr '\n' ' ' <<< "$after")"

successes=0
grep -q '"success":true' <<< "$out_a" && successes=$((successes + 1)) || true
grep -q '"success":true' <<< "$out_b" && successes=$((successes + 1)) || true
echo "successes: $successes"

ok=1
if [ "$successes" -gt 1 ]; then
    echo "FAIL: both concurrent removals succeeded — admin group integrity broken"
    ok=0
fi
if [ "$after_count" -lt 1 ]; then
    echo "FAIL: admin group is empty after the race"
    ok=0
fi
if [ "$ok" -eq 1 ]; then
    echo "PASS: at most one removal succeeded and at least one admin remains"
fi

exit $((1 - ok))
