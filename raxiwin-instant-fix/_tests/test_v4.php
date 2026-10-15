<?php
/**
 * RaxiWin instant-result v3 tests (NOT shipped in the ZIP).
 *
 * Do hisse:
 *   A) History transform — shipped draw-live-v4/index.php se asli function
 *      extract karke, live fixture par (2026-10-15 ka WinGo_30S data).
 *   B) Period shift — shipped bootstrap se asli label arithmetic functions
 *      extract karke: game ka period label + result dono ek period piche.
 *
 * Fixture (aapki live site se, 2026-10-15):
 *   draw  : previous=...103 (08:51:30), current=...104 (08:52:00), next=...105
 *   feed  : ...104->4  ...103->4  ...102->5  ...101->6  ...100->9  ...099->4
 *           ...098->5  ...097->6  ...096->3  ...095->1
 */

$ROOT = '/site';
$adapter = file_get_contents($ROOT . '/draw-live-v4/index.php');
$bootstrap = file_get_contents($ROOT . '/saas_lottery/bootstrap_live_v4.php');
if ($adapter === false || $bootstrap === false) {
    echo "FAIL  shipped files could not be read\n";
    exit(1);
}

$pass = 0;
$fail = 0;
function chk($cond, $name, $detail = '')
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name  $detail\n"; }
}

/* ---------------------------------------- extract real functions + helpers */
function ev_extract($source, $name, $required = true)
{
    if (!preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n\}\n/s', $source, $m)) {
        if ($required) {
            echo "FAIL  function $name shipped file me nahi mili\n";
            exit(1);
        }
        return false;
    }
    eval($m[0]);
    return true;
}

foreach (array('sl_game_config', 'sl_game_family', 'sl_interval_seconds', 'sl_issue_number_for_start',
    'sl_instant_result_enabled', 'sl_instant_shift_issue', 'sl_instant_previous_issue', 'sl_instant_bettable_issue', 'sl_display_previous_current') as $name) {
    ev_extract($bootstrap, $name);
}
ev_extract($adapter, 'raxiwin_display_own_period_results');
ev_extract($adapter, 'raxiwin_display_result_ceiling');

$SL_CONFIG = array('games' => array(
    'WinGo_30S' => array('lottery' => 'WinGo', 'interval' => 0.5),
    'WinGo_1M' => array('lottery' => 'WinGo', 'interval' => 1),
    'WinGo_3M' => array('lottery' => 'WinGo', 'interval' => 3),
    'WinGo_5M' => array('lottery' => 'WinGo', 'interval' => 5),
    'K3_1M' => array('lottery' => 'K3', 'interval' => 1),
    'TrxWinGo_1M' => array('lottery' => 'TrxWinGo', 'interval' => 1),
));
$GLOBALS['RW_INSTANT'] = '1';
function app_setting_bool($key, $default = false)
{
    global $RW_INSTANT;
    if ($key !== 'instant_result_enabled') {
        return $default;
    }
    return in_array((string) $RW_INSTANT, array('1', 'true', 'on', 'yes'), true);
}
function sl_now_ms() { return 1792055520000; }

/* ======================= A) HISTORY: apne hi period ka result ============ */
$raw = array(
    array('issueNumber'=>'20261015100051104','number'=>'4','color'=>'red','premium'=>'4','sum'=>0),
    array('issueNumber'=>'20261015100051103','number'=>'4','color'=>'red','premium'=>'4','sum'=>0),
    array('issueNumber'=>'20261015100051102','number'=>'5','color'=>'green,violet','premium'=>'5','sum'=>0),
    array('issueNumber'=>'20261015100051101','number'=>'6','color'=>'red','premium'=>'6','sum'=>0),
    array('issueNumber'=>'20261015100051100','number'=>'9','color'=>'green','premium'=>'9','sum'=>0),
    array('issueNumber'=>'20261015100051099','number'=>'4','color'=>'red','premium'=>'4','sum'=>0),
    array('issueNumber'=>'20261015100051098','number'=>'5','color'=>'green,violet','premium'=>'5','sum'=>0),
    array('issueNumber'=>'20261015100051097','number'=>'6','color'=>'red','premium'=>'6','sum'=>0),
    array('issueNumber'=>'20261015100051096','number'=>'3','color'=>'green','premium'=>'3','sum'=>0),
    array('issueNumber'=>'20261015100051095','number'=>'1','color'=>'green','premium'=>'1','sum'=>0),
    array('issueNumber'=>'20261015100051094','number'=>'1','color'=>'green','premium'=>'1','sum'=>0),
);
$rawNumbers = array();
foreach ($raw as $row) { $rawNumbers[$row['issueNumber']] = $row['number']; }
$ceiling = '20261015100051103';                       // draw ka previous = displayed current

