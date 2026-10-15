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
    'sl_instant_result_enabled', 'sl_instant_shift_issue', 'sl_instant_previous_issue', 'sl_instant_bettable_issue', 'sl_instant_bet_candidates', 'sl_display_previous_current') as $name) {
    ev_extract($bootstrap, $name);
}
ev_extract($adapter, 'raxiwin_display_own_period_results');
ev_extract($adapter, 'rw5_shift_issue');
ev_extract($adapter, 'rw5_instant_on');
ev_extract($adapter, 'rw5_served_current');

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
chk(substr_count($adapter, 'rw5_shift_issue($gameCode, (string) $payload[$key][\'issueNumber\'])') === 1, 'v5 draw me local shift call');

foreach (array('draw/index.php', 'draw-live-v3/index.php') as $rel) {
    $text = file_get_contents($ROOT . '/' . $rel);
    chk(substr_count($text, 'sl_display_previous_current($gameCode, $payload)') === 1, "$rel me shift call");
    chk(strpos($text, 'raxiwin_display_previous_periods') === false, "$rel me purana relabel nahi");
}


/* ======================= D) chain: app period == history top row == result === */
/* App JS: e.issue = BigInt(draw.current.issueNumber) - 1  (useLottery hook) */
$appPeriod = (string) ((int) $display['current']['issueNumber'] - 1);
$servedRaw = rw5_served_current('WinGo_30S', $provider['current']['issueNumber']);
$ceiling   = rw5_shift_issue('WinGo_30S', $servedRaw);

chk($ceiling === $appPeriod, 'chain: history ceiling == app ka displayed period', "ceiling=$ceiling appPeriod=$appPeriod");
chk($ceiling === '20261015100051102', 'chain: ceiling = raw-2 (shifted config)', $ceiling);
chk(rw5_served_current('WinGo_30S', $provider['current']['issueNumber']) === '20261015100051103', 'chain: served current = raw-1');

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
chk(count($shown) === 10, 'chain: 10 rows response me', 'count=' . count($shown));
chk($shown[0]['issueNumber'] === $appPeriod, 'chain: history top row == app ka period', $shown[0]['issueNumber'] ?? '');
chk((string)$shown[0]['number'] === '5', 'chain: top row ka apna result (1102 -> 5)', 'number=' . ($shown[0]['number'] ?? ''));
chk(strcmp((string)$shown[0]['issueNumber'], '20261015100051103') < 0, 'chain: koi row raw-1 se aage nahi');

/* ======================= E) v5: tolerant gate (bet 404 fix) ============== */
/* provider ka raw current 1104 (feed) -> candidates = raw-1, raw-2, raw-3 */
$candidates = sl_instant_bet_candidates('WinGo_30S', $provider);
chk($candidates === array('20261015100051103','20261015100051102','20261015100051101'), 'v5: candidates = raw-1, raw-2, raw-3', json_encode($candidates));

/* app ke alag paths alag label bhejte hain — dono accept hone chahiye */
$servedCurrent = rw5_served_current('WinGo_30S', '20261015100051104');           // draw me jo "current" jayega
$displayedPeriod = rw5_shift_issue('WinGo_30S', $servedCurrent);                  // JS ka display period
chk($servedCurrent === '20261015100051103', 'v5: served current = raw-1 (shift ON)', $servedCurrent);
chk($displayedPeriod === '20261015100051102', 'v5: displayed period = raw-2', $displayedPeriod);
chk(in_array($servedCurrent, $candidates, true), 'v5: app jab served current bhejta hai to bet accept (path 1)');
chk(in_array($displayedPeriod, $candidates, true), 'v5: app jab displayed period bhejta hai to bet accept (path 2)');
chk(in_array('20261015100051101', $candidates, true), 'v5: latency margin (raw-3) bhi accept');

/* unshifted configuration (draw shift off / toggle off): app bhejta raw-1 */
$GLOBALS['RW_INSTANT'] = '0';
chk(rw5_served_current('WinGo_30S', '20261015100051104') === '20261015100051104', 'v5: toggle OFF -> draw unshifted', rw5_served_current('WinGo_30S', '20261015100051104'));
chk(sl_instant_bet_candidates('WinGo_30S', $provider) === array('20261015100051103','20261015100051102','20261015100051101'), 'v5: candidates toggle ke bina bhi nikalte hain (bet accept hoga)', json_encode(sl_instant_bet_candidates('WinGo_30S', $provider)));
$GLOBALS['RW_INSTANT'] = '1';

/* K3 / TrxWinGo par koi candidate nahi */
chk(sl_instant_bet_candidates('K3_1M', $provider) === array(), 'v5: K3 par instant candidates nahi');
chk(sl_instant_bet_candidates('TrxWinGo_1M', $provider) === array(), 'v5: TrxWinGo par instant candidates nahi');

