<?php
/**
 * Rhinopay create-order → /pay/payment7 custom cashier.
 * User-facing channel name is Rhinopay. Credits via rhinopay_callback.php.
 */
@ob_start();

include __DIR__ . '/rhinopay_db.php';
$config = include __DIR__ . '/rhinopay_config.php';
require_once __DIR__ . '/rhinopay_lib.php';
require_once __DIR__ . '/deposit_bonus.php';
$_dmgSec = __DIR__ . '/../deepanshu/helpers/dmg_critical_security.php';
if (is_readable($_dmgSec)) {
    require_once $_dmgSec;
}
require_once __DIR__ . '/remote_player.php';
if (!is_array($config)) {
    $config = [];
}
if (empty($config['LOG_FILE'])) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $tmp = rtrim((string)sys_get_temp_dir(), '/\\');
    $config['LOG_FILE'] = is_dir($logDir) ? ($logDir . '/rhinopay.log') : (($tmp !== '' ? $tmp : '/tmp') . '/rhinopay.log');
}

function rhinopay_unavailable_100($conn, $mchOrderNo): void
{
    if (isset($conn) && $conn instanceof mysqli && $mchOrderNo !== '') {
        $d = $conn->prepare('DELETE FROM thevani WHERE dharavahi=? AND sthiti=0 LIMIT 1');
        if ($d) {
            $d->bind_param('s', $mchOrderNo);
            $d->execute();
            $d->close();
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head>'
        . '<meta charset="utf-8"/>'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"/>'
        . '<title>Deposit</title>'
        . '<style>'
        . '*,*::before,*::after{box-sizing:border-box}'
        . 'html,body{margin:0;padding:0;width:100%;min-height:100%;min-height:100dvh;-webkit-text-size-adjust:100%}'
        . 'body{background:#fff;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:max(24px,env(safe-area-inset-top)) max(20px,env(safe-area-inset-right)) max(24px,env(safe-area-inset-bottom)) max(20px,env(safe-area-inset-left));text-align:center}'
        . 'p{font-size:clamp(16px,4.8vw,20px);line-height:1.55;max-width:min(420px,100%);margin:0;font-weight:500;word-wrap:break-word}'
        . '</style></head>'
        . '<body><p>100 Rs Deposit Not Available Try Again After Few Hours</p></body></html>';
    exit;
}

function rhinopay_fail($message, $extra = null) {
    $wantsJson = (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_GET['format']) && $_GET['format'] === 'json');
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        $out = ['status' => false, 'message' => $message];
        if ($extra !== null) {
            $out['res'] = $extra;
        }
        echo json_encode($out);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $msg = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Payment</title></head><body style="font-family:sans-serif;padding:20px;background:#14082B;color:#fff">'
        . '<p>' . $msg . '</p>'
        . '<p><a style="color:#F5C24C" href="/#/wallet/Recharge">Back to Deposit</a></p>'
        . '</body></html>';
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    rhinopay_fail('database unavailable');
}

$amount = number_format((float)($_GET['amount'] ?? 0), 2, '.', '');
$uidStr = function_exists('pay_uid_digits') ? pay_uid_digits($_GET['uid'] ?? '') : preg_replace('/\D+/', '', (string)($_GET['uid'] ?? ''));
$uid = (int) $uidStr;
$tyid = (int)($_GET['tyid'] ?? 1010);
$channel = trim((string)($_GET['channel'] ?? ''));
$displayName = (string)($config['display_name'] ?? 'Rhinopay');
if ($channel === '') {
    $channel = $displayName;
}
$gwTag = rhinopay_normalize_gateway((string)($_GET['gateway'] ?? 'watchpay'));
$methodKey = deposit_bonus_method_from_request($channel);

if ((float)$amount <= 0 || $uidStr === '' || $uidStr === '0') {
    rhinopay_fail('invalid amount or uid');
}

if (!pay_accept_uid_jwt($uidStr)) {
    rhinopay_fail('unauthorized');
}
if (!pay_ensure_local_player($conn, $uidStr)) {
    rhinopay_fail('user not found');
}

$stmt = $conn->prepare('SELECT mobile, codechorkamukala FROM shonu_subjects WHERE id=? LIMIT 1');
$stmt->bind_param('s', $uidStr);
$stmt->execute();
$stmt->bind_result($mobile, $nick);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    rhinopay_fail('user not found');
}
if (!$mobile) {
    $p = function_exists('pay_jwt_payload') ? pay_jwt_payload() : [];
    $mobile = preg_replace('/\D+/', '', (string) ($p['UserName'] ?? $p['userName'] ?? $uidStr));
    if ($mobile === '') {
        $mobile = $uidStr;
    }
}

