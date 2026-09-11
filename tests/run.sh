#!/usr/bin/env bash
# Self-test for the compatibility gate.
#
# Exit codes are the contract, because that is what a workflow branches on:
#   0 satisfied   1 declared minimum violated   2 repository not understood
#
# 2 is asserted as carefully as 1. A gate that cannot parse a repository and
# exits 0 certifies every future upgrade for that project forever, silently.
set -uo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
gate="$here/../bin/wp-compat-check.php"
fixtures="$here/fixtures"
pass=0; fail=0

check() {
  local name="$1" want="$2"; shift 2
  "$@" >/dev/null 2>&1
  local got=$?
  if [ "$got" = "$want" ]; then
    printf '  ok    %-52s exit %s\n' "$name" "$got"; pass=$((pass+1))
  else
    printf '  FAIL  %-52s exit %s, wanted %s\n' "$name" "$got" "$want"; fail=$((fail+1))
  fi
}

check "satisfied: target meets every declared minimum" 0 \
  php "$gate" --root="$fixtures/compatible"

check "violated: target below a declared PHP minimum" 1 \
  php "$gate" --root="$fixtures/compatible" --image=wordpress:5.8-php7.2-fpm

check "not understood: no wordpress pin anywhere" 2 \
  php "$gate" --root="$fixtures/no-pin"

check "not understood: pins disagree between Dockerfiles" 2 \
  php "$gate" --root="$fixtures/split-pins"

check "not understood: pin present but no plugins or themes" 2 \
  php "$gate" --root="$fixtures/no-components"

check "not understood: unparseable --image" 2 \
  php "$gate" --root="$fixtures/compatible" --image=nginx:latest

echo
printf '%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
