<?php
/**
 * DMFirst recharge-gate check — batata hai ki "recharge required" gate OFF hua
 * ya nahi, aur app (browser) ko kya jawab mil raha hai.
 *
 * Kholo:  https://dmfirst.club9.eu.cc/dmfirst-recharge-check.php?key=b7f3c1a94e2d5068
 * Kaam ke baad DELETE kar dena. Ye kuch badalta nahi, sirf report karta hai.
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

function rc_line($text = '')
{
    echo $text . "\n";
}
function rc_head($title)
{
    rc_line();
    rc_line('=== ' . strtoupper($title) . ' ' . str_repeat('=', max(0, 58 - strlen($title))));
}
function rc_read($rel)
{
    $path = __DIR__ . '/' . $rel;
    return is_readable($path) ? (string) file_get_contents($path) : null;
}
/** Patched file se app ko milne wali values nikaalo. */
function rc_gate_values($code)
{
    if ($code === null || !preg_match('/\$res\[\'data\'\]\s*=\s*\[(.*?)\];/s', $code, $m)) {
        return null;
    }
    $values = array();
    foreach (array('canDirectToGame', 'userRechargeTimes', 'allowNoRechargeGame', 'userRechargeAmount', 'lowestRechargeAmountToGame') as $key) {
        if (preg_match("/'" . preg_quote($key, '/') . "'\s*=>\s*([^,\n]+)/", $m[1], $mm)) {
            $values[$key] = trim($mm[1]);
        }
    }
    return $values ? $values : null;
}
/** App ke JS gate ka wahi faisla (assets/js/index-DHU_0nuy.js se). */
function rc_app_decision($values, $userRechargeAmount)
{
    if (!is_array($values)) {
        return 'unknown';
    }
    $trim = function ($v) { return trim((string) $v, "\"'"); };
    $ae = $trim($values['allowNoRechargeGame'] ?? '0');
    $ue = (int) $trim($values['userRechargeTimes'] ?? '0');
    $ge = $trim($values['lowestRechargeAmountToGame'] ?? '0');
    $D = strtolower($trim($values['canDirectToGame'] ?? 'false')) === 'true';
    if ($ae === '1' || $D) {
        return 'open';
    }
    if ((float) $ge != 0.0 && (float) $ge > (float) $userRechargeAmount) {
        return 'popup_min_recharge';
    }
    if ((float) $ge === 0.0 && $ue === 0) {
        return 'popup_recharge_required';
    }
    return 'open';
}

rc_line('DMFIRST RECHARGE-GATE CHECK');
rc_line('time: ' . gmdate('Y-m-d H:i:s') . ' UTC   php ' . PHP_VERSION);
rc_line('docroot: ' . __DIR__);

/* ------------------------------------------------------------ 1) files */
rc_head('files');
$allowRel = 'devmaruf/api/webapi/GetAllowBetSetting.php';
$allow = rc_read($allowRel);
$gateOff = false;
$values = null;
if ($allow === null) {
    rc_line('  ' . $allowRel);
    rc_line('    MISSING — is path par file nahi mili (upload adhura hai?)');
} else {
    $values = rc_gate_values($allow);
    $trim = function ($v) { return trim((string) $v, "\"'"); };
    $gateOff = is_array($values)
        && (trim((string) ($values['allowNoRechargeGame'] ?? ''), "\"'") === '1'
            || strtolower(trim((string) ($values['canDirectToGame'] ?? 'false'))) === 'true');
    $junk = strpos($allow, 'typeNameCode') !== false;
    rc_line('  ' . $allowRel);
    rc_line('    sha256 ' . substr(hash('sha256', $allow), 0, 16) . '   ' . strlen($allow) . ' bytes   mtime '
        . date('Y-m-d H:i:s', (int) filemtime(__DIR__ . '/' . $allowRel)));
    if (is_array($values)) {
        foreach ($values as $key => $value) {
            rc_line(sprintf('    %-28s %s', $key, $value));
        }
        rc_line('    gate: ' . ($gateOff ? 'OFF ✓ (bina recharge ke game khulega)' : 'ABHI BHI ON (purana build)'));
    } else {
        rc_line('    response block parse nahi hua — file expected version hai kya?');
    }
    rc_line('    junk category branch : ' . ($junk
        ? 'ABHI BHI HAI (recharge kar chuke users ko galat jawab jata hai — purana build)'
        : 'hata diya gaya ✓'));
    rc_line('    signature/JWT flow   : ' . ((strpos($allow, 'is_jwt_valid') !== false && strpos($allow, 'Wrong signature') !== false)
        ? 'intact ✓' : 'MISSING! galat file hai'));
}