$history = raxiwin_display_own_period_results('WinGo_30S', $raw, $ceiling);
chk(is_array($history) && count($history) === 10, 'history: khula period hatne ke baad 10 rows', 'count=' . (is_array($history) ? count($history) : 'n/a'));
chk($history[0]['issueNumber'] === '20261015100051103', 'history top row = displayed current period (1103)', $history[0]['issueNumber'] ?? '');
chk($history[0]['number'] === '4', 'top row apna hi result dikhata hai (1103 -> 4)', 'number=' . ($history[0]['number'] ?? ''));

$pairingOk = true; $pairDetail = ''; $over = '';
foreach ($history as $row) {
    $issue = (string) $row['issueNumber'];
    if (strcmp($issue, $ceiling) > 0) { $over = $issue; }
    if (!isset($rawNumbers[$issue]) || $rawNumbers[$issue] !== (string) $row['number']) {
        $pairingOk = false; $pairDetail = $issue . ' -> ' . $row['number'];
    }
}
chk($pairingOk, 'har row apne hi period ka result (label == result ka period)', $pairDetail);
chk($over === '', 'koi bet-able / future period list me nahi', 'found=' . $over);

$row1102 = null;
foreach ($history as $row) { if ((string)$row['issueNumber'] === '20261015100051102') { $row1102 = $row; } }
chk($row1102 !== null && (string)$row1102['number'] === '5', 'purana off-by-one gaya: 1102 ab 5 (pehle 4)', 'number=' . ($row1102['number'] ?? 'missing'));

chk(count(raxiwin_display_own_period_results('K3_1M', $raw, $ceiling)) === count($raw), 'WinGo ke alawa games untouched');
chk(count(raxiwin_display_own_period_results('WinGo_30S', $raw, '')) === count($raw), 'ceiling khaali ho to list waise hi');
chk(count(raxiwin_display_own_period_results('WinGo_30S', $raw, '20261015100051000')) === count($raw), 'safety: sab rows ceiling se neeche na hon to original list');

/* ======================= B) GAME (period + result) ek period piche ====== */
$provider = array(
    'gameCode' => 'WinGo_30S',
    'intervalMinute' => 0.5,
    'state' => 1,
    'previous' => array('issueNumber'=>'20261015100051103','startTime'=>1792055490000,'endTime'=>1792055520000),
    'current'  => array('issueNumber'=>'20261015100051104','startTime'=>1792055520000,'endTime'=>1792055550000),
    'next'     => array('issueNumber'=>'20261015100051105','startTime'=>1792055550000,'endTime'=>1792055580000),
);

$display = sl_display_previous_current('WinGo_30S', $provider);
chk($display['current']['issueNumber'] === '20261015100051103', 'game current period ek piche (1104 -> 1103)', $display['current']['issueNumber'] ?? '');
chk($display['previous']['issueNumber'] === '20261015100051102', 'game previous bhi ek piche (1103 -> 1102)', $display['previous']['issueNumber'] ?? '');
chk($display['next']['issueNumber'] === '20261015100051104', 'game next bhi ek piche (1105 -> 1104)', $display['next']['issueNumber'] ?? '');
chk($display['current']['startTime'] === 1792055520000 && $display['current']['endTime'] === 1792055550000, 'countdown times untouched (current window same)', json_encode($display['current']));
chk($display['previous']['startTime'] === 1792055490000, 'previous window bhi untouched');
chk($display['gameCode'] === 'WinGo_30S' && $display['state'] === 1, 'baaki payload fields safe');

