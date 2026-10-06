<?php
if (!isset($e) || !isset($p7)) {
    http_response_code(404);
    echo 'Not found';
    return;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,maximum-scale=1"/>
<title>UPI Payment</title>
<link rel="stylesheet" href="cashier_wiki.css?v=2"/>
<link rel="stylesheet" href="cashier_expired.css"/>
</head>
<body class="wiki">
<div class="wk-shell">
  <header class="wk-top">
    <span class="wk-brand">UPI Payment</span>
    <span class="wk-timer" id="timer">05:00</span>
  </header>
  <div class="wk-upi-row">
    <span><?php echo $e($vpaMasked !== '' ? $vpaMasked : 'UPI'); ?></span>
    <?php if ($vpa !== '' || $upi !== ''): ?>
    <button type="button" id="copyVpa" aria-label="Copy UPI">⧉</button>
    <?php endif; ?>
  </div>
  <div class="wk-scroll">
    <p class="wk-amt">₹ <?php echo $e($amount); ?></p>
    <p class="wk-scan">Use Mobile Scan code to pay</p>
    <div class="qr-wrap" id="qrcode">
      <?php if ($hasUpi): ?>
      <div id="qrInner"></div>
      <img id="qrFallback" alt="UPI QR" width="220" height="220" style="display:none"/>
      <?php else: ?>
      <p class="err">QR not ready. Return to Deposit and try again.</p>
      <?php endif; ?>
    </div>
    <?php if ($hasUpi): ?>
    <p class="wk-save"><button type="button" id="saveQr">Save QrCode</button></p>
    <?php endif; ?>
    <div class="wk-apps">
      <a class="wk-row" href="<?php echo $e($paytmHref !== '' ? $paytmHref : '#'); ?>">
        <img src="/pay/assets/paytm.png" alt="Paytm" width="40" height="40"/>
        <span>Paytm</span>
      </a>
      <a class="wk-row" href="<?php echo $e($phonepeHref !== '' ? $phonepeHref : '#'); ?>">
        <img src="/pay/assets/phonepe.png" alt="PhonePe" width="40" height="40"/>
        <span>PhonePe</span>
      </a>
    </div>
    <p class="wk-utr-lab">UTR</p>
    <input id="utr" class="wk-input" type="text" inputmode="numeric" maxlength="22" placeholder="Input 12-digit here" autocomplete="off"/>
    <button type="button" class="wk-submit" id="submitUtr">Submit Ref Number</button>
    <p class="utr-msg" id="utrMsg"></p>
    <div class="wk-notice">
      <b>Notice</b>
      <ol>
        <li>The payment amount must match the order amount, otherwise the order will not success.</li>
        <li>Please select a payment method and make sure you has installed that wallet.</li>
        <li>Please enter the UTR to speed up your order callback.</li>
        <li>Once the UTR is submitted, your order will enter the verification process. Please avoid placing duplicate orders.</li>
      </ol>
    </div>
  </div>
</div>
<script>
window.P7 = <?php echo json_encode($p7, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="payment7.js?v=2"></script>
</body>
</html>
