<?php
/**
 * RaxiWin build check — batata hai ki server par kaun se file ka kaun sa version
 * live hai, app ko draw/history me kya mil raha hai (server se khud probe karke),
 * aur aapki pending bets kyun settle nahi hui.
 *
 * Kholne ka tareeka:  https://<domain>/raxiwin-build-check.php?key=b7f3c1a94e2d5068
 * Settlement bhi chala kar dekhna ho to:  ...&settle=1
 * Provider ko chhod kar (fast report) chahiye to:  ...&quick=1
 * Kaam ke baad DELETE kar dena.
 *
 * Ye sirf BATAta hai (settle=1 ke bina kuch badalta nahi). Koi password/secret
 * print nahi hota.
 */

$CHECK_KEY = 'b7f3c1a94e2d5068';
if (!hash_equals($CHECK_KEY, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function bc_line($text = '')
{
    echo $text . "\n";
}
function bc_head($title)
{
    bc_line();
    bc_line('=== ' . strtoupper($title) . ' ' . str_repeat('=', max(0, 62 - strlen($title))));
}
/** UTC me time (report me TZ ki gadbad na ho). */
function bc_utc($ms)
{
    $sec = (int) round(((float) $ms) / 1000);
    return gmdate('H:i:s', $sec) . ' UTC';
}
function bc_json_from($body)
{
    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}

@ini_set('default_socket_timeout', '8');
bc_line('RAXIWIN BUILD CHECK v2');
bc_line('time: ' . gmdate('Y-m-d H:i:s') . ' UTC   php ' . PHP_VERSION
    . '   host-tz ' . date_default_timezone_get() . ' (' . date('H:i') . ')');
bc_line('docroot: ' . __DIR__);

/* ------------------------------------------------------ 1) file versions */
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
    if (strpos($code, 'sl_settle_pending_bets') !== false) {
        $build = 'v6 (sweep + tolerant gate)';
    } elseif (strpos($code, 'sl_instant_bet_candidates') !== false) {
        $build = 'v5 (tolerant gate)';
    } elseif (strpos($code, 'sl_instant_bettable_issue') !== false) {
        $build = 'v4';
    } elseif (strpos($code, 'sl_display_previous_current') !== false) {
        $build = 'v3';
    } elseif (strpos($code, 'rw_instant_result_item') !== false) {
        $build = 'v2';
    } elseif (strpos($code, 'raxiwin_display_own_period_results') !== false) {
        $build = 'instant (adapter)';
    } elseif (strpos($code, 'raxiwin_display_previous_periods') !== false) {
        $build = 'ORIGINAL (result ek period aage)';
    }
    if (strpos($rel, 'draw-live-v4') === 0) {
        $build = strpos($code, 'rw5_shift_issue') !== false
            ? 'adapter v5+ (self-contained shift)'
            : (strpos($code, 'raxiwin_display_own_period_results') !== false ? 'v4 (bootstrap helper par depend)' : $build);
    } elseif (strpos($rel, 'draw/') === 0 || strpos($rel, 'draw-live-v3') === 0) {
        $build = strpos($code, 'sl_display_previous_current') !== false ? 'shift ON (v3+)' : 'no shift';
    }
    bc_line(sprintf('  %-36s %-42s %s', $rel, $build, substr(hash('sha256', $code), 0, 16)));
    bc_line(sprintf('  %-36s %-42s mtime %s | %d bytes', '', '', date('Y-m-d H:i:s', (int) filemtime($path)), strlen($code)));
}

/* --------------------------------------------------------- 2) opcache */
bc_head('opcache');
if (!function_exists('opcache_get_status')) {
    bc_line('  opcache extension: not loaded (koi opcache problem nahi)');
} else {
    $status = @opcache_get_status(false);
    if (!is_array($status) || empty($status['opcache_enabled'])) {
        bc_line('  opcache: loaded but disabled for web requests (theek hai)');
    } else {
        $ini = @ini_get('opcache.validate_timestamps');
        bc_line('  opcache enabled       : YES');
        bc_line('  validate_timestamps   : ' . ($ini === '' ? '?' : $ini) . (($ini === '0' || $ini === 0) ? '   <-- 0 = file badalne par bhi purana code chalta hai!' : ''));
        bc_line('  cached scripts        : ' . (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0));
        $scripts = $status['scripts'] ?? array();
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
            bc_line('  FIX: cPanel > Select PHP Version > PHP Options me opcache reset karo, ya host se');
            bc_line('       PHP-FPM restart karwao (tab tak upload ka asar nahi dikhega).');
        }
    }
}