$loadedRel = 'devmaruf/api/webapi/GetLoadedSetting.php';
$loaded = rc_read($loadedRel);
if ($loaded === null) {
    rc_line('  ' . $loadedRel . '   MISSING (optional — sirf first-recharge popup ke liye)');
} else {
    $json = json_decode($loaded, true);
    $popup = is_array($json) ? ($json['data']['needPopupFirstRecharge'] ?? null) : null;
    rc_line('  ' . $loadedRel);
    rc_line('    valid JSON: ' . (is_array($json) ? 'HA ✓' : 'NAHI — file kharab!')
        . '   needPopupFirstRecharge: ' . ($popup === null ? '(nahi mila)' : ($popup ? 'true (popup AAYEGA)' : 'false (popup band ✓)')));
}

/* legacy betting files (app inko use nahi karta, sirf jaankari) */
rc_head('legacy betting files (jaankari)');
foreach (array('GameBetting.php', 'K3GameBetting.php', 'Game5DBetting.php') as $name) {
    $rel = 'devmaruf/api/webapi/' . $name;
    $code = rc_read($rel);
    if ($code === null) {
        rc_line('  ' . $name . '   (nahi mili)');
        continue;
    }
    $minGate = strpos($code, 'minRechargeRequired') !== false || strpos($code, '>= 100') !== false;
    rc_line('  ' . $name . '   min-recharge check: ' . ($minGate ? 'HAA (purana legacy gate)' : 'nahi'));
}
rc_line('  note: app ke WinGo/K3/5D bets /Lottery/* (SaaS) se jate hain, in legacy');
rc_line('        files se nahi — isliye zip me ye nahi hain. Zaroorat padi to bolo.');

/* -------------------------------------------------------------- 2) DB */
rc_head('database');
$dbConfig = null;
if (is_readable(__DIR__ . '/config/database.php')) {
    try { $dbConfig = include __DIR__ . '/config/database.php'; } catch (Throwable $e) { $dbConfig = null; }
}
if (!is_array($dbConfig) || !function_exists('mysqli_connect')) {
    rc_line('  config/database.php ya mysqli nahi mila -> skip');
} else {
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }
    $host = (string) ($dbConfig['host'] ?? '127.0.0.1');
    $port = (int) ($dbConfig['port'] ?? 3306);
    $user = (string) ($dbConfig['user'] ?? ($dbConfig['username'] ?? ''));
    $pass = (string) ($dbConfig['password'] ?? ($dbConfig['pass'] ?? ''));
    $dbname = (string) ($dbConfig['database'] ?? ($dbConfig['dbname'] ?? ''));
    try {
        $conn = @new mysqli($host, $user, $pass, $dbname, $port);
    } catch (Throwable $e) {
        $conn = null;
        rc_line('  connect exception: ' . $e->getMessage());
    }
    if (!$conn || $conn->connect_errno) {
        rc_line('  connection failed (' . ($conn ? $conn->connect_error : 'exception') . ')');
    } else {
        rc_line('  connected: db=' . $dbname . '  host=' . $host);
        $res = @$conn->query('SELECT allowbet, min_recharge FROM web_setting LIMIT 1');
        if ($res && ($row = $res->fetch_assoc())) {
            rc_line('  web_setting: allowbet=' . (string) $row['allowbet'] . '  min_recharge=' . (string) $row['min_recharge']
                . '   (patched file in values ko ignore karti hai — gate OFF hai)');
        } else {
            rc_line('  web_setting row nahi mili (patched file ko isse farak nahi padta)');
        }
        $res = @$conn->query('SELECT COUNT(*) AS c, COALESCE(SUM(motta),0) AS s FROM thevani WHERE sthiti = 1');
        if ($res && ($row = $res->fetch_assoc())) {
            rc_line('  thevani (approved recharges): ' . (int) $row['c'] . ' rows, total ' . (string) $row['s']);
        } else {
            rc_line('  thevani query fail (patched file me recharge count 0 dikhega, kuch tootega nahi)');
        }
        $res = @$conn->query('SELECT COUNT(*) AS c FROM shonu_subjects');
        if ($res && ($row = $res->fetch_assoc())) {
            rc_line('  users (shonu_subjects): ' . (int) $row['c']);
        }
        $conn->close();
    }
}

