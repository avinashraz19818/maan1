<?php
/**
 * RaxiWin recharge-gate patch tests (NOT shipped in the ZIP).
 *
 * Kya check hota hai:
 *   A) Shipped GetAllowBetSetting.php se asli gate values parse karke, app ke JS
 *      wale faisle ko dobara chalate hain:
 *          if (ae == "1" || D) return OPEN;
 *          if (Number(ge) && Number(ge) > Te) return POPUP("itna recharge karo");
 *          if (Number(ge) === 0 && ue === 0) return POPUP("Game requires recharge to enter");
 *      Purani values par popup aata tha, nayi values par game khulta hai.
 *   B) Baki poora file (signature check, JWT check, thevani query, error codes)
 *      waise hi hai — sirf gate badla hai.
 *   C) GetLoadedSetting.php valid JSON hai aur needPopupFirstRecharge false hai,
 *      baaki keys preserved hain.
 */

$ROOT = '/site';
$allowRel = 'developer-maruf/api/webapi/GetAllowBetSetting.php';
$loadedRel = 'developer-maruf/api/webapi/GetLoadedSetting.php';
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

/** Patch ki hui file se values parse karo (PHP source padh kar, eval se nahi). */
function gate_values($code)
{
    if (!preg_match('/\$data\s*=\s*\[(.*?)\];/s', $code, $m)) {
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

/** App ke JS gate ka wahi faisla (assets/js/index-BVjtEoMt.js se). */
function app_gate($values, $userRechargeAmount)
{
    // PHP source se aayi values JSON me strings banti hain -> quotes hata do
    $trim = function ($v) { return trim((string) $v, "\"'"); };
    $ae = $trim($values['allowNoRechargeGame']);
    $ue = (int) $trim($values['userRechargeTimes']);
    $ge = $trim($values['lowestRechargeAmountToGame']);
    $Te = (float) $userRechargeAmount;
    $D = $trim($values['canDirectToGame']) === 'true';
    if ($ae === '1' || $D) {
        return 'open';
    }
    if ((float) $ge != 0 && (float) $ge > $Te) {
        return 'popup_min_recharge';
    }
    if ((float) $ge === 0.0 && $ue === 0) {
        return 'popup_recharge_required';
    }
    return 'open';
}

$values = gate_values($allow);
chk(is_array($values), 'response block parse hua');

/* ---- A) gate values + app ka faisla ---- */
chk(($values['allowNoRechargeGame'] ?? '') === '"1"', 'allowNoRechargeGame = "1"', $values['allowNoRechargeGame'] ?? '');
chk(($values['canDirectToGame'] ?? '') === 'true', 'canDirectToGame = true', $values['canDirectToGame'] ?? '');
chk(($values['lowestRechargeAmountToGame'] ?? '') === '"0"', 'lowestRechargeAmountToGame = "0"', $values['lowestRechargeAmountToGame'] ?? '');
chk(strpos($allow, '$userRechargeTimes') !== false && strpos($allow, '$userRechargeAmount') !== false, 'recharge history values ab bhi bhejte hain (sirf gate off)');

/* naya user: 0 recharge, 0 balance -> phir bhi game khule */
$fresh = array('canDirectToGame'=>'true','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"1"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"0"');
chk(app_gate($fresh, 0) === 'open', 'naya user (0 recharge, 0 balance) -> game OPEN');
chk(app_gate($fresh, 50) === 'open', 'naya user (50 balance) -> game OPEN');

/* sirf allowNoRechargeGame = "1" bhi kaafi hai (doosra flag false ho to bhi) */
$only = array('canDirectToGame'=>'false','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"1"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"100"');
chk(app_gate($only, 0) === 'open', 'sirf allowNoRechargeGame = "1" -> game OPEN');

/* purani values par wahi user block hota tha (proof ki yeh patch asar karta hai) */
$old = array('canDirectToGame'=>'$userRechargeTimes > 1','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"0"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"100"');
chk(app_gate($old, 0) === 'popup_min_recharge', 'purani values -> "itna recharge karo" popup (jo aapko dikh raha tha)');
$old2 = array('canDirectToGame'=>'false','userRechargeTimes'=>'0','allowNoRechargeGame'=>'"0"','userRechargeAmount'=>'0','lowestRechargeAmountToGame'=>'"0"');
chk(app_gate($old2, 0) === 'popup_recharge_required', 'purani values -> "Game requires recharge to enter" popup');

/* purane user (2 recharge) ka behaviour bhi waisa hi — open */
$oldUser = array('canDirectToGame'=>'true','userRechargeTimes'=>'3','allowNoRechargeGame'=>'"1"','userRechargeAmount'=>'700','lowestRechargeAmountToGame'=>'"0"');
chk(app_gate($oldUser, 700) === 'open', 'purana user (already recharged) -> game OPEN');

/* ---- B) file ka baki hissa intact ---- */
chk(strpos($allow, 'is_jwt_valid') !== false, 'JWT check intact');
chk(strpos($allow, '$shonusign == $signature') !== false, 'signature check intact');
chk(strpos($allow, 'FROM thevani') !== false, 'thevani recharge query intact');
chk(strpos($allow, "'Method not allowed'") !== false, 'GET par 405 response intact');
chk(strpos($allow, 'No operation permission') !== false, '401 error path intact');
chk(strpos($allow, 'PHP_INT') === false && strpos($allow, 'TODO') === false, 'koi debug/TODO nahi chhoda');

/* ---- C) GetLoadedSetting ---- */
$json = json_decode($loaded, true);
chk(is_array($json), 'GetLoadedSetting valid JSON hai');
chk(($json['code'] ?? null) === 0 && ($json['msg'] ?? '') === 'Succeed', 'envelope (code/msg) waisa hi');
chk(($json['data']['needPopupFirstRecharge'] ?? null) === false, 'needPopupFirstRecharge = false');
$keysOriginal = array('isExistGrandAward','children_Lv_RebateAmount_Yesterday','returnAwards','isARPay','isRSNPay','isLandingPageEnabled','landingPageUrl','isFinancePromptTextEnabled','financePromptText','isAppDownloadPromptTextEnabled','appDownloadPromptText','isAppForcedDownloadEnabled','appForcedDownloadUrl');
$missing = array();
foreach ($keysOriginal as $key) {
    if (!array_key_exists($key, $json['data'])) {
        $missing[] = $key;
    }
}
chk($missing === array(), 'baaki 13 setting keys preserved', implode(',', $missing));
chk(strpos($loaded, '<?php') === false, 'GetLoadedSetting me PHP code nahi (plain JSON hi rehna chahiye)');

/* ---- D) checker page ---- */
$checker = @file_get_contents($ROOT . '/raxiwin-recharge-check.php');
chk($checker !== false && strpos($checker, 'RAXIWIN RECHARGE-GATE CHECK') !== false, 'recharge-check page shipped');
chk(strpos($checker, "hash_equals(\$CHECK_KEY") !== false, 'checker key-protected hai');
chk(strpos($checker, 'Game requires recharge to enter') !== false, 'checker wahi message dikhata hai');

echo "\nRESULT  pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
