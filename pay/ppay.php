<?php
/**
 * PPayPros create-order → redirect to cashier.
 * Credits via ppay_callback.php into thevani / shonu_kaichila.
 * Payload shape matches .well-known reference api/_core/ppaypros.php
 */
@ob_start();

include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/ppay_config.php';
require_once __DIR__ . '/ppay_lib.php';
require_once __DIR__ . '/deposit_bonus.php';
$_dmgSec = __DIR__ . '/../deepanshu/helpers/dmg_critical_security.php';
if (is_readable($_dmgSec)) {
    require_once $_dmgSec;
}
if (!is_array($config)) {
    $config = [];
}
if (empty($config['LOG_FILE'])) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $tmp = rtrim((string)sys_get_temp_dir(), '/\\');
    $config['LOG_FILE'] = is_dir($logDir) ? ($logDir . '/ppay.log') : (($tmp !== '' ? $tmp : '/tmp') . '/ppay.log');
}

function ppay_log($m) {
    global $config;
    ppay_log_line(is_array($config) ? $config : [], (string)$m);
}

function ppay_unavailable_100($conn, $mchOrderNo): void
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

function ppay_fail($message, $extra = null) {
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

$amount = number_format((float)($_GET['amount'] ?? 0), 2, '.', '');
$uid = (int)($_GET['uid'] ?? 0);
$tyid = (int)($_GET['tyid'] ?? 400097);
$channel = trim((string)($_GET['channel'] ?? ''));
if ($channel === '') {
    $channel = '7Day-Paytm';
}

if ((float)$amount <= 0 || $uid <= 0) {
    ppay_fail('invalid amount or uid');
}

if (!function_exists('dmg_require_uid_jwt') || !dmg_require_uid_jwt($uid)) {
    ppay_fail('unauthorized');
}

$stmt = $conn->prepare('SELECT mobile, codechorkamukala FROM shonu_subjects WHERE id=? LIMIT 1');
$stmt->bind_param('i', $uid);
$stmt->execute();
$stmt->bind_result($mobile, $nick);
$found = $stmt->fetch();
$stmt->close();

if (!$found || !$mobile) {
    ppay_fail('user not found');
}

$customerName = trim((string)$nick);
if ($customerName === '') {
    $customerName = 'User' . $uid;
}

$digits = preg_replace('/\D+/', '', (string)$mobile) ?? '';
if (strlen($digits) >= 10) {
    $customerPhone = substr($digits, -10);
} else {
    $customerPhone = '98' . str_pad(substr((string)$uid, -8), 8, '0', STR_PAD_LEFT);
}
if (!preg_match('/^[6-9]\d{9}$/', $customerPhone)) {
    $customerPhone = '9876543210';
}

$customerEmail = 'user' . $uid . '@gmail.com';
if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    $customerEmail = 'user' . substr((string)$uid, -8) . '@gmail.com';
}

$mchOrderNo = 'PP' . date('YmdHis') . rand(1000, 9999);
$amountPaise = (int)round((float)$amount * 100);
$notifyUrl = (string)($config['NOTIFY_URL'] ?? 'https://wingoserver.trusheme.info/pay/ppay_callback.php');
$returnBase = (string)($config['RETURN_URL'] ?? 'https://wingoserver.trusheme.info/pay/ppay_return.php');
$returnUrl = $returnBase . (strpos($returnBase, '?') !== false ? '&' : '?') . 'o=' . rawurlencode($mchOrderNo);

/* Insert pending thevani FIRST so Deposit history shows Type=channel even if gateway fails */
$createdate = date('Y-m-d H:i:s');
$payid = 2;
$balakedara = (string)$uid;
$mottaFloat = (float)$amount;
$srl = $mchOrderNo;
$refNum = '';
$mula = $channel; // History Type = selected channel (Pay-MX, Phone-A1, …)
$gwLabel = 'PPPay';
$sthiti = 0;
$ekik = (string)$tyid;
$pav = 2;

/* Explicit shonu: dump-7 thevani often lacks AUTO_INCREMENT (#1364) */
$nextShonu = 1;
$mx = @$conn->query('SELECT IFNULL(MAX(shonu),0)+1 AS n FROM thevani');
if ($mx && ($mr = $mx->fetch_assoc())) {
    $nextShonu = (int)$mr['n'];
}
if ($nextShonu < 1) {
    $nextShonu = 1;
}

$sql = 'INSERT INTO thevani
    (shonu,payid,balakedara,motta,dharavahi,mula,ullekha,duravani,ekikrtapavati,dinankavannuracisi,madari,pavatiaidi,sthiti)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    ppay_fail('db prepare failed');
}
$stmt->bind_param(
    'iisdsssssssii',
    $nextShonu,
    $payid,
    $balakedara,
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
if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    ppay_log('INSERT FAIL ' . $mchOrderNo . ' ' . $err);
    if ((float)$amount == 100.0) {
        ppay_unavailable_100($conn, $mchOrderNo);
    }
    ppay_fail('could not create deposit order');
}
$stmt->close();
deposit_bonus_stamp_method($conn, $mchOrderNo, deposit_bonus_method_from_request($channel));
ppay_log('INSERTED pending ' . $mchOrderNo . ' uid=' . $uid . ' amt=' . $amount . ' channel=' . $channel . ' shonu=' . $nextShonu);