chk(sl_instant_previous_issue('WinGo_30S', $provider) === '20261015100051103', 'bet ka instant issue == displayed current (1103)', sl_instant_previous_issue('WinGo_30S', $provider));
chk(sl_instant_previous_issue('WinGo_30S', $provider) === $display['current']['issueNumber'], 'ek hi label: draw current == bet-able period');

/* draw (displayed current) aur history ka top row match karte hain? */
chk($display['current']['issueNumber'] === $history[0]['issueNumber'], 'draw ka period aur history ka top row ek hi label', $display['current']['issueNumber'] . ' vs ' . $history[0]['issueNumber']);
chk((string)$history[0]['number'] === '4', 'usi label ka result history me already dikh raha hai (instant bet par wahi aayega)');

/* other intervals */
/* Provider ke labels: 30S wale live fixture me label = formula(start) - 1 nikla,
   isliye yahan bhi wahi convention use karte hain. Shift label se hota hai. */
foreach (array(
    // game => [interval, current window start, expected displayed (one period back) label]
    'WinGo_1M' => array(60, 1792055520000, '20261015100010552'),
    'WinGo_3M' => array(180, 1792055400000, '20261015100020183'),
    'WinGo_5M' => array(300, 1792055100000, '20261015100030109'),
) as $game => $spec) {
    list($interval, $startMs, $expected) = $spec;
    $rawLabel = sl_issue_number_for_start($game, (int)($startMs / 1000));   // provider ka current label
    $nextSeq = (int)substr($rawLabel, -4) + 1;
    $payload = array(
        'gameCode' => $game,
        'previous' => array('issueNumber'=>sl_instant_shift_issue($game, $rawLabel),'startTime'=>($startMs/1000 - $interval)*1000,'endTime'=>$startMs),
        'current' => array('issueNumber'=>$rawLabel,'startTime'=>$startMs,'endTime'=>$startMs + $interval*1000),
        'next' => array('issueNumber'=>substr($rawLabel, 0, 12) . sprintf('%04d', $nextSeq),'startTime'=>$startMs + $interval*1000,'endTime'=>$startMs + 2*$interval*1000),
    );
    $shifted = sl_display_previous_current($game, $payload);
    chk($shifted['current']['issueNumber'] === $expected, "$game: displayed current ek period piche ($expected)", $shifted['current']['issueNumber'] . ' (window=' . $interval . 's)');
    chk($shifted['current']['startTime'] === $startMs, "$game: times untouched");
    chk(sl_instant_shift_issue($game, $expected) === sl_instant_shift_issue($game, $shifted['current']['issueNumber']), "$game: shift stable (dobara shift karne par wahi)");
}

/* day boundary: 00:00:10 UTC par 1M ka current (seq 1) -> pichle din ka seq 1440 */
$midnight = array(
    'gameCode' => 'WinGo_1M',
    'previous' => array('issueNumber'=>'20261014100011440','startTime'=>1792022340000,'endTime'=>1792022400000),
    'current' => array('issueNumber'=>'20261015100010001','startTime'=>1792022400000,'endTime'=>1792022460000),
    'next' => array('issueNumber'=>'20261015100010002','startTime'=>1792022460000,'endTime'=>1792022520000),
);
$midDisplay = sl_display_previous_current('WinGo_1M', $midnight);
chk($midDisplay['current']['issueNumber'] === '20261014100011440', 'day boundary: pichle din ka last period (20261014100011440)', $midDisplay['current']['issueNumber'] ?? '');
chk(substr($midDisplay['current']['issueNumber'], 0, 9) === '202610141', 'day boundary: date prefix pichla din');