/* --------------------------------------------------------- 3) settings */
bc_head('settings (database)');
$dbConfig = null;
if (is_readable(__DIR__ . '/config/database.php')) {
    try { $dbConfig = include __DIR__ . '/config/database.php'; } catch (Throwable $e) { $dbConfig = null; }
}
$conn = null;
if (is_array($dbConfig) && function_exists('mysqli_connect')) {
    $host = (string) ($dbConfig['host'] ?? '127.0.0.1');
    $port = (int) ($dbConfig['port'] ?? 3306);
    $user = (string) ($dbConfig['user'] ?? ($dbConfig['username'] ?? ''));
    $pass = (string) ($dbConfig['password'] ?? ($dbConfig['pass'] ?? ''));
    $dbname = (string) ($dbConfig['database'] ?? ($dbConfig['dbname'] ?? ''));
    bc_line('  config: ' . $host . ':' . $port . '  db=' . $dbname . '  user=' . $user);
    // PHP 8.1+ me mysqli exception throw karta hai — report ko marna nahi chahiye.
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }
    try {
        $conn = @new mysqli($host, $user, $pass, $dbname, $port);
    } catch (Throwable $e) {
        $conn = null;
        bc_line('  database: connect exception (' . $e->getMessage() . ')  -> aage ke DB checks skip');
    }
    if (!$conn || $conn->connect_errno) {
        if ($conn) {
            bc_line('  database: connection failed (' . $conn->connect_error . ')  -> aage ke DB checks skip');
        }
        $conn = null;
    } else {
        bc_line('  database: connected');
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
} else {
    bc_line('  config/database.php nahi mila ya mysqli nahi hai -> DB checks skip');
}

