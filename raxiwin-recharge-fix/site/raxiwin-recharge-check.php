<?php
/**
 * RaxiWin recharge-gate check — batata hai ki "recharge required" gate OFF hua
 * ya nahi, aur app ke hisaab se game khulega ya nahi.
 *
 * Kholne ka tareeka:  https://<domain>/raxiwin-recharge-check.php?key=b7f3c1a94e2d5068
 * Kaam ke baad DELETE kar dena. Ye kuch badalta nahi, sirf padhta hai.
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
/** File se app ko milne wale values nikaalo (jawab ko actually parse karke). */
function rc_gate_values($code)
{
    if ($code === null) {
        return null;
    }
    $block = null;
    if (preg_match('/\$data\s*=\s*\[(.*?)\];/s', $code, $m)) {
        $block = $m[1];
    }
    if ($block === null) {
        return null;
    }
    $values = array();
    foreach (array('canDirectToGame', 'userRechargeTimes', 'allowNoRechargeGame', 'userRechargeAmount', 'lowestRechargeAmountToGame') as $key) {
        if (preg_match("/'" . preg_quote($key, '/') . "'\s*=>\s*([^,\n]+)/", $block, $mm)) {
            $values[$key] = trim($mm[1]);
        }
    }
    return $values;
}

rc_line('RAXIWIN RECHARGE-GATE CHECK');
rc_line('time: ' . gmdate('Y-m-d H:i:s') . ' UTC   php ' . PHP_VERSION);

/* ------------------------------------------------- 1) file versions */
rc_head('files');
$allowRel = 'developer-maruf/api/webapi/GetAllowBetSetting.php';
$allow = rc_read($allowRel);
if ($allow === null) {
    rc_line('  ' . $allowRel);
    rc_line('    MISSING — file is path par nahi mili (upload adhura hai?)');
} else {
    $values = rc_gate_values($allow);
    $gateOff = is_array($values) && isset($values['allowNoRechargeGame'])
        && trim($values['allowNoRechargeGame'], '"\'') === '1';
    $direct = is_array($values) && isset($values['canDirectToGame'])
        && strtolower(trim($values['canDirectToGame'])) === 'true';
    rc_line('  ' . $allowRel . '   ' . substr(hash('sha256', $allow), 0, 16)
        . '   ' . date('Y-m-d H:i:s', (int) filemtime(__DIR__ . '/' . $allowRel)));
    if (!is_array($values)) {
        rc_line('    response block parse nahi hua — file kuch aur hai?');
    } else {
        foreach ($values as $key => $value) {
            rc_line(sprintf('    %-28s %s', $key, $value));
        }
        rc_line('    allowNoRechargeGame = "1"  -> ' . ($gateOff ? 'HA ✓' : 'NAHI (purana build)'));
        rc_line('    canDirectToGame = true     -> ' . ($direct ? 'HA ✓' : 'NAHI (purana build)'));
        rc_line('    app ka faisla (JS): ' . (($gateOff || $direct)
            ? 'game SEEDHA khulega, koi recharge popup nahi'
            : 'game block hoga -> "Game requires recharge to enter" popup'));
    }
    rc_line('    signature/JWT checks    : ' . (strpos($allow, 'is_jwt_valid') !== false ? 'intact ✓' : 'MISSING! ye galat file hai'));
}

$loadedRel = 'developer-maruf/api/webapi/GetLoadedSetting.php';
$loaded = rc_read($loadedRel);
if ($loaded === null) {
    rc_line('  ' . $loadedRel . '   MISSING (optional file — sirf first-recharge popup ke liye tha)');
} else {
    $json = json_decode($loaded, true);
    $popup = is_array($json) ? ($json['data']['needPopupFirstRecharge'] ?? null) : null;
    rc_line('  ' . $loadedRel . '   ' . substr(hash('sha256', $loaded), 0, 16)
        . '   ' . date('Y-m-d H:i:s', (int) filemtime(__DIR__ . '/' . $loadedRel)));
    rc_line('    valid JSON              : ' . (is_array($json) ? 'HA ✓' : 'NAHI — file kharab hai!'));
    rc_line('    needPopupFirstRecharge  : ' . ($popup === null ? '(nahi mila)' : ($popup ? 'true (popup AAYEGA)' : 'false (popup band ✓)')));
}

/* ------------------------------------------------- 2) DB sanity */
rc_head('database (thevani = recharge records)');
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
        rc_line('  connection failed' . ($conn ? ' (' . $conn->connect_error . ')' : ''));
    } else {
        rc_line('  connected: db=' . $dbname);
        $res = @$conn->query('SELECT COUNT(*) FROM thevani');
        if ($res && ($row = $res->fetch_row())) {
            rc_line('  thevani rows (kul recharge records): ' . (int) $row[0]);
        } else {
            rc_line('  thevani table nahi mili ya query fail — gate phir bhi OFF hai (kuch break nahi hoga)');
        }
        $res = @$conn->query('SELECT COUNT(*) FROM shonu_subjects');
        if ($res && ($row = $res->fetch_row())) {
            rc_line('  users (shonu_subjects)            : ' . (int) $row[0]);
        }
        $conn->close();
    }
}

/* ----------------------------------------------------- 3) verdict */
rc_head('verdict');
$gateOff = false;
$direct = false;
if ($allow !== null) {
    $values = rc_gate_values($allow);
    $gateOff = is_array($values) && isset($values['allowNoRechargeGame']) && trim($values['allowNoRechargeGame'], '"\'') === '1';
    $direct = is_array($values) && isset($values['canDirectToGame']) && strtolower(trim($values['canDirectToGame'])) === 'true';
}
if ($gateOff || $direct) {
    rc_line('  Gate OFF hai — koi bhi user bina recharge ke game khol sakta hai.');
    rc_line('  App me purani JS cache ho sakti hai: user ko ek baar app band karke');
    rc_line('  dobara kholna hoga (ya browser me "clear site data"), warna purana');
    rc_line('  code purana jawab de sakta hai.');
} elseif ($allow === null) {
    rc_line('  GetAllowBetSetting.php hi nahi mili -> upload karo:');
    rc_line('     developer-maruf/api/webapi/GetAllowBetSetting.php');
} else {
    rc_line('  PROBLEM: gate abhi bhi purana hai (allowNoRechargeGame "1" nahi).');
    rc_line('  FIX: ZIP se GetAllowBetSetting.php upload karo is exact path par:');
    rc_line('     developer-maruf/api/webapi/GetAllowBetSetting.php');
}
rc_line();
rc_line('end of report  |  delete raxiwin-recharge-check.php after use');