/* non-WinGo + kill switch */
$trx = array('gameCode'=>'TrxWinGo_1M','current'=>array('issueNumber'=>'20261015100011200','startTime'=>1792055520000,'endTime'=>1792055580000));
$trxOut = sl_display_previous_current('TrxWinGo_1M', $trx);
chk($trxOut['current']['issueNumber'] === '20261015100011200', 'TrxWinGo par koi shift nahi');
chk(sl_instant_previous_issue('TrxWinGo_1M', $trx) === '', 'TrxWinGo instant bet nahi');

$GLOBALS['RW_INSTANT'] = '0';
$off = sl_display_previous_current('WinGo_30S', $provider);
chk($off['current']['issueNumber'] === '20261015100051104', 'toggle OFF -> period shift bhi off', $off['current']['issueNumber'] ?? '');
chk(sl_instant_previous_issue('WinGo_30S', $provider) === '', 'toggle OFF -> instant bet nahi');
$GLOBALS['RW_INSTANT'] = '1';

/* ======================= C) static: bootstrap hooks ====================== */
$gate = 'if (!$instantBet && ($currentIssue !== $issue || $endTime <= sl_now_ms() + ($lockSeconds * 1000))) {';
chk(substr_count($bootstrap, $gate) === 1, 'bet gate: displayed period accept karta hai, baaki waise hi');
chk(substr_count($bootstrap, '$instantBet = ($instantIssue !== \'\' && $issue === $instantIssue);') === 1, 'gate me $instantBet compute hota hai');
chk(substr_count($bootstrap, "\$response['instantResult'] = true;") === 1, 'bet response me instant outcome');
chk(substr_count($bootstrap, 'sl_instant_settle($gameCode, $issue);') === 2, 'bet ke waqt aur poll par settle (2 jagah)');
chk(strpos($bootstrap, 'rw_instant_result_item') === false, 'v2 ka extra helper hat gaya (file clean)');
chk(strpos($bootstrap, 'function sl_display_previous_current') !== false, 'draw shift helper mojood');
chk(substr_count($bootstrap, '$payload = sl_display_previous_current($gameCode, $payload);') === 0, 'bootstrap me adapter ka call nahi (wo draw files me hai)');

$adapterChecks = array(
    'pageSize 11 har page par' => "        \$input['pageSize'] = 11;",
    'purana -1 relabel function hat gaya' => null,
);
chk(substr_count($adapter, "        \$input['pageSize'] = 12;") === 1, 'pageSize 12 (page 1 par 2 row hatne ke baad bhi 10 rows)');
chk(strpos($adapter, 'raxiwin_display_previous_periods') === false, 'purane relabel function ka naam bacha nahi');
chk(substr_count($adapter, 'sl_display_previous_current($gameCode, $payload)') === 1, 'v4 draw me shift call');

foreach (array('draw/index.php', 'draw-live-v3/index.php') as $rel) {
    $text = file_get_contents($ROOT . '/' . $rel);
    chk(substr_count($text, 'sl_display_previous_current($gameCode, $payload)') === 1, "$rel me shift call");
    chk(strpos($text, 'raxiwin_display_previous_periods') === false, "$rel me purana relabel nahi");
}


/* ======================= D) v4: app period == history top row == result === */
/* App JS: e.issue = BigInt(draw.current.issueNumber) - 1  (useLottery hook) */
$appPeriod = (string) ((int) $display['current']['issueNumber'] - 1);
$ceiling   = raxiwin_display_result_ceiling('WinGo_30S', $provider['previous']['issueNumber']);

chk($ceiling === $appPeriod, 'v4: history ceiling == app ka displayed period', "ceiling=$ceiling appPeriod=$appPeriod");
chk($ceiling === '20261015100051102', 'v4: ceiling ek aur period piche (1103 -> 1102)', $ceiling);
chk(sl_instant_bettable_issue('WinGo_30S', $provider) === $appPeriod, 'v4: bet ka instant issue == app period == ceiling', sl_instant_bettable_issue('WinGo_30S', $provider));
chk(sl_instant_bettable_issue('WinGo_30S', $provider) === '20261015100051102', 'v4: bettable issue = raw current - 2 (1104 -> 1102)', sl_instant_bettable_issue('WinGo_30S', $provider));