/* Reference Jeepay/PPayPros shape — customerPhone = 10 digits, amount int (paise) */
$params = [
    'mchNo' => (string)($config['mch_no'] ?? ''),
    'appId' => (string)($config['app_id'] ?? ''),
    'mchOrderNo' => $mchOrderNo,
    'amount' => $amountPaise,
    'currency' => (string)($config['currency'] ?? 'INR'),
    'customerName' => $customerName,
    'customerEmail' => $customerEmail,
    'customerPhone' => $customerPhone,
    'wayCode' => (string)($config['way_code'] ?? '801'),
    'notifyUrl' => $notifyUrl,
    'returnUrl' => $returnUrl,
];

$signParams = [];
foreach ($params as $k => $v) {
    $signParams[$k] = is_int($v) || is_float($v) ? (string)$v : $v;
}
$params['sign'] = ppay_md5_sign($signParams, (string)($config['md5_key'] ?? ''));

$body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ch = curl_init((string)($config['api_url'] ?? ''));
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_PROXY => '',
    CURLOPT_NOPROXY => '*',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CO Games/1.0)',
]);
$response = curl_exec($ch);
$cerr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
ppay_log('CREATE ' . $mchOrderNo . ' phone=' . $customerPhone . ' http=' . $httpCode
    . ' notifyUrl=' . $notifyUrl
    . ' resp=' . substr((string)$response, 0, 800) . ' err=' . $cerr);

$res = json_decode((string)$response, true);
$payUrl = '';
if (is_array($res) && (int)($res['code'] ?? -1) === 0) {
    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    $payData = trim((string)($data['payData'] ?? ''));
    $payDataType = trim((string)($data['payDataType'] ?? ''));
    if ($payDataType === 'payUrl' || preg_match('#^https?://#i', $payData)) {
        $payUrl = $payData;
    } elseif ($payData !== '') {
        $payUrl = $payData;
    }
    if ($payUrl === '') {
        $payUrl = (string)($data['payUrl'] ?? $res['payUrl'] ?? '');
    }
}

/* Fallback: some merchant builds still expect customerMobile (91…) + customerEmail */
if ($payUrl === '') {
    $fallbackOrder = $mchOrderNo . 'F';
    $fallback = [
        'mchNo' => (string)($config['mch_no'] ?? ''),
        'appId' => (string)($config['app_id'] ?? ''),
        'mchOrderNo' => $fallbackOrder,
        'amount' => (string)$amountPaise,
        'currency' => (string)($config['currency'] ?? 'INR'),
        'customerName' => $customerName,
        'customerEmail' => $customerEmail,
        'customerMobile' => '91' . $customerPhone,
        'customerPhone' => $customerPhone,
        'wayCode' => (string)($config['way_code'] ?? '801'),
        'notifyUrl' => $notifyUrl,
        'returnUrl' => $returnBase . (strpos($returnBase, '?') !== false ? '&' : '?') . 'o=' . rawurlencode($fallbackOrder),
        'clientIp' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'subject' => 'Deposit',
        'body' => 'Deposit ' . $amount,
        'reqTime' => (string)round(microtime(true) * 1000),
        'version' => '1.0',
        'signType' => 'MD5',
    ];
    $fallback['sign'] = ppay_md5_sign($fallback, (string)($config['md5_key'] ?? ''));
    $ch = curl_init((string)($config['api_url'] ?? ''));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);
    $response = curl_exec($ch);
    $cerr = curl_error($ch);
    unset($ch);
    ppay_log('CREATE-FALLBACK ' . $fallback['mchOrderNo'] . ' resp=' . substr((string)$response, 0, 800) . ' err=' . $cerr);
    $res = json_decode((string)$response, true);
    if (is_array($res) && (int)($res['code'] ?? -1) === 0) {
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $payUrl = (string)($data['payData'] ?? $data['payUrl'] ?? '');
        if ($payUrl !== '') {
            /* Keep one history row: rename order id to fallback F suffix */
            $upd = $conn->prepare('UPDATE thevani SET dharavahi=? WHERE dharavahi=? AND balakedara=? LIMIT 1');
            if ($upd) {
                $uidStr = (string)$uid;
                $upd->bind_param('sss', $fallbackOrder, $mchOrderNo, $uidStr);
                $upd->execute();
                $upd->close();
            }
            $mchOrderNo = $fallbackOrder;
            $returnUrl = $fallback['returnUrl'];
        }
    }
}

if ($payUrl === '') {
    /* Same as Rhinopay: ₹100 order not created → user-facing unavailable page */
    if ((float)$amount == 100.0) {
        ppay_unavailable_100($conn, $mchOrderNo);
    }
    /* Pending row already in history with channel name — user can see To Be Paid */
    $dnsHint = '';
    if (stripos((string)$cerr, 'Could not resolve host') !== false
        || stripos((string)$cerr, 'resolve host') !== false) {
        $dnsHint = ' (DNS: cannot resolve pay.ppaypros.com on this server)';
    }
    ppay_fail('gateway error' . $dnsHint, $res);
}

$refNum = (string)($res['data']['payOrderId'] ?? $res['data']['orderId'] ?? '');
if ($refNum !== '') {
    $upd2 = $conn->prepare('UPDATE thevani SET ullekha=? WHERE dharavahi=? AND balakedara=? LIMIT 1');
    if ($upd2) {
        $uidStr = (string)$uid;
        $upd2->bind_param('sss', $refNum, $mchOrderNo, $uidStr);
        $upd2->execute();
        $upd2->close();
    }
}

header('Location: ' . $payUrl);
exit;
