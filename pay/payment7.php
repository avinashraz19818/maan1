<?php
/**
 * Rhinopay cashier: our chrome + gateway UPI/QR only (no WatchPay page iframe).
 */
@ob_start();
include __DIR__ . '/rhinopay_db.php';
$config = include __DIR__ . '/rhinopay_config.php';
require_once __DIR__ . '/rhinopay_lib.php';
if (!is_array($config)) {
    $config = [];
}

$orderId = trim((string)($_GET['orderId'] ?? ''));
$mchOrderNo = trim((string)($_GET['mchOrderNo'] ?? ''));
$amount = number_format((float)($_GET['amount'] ?? 0), 2, '.', '');
$t = trim((string)($_GET['t'] ?? ''));
$sign = trim((string)($_GET['sign'] ?? ''));

function payment7_fail(string $msg): void
{
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"/><title>Payment</title></head>'
        . '<body style="font-family:sans-serif;padding:24px;background:#0b1220;color:#fff">'
        . '<p>' . $safe . '</p><p><a style="color:#4da3ff" href="/#/wallet/Recharge">Back to Deposit</a></p></body></html>';
    exit;
}

if (!rhinopay_cashier_verify($config, $orderId, $mchOrderNo, $amount, $t, $sign)) {
    payment7_fail('Invalid or expired payment link');
}

$row = rhinopay_cashier_load($mchOrderNo);
if (!is_array($row) || (string)($row['orderId'] ?? '') !== $orderId) {
    payment7_fail('Order not found');
}
if (number_format((float)($row['amount'] ?? 0), 2, '.', '') !== $amount) {
    payment7_fail('Amount mismatch');
}

$needExtract = trim((string)($row['upi'] ?? '')) === '' && trim((string)($row['vpa'] ?? '')) === '';
if ($needExtract && trim((string)($row['payInfo'] ?? '')) !== '') {
    $tn = (string)(($row['tradeNo'] ?? '') !== '' ? $row['tradeNo'] : $mchOrderNo);
    $got = rhinopay_extract_pay_instruments($config, (string)$row['payInfo'], $amount, $tn);
    $row = array_merge($row, $got);
    rhinopay_cashier_save($mchOrderNo, $row);
}

$vpa = (string)($row['vpa'] ?? '');
$upi = (string)($row['upi'] ?? '');
if ($upi === '' && $vpa !== '') {
    $upi = rhinopay_build_upi($vpa, $amount, (string)($row['walletOrderNo'] ?? $mchOrderNo));
}
$paytm = (string)($row['paytm'] ?? '');
$phonepe = (string)($row['phonepe'] ?? '');
$countDown = (int)($row['countDown'] ?? 0);
if ($countDown < 30) {
    $countDown = 300;
}
$created = (int)($row['created'] ?? 0);
$expiresAt = (int)($row['expiresAt'] ?? 0);
if ($expiresAt <= 0) {
    $expiresAt = ($created > 0 ? $created : time()) + $countDown;
}
$seconds = max(0, $expiresAt - time());
$expired = $seconds <= 0;
if ((int)($row['expiresAt'] ?? 0) !== $expiresAt) {
    $row['expiresAt'] = $expiresAt;
    $row['countDown'] = $countDown;
    rhinopay_cashier_save($mchOrderNo, $row);
}
$hasUpi = $upi !== '' || $vpa !== '';
$paytmHref = $paytm !== '' ? $paytm : $upi;
$phonepeHref = $phonepe !== '' ? $phonepe : $upi;
$vpaMasked = function_exists('rhinopay_mask_vpa') ? rhinopay_mask_vpa($vpa) : $vpa;

$statusQs = http_build_query([
    'orderId' => $orderId,
    'mchOrderNo' => $mchOrderNo,
    'amount' => $amount,
    't' => $t,
    'sign' => $sign,
], '', '&', PHP_QUERY_RFC3986);

