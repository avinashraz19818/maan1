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
<title>ArUpiPay</title>
<link rel="stylesheet" href="cashier_arupi.css?v=2"/>
<link rel="stylesheet" href="cashier_expired.css"/>
</head>
<body class="arupi">
<div class="ar-shell">
  <header class="ar-top">
    <a class="ar-back" href="<?php echo $e($p7['cancelUrl']); ?>" aria-label="Back"></a>
    <h1>ArUpiPay</h1>
    <a class="ar-cs" href="<?php echo $e($p7['cancelUrl']); ?>">Customer Service</a>
  </header>
  <div class="ar-scroll">
    <div class="ar-amt">
      <b>₹<?php echo $e($amount); ?></b>
      <button type="button" class="ar-copy-amt" id="copyAmt" data-copy="<?php echo $e($amount); ?>" aria-label="Copy amount">⧉</button>
      <span class="ar-timer" id="timer">05:00</span>
    </div>
    <p class="ar-lab"><i></i> Choose a payment method to pay</p>
    <div class="ar-methods">
      <a class="ar-m" href="<?php echo $e($paytmHref !== '' ? $paytmHref : '#'); ?>">
        <span class="ar-logo ar-logo-pt"><img src="/pay/assets/paytm.png" alt="Paytm" width="56" height="56"/></span>
        <em>Wake up support</em>
      </a>
      <a class="ar-m" href="<?php echo $e($phonepeHref !== '' ? $phonepeHref : '#'); ?>">
        <span class="ar-logo ar-logo-pe"><img src="/pay/assets/phonepe.png" alt="PhonePe" width="56" height="56"/></span>
        <em>Wake up support</em>
      </a>
    </div>
    <p class="ar-lab"><i></i> Use Mobile Scan code to pay</p>
    <div class="ar-qr-card">
      <div class="qr-wrap" id="qrcode">
        <?php if ($hasUpi): ?>
        <div id="qrInner"></div>
        <img id="qrFallback" alt="UPI QR" width="200" height="200" style="display:none"/>
        <?php else: ?>
        <p class="err">QR not ready. Return to Deposit and try again.</p>
        <?php endif; ?>
      </div>
      <?php if ($vpaMasked !== ''): ?>
      <div class="ar-vpa">
        <span><?php echo $e($vpaMasked); ?></span>
        <button type="button" id="copyVpa" aria-label="Copy UPI">⧉</button>
      </div>
      <?php endif; ?>
      <ol class="ar-tips">
        <li>Please use another device to scan the QR code with your payment app</li>
        <li>If you scan the QR code from this device's gallery, the payment amount may be limited (≤2000).</li>
      </ol>
    </div>
    <p class="ar-lab"><i></i> Input UTR/ Paste UTR</p>
    <p class="ar-warn">If you do not back fill UTR / paste UTR, 100% will fail.</p>
    <div class="ar-utr">
      <input id="utr" type="text" inputmode="numeric" maxlength="22" placeholder="Input 12 digits here" autocomplete="off"/>
      <button type="button" id="pasteUtr">Paste</button>
    </div>
    <p class="ar-lab"><i></i> Important reminder :</p>
    <ol class="ar-tips ar-tips-out">
      <li>Do not pay for the same link repeatedly!</li>
      <li>Paytm are wake up support!</li>
    </ol>
    <p class="utr-msg" id="utrMsg"></p>
  </div>
  <footer class="ar-foot">
    <button type="button" class="ar-cancel" id="cancelPay">Cancel</button>
    <button type="button" class="ar-submit" id="submitUtr" data-empty-label="Submit (UTR not entered)" data-ready-label="Submit">Submit (UTR not entered)</button>
  </footer>
</div>
<script>
window.P7 = <?php echo json_encode($p7, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="payment7.js?v=2"></script>
</body>
</html>