$dbRows = array(   // feed sync se DB me jo rows hoti hain (provider labels + numbers)
    array('issueNumber'=>'20261015100051104','number'=>'4','color'=>'red','premium'=>'4','sum'=>0),
    array('issueNumber'=>'20261015100051103','number'=>'4','color'=>'red','premium'=>'4','sum'=>0),
    array('issueNumber'=>'20261015100051102','number'=>'5','color'=>'green,violet','premium'=>'5','sum'=>0),
    array('issueNumber'=>'20261015100051101','number'=>'6','color'=>'red','premium'=>'6','sum'=>0),
    array('issueNumber'=>'20261015100051100','number'=>'9','color'=>'green','premium'=>'9','sum'=>0),
    array('issueNumber'=>'20261015100051099','number'=>'4','color'=>'red','premium'=>'4','sum'=>0),
    array('issueNumber'=>'20261015100051098','number'=>'5','color'=>'green,violet','premium'=>'5','sum'=>0),
    array('issueNumber'=>'20261015100051097','number'=>'6','color'=>'red','premium'=>'6','sum'=>0),
    array('issueNumber'=>'20261015100051096','number'=>'3','color'=>'green','premium'=>'3','sum'=>0),
    array('issueNumber'=>'20261015100051095','number'=>'1','color'=>'green','premium'=>'1','sum'=>0),
    array('issueNumber'=>'20261015100051094','number'=>'6','color'=>'red','premium'=>'6','sum'=>0),
    array('issueNumber'=>'20261015100051093','number'=>'4','color'=>'red','premium'=>'4','sum'=>0),
);
$shown = raxiwin_display_own_period_results('WinGo_30S', $dbRows, $ceiling);
chk(count($shown) === 10, 'v4: 10 rows response me', 'count=' . count($shown));
chk($shown[0]['issueNumber'] === $appPeriod, 'v4: history top row == app ka period', $shown[0]['issueNumber'] ?? '');
chk((string)$shown[0]['number'] === '5', 'v4: top row ka apna result (1102 -> 5)', 'number=' . ($shown[0]['number'] ?? ''));
$instantResult = null;
foreach ($dbRows as $row) { if ((string)$row['issueNumber'] === $appPeriod) { $instantResult = (string)$row['number']; } }
chk($instantResult === (string)$shown[0]['number'], 'v4: bet lagate hi jo number settle hoga wahi history me top row par hai', "instant=$instantResult history=" . ($shown[0]['number'] ?? ''));
chk(strcmp((string)$shown[0]['issueNumber'], $appPeriod) === 0 && strcmp((string)$shown[0]['issueNumber'], '20261015100051103') < 0, 'v4: koi row app ke period se aage nahi');

/* helper edge cases */
chk(raxiwin_display_result_ceiling('K3_1M', '20261015100051103') === '20261015100051103', 'v4: K3 par ceiling waise hi');
chk(raxiwin_display_result_ceiling('TrxWinGo_1M', '20261015100051103') === '20261015100051103', 'v4: TrxWinGo par ceiling waise hi');
chk(raxiwin_display_result_ceiling('WinGo_30S', 'garbage') === 'garbage', 'v4: non-numeric issue waise hi');
$GLOBALS['RW_INSTANT'] = '0';
chk(raxiwin_display_result_ceiling('WinGo_30S', '20261015100051103') === '20261015100051103', 'v4: toggle OFF -> ceiling shift nahi');
$GLOBALS['RW_INSTANT'] = '1';

/* static wiring */
chk(substr_count($adapter, 'raxiwin_display_result_ceiling($gameCode, $expectedIssue)') === 1, 'v4: adapter me ceiling call');
chk(strpos($adapter, 'function raxiwin_display_result_ceiling') !== false, 'v4: helper shipped adapter me hai');
chk(substr_count($adapter, 'function raxiwin_display_own_period_results') === 1, 'v4: v3 ka own-period filter bacha hua');

echo "\nRESULT  pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