$mchOrderNo = 'RP' . date('YmdHis') . rand(1000, 9999);
$notifyUrl = (string)($config['NOTIFY_URL'] ?? 'https://wingoserver.trusheme.info/pay/rhinopay_callback.php');
$returnBase = (string)($config['RETURN_URL'] ?? 'https://wingoserver.trusheme.info/pay/rhinopay_return.php');
$returnUrl = $returnBase . (strpos($returnBase, '?') !== false ? '&' : '?') . 'o=' . rawurlencode($mchOrderNo);
$orderDate = date('Y-m-d H:i:s');

$createdate = $orderDate;
$payid = 2;
$mottaFloat = (float)$amount;
$srl = $mchOrderNo;
$refNum = '';
$mula = $channel;
$gwLabel = ($gwTag === 'watchpay') ? 'WatchPay' : 'Rhinopay';
$sthiti = 0;
$ekik = (string)$tyid;
$pav = 2;

$ins = pay_insert_thevani_pending(
    $conn,
    $uidStr,
    $payid,
    $mottaFloat,
    $srl,
    $mula,
    $refNum,
    $mobile,
    $gwLabel,
    $createdate,
    $ekik,
    $pav,
    $sthiti
);
if (empty($ins['ok'])) {
    rhinopay_log($config, 'INSERT FAIL ' . $mchOrderNo . ' uid=' . $uidStr . ' ' . (string)($ins['error'] ?? ''));
    rhinopay_fail('could not create deposit order');
}
deposit_bonus_stamp_method($conn, $mchOrderNo, $methodKey);
rhinopay_log($config, 'INSERTED pending ' . $mchOrderNo . ' uid=' . $uidStr . ' amt=' . $amount . ' channel=' . $channel . ' method=' . $methodKey . ' gateway=' . $gwTag);

$params = [
    'version' => (string)($config['api_version'] ?? '1.0'),
    'mch_id' => (string)($config['mch_id'] ?? ''),
    'mch_order_no' => $mchOrderNo,
    'trade_amount' => $amount,
    'order_date' => $orderDate,
    'pay_type' => (string)($config['pay_type'] ?? '101'),
    'goods_name' => 'Deposit',
    'notify_url' => $notifyUrl,
    'page_url' => $returnUrl,
    'sign_type' => 'MD5',
];
$params['sign'] = rhinopay_md5_sign($params, (string)($config['md5_key'] ?? ''));

$payUrlApi = (string)($config['pay_url'] ?? '');
$posted = rhinopay_post_form($payUrlApi, $params);
rhinopay_log($config, 'CREATE ' . $mchOrderNo . ' http=' . $posted['http']
    . ' notifyUrl=' . $notifyUrl
    . ' resp=' . substr($posted['raw'], 0, 800) . ' err=' . $posted['error']);

$payUrl = rhinopay_pick_pay_url($posted['json'], $posted['raw']);

/* Fallback camelCase field names used in some WatchPay merchant builds */
if ($payUrl === '') {
    $alt = [
        'mchId' => (string)($config['mch_id'] ?? ''),
        'mchOrderNo' => $mchOrderNo,
        'amount' => $amount,
        'orderDate' => $orderDate,
        'version' => (string)($config['api_version'] ?? '1.0'),
        'payType' => (string)($config['pay_type'] ?? '101'),
        'notifyUrl' => $notifyUrl,
        'returnUrl' => $returnUrl,
        'signType' => 'MD5',
    ];
    $alt['sign'] = rhinopay_md5_sign($alt, (string)($config['md5_key'] ?? ''));
    $posted = rhinopay_post_form($payUrlApi, $alt);
    rhinopay_log($config, 'CREATE-CAMEL ' . $mchOrderNo . ' http=' . $posted['http']
        . ' resp=' . substr($posted['raw'], 0, 800) . ' err=' . $posted['error']);
    $payUrl = rhinopay_pick_pay_url($posted['json'], $posted['raw']);
}

if ($payUrl === '' && preg_match('#<iframe[^>]+src=["\'](https?://[^"\']+)#i', (string)$posted['raw'], $im)) {
    $payUrl = $im[1];
}

