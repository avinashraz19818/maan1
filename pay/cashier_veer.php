<?php
if (!isset($e) || !isset($p7)) {
    http_response_code(404);
    echo 'Not found';
    return;
}
$gpayHref = $upi !== '' ? $upi : '#';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,maximum-scale=1"/>
<title>Rhinopay</title>
<link rel="stylesheet" href="cashier_veer.css?v=1"/>
<link rel="stylesheet" href="cashier_expired.css"/>
</head>
<body class="veer">
<div class="veer-shell">
  <header class="veer-top">
    <a class="veer-back" href="<?php echo $e($p7['cancelUrl']); ?>" aria-label="Back">‹</a>
    <h1>Rhinopay</h1>
    <span class="veer-timer" id="timer">05:00</span>
  </header>
  <div class="veer-scroll">
    <section class="veer-amt">
      <p class="veer-amt-lab">Amount payable</p>
      <p class="veer-amt-val">₹<?php echo $e($amount); ?></p>
      <button type="button" class="veer-copy" id="copyAmt" data-copy="<?php echo $e($amount); ?>">Copy amount</button>
    </section>
    <p class="veer-hint">Scan QR with any UPI app. Do not reuse this QR.</p>
    <div class="veer-qr" id="qrcode">
      <?php if ($hasUpi): ?>
      <div id="qrInner"></div>
      <img id="qrFallback" alt="UPI QR" width="220" height="220" style="display:none"/>
      <?php else: ?>
      <p class="veer-err">QR not ready. Go back to Deposit and try again.</p>
      <?php endif; ?>
    </div>
    <?php if ($vpaMasked !== ''): ?>
    <div class="veer-vpa">
      <span><?php echo $e($vpaMasked); ?></span>
      <button type="button" id="copyVpa">Copy UPI</button>
    </div>
    <?php endif; ?>
    <?php if ($hasUpi): ?>
    <p class="veer-save"><button type="button" class="veer-ghost" id="saveQr">Save QR</button></p>
    <?php endif; ?>
    <section class="veer-card">
      <h2>Pay with app</h2>
      <a class="veer-m" href="<?php echo $e($paytmHref !== '' ? $paytmHref : '#'); ?>">
        <span>Paytm</span>
      </a>
      <a class="veer-m" href="<?php echo $e($phonepeHref !== '' ? $phonepeHref : '#'); ?>">
        <span>PhonePe</span>
      </a>
      <a class="veer-m" href="<?php echo $e($gpayHref); ?>">
        <span>Google Pay</span>
      </a>
    </section>
    <section class="veer-card">
      <h2>Paid? Submit UTR</h2>
      <input id="utr" type="text" inputmode="numeric" maxlength="22" placeholder="UTR / UPI Ref No" autocomplete="off"/>
      <button type="button" class="veer-submit" id="submitUtr">Submit UTR</button>
      <p class="utr-msg" id="utrMsg"></p>
    </section>
    <p class="veer-oid">Order <?php echo $e($orderId); ?></p>
  </div>
</div>
<script>
window.P7 = <?php echo json_encode($p7, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="payment7.js?v=2"></script>
</body>
</html>