/* v4-style candidate (raw-2) bhi set me hai — pichhla gate bhi kaam karta rahe */
chk(in_array(sl_instant_bettable_issue('WinGo_30S', $provider), $candidates, true), 'v5: v4 ka bettable issue bhi candidates me');

/* ---- history ceiling v5: served current se derive hota hai ---- */
$ceilingV5 = rw5_shift_issue('WinGo_30S', rw5_served_current('WinGo_30S', '20261015100051104'));
chk($ceilingV5 === '20261015100051102', 'v5: ceiling = served current - 1 (shifted config)', $ceilingV5);
$ceilingV5off = rw5_shift_issue('WinGo_30S', rw5_served_current('WinGo_30S', '20261015100051104'));
chk($ceilingV5off === '20261015100051102', 'v5: ceiling unshifted config me bhi displayed period ke barabar');
$shownV5 = raxiwin_display_own_period_results('WinGo_30S', $dbRows, $ceilingV5);
chk(count($shownV5) === 10 && $shownV5[0]['issueNumber'] === '20261015100051102', 'v5: history top row = displayed period', 'count=' . count($shownV5) . ' top=' . ($shownV5[0]['issueNumber'] ?? ''));
chk((string)$shownV5[0]['number'] === '5', 'v5: top row par uska apna result', 'number=' . ($shownV5[0]['number'] ?? ''));

/* ---- static: gate + response guard ---- */
chk(substr_count($bootstrap, '$instantCandidates = sl_instant_result_enabled($gameCode) ? sl_instant_bet_candidates($gameCode, $current) : array();') === 1, 'v5: gate candidates se chalta hai (toggle ke andar)');
/* toggle OFF -> koi tolerant candidate nahi (closed period par bet accept nahi hogi) */
$GLOBALS['RW_INSTANT'] = '0';
chk(sl_instant_result_enabled('WinGo_30S') === false, 'v5: toggle OFF detect hota hai');
chk((sl_instant_result_enabled('WinGo_30S') ? sl_instant_bet_candidates('WinGo_30S', $provider) : array()) === array(), 'v5: toggle OFF -> tolerant gate band (strict gate waisa hi)');
$GLOBALS['RW_INSTANT'] = '1';
chk((sl_instant_result_enabled('WinGo_30S') ? sl_instant_bet_candidates('WinGo_30S', $provider) : array()) !== array(), 'v5: toggle ON -> tolerant gate chalu');
chk(substr_count($bootstrap, '$instantBet = in_array($issue, $instantCandidates, true);') === 1, 'v5: gate in_array se decide karta hai');
chk(substr_count($bootstrap, 'if ($instantBet && sl_instant_result_enabled($gameCode)) {') === 1, 'v5: instant response toggle se guarded');
chk(substr_count($bootstrap, 'sl_settle_pending_bets($gameCode)') === 2, 'v6: sweep My History + WinLoss dono par laga hai');
chk(strpos($bootstrap, "X-Raxiwin-Build: v6") !== false, 'v6: response me build header jata hai');
chk(strpos($bootstrap, "Cache-Control: no-store") !== false, 'v6: API/draw jawab no-store (cache-proof)');
chk(strpos($adapter, "Cache-Control: no-store") !== false, 'v6: draw adapter bhi khud no-store bhejta hai');
chk(strpos($bootstrap, '$instantIssue = sl_instant_bettable_issue($gameCode, $current);') === false, 'v5: purana single-label gate call hata diya');
chk(strpos($adapter, 'raxiwin_display_result_ceiling') === false, 'v5: adapter ka purana ceiling helper hat gaya');
chk(substr_count($adapter, 'function rw5_shift_issue') === 1, 'v5: adapter self-contained shift');
chk(substr_count($adapter, 'rw5_instant_on($gameCode)') >= 2, 'v5: adapter rw5_instant_on use karta hai');
chk(substr_count($adapter, '$served = rw5_served_current($gameCode, $rawCurrent);') === 1, 'v5: ceiling served current se derive hota hai');
chk(strpos($adapter, 'sl_instant_shift_issue') === false, 'v5: adapter bootstrap helper par depend nahi karta');

$legacy = file_get_contents($ROOT . '/draw/index.php');
$legacy3 = file_get_contents($ROOT . '/draw-live-v3/index.php');
chk(strpos($legacy, "function_exists('sl_display_previous_current')") !== false, 'v5: draw/index.php guard lagaya');
chk(strpos($legacy3, "function_exists('sl_display_previous_current')") !== false, 'v5: draw-live-v3 guard lagaya');

