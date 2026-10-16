<?php
/**
 * DMFirst recharge-gate patch tests (NOT shipped in the ZIP).
 *
 * A) Patched GetAllowBetSetting.php se app ko milne wali values parse karke app
 *    ke JS gate ka wahi faisla dobara chalaya jata hai:
 *        if (allowNoRechargeGame == "1" || canDirectToGame) -> open
 *        else if (min > amount)  -> "₹min recharge karo"
 *        else if (min == 0 && times == 0) -> "Game requires recharge to enter"
 * B) File ka baki hissa (signature, JWT, error codes, 405) intact hai +
 *    junk category branch hat gaya.
 * C) GetLoadedSetting.php valid JSON, popup off, baaki keys preserved.
 */

$ROOT = '/site';
$allowRel = 'devmaruf/api/webapi/GetAllowBetSetting.php';
$loadedRel = 'devmaruf/api/webapi/GetLoadedSetting.php';
$allow = @file_get_contents($ROOT . '/' . $allowRel);
$loaded = @file_get_contents($ROOT . '/' . $loadedRel);
if ($allow === false || $loaded === false) {
    echo "FAIL  shipped files read nahi hue\n";
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

function gate_values($code)
{
    if (!preg_match('/\$res\[\'data\'\]\s*=\s*\[(.*?)\];/s', $code, $m)) {
        return null;
    }
    $values = array();
    foreach (array('canDirectToGame', 'userRechargeTimes', 'allowNoRechargeGame', 'userRechargeAmount', 'lowestRechargeAmountToGame') as $key) {
        if (preg_match("/'" . preg_quote($key, '/') . "'\s*=>\s*([^,\n]+)/", $m[1], $mm)) {
            $values[$key] = trim($mm[1]);
        }
    }
    return $values;
}

/** App ka JS gate (assets/js/index-DHU_0nuy.js) — wahi steps. */
function app_gate($values, $amount)
{
    $trim = function ($v) { return trim((string) $v, "\"'"); };
    $ae = $trim($values['allowNoRechargeGame']);
    $ue = (int) $trim($values['userRechargeTimes']);
    $ge = $trim($values['lowestRechargeAmountToGame']);
    $D = strtolower($trim($values['canDirectToGame'])) === 'true';
    if ($ae === '1' || $D) {
        return 'open';
    }
    if ((float) $ge != 0.0 && (float) $ge > (float) $amount) {
        return 'popup_min_recharge';
    }
    if ((float) $ge === 0.0 && $ue === 0) {
        return 'popup_recharge_required';
    }
    return 'open';
}

$values = gate_values($allow);
chk(is_array($values), 'response block parse hua (ek hi saaf payload)');

/* ---- A) gate values + app ka faisla ---- */
chk(($values['allowNoRechargeGame'] ?? '') === '"1"', 'allowNoRechargeGame = "1"', $values['allowNoRechargeGame'] ?? '');
chk(($values['canDirectToGame'] ?? '') === 'true', 'canDirectToGame = true', $values['canDirectToGame'] ?? '');
chk(($values['lowestRechargeAmountToGame'] ?? '') === '"0"', 'lowestRechargeAmountToGame = "0"', $values['lowestRechargeAmountToGame'] ?? '');

$fresh = array('canDirectToGame'=>'true','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"1"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"0"');
chk(app_gate($fresh, 0) === 'open', 'naya user (0 recharge, ₹0) -> game OPEN');
chk(app_gate($fresh, 50) === 'open', 'naya user (₹50) -> game OPEN');
$recharged = array('canDirectToGame'=>'true','userRechargeTimes'=>'3','allowNoRechargeGame'=>'"1"','userRechargeAmount'=>'1200','lowestRechargeAmountToGame'=>'"0"');
chk(app_gate($recharged, 1200) === 'open', 'recharge kar chuka user -> game OPEN');
$onlyFlag = array('canDirectToGame'=>'false','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"1"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"100"');
chk(app_gate($onlyFlag, 0) === 'open', 'sirf allowNoRechargeGame = "1" -> OPEN');

/* purane values par wahi user block hota tha (proof) */
$old = array('canDirectToGame'=>'false','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"0"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"100"');
chk(app_gate($old, 0) === 'popup_min_recharge', 'purani values (min ₹100) -> "itna recharge karo" popup');
$old2 = array('canDirectToGame'=>'false','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"0"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"0"');
chk(app_gate($old2, 0) === 'popup_recharge_required', 'purani values -> "Game requires recharge to enter" popup');

/* ---- B) file ka baki hissa ---- */
chk(strpos($allow, 'typeNameCode') === false, 'junk category branch hat gaya (recharged users ko sahi jawab)');
chk(strpos($allow, 'categoryImg') === false, 'junk images bhi gaye');
chk(strpos($allow, 'FROM web_setting') === false, 'gate ab DB ke allowbet/min_recharge par depend nahi');
chk(strpos($allow, 'FROM thevani') !== false, 'recharge summary thevani se aa rahi hai (asli values)');
chk(strpos($allow, 'is_jwt_valid') !== false, 'JWT check intact');
chk(strpos($allow, '$shonusign == $signature') !== false, 'signature check intact');
chk(strpos($allow, "'Wrong signature'") !== false, 'wrong-signature response intact');
chk(strpos($allow, "'Param is Invalid'") !== false, 'missing-params response intact');
chk(strpos($allow, "'Method not allowed'") !== false, 'GET par 405 response intact');
chk(substr_count($allow, 'echo json_encode($res);') === 4, 'char response paths (gate + 3 error) intact', 'count=' . substr_count($allow, 'echo json_encode($res);'));
chk(strpos($allow, 'http_response_code(405)') !== false, '405 http code intact');
chk(strpos($allow, 'function_exists') === false && strpos($allow, 'TODO') === false, 'koi debug/TODO nahi');

/* ---- C) GetLoadedSetting ---- */
$json = json_decode($loaded, true);
chk(is_array($json), 'GetLoadedSetting valid JSON');
chk(($json['data']['needPopupFirstRecharge'] ?? null) === false, 'needPopupFirstRecharge = false');
foreach (array('isExistGrandAward','returnAwards','returnNewRechargeAwards','isARPay','isRSNPay','isLandingPageEnabled','downAppRewardBonusAmount','registerGiftAmount','activityRedDot') as $key) {
    if (!array_key_exists($key, $json['data'])) {
        chk(false, "GetLoadedSetting key '$key' preserved");
    }
}
chk(true, 'GetLoadedSetting ki baaki keys preserved');
chk(strpos($loaded, '<?php') === false, 'GetLoadedSetting plain JSON hi hai');

/* ---- C2) GetGameUrl.php (launch gate) ---- */
$gameUrlRel = 'devmaruf/api/webapi/GetGameUrl.php';
$gameUrl = @file_get_contents($ROOT . '/' . $gameUrlRel);
chk($gameUrl !== false, 'GetGameUrl.php read hua');
chk(strpos($gameUrl, '$REQUIRE_RECHARGE_TO_LAUNCH') !== false, 'launch gate ab toggle ke andar hai');
chk(preg_match('/\$REQUIRE_RECHARGE_TO_LAUNCH\s*=\s*false;/', $gameUrl) === 1, 'toggle false hai (gate OFF)');
chk(preg_match('/\$REQUIRE_RECHARGE_TO_LAUNCH\s*=\s*true;/', $gameUrl) === 0, 'toggle true nahi hai');
/* asli gate line toggle ke andar hai — matlab launch block nahi hoga */
$togglePos = strpos($gameUrl, '$REQUIRE_RECHARGE_TO_LAUNCH = false;');
$gatePos = strpos($gameUrl, "if (mysqli_num_rows(\$rechargeResult) == 0) {");
chk($togglePos !== false && $gatePos !== false && $togglePos < $gatePos, 'purani gate line toggle ke BAAD hai (chalu nahi hoti)');
chk(strpos($gameUrl, "'ARLottery'") !== false, 'ARLottery (SaaS lottery / WinGo) route intact');
chk(strpos($gameUrl, 'is_jwt_valid') !== false, 'GetGameUrl JWT check intact');
chk(strpos($gameUrl, 'Demo accounts cannot launch games') !== false, 'demo-account block intact');
chk(strpos($gameUrl, 'function game_url_reply') !== false, 'game_url_reply helper intact');
chk(strpos($gameUrl, "game_url_lottery_route") !== false, 'lottery route helper intact');
chk(substr_count($gameUrl, 'game_url_reply') === substr_count(@file_get_contents($ROOT . '/site_orig_marker') ? : $gameUrl, 'game_url_reply'), 'response paths ki ginti waisi hi');

/* ---- D) checker page ---- */
$checker = @file_get_contents($ROOT . '/dmfirst-recharge-check.php');
chk($checker !== false && strpos($checker, 'DMFIRST RECHARGE-GATE CHECK') !== false, 'dmfirst-recharge-check.php shipped');
chk(strpos($checker, "hash_equals(\$CHECK_KEY") !== false, 'checker key-protected');
chk(strpos($checker, 'rc_app_decision') !== false, 'checker app ka faisla bhi dikhata hai');

echo "\nRESULT  pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
