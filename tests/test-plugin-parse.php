<?php
/**
 * Tests Afimex_Nightly_Maintenance::parse_auction_date — the function that
 * decides which vehicles are eligible for permanent deletion in the plugin.
 * Stubs the minimal WordPress surface needed to load the plugin file.
 *
 * Run: php tests/test-plugin-parse.php
 */
define('ABSPATH', '/tmp/');
define('YEAR_IN_SECONDS', 31536000);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function add_action(...$a) {}
function wp_timezone() { return new DateTimeZone('UTC'); }

require __DIR__ . '/../wordpress-plugin/afimex-nightly-maintenance.php';

$pass = 0; $fail = 0;
function is_eq($name, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  ok   $name\n"; }
    else { $fail++; echo "  FAIL $name\n     expected: " . var_export($expected, true) . "\n     actual:   " . var_export($actual, true) . "\n"; }
}
$p = fn($v) => Afimex_Nightly_Maintenance::parse_auction_date($v);

echo "== parse_auction_date ==\n";
is_eq('ISO date',              $p('2026-07-18'),           strtotime('2026-07-18 00:00:00 UTC'));
is_eq('ISO datetime',          $p('2026-07-18 14:30:00'),  strtotime('2026-07-18 14:30:00 UTC'));
is_eq('ISO datetime no secs',  $p('2026-07-18 14:30'),     strtotime('2026-07-18 14:30:00 UTC'));
is_eq('unix timestamp',        $p('1784332800'),           1784332800);
is_eq('timestamp as int-ish',  $p(' 1784332800 '),         1784332800);
is_eq('empty preserved',       $p(''),                     false);
is_eq('zero preserved',        $p('0'),                    false);
is_eq('garbage preserved',     $p('not-a-date'),           false);
is_eq('US format preserved',   $p('07/18/2026'),           false);
is_eq('8-digit num preserved', $p('20260718'),             false);
is_eq('impossible date',       $p('2026-13-45'),           false);
is_eq('partial iso preserved', $p('2026-07'),              false);
is_eq('sql zero preserved',    $p('0000-00-00'),           false);
// Timestamp plausibility window: only now +/- 10 years is believable.
is_eq('9-digit ts (2001) preserved',   $p('999999999'),   false);
is_eq('far-future ts (2100) preserved', $p('4102444800'), false);
is_eq('11-digit ts (2286) preserved',  $p('99999999999'), false);

// Eligibility boundary: >7 days deletes, <=7 preserves (spec section 5).
$now = strtotime('2026-08-17 04:00:00 UTC');
$age = fn($v) => (int) floor(($now - $p($v)) / 86400);
is_eq('8d ago eligible (age)',  $age('2026-08-09 03:00:00') > 7, true);
is_eq('exactly 7d preserved',   $age('2026-08-10 04:00:00') > 7, false);
is_eq('30d ago eligible',       $age('2026-07-18') > 7, true);

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