/* ---- build check script ---- */
$checker = file_get_contents($ROOT . '/raxiwin-build-check.php');
chk($checker !== false && strpos($checker, 'RAXIWIN BUILD CHECK v2') !== false, 'v6: build-check v2 shipped');
chk(strpos($checker, 'http self-probe') !== false, 'v6: checker server se khud draw/history probe karta hai');
chk(strpos($checker, 'sl_settle_pending_bets') !== false, 'v6: checker pending-bet sweep report karta hai');
chk(strpos($checker, "isset(\$_GET['settle'])") !== false, 'v6: settlement sirf &settle=1 par chalta hai');
chk(strpos($checker, 'bc_utc') !== false, 'v6: checker UTC me window print karta hai (TZ confusion khatam)');
chk(strpos($checker, 'sl_instant_bet_candidates') !== false, 'v5: build-check v5 gate detect karta hai');
chk(strpos($checker, 'opcache_get_status') !== false, 'v5: build-check opcache staleness report karta hai');


/* =================== F) v6: pending-bet settlement sweep ================= */
/* Shipped sl_result_row() + sl_settle_pending_bets() ko stub DB / stub
   settlement ke saath chala kar dekhte hain ki purane pending bets settle
   hote hain ya nahi (yahi "history me kuch aa nahi raha" ka fix hai). */
ev_extract($bootstrap, 'sl_result_row');
ev_extract($bootstrap, 'sl_provider_result_lookup');
ev_extract($bootstrap, 'sl_settle_pending_bets');

$GLOBALS['SL_SWEEP_PENDING'] = array('20261015100051519','20261015100051518');
$GLOBALS['SL_SWEEP_RESULTS'] = array('20261015100051518' => array('issue_number'=>'20261015100051518','premium'=>'2','color'=>'red'));
$GLOBALS['SL_SWEEP_SYNC'] = 0;
$GLOBALS['SL_SWEEP_SETTLED'] = array();
$GLOBALS['SL_SWEEP_LOCAL'] = array();

function rw_rows_for($sql, $params)
{
    if (strpos($sql, 'DISTINCT issue_number') !== false) {
        $out = array();
        foreach ($GLOBALS['SL_SWEEP_PENDING'] as $issue) {
            $out[] = array('issue_number' => $issue);
        }
        return $out;
    }
    if (strpos($sql, 'FROM saas_lottery_results') !== false) {
        // Query me placeholder (?) hota hai — bound value stub se aata hai.
        foreach ($params as $param) {
            $param = (string) $param;
            if (isset($GLOBALS['SL_SWEEP_RESULTS'][$param])) {
                return array($GLOBALS['SL_SWEEP_RESULTS'][$param]);
            }
        }
        return array();
    }
    return array();
}
class RwResult
{
    public $rows;
    private $i = 0;
    public function __construct($sql, $params) { $this->rows = rw_rows_for($sql, $params); }
    public function fetch_assoc() { return $this->i < count($this->rows) ? $this->rows[$this->i++] : null; }
}
class RwStmt
{
    private $sql;
    private $params = array();
    public function __construct($sql) { $this->sql = $sql; }
    public function bind_param($types = '', ...$values) { $this->params = $values; return true; }
    public function execute() { return true; }
    public function close() { return true; }
    public function get_result() { return new RwResult($this->sql, $this->params); }
}
class RwConn
{
    public function prepare($sql) { return new RwStmt($sql); }
}
$GLOBALS['conn'] = new RwConn();

$GLOBALS['SL_PROVIDER_PAGES'] = array();   // page => list of rows
$GLOBALS['SL_PROVIDER_CALLS'] = 0;
$GLOBALS['SL_SAVED_LISTS'] = 0;
function sl_sync_results($gameCode) { $GLOBALS['SL_SWEEP_SYNC']++; return null; }
function sl_provider_history($gameCode, $page, $pageSize) {
    $GLOBALS['SL_PROVIDER_CALLS']++;
    $pages = $GLOBALS['SL_PROVIDER_PAGES'];
    if (!isset($pages[$page])) { return null; }
    return array('data' => array('list' => $pages[$page]));
}
function sl_save_and_settle_results($gameCode, $list) {
    $GLOBALS['SL_SAVED_LISTS']++;
    // provider ka result DB me 'save' hone ka asar
    foreach ((array) $list as $item) {
        if (isset($item['issueNumber'], $item['premium'])) {
            $GLOBALS['SL_SWEEP_RESULTS'][(string) $item['issueNumber']] = array(
                'issue_number'=>(string) $item['issueNumber'], 'premium'=>(string) $item['premium'], 'color'=>'red');
        }
    }
    return null;
}
function sl_settle_issue($gameCode, $issue, $resultItem) { $GLOBALS['SL_SWEEP_SETTLED'][(string) $issue] = (string) $resultItem['premium']; }
function sl_local_result($gameCode, $issue) { $GLOBALS['SL_SWEEP_LOCAL'][] = (string) $issue; return array('issueNumber'=>(string) $issue, 'premium'=>'7'); }

