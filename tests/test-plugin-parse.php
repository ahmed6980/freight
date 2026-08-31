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
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

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

$C = 'Afimex_Nightly_Maintenance';

echo "\n== migrate_settings (v2: 30 GB ceiling, derived tiers) ==\n";
$m = $C::migrate_settings(['storage_limit_gb' => 40, 'threshold_aggressive' => 35, 'threshold_remove_old' => 38]);
is_eq('40 GB clamps to 30',        $m['storage_limit_gb'], 30);
is_eq('aggressive key removed',    array_key_exists('threshold_aggressive', $m), false);
is_eq('remove_old key removed',    array_key_exists('threshold_remove_old', $m), false);
is_eq('25 GB stays 25',            $C::migrate_settings(['storage_limit_gb' => 25])['storage_limit_gb'], 25);
is_eq('junk clamps to floor 5',    $C::migrate_settings(['storage_limit_gb' => 'abc'])['storage_limit_gb'], 5);
is_eq('empty stays empty',         $C::migrate_settings([]), []);

echo "\n== storage_tier boundaries at limit 30 ==\n";
$gib = 1024 * 1024 * 1024;
$cfg = ['storage_limit_gb' => 30];
is_eq('24 GB -> NORMAL',      $C::storage_tier(24   * $gib, $cfg), 'NORMAL');
is_eq('26 GB -> AGGRESSIVE',  $C::storage_tier(26   * $gib, $cfg), 'AGGRESSIVE');
is_eq('28.5 GB -> REMOVE_OLD',$C::storage_tier(28.5 * $gib, $cfg), 'REMOVE_OLD');
is_eq('29.5 GB -> HARD_CLEAN',$C::storage_tier(29.5 * $gib, $cfg), 'HARD_CLEAN');
is_eq('30 GB -> EMERGENCY',   $C::storage_tier(30   * $gib, $cfg), 'EMERGENCY');

echo "\n== growth_projection ==\n";
$hist = fn(array $vals) => array_map(fn($v) => ['after' => $v], $vals);
$g = $C::growth_projection($hist([25.0, 25.0, 25.0, 25.0]), 30);
is_eq('flat history -> no ceiling ETA', $g['days_to_ceiling'], null);
$g = $C::growth_projection($hist([25.5, 26.0, 26.5, 27.0]), 30);
is_eq('+0.5/night rate',            $g['rate_gb_day'], 0.5);
is_eq('+0.5/night -> 6 days out',   $g['days_to_ceiling'], 6.0);
$g = $C::growth_projection($hist([28.0, 27.0, 26.0]), 30);
is_eq('shrinking -> no ceiling ETA', $g['days_to_ceiling'], null);
$g = $C::growth_projection($hist([25.0, 26.0]), 30);
is_eq('under 3 points -> null',      $g['days_to_ceiling'], null);

echo "\n== seo_parse_homepage ==\n";
$html = '<html><head><title>Afimex Logistics — US to Mauritania Shipping</title>'
      . '<meta name="description" content="Freight forwarding, vehicle purchasing from US auctions, and cargo tracking for shipments to Mauritania.">'
      . '<link rel="canonical" href="https://afimex.com/">'
      . '<meta name="robots" content="index, follow"></head><body></body></html>';
$hp = $C::seo_parse_homepage($html);
is_eq('title extracted',      $hp['title'], 'Afimex Logistics — US to Mauritania Shipping');
is_eq('description found',    $hp['meta_description'] !== null, true);
is_eq('canonical found',      $hp['canonical'], 'https://afimex.com/');
is_eq('not noindex',          $hp['noindex'], false);
$hp = $C::seo_parse_homepage('<meta content="Swapped order desc that is long enough to be looked at properly here." name="description"><meta content="noindex" name="robots">');
is_eq('attr order swapped: desc found', $hp['meta_description'] !== null, true);
is_eq('attr order swapped: noindex',    $hp['noindex'], true);
$hp = $C::seo_parse_homepage('<html><head><title>x</title></head></html>');
is_eq('missing description is null', $hp['meta_description'], null);
is_eq('empty input safe', $C::seo_parse_homepage('')['title'], null);

echo "\n== email_subject ==\n";
is_eq('critical subject',
    $C::email_subject(['status' => 'CRITICAL', 'storage_after' => 31.2, 'storage_limit' => 30, 'mode' => 'EXECUTE'], 'afimex.com'),
    '[afimex.com] Maintenance CRITICAL — 31.2/30 GB');
is_eq('dry-run subject labeled',
    $C::email_subject(['status' => 'HEALTHY', 'storage_after' => 24.8, 'storage_limit' => 30, 'mode' => 'DRY RUN'], 'afimex.com'),
    '[afimex.com] Maintenance HEALTHY — 24.8/30 GB (dry run)');

echo "\n== build_email_html ==\n";
$report = [
    'status' => 'WARNING', 'mode' => 'EXECUTE', 'time' => '2026-08-31 04:00:00 EDT',
    'storage_before' => 26.1, 'storage_after' => 25.4, 'storage_limit' => 30,
    'tier' => 'NORMAL', 'reclaimed_gb' => 0.7,
    'counts' => ['files_deleted' => 12, 'pages_deleted' => 3, 'vehicles_deleted' => 3, 'vehicles_eligible' => 3, 'images_deleted' => 40, 'errors' => 0],
    'health' => ['ok' => true, 'db_ok' => true, 'homepage_code' => 200, 'archive_code' => 200, 'cron_ok' => true],
    'seo' => [['label' => 'Search engine visibility', 'ok' => true, 'detail' => 'indexing allowed']],
    'notices' => [['severity' => 'warning', 'text' => 'Something <script>alert(1)</script> worth knowing']],
    'breakdown' => ['wp-content/uploads/2026' => 20.1],
    'log' => ['[2026-08-31] EXEC: delete vehicle "Toyota <script>alert(2)</script>"'],
];
$mail = $C::build_email_html($report);
is_eq('notice script escaped', strpos($mail, '<script>alert(1)') === false, true);
is_eq('log script escaped',    strpos($mail, '<script>alert(2)') === false, true);
is_eq('escaped entity present', strpos($mail, '&lt;script&gt;') !== false, true);
is_eq('IMPORTANT box present when notices exist', strpos($mail, 'IMPORTANT INFORMATION') !== false, true);
is_eq('no stylesheet tags', preg_match('~<(style|link)\b~i', $mail), 0);
unset($report['notices']);
is_eq('IMPORTANT box absent without notices', strpos($C::build_email_html($report), 'IMPORTANT INFORMATION'), false);

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