/* -------------------------------------------------- 3) HTTP self-probe */
rc_head('http self-probe');
$base = ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http')
    . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$probes = array(
    'GetAllowBetSetting (POST-only)' => '/devmaruf/api/webapi/GetAllowBetSetting',
    'SaaS game list' => '/Lottery/GetGameList',
    'WinGo 30S draw' => '/WinGo/WinGo_30S.json',
);
foreach ($probes as $label => $path) {
    $started = microtime(true);
    $context = stream_context_create(array(
        'http' => array('timeout' => 8, 'ignore_errors' => true, 'header' => "Cache-Control: no-cache\r\n"),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
    ));
    $body = @file_get_contents($base . $path, false, $context);
    $took = (int) round((microtime(true) - $started) * 1000);
    rc_line('  --- ' . $label . '  (' . $path . ')');
    if ($body === false || $body === '') {
        rc_line('    request fail (' . $took . ' ms) — server khud ko call nahi kar paya');
        continue;
    }
    $json = json_decode((string) $body, true);
    $summary = 'body: ' . substr(preg_replace('/\s+/', ' ', (string) $body), 0, 110);
    if (is_array($json)) {
        if (isset($json['msg']) && $json['msg'] === 'Method not allowed') {
            $summary = 'JSON ok — "Method not allowed" (matlab endpoint live hai, sirf POST leta hai) ✓';
        } elseif (isset($json['result'])) {
            $summary = 'JSON ok — result=' . var_export($json['result'], true)
                . (isset($json['data'][0]['gameList']) ? ' (' . count($json['data']) . ' game families)' : '')
                . (isset($json['current']['issueNumber']) ? '  current=' . $json['current']['issueNumber'] : '');
        } else {
            $summary = 'JSON ok — code=' . (string) ($json['code'] ?? '?') . ' msg=' . (string) ($json['msg'] ?? '?');
        }
    }
    rc_line('    ' . $took . 'ms  ' . $summary);
}

/* ------------------------------------------------------------ verdict */
rc_head('verdict');
if ($allow === null) {
    rc_line('  File upload nahi hui. ZIP se is exact path par upload karo:');
    rc_line('    devmaruf/api/webapi/GetAllowBetSetting.php');
} elseif ($gateOff) {
    rc_line('  Gate OFF hai — koi bhi user bina recharge ke game khol sakta hai,');
    rc_line('  aur naya payload dene ke baad "recharge required" popup nahi aayega.');
    if (is_array($values)) {
        foreach (array(0, 50, 500) as $amount) {
            rc_line('    test: user ka recharge ₹' . $amount . ' -> app ka faisla: '
                . rc_app_decision($values, $amount));
        }
    }
    rc_line('  User ko ek baar app band karke dobara kholna hoga (purani JS cache),');
    rc_line('  ya browser me "clear site data" — warna purana screen dikh sakta hai.');
} else {
    rc_line('  PROBLEM: file purani hai (gate ON). Neeche wala file upload karo:');
    rc_line('    devmaruf/api/webapi/GetAllowBetSetting.php');
}
rc_line();
rc_line('end of report  |  delete dmfirst-recharge-check.php after use');