/* --- instant mode ON: dono pending issue settle hone chahiye --- */
$GLOBALS['RW_INSTANT'] = '1';
$done = sl_settle_pending_bets('WinGo_30S');
chk($done === 2, 'v6 sweep: instant mode me 2 pending issue settle hue', 'done=' . $done);
chk($GLOBALS['SL_SWEEP_SYNC'] === 1, 'v6 sweep: provider sync sirf ek baar (network bachaya)', 'sync=' . $GLOBALS['SL_SWEEP_SYNC']);
chk(($GLOBALS['SL_SWEEP_SETTLED']['20261015100051518'] ?? '') === '2', 'v6 sweep: jiska result DB me tha wahi number use hua', json_encode($GLOBALS['SL_SWEEP_SETTLED']));
chk(($GLOBALS['SL_SWEEP_SETTLED']['20261015100051519'] ?? '') === '7', 'v6 sweep: jiska result nahi tha wo instant local number se settle hua');
chk($GLOBALS['SL_SWEEP_LOCAL'] === array('20261015100051519'), 'v6 sweep: local fallback sirf usi issue par chala', json_encode($GLOBALS['SL_SWEEP_LOCAL']));

/* --- instant mode OFF: sirf wo jiska result already hai --- */
$GLOBALS['RW_INSTANT'] = '0';
$GLOBALS['SL_SWEEP_SYNC'] = 0;
$GLOBALS['SL_SWEEP_SETTLED'] = array();
$GLOBALS['SL_SWEEP_LOCAL'] = array();
$done = sl_settle_pending_bets('WinGo_30S');
chk($done === 1, 'v6 sweep: toggle OFF par jiska result nahi hai wo pending rehta hai', 'done=' . $done);
chk($GLOBALS['SL_SWEEP_LOCAL'] === array(), 'v6 sweep: toggle OFF par koi local result invent nahi hota');
$GLOBALS['RW_INSTANT'] = '1';

/* --- purge issue ka asli result provider paging se aaye to fabricated na ho --- */
$GLOBALS['RW_INSTANT'] = '1';
$GLOBALS['SL_SWEEP_SETTLED'] = array();
$GLOBALS['SL_SWEEP_LOCAL'] = array();
$GLOBALS['SL_SWEEP_RESULTS'] = array();          // DB me kuch nahi
$GLOBALS['SL_PROVIDER_PAGES'] = array(
    // provider pages naya -> purana; 519 page 1 par, 518 page 2 par
    1 => array(
        array('issueNumber'=>'20261015100051520','premium'=>'3','color'=>'green'),
        array('issueNumber'=>'20261015100051519','premium'=>'9','color'=>'green'),
    ),
    2 => array(array('issueNumber'=>'20261015100051518','premium'=>'2','color'=>'red')),
);
$GLOBALS['SL_PROVIDER_CALLS'] = 0;
$done = sl_settle_pending_bets('WinGo_30S');
chk($done === 2, 'v6 sweep: paging se dono issue settle hue', 'done=' . $done);
chk(($GLOBALS['SL_SWEEP_SETTLED']['20261015100051518'] ?? '') === '2', 'v6 sweep: page 1 ka asli number use hua');
chk(($GLOBALS['SL_SWEEP_SETTLED']['20261015100051519'] ?? '') === '9', 'v6 sweep: page 2 ka asli number use hua (fabricated nahi)', json_encode($GLOBALS['SL_SWEEP_SETTLED']));
chk($GLOBALS['SL_SWEEP_LOCAL'] === array(), 'v6 sweep: asli result milne par local fallback bilkul nahi chala');
$GLOBALS['SL_PROVIDER_PAGES'] = array();
$GLOBALS['SL_SWEEP_RESULTS'] = array('20261015100051518' => array('issue_number'=>'20261015100051518','premium'=>'2','color'=>'red'));

/* --- koi pending nahi --- */
$GLOBALS['SL_SWEEP_PENDING'] = array();
chk(sl_settle_pending_bets('WinGo_30S') === 0, 'v6 sweep: pending na ho to 0 issue, koi sync bhi nahi');
$GLOBALS['SL_SWEEP_PENDING'] = array('20261015100051519','20261015100051518');

echo "\nRESULT  pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