$e = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$skin = strtolower(trim((string)($row['cashier_skin'] ?? 'arupi')));
if (!in_array($skin, ['arupi', 'gaax', 'wiki', 'payment7'], true)) {
    $skin = 'arupi';
}

$p7 = [
    'upi' => $upi,
    'vpa' => $vpa,
    'vpaMasked' => $vpaMasked,
    'statusUrl' => 'payment7_status?' . $statusQs,
    'returnUrl' => '/pay/rhinopay_return.php?o=' . rawurlencode($mchOrderNo),
    'cancelUrl' => '/#/wallet/Recharge',
    'seconds' => $seconds,
    'expired' => $expired,
    'skin' => $skin,
];

$skinFile = [
    'arupi' => __DIR__ . '/cashier_arupi.php',
    'gaax' => __DIR__ . '/cashier_gaax.php',
    'wiki' => __DIR__ . '/cashier_wiki.php',
];
if (isset($skinFile[$skin]) && is_file($skinFile[$skin])) {
    require $skinFile[$skin];
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,maximum-scale=1"/>
<title>Payment</title>
<link rel="stylesheet" href="payment7.css?v=2"/>
<link rel="stylesheet" href="cashier_expired.css"/>
</head>
<body>
<div class="shell">
  <header class="top">
    <span class="top-title">Payment</span>
    <span class="timer" id="timer">05:00</span>
  </header>
  <div class="scroll">
    <p class="amount-label">Amount Payable</p>
    <p class="amount">₹<?php echo $e($amount); ?></p>
    <p class="hint">Please fill in UTR after successful payment</p>
    <p class="hint strong">Use Mobile Scan QR To Pay</p>
    <div class="qr-wrap" id="qrcode">
      <?php if ($hasUpi): ?>
      <div id="qrInner"></div>
      <img id="qrFallback" alt="UPI QR" width="220" height="220" style="display:none"/>
      <?php else: ?>
      <p class="err">QR not ready. Return to Deposit and try again.</p>
      <?php endif; ?>
    </div>
    <p class="warn">Do not save QR codes for repeated payments, or the funds may not be credited.</p>
    <?php if ($hasUpi): ?>
    <p class="save-row"><button type="button" class="save" id="saveQr">Save</button></p>
    <?php endif; ?>
    <section class="card">
      <h2>Choose a payment method to pay</h2>
      <a class="method" href="<?php echo $e($paytmHref !== '' ? $paytmHref : '#'); ?>">
        <img src="/pay/assets/paytm.png" alt="Paytm" width="40" height="40"/>
        <span>Paytm</span>
        <i class="hand" aria-hidden="true"></i>
      </a>
      <a class="method" href="<?php echo $e($phonepeHref !== '' ? $phonepeHref : '#'); ?>">
        <img src="/pay/assets/phonepe.png" alt="PhonePe" width="40" height="40"/>
        <span>PhonePe</span>
        <i class="hand" aria-hidden="true"></i>
      </a>
      <a class="method" href="<?php echo $e($phonepeHref !== '' ? $phonepeHref : '#'); ?>">
        <img src="/pay/assets/phonepe.png" alt="PhonePe Bank" width="40" height="40"/>
        <span>PhonePe Bank(Bank Cards Only)</span>
        <i class="hand" aria-hidden="true"></i>
      </a>
    </section>
    <section class="card utr-card">
      <h2>Paid? Submit UTR/Ref No</h2>
      <label class="utr-row">
        <span class="utr-ico">#</span>
        <input id="utr" type="text" inputmode="numeric" maxlength="22" placeholder="* UTR / UPI Ref No / UPI Transaction ID" autocomplete="off"/>
      </label>
      <button type="button" class="submit" id="submitUtr">Submit UTR / Ref No</button>
      <p class="utr-msg" id="utrMsg"></p>
      <p class="secure">This payment is secured by UPI APP Pay</p>
    </section>
    <p class="oid">Order <?php echo $e($orderId); ?></p>
  </div>
</div>
<script>
window.P7 = <?php echo json_encode($p7, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="payment7.js?v=2"></script>
</body>
</html>
