<?php
/**
 * RaxiWin instant-result v2 — history transform tests (NOT shipped in the ZIP).
 *
 * Ye test shipped file se hi function uthata hai (regex extract + eval), isliye
 * test aur shipped code kabhi drift nahi kar sakte.
 *
 * Fixture = 2026-10-15 ko live site se mila asli data:
 *   GET /WinGo/WinGo_30S/GetHistoryIssuePage.json (purana, shifted endpoint)
 *     served labels 1103..1094 -> numbers 4,4,5,6,9,4,5,6,3,1
 *   purana code label ko -1 karta tha, matlab DB ki asli rows ye hain:
 *     1104->4, 1103->4, 1102->5, 1101->6, 1100->9, 1099->4, 1098->5, 1097->6, 1096->3, 1095->1
 *   us waqt draw endpoint ka previous issue 1103 tha (= ceiling).
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

/* ------------------------------------------------ extract + eval real code */
if (!preg_match('/function raxiwin_display_own_period_results\(.*?\n\}\n/s', $adapter, $m)) {
    echo "FAIL  raxiwin_display_own_period_results() shipped adapter me nahi mili\n";
    exit(1);
}
eval($m[0]);

/* ------------------------------------------------------------- fixtures */
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
);
$rawNumbers = array();
foreach ($raw as $row) { $rawNumbers[$row['issueNumber']] = $row['number']; }
$ceiling = '20261015100051103';   // draw endpoint ka previous issue

$out = raxiwin_display_own_period_results('WinGo_30S', $raw, $ceiling);

chk(is_array($out) && count($out) === 9, 'page 1: ek row (bet-able period) hat gayi', 'count=' . (is_array($out) ? count($out) : 'n/a'));
chk($out[0]['issueNumber'] === '20261015100051103', 'top row = current se ek period piche (1103)', $out[0]['issueNumber'] ?? '');
chk($out[0]['number'] === '4', 'top row apna hi number dikhata hai', 'number=' . ($out[0]['number'] ?? ''));

$pairingOk = true;
$pairDetail = '';
$overCeiling = '';
foreach ($out as $row) {
    $issue = (string) $row['issueNumber'];
    if (strcmp($issue, $ceiling) > 0) { $overCeiling = $issue; }
    if (!isset($rawNumbers[$issue]) || $rawNumbers[$issue] !== (string) $row['number']) {
        $pairingOk = false;
        $pairDetail = $issue . ' -> ' . $row['number'];
    }
}
chk($pairingOk, 'har row apne hi period ka result (label == result ka period)', $pairDetail);
chk($overCeiling === '', 'koi bet-able/future period list me nahi', 'found=' . $overCeiling);

// purane bug ka proof: 1102 ka number pehle 4 dikhta tha (1103 ka), ab 5 hona chahiye
$row1102 = null;
foreach ($out as $row) { if ((string)$row['issueNumber'] === '20261015100051102') { $row1102 = $row; } }
chk($row1102 !== null && (string)$row1102['number'] === '5', 'purana off-by-one gaya: 1102 ab 5 dikhata hai (pehle 4)', 'number=' . ($row1102['number'] ?? 'missing'));

/* -------------------------------------------------------------- edge cases */
$k3 = raxiwin_display_own_period_results('K3_1M', $raw, $ceiling);
chk(count($k3) === count($raw), 'WinGo ke alawa games untouched', 'count=' . count($k3));

$noCeiling = raxiwin_display_own_period_results('WinGo_30S', $raw, '');
chk(count($noCeiling) === count($raw), 'ceiling khaali ho to list waise hi', 'count=' . count($noCeiling));

$allAbove = raxiwin_display_own_period_results('WinGo_30S', $raw, '20261015100051000');
chk(count($allAbove) === count($raw), 'safety: sab rows ceiling se neeche na hon to original list', 'count=' . count($allAbove));

$messy = array(
    array('issueNumber'=>'20261015100051104','number'=>'4'),
    array('issueNumber'=>'not-an-issue','number'=>'7'),
    array('issueNumber'=>'20261015100051100','number'=>'9'),
);
$messyOut = raxiwin_display_own_period_results('WinGo_30S', $messy, $ceiling);
chk(count($messyOut) === 2 && $messyOut[1]['issueNumber'] === '20261015100051100', 'non-numeric issue keep hota hai, future nahi', json_encode(array_column($messyOut, 'issueNumber')));

/* ------------------------------------------------- static / no-regression */
chk(strpos($adapter, 'function raxiwin_display_previous_periods') === false, 'purana -1 relabel function shipped file se hat gaya');
chk(strpos($adapter, 'raxiwin_display_previous_periods') === false, 'purane function ka koi call bacha nahi');
chk(strpos($adapter, 'raxiwin_display_own_period_results') !== false, 'naya transform shipped adapter me hai');
chk(strpos($adapter, 'array_slice($data[\'list\'], 0, 10)') !== false, 'page 1 ka extra row slice hota hai (10 rows response me)');
chk(substr_count($adapter, 'pageSize\'] = $input[\'pageNo\'] === 1 ? 11 : 10') === 1, 'page 1 par 11 rows maangta hai, baaki pages 10');

chk(strpos($bootstrap, 'sl_display_previous_current') === false, 'bootstrap me draw label shift hata diya');
chk(strpos($bootstrap, 'sl_instant_previous_issue') === false, 'label-arithmetic helper hata diya');
chk(substr_count($bootstrap, 'function rw_instant_result_item') === 1, 'naya rw_instant_result_item helper mojood');
chk(substr_count($bootstrap, 'sl_settle_issue($gameCode, $issue, $instantItem)') === 1, 'bet ke waqt turant settle hota hai');
chk(substr_count($bootstrap, "\$response['instantResult'] = true;") === 1, 'bet response me instant outcome jodta hai');
chk(substr_count($bootstrap, 'if ($currentIssue !== $issue || $endTime <= sl_now_ms() + ($lockSeconds * 1000))') === 1, 'bet gate normal (koi shift-based exception nahi)');
chk(substr_count($bootstrap, 'sl_instant_settle($gameCode, $issue);') === 1, 'GetWinLossResult poll wala settle hook bacha hua hai');
chk(strpos($bootstrap, 'function sl_instant_result_enabled') !== false, 'toggle helper mojood (instant_result_enabled)');

echo "\nRESULT  pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
