<?php
/**
 * RaxiWin build check — batata hai ki server par kaun se file ka kaun sa version
 * live hai, aur period chain kya ban rahi hai (bina guess kiye).
 *
 * Kholne ka tareeka:  https://<domain>/raxiwin-build-check.php?key=b7f3c1a94e2d5068
 * Kaam ke baad DELETE kar dena.
 *
 * Ye sirf BATAta hai — kuch badalta nahi. Koi password/secret print nahi hota.
 */

$CHECK_KEY = 'b7f3c1a94e2d5068';
if (!hash_equals($CHECK_KEY, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}
header('Content-Type: text/plain; charset=utf-8');

function bc_line($text = '')
{
    echo $text . "\n";
}
function bc_head($title)
{
    bc_line();
    bc_line('=== ' . strtoupper($title) . ' ' . str_repeat('=', max(0, 62 - strlen($title))));
}

bc_line('RAXIWIN BUILD CHECK');
bc_line('time: ' . date('Y-m-d H:i:s') . '   php ' . PHP_VERSION);

/* ------------------------------------------------------ 1) file versions */
@ini_set('default_socket_timeout', '6');
bc_head('file versions');
$files = array(
    'saas_lottery/bootstrap_live_v4.php', 'saas_lottery/bootstrap_live_v3.php', 'saas_lottery/bootstrap.php',
    'draw-live-v4/index.php', 'draw-live-v3/index.php', 'draw/index.php',
);
$found = array();
foreach ($files as $rel) {
    $path = __DIR__ . '/' . $rel;
    if (!is_readable($path)) {
        bc_line(sprintf('  %-36s MISSING', $rel));
        continue;
    }
    $code = (string) file_get_contents($path);
    $found[$rel] = $code;
    $build = 'original';
    if (strpos($code, 'sl_instant_bet_candidates') !== false && strpos($code, 'sl_instant_shift_issue') !== false) {
        $build = 'v5 (tolerant gate)';
    } elseif (strpos($code, 'sl_instant_bettable_issue') !== false) {
        $build = 'v4';
    } elseif (strpos($code, 'sl_display_previous_current') !== false) {
        $build = 'v3';
    } elseif (strpos($code, 'rw_instant_result_item') !== false) {
        $build = 'v2';
    } elseif (strpos($code, 'raxiwin_display_own_period_results') !== false) {
        $build = 'v5-instant (adapter)';
    } elseif (strpos($code, 'raxiwin_display_previous_periods') !== false) {
        $build = 'ORIGINAL (result ek period aage)';
    } elseif (strpos($code, 'sl_display_previous_current') !== false) {
        $build = 'v1';
    }
    if (strpos($rel, 'draw-live-v4') === 0) {
        $build = strpos($code, 'rw5_shift_issue') !== false
            ? 'v5 (self-contained shift)'
            : (strpos($code, 'raxiwin_display_own_period_results') !== false ? 'v4 (bootstrap helper par depend)' : $build);
    } elseif (strpos($rel, 'draw/') === 0 || strpos($rel, 'draw-live-v3') === 0) {
        $build = strpos($code, 'sl_display_previous_current') !== false ? 'shift ON (v3+)' : 'no shift';
    }
    bc_line(sprintf('  %-36s %-42s mtime %s', $rel, $build, date('Y-m-d H:i:s', (int) filemtime($path))));
}
if (!is_readable(__DIR__ . '/raxiwin-build-check.php')) {
    bc_line('  (build-check apne aap ko padh nahi paya — permissions?)');
}

/* --------------------------------------------------------- 2) opcache */
bc_head('opcache');
if (!function_exists('opcache_get_status')) {
    bc_line('  opcache extension: not loaded (koi cache problem nahi)');
} else {
    $status = @opcache_get_status(false);
    if (!is_array($status) || empty($status['opcache_enabled'])) {
        bc_line('  opcache: loaded but disabled for web requests (theek hai)');
    } else {
        $ini = @ini_get('opcache.validate_timestamps');
        bc_line('  opcache enabled       : YES');
        bc_line('  validate_timestamps   : ' . ($ini === '' ? '?' : $ini) . (($ini === '0' || $ini === 0) ? '   <-- 0 = file badalne par bhi purana code chalta hai!' : ''));
        bc_line('  cached scripts        : ' . (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0));
        $scripts = $status['scripts'] ?? (function_exists('opcache_get_status') ? (@opcache_get_status(true)['scripts'] ?? array()) : array());
        $stale = 0;
        foreach ($files as $rel) {
            $path = __DIR__ . '/' . $rel;
            if (!is_readable($path)) {
                continue;
            }
            $real = (string) realpath($path);
            if ($real !== '' && isset($scripts[$real])) {
                $cachedTime = (int) ($scripts[$real]['timestamp'] ?? 0);
                $diskTime = (int) filemtime($path);
                $flag = $cachedTime < $diskTime ? '  <-- STALE CACHE (disk par naya code, opcache me purana)' : '  (fresh)';
                if ($cachedTime < $diskTime) {
                    $stale++;
                }
                bc_line(sprintf('    %-36s cached=%s disk=%s%s', $rel, date('H:i:s', $cachedTime), date('H:i:s', $diskTime), $flag));
            }
        }
        if ($stale > 0) {
            bc_line('  FIX: cPanel > Select PHP Version > "PHP Options"/"Extensions" me opcache reset karo,');
            bc_line('       ya hosting support se PHP-FPM restart karwao. (Tab tak upload ka asar nahi dikhega.)');
        }
    }
}

/* --------------------------------------------------------- 3) settings */
bc_head('settings (database)');
$dbConfig = null;
if (is_readable(__DIR__ . '/config/database.php')) {
    try { $dbConfig = include __DIR__ . '/config/database.php'; } catch (Throwable $e) { $dbConfig = null; }
}
mysqli_report(MYSQLI_REPORT_OFF);
$conn = null;
if (is_array($dbConfig) && function_exists('mysqli_init')) {
    try {
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        if (!@$conn->real_connect((string) $dbConfig['host'], (string) $dbConfig['user'], (string) $dbConfig['password'], (string) $dbConfig['database'], (int) $dbConfig['port'])) {
            $conn = null;
        }
    } catch (Throwable $e) {
        $conn = null;
    }
}
if (!$conn) {
    bc_line('  database: connection failed (isse period chain nahi niklega)');
} else {
    $keys = array('instant_result_enabled', 'betting_enabled', 'auto_settlement_enabled', 'result_sync_enabled');
    foreach ($keys as $key) {
        $value = '(not set)';
        $res = @$conn->query("SELECT setting_value FROM app_settings WHERE setting_key='" . $conn->real_escape_string($key) . "' LIMIT 1");
        if ($res && ($row = $res->fetch_row())) {
            $value = (string) $row[0];
        }
        bc_line(sprintf('  %-24s %s%s', $key, $value, $key === 'instant_result_enabled' ? '   (default ON; 0 = feature off)' : ''));
    }
}

/* ------------------------------------------------------ 4) period chain */
bc_head('live period chain');
$bootstrapped = false;
$needed = array(
    'saas_lottery/config_live_v4.php',
    'developer-maruf/conn.php',
    'developer-maruf/functions2.php',
    'developer-maruf/app_core_live_v4.php',
    'developer-maruf/vip_core.php',
);
$missing = array();
foreach ($needed as $rel) {
    if (!is_file(__DIR__ . '/' . $rel)) {
        $missing[] = $rel;
    }
}
if ($missing) {
    bc_line('  ye files nahi mili, isliye period chain skip kiya:');
    foreach ($missing as $rel) {
        bc_line('    ' . $rel);
    }
    bc_line('  (report chalti rahegi; bet/result inke bina bhi load nahi hote —');
    bc_line('   matlab upload adhura hai ya file ek folder andar chali gayi hai.)');
} else {
    try {
        require_once __DIR__ . '/saas_lottery/bootstrap_live_v4.php';
        $bootstrapped = true;
    } catch (Throwable $e) {
        bc_line('  bootstrap load failed: ' . $e->getMessage());
    }
}
if ($bootstrapped) {
    $game = 'WinGo_30S';
    $on = function_exists('sl_instant_result_enabled') ? sl_instant_result_enabled($game) : null;
    bc_line('  instant_result_enabled   : ' . ($on === null ? 'helper nahi mila (old bootstrap)' : ($on ? 'ON' : 'OFF')));
    try {
        // quick=1 -> provider ko network par nahi poochte (report hang na ho).
        $quick = isset($_GET['quick']) && $_GET['quick'] !== '0';
        $raw = $quick ? null : sl_provider_current($game);
        $rawSource = $quick ? 'local (quick=1)' : 'provider';
        if (!is_array($raw) || !isset($raw['current']['issueNumber'])) {
            $raw = sl_local_current($game);
            $rawSource = $quick ? 'local (quick=1)' : 'local (provider se nahi mila)';
        }
        $rawCurrent = (string) ($raw['current']['issueNumber'] ?? '');
        bc_line('  raw current source       : ' . $rawSource);
        bc_line('  raw current              : ' . $rawCurrent . '   (window ' . (isset($raw['current']['startTime']) ? date('H:i:s', (int) ((float) $raw['current']['startTime'] / 1000)) : '?')
            . ' -> ' . (isset($raw['current']['endTime']) ? date('H:i:s', (int) ((float) $raw['current']['endTime'] / 1000)) : '?') . ' UTC)');
        if ($rawCurrent !== '') {
            $shift1 = function_exists('sl_instant_shift_issue') ? sl_instant_shift_issue($game, $rawCurrent) : '';
            $shift2 = $shift1 !== '' && function_exists('sl_instant_shift_issue') ? sl_instant_shift_issue($game, $shift1) : '';
            bc_line('  shift1 (raw-1)           : ' . ($shift1 !== '' ? $shift1 : 'n/a'));
            bc_line('  shift2 (raw-2)           : ' . ($shift2 !== '' ? $shift2 : 'n/a'));
            if (function_exists('sl_display_previous_current')) {
                $served = sl_display_previous_current($game, array(
                    'previous' => array('issueNumber' => (string) ($raw['previous']['issueNumber'] ?? ''), 'startTime' => 0, 'endTime' => 0),
                    'current' => array('issueNumber' => $rawCurrent, 'startTime' => 0, 'endTime' => 0),
                    'next' => array('issueNumber' => (string) ($raw['next']['issueNumber'] ?? ''), 'startTime' => 0, 'endTime' => 0),
                ));
                bc_line('  draw me served current   : ' . (string) ($served['current']['issueNumber'] ?? '?') . ($on ? '  (shift ON)' : '  (shift OFF)'));
                $displayed = function_exists('sl_instant_shift_issue') ? sl_instant_shift_issue($game, (string) ($served['current']['issueNumber'] ?? '')) : '';
                bc_line('  app par period dikhega   : ' . ($displayed !== '' ? $displayed : '?') . '   (JS: served current - 1)');
            } else {
                bc_line('  draw me served current   : ' . $rawCurrent . '   (shift function nahi hai -> old bootstrap)');
            }
            if (function_exists('sl_instant_bet_candidates')) {
                $candidates = sl_instant_bet_candidates($game, $raw);
                bc_line('  bet accept honge         : ' . ($candidates ? implode(', ', $candidates) : 'none') . '   (v5 tolerant gate)');
            } else {
                bc_line('  bet accept honge         : n/a — bootstrap v5 nahi hai (isi liye bet 404 ho raha tha)');
            }
        }
    } catch (Throwable $e) {
        bc_line('  period chain failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------- verdict */
bc_head('verdict');
$bootstrapV5 = false;
$adapterV5 = false;
foreach ($found as $rel => $code) {
    if (strpos($rel, 'saas_lottery/bootstrap') === 0 && strpos($code, 'sl_instant_bet_candidates') !== false) {
        $bootstrapV5 = true;
    }
    if ($rel === 'draw-live-v4/index.php' && strpos($code, 'rw5_shift_issue') !== false) {
        $adapterV5 = true;
    }
}
if ($bootstrapV5 && $adapterV5) {
    bc_line('  Sab theek: v5 bootstrap + v5 adapter live hain. Bet lag jani chahiye aur');
    bc_line('  result usi response me aana chahiye. Phir bhi problem ho to mujhe ye page bhejo.');
} elseif (!$bootstrapV5) {
    bc_line('  PROBLEM: bootstrap v5 nahi hai. Bet gate purana hai -> bet 404 hoti hai.');
    bc_line('  FIX: ZIP ke teeno bootstrap file upload karo (wahi 6 files, ek hi ZIP se):');
    bc_line('       saas_lottery/bootstrap.php, bootstrap_live_v3.php, bootstrap_live_v4.php');
    bc_line('       (aur saath me draw-live-v4/index.php bhi, taaki sab ek hi build ka ho.)');
} elseif (!$adapterV5) {
    bc_line('  Bootstrap v5 hai par draw-live-v4/index.php purana hai.');
    bc_line('  FIX: ZIP se draw-live-v4/index.php upload kar do (baaki files theek hain).');
}
bc_line();
bc_line('end of report  |  delete raxiwin-build-check.php after use');
