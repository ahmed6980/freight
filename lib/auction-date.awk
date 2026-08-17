# Auction-date eligibility filter.
#
# This is the single source of truth for deciding which vehicles are eligible
# for permanent deletion. lib/vehicles.sh and tests/test-logic.sh both run THIS
# file — the logic is never copied, so a passing test means the production path
# is what was tested.
#
# Input  (TSV): ID <TAB> auction_date <TAB> post_status <TAB> title
# Output (TSV): ID <TAB> auction_epoch <TAB> age_days <TAB> post_status <TAB> title
#               emitted only when age_days > ret
# Variables: now = current unix time, ret = retention days
#
# PORTABILITY: no {n,m} range intervals anywhere below. mawk (the default awk
# on Debian/Ubuntu) supports {n} but silently fails to match {n,m}. Using them
# here would make timestamp-formatted auction dates unparseable and quietly skip
# every vehicle forever. Length comparisons are used instead.

function to_epoch(v,   d, t) {
    gsub(/^[ \t]+|[ \t]+$/, "", v)

    # Unix timestamp (9-11 digits covers 1973..5138).
    if (v ~ /^[0-9]+$/ && length(v) >= 9 && length(v) <= 11)
        return v + 0

    # YYYY-MM-DD, optionally followed by HH:MM:SS.
    if (v ~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]/) {
        d = substr(v, 1, 10)
        t = "00 00 00"
        if (v ~ /[0-9][0-9]:[0-9][0-9]:[0-9][0-9]/) {
            match(v, /[0-9][0-9]:[0-9][0-9]:[0-9][0-9]/)
            t = substr(v, RSTART, RLENGTH)
            gsub(/:/, " ", t)
        }
        gsub(/-/, " ", d)
        return mktime(d " " t)
    }

    # Anything else is NOT guessed at. An unrecognised date means the vehicle
    # is preserved, never deleted.
    return -1
}

{
    e = to_epoch($2)

    if (e <= 0) {
        printf "UNPARSED\tID=%s\tvalue=%s\n", $1, $2 > "/dev/stderr"
        next
    }

    age = int((now - e) / 86400)

    # Strictly greater than the retention window. A vehicle exactly at the
    # boundary is preserved (requirement 5: "still within 7 days, preserve it").
    if (age > ret)
        printf "%s\t%d\t%d\t%s\t%s\n", $1, e, age, $3, $4
}