/* ------------------------------------------------- 4) live period chain */
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
$rawCurrent = '';
$servedCurrent = '';
$appPeriod = '';
$candidates = array();
if ($missing) {
    bc_line('  ye files nahi mili, isliye period chain skip kiya:');
    foreach ($missing as $rel) {
        bc_line('    ' . $rel);
    }
    bc_line('  (upload adhura hai ya files ek folder andar chali gayi hain.)');
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
    bc_line('  settle sweep available   : ' . (function_exists('sl_settle_pending_bets') ? 'YES (v6)' : 'NO (v6 se pehle ka build)'));
    try {
        $quick = isset($_GET['quick']) && $_GET['quick'] !== '0';
        $raw = $quick ? null : sl_provider_current($game);
        $rawSource = $quick ? 'local (quick=1)' : 'provider';
        if (!is_array($raw) || !isset($raw['current']['issueNumber'])) {
            $raw = sl_local_current($game);
            $rawSource = $quick ? 'local (quick=1)' : 'local (provider se nahi mila)';
        }
        $rawCurrent = (string) ($raw['current']['issueNumber'] ?? '');
        bc_line('  raw current source       : ' . $rawSource);
        bc_line('  raw current              : ' . $rawCurrent
            . (isset($raw['current']['startTime']) ? '   (window ' . bc_utc($raw['current']['startTime']) . ' -> ' . bc_utc($raw['current']['endTime'] ?? 0) . ')' : ''));
        if ($rawCurrent !== '') {
            $shift1 = function_exists('sl_instant_shift_issue') ? sl_instant_shift_issue($game, $rawCurrent) : '';
            $shift2 = $shift1 !== '' && function_exists('sl_instant_shift_issue') ? sl_instant_shift_issue($game, $shift1) : '';
            bc_line('  shift1 (raw-1)           : ' . ($shift1 !== '' ? $shift1 : 'n/a'));
            bc_line('  shift2 (raw-2)           : ' . ($shift2 !== '' ? $shift2 : 'n/a'));
            if (function_exists('sl_display_previous_current')) {
                $servedPayload = sl_display_previous_current($game, array(
                    'previous' => array('issueNumber' => (string) ($raw['previous']['issueNumber'] ?? '')),
                    'current' => array('issueNumber' => $rawCurrent),
                    'next' => array('issueNumber' => (string) ($raw['next']['issueNumber'] ?? '')),
                ));
                $servedCurrent = (string) ($servedPayload['current']['issueNumber'] ?? '');
                bc_line('  bootstrap ka served      : ' . $servedCurrent . ($on ? '  (shift ON)' : '  (shift OFF)'));
                $appPeriod = $servedCurrent !== '' && function_exists('sl_instant_shift_issue') ? sl_instant_shift_issue($game, $servedCurrent) : '';
                bc_line('  app par period dikhega   : ' . ($appPeriod !== '' ? $appPeriod : '?') . '   (JS: served current - 1)');
            } else {
                bc_line('  bootstrap ka served      : n/a (shift helper nahi mila -> old bootstrap)');
            }
            if (function_exists('sl_instant_bet_candidates')) {
                $candidates = sl_instant_bet_candidates($game, $raw);
                bc_line('  bet accept honge         : ' . ($candidates ? implode(', ', $candidates) : 'none') . '   (tolerant gate)');
            } else {
                bc_line('  bet accept honge         : n/a — bootstrap purana hai (isi liye bet 404 hoti thi)');
            }
        }
    } catch (Throwable $e) {
        bc_line('  period chain failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------- 5) bets + settlement */
bc_head('bets & settlement (WinGo_30S)');
if (!$conn) {
    bc_line('  database nahi mila -> skip');
} else {
    $rows = array();
    $res = @$conn->query("SELECT id,user_id,issue_number,status,stake,payout,result_premium,created_at FROM saas_lottery_bets WHERE game_code='WinGo_30S' ORDER BY id DESC LIMIT 12");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    if (!$rows) {
        bc_line('  koi bet row nahi mili (ya saas_lottery_bets table nahi hai)');
    } else {
        bc_line('  last bets (naya pehle): id | user | issue | status | stake | payout | result | time');
        foreach ($rows as $row) {
            bc_line(sprintf('    %-6s %-5s %-19s %-8s %-9s %-9s %-4s %s',
                $row['id'], $row['user_id'], $row['issue_number'], $row['status'],
                $row['stake'], $row['payout'], ($row['result_premium'] === null ? '-' : $row['result_premium']), $row['created_at']));
        }
    }
    $pending = array();
    $res = @$conn->query("SELECT DISTINCT issue_number FROM saas_lottery_bets WHERE game_code='WinGo_30S' AND status='pending' ORDER BY issue_number DESC LIMIT 50");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pending[] = (string) $row['issue_number'];
        }
    }
    $settleable = 0;
    $withResult = array();
    $withoutResult = array();
    foreach ($pending as $issue) {
        $has = false;
        $res = @$conn->query("SELECT premium FROM saas_lottery_results WHERE game_code='WinGo_30S' AND issue_number='" . $conn->real_escape_string($issue) . "' LIMIT 1");
        if ($res && ($row = $res->fetch_row())) {
            $has = true;
        }
        if ($has) {
            $settleable++;
            $withResult[] = $issue;
        } else {
            $withoutResult[] = $issue;
        }
    }
    bc_line('  pending issues           : ' . (count($pending) ? count($pending) : 0) . ($pending ? '  (' . implode(', ', array_slice($pending, 0, 8)) . (count($pending) > 8 ? ' …' : '') . ')' : ''));
    bc_line('  jinka result DB me hai   : ' . $settleable . ($withResult ? '  (' . implode(', ', array_slice($withResult, 0, 8)) . ')' : ''));
    if ($withoutResult) {
        bc_line('  jinka result nahi hai    : ' . count($withoutResult) . '  (' . implode(', ', array_slice($withoutResult, 0, 8)) . ')');
        bc_line('    -> v6 in pending bets ko instant mode me local deterministic result se');
        bc_line('       settle kar deta hai (wahi number jo bet ke waqt decide hota hai).');
    }
    $res = @$conn->query("SELECT issue_number,premium,color FROM saas_lottery_results WHERE game_code='WinGo_30S' ORDER BY id DESC LIMIT 6");
    if ($res) {
        $line = array();
        while ($row = $res->fetch_assoc()) {
            $line[] = $row['issue_number'] . '->' . $row['premium'];
        }
        bc_line('  last results in DB       : ' . ($line ? implode('  ', $line) : 'koi nahi'));
    }
    if (isset($_GET['settle']) && $_GET['settle'] !== '0' && function_exists('sl_settle_pending_bets')) {
        bc_line('  settle=1 -> sweep chala rahe hain …');
        try {
            $done = sl_settle_pending_bets('WinGo_30S');
            bc_line('  sweep ne ' . (int) $done . ' issue process kiye');
        } catch (Throwable $e) {
            bc_line('  sweep failed: ' . $e->getMessage());
        }
    } else {
        bc_line('  (settle chalane ke liye end me &settle=1 laga do — v6 build par chalega)');
    }
}

/* ------------------------------------------------ 6) HTTP self-probe */
bc_head('http self-probe (app ko actually kya mil raha hai)');
$probeBase = ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http')
    . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$uniq = substr(md5((string) microtime(true) . (string) mt_rand()), 0, 8);
$probes = array(
    'draw (app ka URL)' => '/draw-live-v4/WinGo/WinGo_30S.json',
    'draw (fresh key)' => '/draw-live-v4/WinGo/WinGo_30S.json?cb=' . $uniq,
    'history (app ka URL)' => '/WinGo/WinGo_30S/GetHistoryIssuePage.json',
    'history (fresh key)' => '/WinGo/WinGo_30S/GetHistoryIssuePage.json?cb=' . $uniq . 'b',
);
foreach ($probes as $label => $path) {
    $url = $probeBase . $path;
    $body = '';
    $started = microtime(true);
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "Cache-Control: no-cache\r\nPragma: no-cache\r\n",
        ),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
    ));
    $body = @file_get_contents($url, false, $context);
    $took = (int) round((microtime(true) - $started) * 1000);
    bc_line('  --- ' . $label . '   (' . $path . ')');
    if ($body === false || $body === '') {
        bc_line('    request fail (' . $took . ' ms) — server khud ko call nahi kar paya (firewall/loopback)');
        continue;
    }
    $status = '?';
    $wanted = array('server', 'cf-cache-status', 'age', 'x-litespeed-cache', 'cache-control', 'x-raxiwin-build', 'content-type');
    $hits = array();
    foreach ((array) ($http_response_header ?? array()) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = $m[1];
        }
        $parts = explode(':', $header, 2);
        if (count($parts) === 2 && in_array(strtolower(trim($parts[0])), $wanted, true)) {
            $hits[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    $pretty = array();
    foreach ($hits as $key => $value) {
        $pretty[] = $key . '=' . $value;
    }
    bc_line('    http=' . $status . '  ' . $took . 'ms  ' . ($pretty ? implode('  ', $pretty) : 'koi cache/build header nahi'));
    $json = bc_json_from($body);
    if (is_array($json) && isset($json['current']['issueNumber'])) {
        $served = (string) $json['current']['issueNumber'];
        bc_line('    served: previous ' . (string) ($json['previous']['issueNumber'] ?? '?')
            . '  current ' . $served . '  next ' . (string) ($json['next']['issueNumber'] ?? '?'));
        $window = isset($json['current']['startTime']) ? bc_utc($json['current']['startTime']) . ' -> ' . bc_utc($json['current']['endTime'] ?? 0) : '?';
        bc_line('    current window: ' . $window);
        if ($rawCurrent !== '') {
            if ($served === $rawCurrent) {
                bc_line('    => SHIFT LAGU NAHI HUI (raw label hi ja raha hai) — ya purani cached copy hai');
            } elseif ($servedCurrent !== '' && $served === $servedCurrent) {
                bc_line('    => shift theek chal rahi hai (served = raw - 1)');
            } else {
                bc_line('    => label raw/shift dono se alag hai -> cache ki sambhavna (age/window dekho)');
            }
        }
    } elseif (is_array($json) && isset($json['data']['list'])) {
        $list = (array) $json['data']['list'];
        $line = array();
        foreach (array_slice($list, 0, 3) as $item) {
            $line[] = (string) ($item['issueNumber'] ?? '?') . '->' . (string) ($item['number'] ?? ($item['premium'] ?? '?'));
        }
        bc_line('    rows=' . count($list) . '  pageNo=' . (string) ($json['data']['pageNo'] ?? '?')
            . '  totalPage=' . (string) ($json['data']['totalPage'] ?? '?') . '  top: ' . implode('  ', $line));
        if ($appPeriod !== '' && isset($list[0]['issueNumber'])) {
            $top = (string) $list[0]['issueNumber'];
            if (strcmp($top, $appPeriod) === 0) {
                bc_line('    => history top row == app ka period ✓');
            } elseif (strcmp($top, $appPeriod) > 0) {
                bc_line('    => history app ke period se AAGE hai (' . $top . ' > ' . $appPeriod . ') — ceiling lagu nahi hui');
            } else {
                bc_line('    => history app ke period se piche hai (theek/normal)');
            }
        }
    } else {
        bc_line('    body parse nahi hua: ' . substr(preg_replace('/\s+/', ' ', (string) $body), 0, 120));
    }
}

/* ------------------------------------------------------------- verdict */
bc_head('verdict');
$bootstrapV6 = false;
$adapterV5 = false;
foreach ($found as $rel => $code) {
    if (strpos($rel, 'saas_lottery/bootstrap') === 0 && strpos($code, 'sl_settle_pending_bets') !== false) {
        $bootstrapV6 = true;
    }
    if ($rel === 'draw-live-v4/index.php' && strpos($code, 'rw5_shift_issue') !== false) {
        $adapterV5 = true;
    }
}
if ($bootstrapV6 && $adapterV5) {
    bc_line('  Bootstrap v6 + adapter v5+ live hain: bet lag jani chahiye, result usi');
    bc_line('  response me, aur pending bets My History kholte hi settle ho jayenge.');
    bc_line('  Agar phir bhi purana period/result dikhe: upar "http self-probe" ke age/');
    bc_line('  window dekho — cache ka chakkar hai to host ka page cache / CDN purge karo.');
} elseif (!$bootstrapV6) {
    bc_line('  PROBLEM: bootstrap v6 nahi hai — pending bets ka sweep aur tolerant bet');
    bc_line('  gate isi me aate hain.');
    bc_line('  FIX: is ZIP ki teeno bootstrap files + draw-live-v4/index.php upload karo');
    bc_line('       (public_html ke andar, ek folder andar nahi).');
} elseif (!$adapterV5) {
    bc_line('  Bootstrap v6 hai par draw-live-v4/index.php purana hai.');
    bc_line('  FIX: ZIP se draw-live-v4/index.php upload kar do.');
}
bc_line();
bc_line('end of report  |  delete raxiwin-build-check.php after use');