if ($payUrl === '') {
    $gwMsg = 'gateway error';
    $jsonFail = is_array($posted['json'] ?? null) ? $posted['json'] : null;
    if (is_array($jsonFail)) {
        $trade = trim((string)($jsonFail['tradeMsg'] ?? $jsonFail['errorMsg'] ?? $jsonFail['msg'] ?? ''));
        $code = trim((string)($jsonFail['respCode'] ?? $jsonFail['errorCode'] ?? ''));
        if ($trade !== '') {
            $gwMsg = $trade;
        }
        if ($code !== '' && stripos($gwMsg, $code) === false) {
            $gwMsg .= ' (' . $code . ')';
        }
    } elseif (preg_match('/errorMsg:\\s*([^<]+)/i', (string)$posted['raw'], $em)) {
        $gwMsg = trim($em[1]);
    }
    if (stripos((string)$posted['error'], 'Could not resolve host') !== false
        || stripos((string)$posted['error'], 'resolve host') !== false) {
        $gwMsg .= ' (API host DNS failed)';
    }
    if ((float)$amount == 100.0 && $methodKey === 'upi') {
        rhinopay_unavailable_100($conn, $mchOrderNo);
    }
    rhinopay_fail($gwMsg, $jsonFail);
}

$json = $posted['json'];
$refNum = '';
if (is_array($json)) {
    $refNum = (string)($json['orderNo'] ?? $json['tradeNo'] ?? $json['order_no'] ?? '');
    if ($refNum === '' && is_array($json['data'] ?? null)) {
        $refNum = (string)($json['data']['orderNo'] ?? $json['data']['tradeNo'] ?? '');
    }
}
if ($refNum !== '') {
    $upd2 = $conn->prepare('UPDATE thevani SET ullekha=? WHERE dharavahi=? AND balakedara=? LIMIT 1');
    if ($upd2) {
        $upd2->bind_param('sss', $refNum, $mchOrderNo, $uidStr);
        $upd2->execute();
        $upd2->close();
    }
}

$publicOrderId = rhinopay_public_order_id();
$cashierT = (string)time();
$cashierSign = rhinopay_cashier_sign($config, $publicOrderId, $mchOrderNo, $amount, $cashierT);
$tn = $refNum !== '' ? $refNum : $mchOrderNo;
$instruments = rhinopay_extract_pay_instruments($config, $payUrl, $amount, $tn);
$cashierSkin = rhinopay_next_cashier_skin();
$countDown = (int)($instruments['countDown'] ?? 0);
if ($countDown < 30) {
    $countDown = 300;
}
$createdAt = time();
rhinopay_cashier_save($mchOrderNo, [
    'orderId' => $publicOrderId,
    'mchOrderNo' => $mchOrderNo,
    'amount' => $amount,
    'uid' => $uidStr,
    'tradeNo' => $refNum,
    'payInfo' => $payUrl,
    'vpa' => $instruments['vpa'],
    'upi' => $instruments['upi'],
    'paytm' => $instruments['paytm'],
    'phonepe' => $instruments['phonepe'],
    'gpay' => $instruments['gpay'],
    'iframe' => $instruments['iframe'],
    'walletOrderNo' => (string)($instruments['walletOrderNo'] ?? ''),
    'countDown' => $countDown,
    'utr' => '',
    'gateway' => $gwTag,
    'cashier_skin' => $cashierSkin,
    'method_key' => $methodKey,
    'created' => $createdAt,
    'expiresAt' => $createdAt + $countDown,
]);
rhinopay_log($config, 'CASHIER ' . $mchOrderNo . ' orderId=' . $publicOrderId
    . ' skin=' . $cashierSkin . ' vpa=' . $instruments['vpa'] . ' upi=' . ($instruments['upi'] !== '' ? '1' : '0'));

if ((float)$amount == 100.0 && $methodKey === 'upi' && trim((string)($instruments['upi'] ?? $instruments['vpa'] ?? '')) === '') {
    rhinopay_unavailable_100($conn, $mchOrderNo);
}

$qs = http_build_query([
    'orderId' => $publicOrderId,
    'amount' => $amount,
    'mchOrderNo' => $mchOrderNo,
    'tradeNo' => $refNum,
    'uid' => $uidStr,
    't' => $cashierT,
    'sign' => $cashierSign,
], '', '&', PHP_QUERY_RFC3986);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"/><title>Payment</title></head><body>'
    . '<script>location.replace(' . json_encode('/pay/payment7?' . $qs) . ');</script></body></html>';
exit;
