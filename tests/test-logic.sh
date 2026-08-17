#!/usr/bin/env bash
#
# Tests the pure logic that does NOT need a WordPress install: byte math,
# threshold classification, and the auction-date parser that decides which
# vehicles get permanently deleted.
#
# Run: ./tests/test-logic.sh
#
set -uo pipefail

MAINT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export MAINT_ROOT

LOG_DIR="$(mktemp -d)"; STATE_DIR="$(mktemp -d)"
trap 'rm -rf "$LOG_DIR" "$STATE_DIR"' EXIT

# shellcheck source=/dev/null
source "${MAINT_ROOT}/lib/common.sh"
# shellcheck source=/dev/null
source "${MAINT_ROOT}/lib/storage.sh"

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
nope() { FAIL=$((FAIL+1)); printf '  FAIL %s\n     expected: %s\n     actual:   %s\n' "$1" "$2" "$3"; }
is()   { [[ "$2" == "$3" ]] && ok "$1" || nope "$1" "$3" "$2"; }

printf '\n== human_bytes ==\n'
is "0 bytes"        "$(human_bytes 0)"                "0 B"
is "1 KiB"          "$(human_bytes 1024)"             "1.00 KiB"
is "1 MiB"          "$(human_bytes 1048576)"          "1.00 MiB"
is "40 GiB"         "$(human_bytes 42949672960)"      "40.00 GiB"

printf '\n== to_gib ==\n'
is "40 GiB exact"   "$(to_gib 42949672960)"           "40.00"
is "38.5 GiB"       "$(to_gib 41339875328)"           "38.50"

printf '\n== bytes_ge_gib (the hard-ceiling comparison) ==\n'
bytes_ge_gib 42949672960 40 && ok "exactly 40 GiB is at the ceiling" || nope "exactly 40 GiB is at the ceiling" "true" "false"
bytes_ge_gib 42949672959 40 && nope "1 byte under 40 GiB is below" "false" "true" || ok "1 byte under 40 GiB is below"
bytes_ge_gib 45000000000 40 && ok "45 GB is over" || nope "45 GB is over" "true" "false"

printf '\n== classify_storage (tier boundaries) ==\n'
STORAGE_LIMIT_GIB=40; THRESHOLD_AGGRESSIVE_GIB=35
THRESHOLD_REMOVE_OLD_GIB=38; THRESHOLD_HARD_CLEAN_GIB=39
check_tier() {
    TOTAL_BYTES=$(awk -v g="$1" 'BEGIN{printf "%d", g*1024*1024*1024}')
    classify_storage >/dev/null 2>&1
    is "$1 GiB -> $2" "$STORAGE_TIER" "$2"
}
check_tier 10   NORMAL
check_tier 34.9 NORMAL
check_tier 35   AGGRESSIVE
check_tier 37.9 AGGRESSIVE
check_tier 38   REMOVE_OLD
check_tier 39   HARD_CLEAN
check_tier 39.9 HARD_CLEAN
check_tier 40   EMERGENCY
check_tier 55   EMERGENCY

# --------------------------------------------------------------------------
# The auction-date parser. This decides what gets permanently deleted, so it is
# tested against every format it claims to support plus malformed input.
# These cases run lib/auction-date.awk directly — the same file the nightly job
# uses — so there is no copy that can drift out of sync with production.
# --------------------------------------------------------------------------
printf '\n== auction date eligibility (retention = 7 days) ==\n'

eligible() {
    # Runs THE PRODUCTION PARSER (lib/auction-date.awk), not a copy of it.
    # $1 = meta_value, $2 = "now" epoch. Echoes the age in days, or "SKIP".
    local out
    out="$(printf '1\t%s\tpublish\tTitle\n' "$1" \
        | awk -F'\t' -v now="$2" -v ret=7 -f "${MAINT_ROOT}/lib/auction-date.awk" 2>/dev/null)"
    [[ -z "$out" ]] && { printf 'SKIP'; return 0; }
    printf '%s' "$out" | cut -f3
}

NOW="$(date -d '2026-08-17 04:00:00' +%s)"
is "auction 30 days ago is eligible"      "$(eligible '2026-07-18' "$NOW")" "30"
is "auction 8 days ago is eligible"       "$(eligible '2026-08-09' "$NOW")" "8"
is "auction 7 days ago is PRESERVED"      "$(eligible '2026-08-10' "$NOW")" "SKIP"
is "auction 2 days ago is PRESERVED"      "$(eligible '2026-08-15' "$NOW")" "SKIP"
is "auction today is PRESERVED"           "$(eligible '2026-08-17' "$NOW")" "SKIP"
is "future auction is PRESERVED"          "$(eligible '2026-12-25' "$NOW")" "SKIP"
is "datetime respects time-of-day"        "$(eligible '2026-07-18 14:30:00' "$NOW")" "29"
is "unix timestamp works"                 "$(eligible "$(date -d '2026-07-18' +%s)" "$NOW")" "30"
is "empty value is skipped"               "$(eligible '' "$NOW")" "SKIP"
is "garbage value is skipped"             "$(eligible 'not-a-date' "$NOW")" "SKIP"
is "US format is skipped, not guessed"    "$(eligible '07/18/2026' "$NOW")" "SKIP"
is "zero is skipped"                      "$(eligible '0' "$NOW")" "SKIP"

printf '\n== ordering: oldest first ==\n'
ORDER="$(printf '2026-08-09\n2026-07-01\n2026-08-01\n' | sort | tr '\n' ' ')"
is "ascending date sort is oldest-first" "$ORDER" "2026-07-01 2026-08-01 2026-08-09 "

printf '\n== json_string escaping ==\n'
is "escapes quotes"    "$(json_string 'a"b')"     '"a\"b"'
is "escapes backslash" "$(json_string 'a\b')"     '"a\\b"'

printf '\n-----------------------------------------\n'
printf 'PASSED: %s   FAILED: %s\n' "$PASS" "$FAIL"
(( FAIL == 0 )) || exit 1
