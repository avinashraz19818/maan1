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
<title>payment</title>
<link rel="stylesheet" href="cashier_gaax.css?v=2"/>
<link rel="stylesheet" href="cashier_expired.css"/>
</head>
<body class="gaax">
<div class="gx-shell">
  <header class="gx-top">payment</header>
  <div class="gx-scroll">
    <p class="gx-lab">Amount Payable</p>
    <p class="gx-amt">₹<?php echo $e($amount); ?></p>
    <p class="gx-scan">Use Mobile Scan code to pay</p>
    <div class="qr-wrap" id="qrcode">
      <?php if ($hasUpi): ?>
      <div id="qrInner"></div>
      <img id="qrFallback" alt="UPI QR" width="220" height="220" style="display:none"/>
      <?php else: ?>
      <p class="err">QR not ready. Return to Deposit and try again.</p>
      <?php endif; ?>
    </div>
    <h2 class="gx-h">Choose a payment method to pay</h2>
    <a class="gx-row" href="<?php echo $e($paytmHref !== '' ? $paytmHref : '#'); ?>">
      <img class="gx-ico" src="/pay/assets/paytm.png" alt="Paytm" width="40" height="40"/>
      <span>Paytm</span>
      <em>Recommended</em>
      <i></i>
    </a>
    <a class="gx-row" href="<?php echo $e($phonepeHref !== '' ? $phonepeHref : '#'); ?>">
      <img class="gx-ico" src="/pay/assets/phonepe.png" alt="PhonePe" width="40" height="40"/>
      <span>PhonePe</span>
      <em>Scan QR to pay</em>
      <i></i>
    </a>
    <h2 class="gx-h">Manual transfer</h2>
    <p class="gx-step">1. Copy the below given UPI</p>
    <div class="gx-upi">
      <code><?php echo $e($vpa !== '' ? $vpa : ($vpaMasked !== '' ? $vpaMasked : 'UPI not ready')); ?></code>
      <button type="button" id="copyVpa">Copy</button>
    </div>
    <p class="gx-tip">Tip: Dont save the UPI, get new UPI every time.</p>
    <p class="gx-step">2. Need to enter your 12 Ref No (UTR)</p>
    <label class="gx-utr">
      <span>Ref No.</span>
      <input id="utr" type="text" inputmode="numeric" maxlength="22" placeholder="Ref No is required" autocomplete="off"/>
    </label>
    <p class="gx-note">Tip: Open your UPI wallet and complete the transfer Record your reference No.(Ref No.) after payment.</p>
    <button type="button" class="gx-submit" id="submitUtr" data-need-utr="1" data-empty-label="Submit" data-ready-label="Submit">Submit</button>
    <p class="utr-msg" id="utrMsg"></p>
    <p class="gx-pink">1.Please make sure you have installed the app<br/>2.Don't pay for the same link repeatedly</p>
    <div class="gx-notice">
      <b>Notice:</b>
      <p>1. Please select the payment method you need and make sure your phone has the corresponding wallet software installed.</p>
    </div>
    <?php if ($hasUpi): ?>
    <p class="gx-save"><button type="button" id="saveQr">Save QR</button></p>
    <?php endif; ?>
  </div>
</div>
<script>
window.P7 = <?php echo json_encode($p7, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="payment7.js?v=2"></script>
</body>
</html>
