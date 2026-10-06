<?php
/**
 * Manual USDT (TRC20) deposit — creates thevani pending order + polished pay page.
 * Keep create-order → redirect ?order= flow; TxID via usdt_submit.php.
 */
include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/usdt_config.php';
require_once __DIR__ . '/deposit_bonus.php';
$_dmgSec = __DIR__ . '/../deepanshu/helpers/dmg_critical_security.php';
if (is_readable($_dmgSec)) {
    require_once $_dmgSec;
}
require_once __DIR__ . '/remote_player.php';
if (!is_array($config)) {
    $config = [];
}

$amountUsdt = (float)($_GET['amount'] ?? 0);
$uidStr = function_exists('pay_uid_digits') ? pay_uid_digits($_GET['uid'] ?? '') : preg_replace('/\D+/', '', (string)($_GET['uid'] ?? ''));
$uid = (int) $uidStr;
$tyid = (int)($_GET['tyid'] ?? 400087);
$orderNo = trim((string)($_GET['order'] ?? ''));
$channel = trim((string)($_GET['channel'] ?? ''));
if ($channel === 'USDT-Bonus' || strcasecmp($channel, 'USDT-Bonus') === 0) {
    $channel = 'USDT-Bonus';
} elseif ($channel === 'USDT-NoBonus' || strcasecmp($channel, 'USDT-NoBonus') === 0) {
    $channel = 'USDT-NoBonus';
} elseif ($channel === '') {
    $channel = 'USDT-NoBonus';
}

$rate = (float)($config['default_rate'] ?? 97);
$bonusRate = (float)($config['deposit_bonus_rate'] ?? 0.07);
/* Clamp: missing/invalid config must not break credit math */
if (!is_finite($bonusRate) || $bonusRate < 0 || $bonusRate > 1) {
    $bonusRate = 0.07;
}
$wallet = (string)($config['wallet_address'] ?? '');
$network = (string)($config['network'] ?? 'Tron (TRC20)');
$min = (float)($config['min_usdt'] ?? 10);
$expireMinutes = (int)($config['expire_minutes'] ?? 60);
if ($expireMinutes <= 0) {
    $expireMinutes = 60;
}

/* Admin Manage USDT (deyyamrici.sthiti=1) is source of truth for deposit address */
try {
    require_once __DIR__ . '/usdt_inr_helper.php';
    if (isset($conn) && $conn instanceof mysqli) {
        $rate = pay_get_usdt_inr_rate($conn);
        $aq = @$conn->query("SELECT maulya FROM deyyamrici WHERE sthiti='1' ORDER BY shonu DESC LIMIT 1");
        if ($aq && ($ar = $aq->fetch_assoc())) {
            $adminWallet = trim((string)($ar['maulya'] ?? ''));
            if ($adminWallet !== '') {
                $wallet = $adminWallet;
            }
        }
    }
} catch (Throwable $e) {
    // keep config fallback
}

function usdt_qr_url(string $wallet, string $configured = ''): string {
    if ($configured !== '') {
        return $configured;
    }
    if ($wallet === '') {
        return '';
    }
    return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($wallet);
}

function usdt_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function usdt_render_pay_page(array $opts): void {
    $orderNo = (string)($opts['orderNo'] ?? '');
    $amount = (float)($opts['amount'] ?? 0);
    $rate = (float)($opts['rate'] ?? 0);
    $inr = (float)($opts['inr'] ?? ($amount * $rate));
    $wallet = (string)($opts['wallet'] ?? '');
    $network = (string)($opts['network'] ?? 'Tron (TRC20)');
    $qr = (string)($opts['qr'] ?? '');
    $remain = (int)($opts['remain'] ?? 0);
    $status = (string)($opts['status'] ?? 'Waiting payment');
    $existingTx = (string)($opts['tx'] ?? '');
    $paid = ($status === 'Paid');
    $txDone = $paid || ($status === 'Pending review') || ($existingTx !== '' && $existingTx !== '0');

    $amountStr = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    if ($amountStr === '') {
        $amountStr = '0';
    }
    $inrStr = rtrim(rtrim(number_format($inr, 2, '.', ''), '0'), '.');
    $rateStr = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>USDT Deposit</title>
<style>
  :root {
    --bg: #f2f3f5;
    --card: #ffffff;
    --orange: #e85d04;
    --green: #5a9e8c;
    --text: #1a1a1a;
    --muted: #8a8f98;
    --border: #e6e8ec;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 16px 14px 40px;
  }
  .wrap { max-width: 420px; margin: 0 auto; }
  .brand {
    display: flex; align-items: center; gap: 8px;
    margin: 4px 0 18px; font-weight: 800; font-size: 22px; letter-spacing: 0.2px;
  }
  .brand img.usdt-logo {
    width: 28px; height: 28px; display: block; object-fit: contain;
  }
  .card {
    background: var(--card);
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.04);
    padding: 18px 16px;
    margin-bottom: 14px;
  }
  .amount-row {
    display: flex; align-items: baseline; justify-content: center; gap: 8px;
    margin-bottom: 14px;
  }
  .amount-val {
    font-size: 40px; font-weight: 800; color: var(--orange); line-height: 1;
  }
  .amount-unit { font-size: 16px; color: #555; font-weight: 600; }
  .inr-hint { text-align: center; font-size: 12px; color: var(--muted); margin: -6px 0 12px; }
  .btn-copy {
    display: block; width: 100%;
    background: var(--green); color: #fff; border: 0;
    border-radius: 10px; height: 46px;
    font-size: 16px; font-weight: 700; cursor: pointer;
  }
  .btn-copy:active { opacity: .9; }
  .order-bar {
    background: #f0f1f3; border-radius: 10px;
    padding: 12px 14px; margin-bottom: 14px;
    font-size: 13px; color: #333; word-break: break-all;
  }
  .order-bar b { font-weight: 800; }
  .net-pill {
    display: inline-flex; align-items: center; gap: 6px;
    margin: 0 auto 16px; padding: 6px 12px;
    border-radius: 999px; background: #f7f7f8; border: 1px solid var(--border);
    font-size: 13px; font-weight: 700; color: #333;
  }
  .net-wrap { text-align: center; }
  .tron-ico {
    width: 16px; height: 16px; border-radius: 50%;
    background: #ef0027; color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 9px; font-weight: 900;
  }
  .qr-wrap { text-align: center; margin: 6px 0 16px; }
  .qr-wrap img {
    width: 220px; height: 220px; object-fit: contain;
    background: #fff; border-radius: 8px;
  }
  .addr-box {
    border: 1px solid var(--border); border-radius: 10px;
    padding: 12px 12px; background: #fafbfc;
    font-size: 13px; word-break: break-all; text-align: center;
    color: #222; margin-bottom: 12px; font-weight: 600;
  }
  .timer {
    margin-top: 16px; text-align: center;
    font-size: 28px; font-weight: 800; color: var(--orange); letter-spacing: 1px;
  }
  .timer-hint {
    margin-top: 6px; text-align: center;
    font-size: 13px; color: var(--muted);
  }
  .toast {
    position: fixed; left: 50%; top: 18px; transform: translateX(-50%);
    background: rgba(20,20,20,.88); color: #fff;
    padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
    z-index: 99; display: none;
  }
  .err {
    background: #fff3f0; color: #c0392b; border: 1px solid #f5c6cb;
    border-radius: 10px; padding: 14px; margin: 20px 0; text-align: center;
  }
  .tx-card { margin-top: 4px; }
  .tx-label { font-size: 13px; font-weight: 700; margin-bottom: 8px; color: #333; }
  .tx-input {
    width: 100%; height: 44px; border: 1px solid var(--border); border-radius: 10px;
    padding: 0 12px; font-size: 14px; margin-bottom: 10px; outline: none;
  }
  .tx-input:focus { border-color: var(--green); }
  .btn-submit {
    display: block; width: 100%; height: 46px;
    background: var(--orange); color: #fff; border: 0; border-radius: 10px;
    font-size: 15px; font-weight: 800; cursor: pointer;
  }
  .btn-submit:disabled { opacity: .55; cursor: default; }
  .status-ok {
    text-align: center; color: var(--green); font-weight: 700; font-size: 14px; padding: 8px 0;
  }
  .tips {
    margin-top: 12px; font-size: 12px; color: var(--muted); line-height: 1.5;
  }
  .processing {
    position: fixed; inset: 0; background: rgba(15,18,22,.55);
    display: none; align-items: center; justify-content: center;
    z-index: 200; flex-direction: column; gap: 14px;
  }
  .processing.show { display: flex; }
  .processing .spin {
    width: 42px; height: 42px; border-radius: 50%;
    border: 4px solid rgba(255,255,255,.25); border-top-color: #fff;
    animation: usdtspin .8s linear infinite;
  }
  .processing .txt { color: #fff; font-weight: 700; font-size: 15px; }
  @keyframes usdtspin { to { transform: rotate(360deg); } }
  .back-link { display:block; text-align:center; margin-top:16px; color:var(--orange); font-weight:700; text-decoration:none; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <img class="usdt-logo" src="/assets/darkPurple/rexsolutions/opt/usdt.svg" alt="USDT" width="28" height="28">
    USDT
  </div>

  <?php if ($orderNo === ''): ?>
    <div class="err">Missing order</div>
  <?php else: ?>

  <div class="order-bar">Order No : <b id="orderNoText"><?php echo usdt_h($orderNo); ?></b></div>

  <div class="card">
    <div class="amount-row">
      <span class="amount-val" id="amountText"><?php echo usdt_h($amountStr); ?></span>
      <span class="amount-unit">(USDT)</span>
    </div>
    <?php if ($inr > 0 && $rate > 0): ?>
    <div class="inr-hint">≈ ₹<?php echo usdt_h($inrStr); ?> · 1 USDT = ₹<?php echo usdt_h($rateStr); ?></div>
    <?php endif; ?>
    <button type="button" class="btn-copy" id="copyAmount">Copy</button>
  </div>

  <div class="card">
    <div class="net-wrap">
      <div class="net-pill"><span class="tron-ico">T</span> <?php echo usdt_h($network); ?></div>
    </div>
    <div class="qr-wrap">
      <?php if ($qr !== ''): ?>
        <img src="<?php echo usdt_h($qr); ?>" alt="USDT QR">
      <?php else: ?>
        <div style="padding:40px;color:#999">QR unavailable</div>
      <?php endif; ?>
    </div>
    <div class="addr-box" id="walletText"><?php echo usdt_h($wallet); ?></div>
    <button type="button" class="btn-copy" id="copyWallet">Copy</button>
    <div class="timer" id="timer">--:--</div>
    <div class="timer-hint" id="timerHint">Please pay within the time limit</div>
  </div>

  <div class="card tx-card">
    <?php if ($paid): ?>
      <div class="status-ok">Payment approved</div>
    <?php elseif ($txDone): ?>
      <div class="status-ok">Submitted — waiting for admin approval</div>
      <?php if ($existingTx !== '' && $existingTx !== '0'): ?>
        <div class="tips" style="text-align:center">Tx: <?php echo usdt_h($existingTx); ?></div>
      <?php endif; ?>
    <?php else: ?>
      <div class="tx-label">After transfer, submit TxID / Hash</div>
      <input class="tx-input" id="txInput" type="text" maxlength="128" placeholder="Enter transaction hash / TxID" autocomplete="off">
      <button type="button" class="btn-submit" id="submitTx">Submit for approval</button>
      <div class="tips">Only send USDT on TRC20 network to the address above. Admin will credit after confirmation.</div>
    <?php endif; ?>
  </div>

  <a class="back-link" href="/#/wallet/RechargeHistory">Back to history</a>
  <?php endif; ?>
</div>
<div class="toast" id="toast"></div>
<div class="processing" id="processing"><div class="spin"></div><div class="txt">Processing…</div></div>
<script>
(function(){
  var remain = <?php echo (int)$remain; ?>;
  var orderNo = <?php echo json_encode($orderNo, JSON_UNESCAPED_UNICODE); ?>;
  var toastEl = document.getElementById('toast');
  var processing = document.getElementById('processing');
  function toast(msg){
    if(!toastEl) return;
    toastEl.textContent = msg;
    toastEl.style.display = 'block';
    setTimeout(function(){ toastEl.style.display = 'none'; }, 1800);
  }
  function showProcessing(){
    if(processing) processing.classList.add('show');
  }
  function goHistory(){
    location.href = '/#/wallet/RechargeHistory';
  }
  function copyText(t){
    t = String(t||'');
    if(!t) return;
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(t).then(function(){ toast('Copied'); }).catch(function(){ fallbackCopy(t); });
    } else fallbackCopy(t);
  }
  function fallbackCopy(t){
    var a = document.createElement('textarea');
    a.value = t; document.body.appendChild(a); a.select();
    try { document.execCommand('copy'); toast('Copied'); } catch(e){ toast('Copy failed'); }
    a.remove();
  }
  var ca = document.getElementById('copyAmount');
  if(ca) ca.addEventListener('click', function(){ copyText(document.getElementById('amountText').textContent); });
  var cw = document.getElementById('copyWallet');
  if(cw) cw.addEventListener('click', function(){ copyText(document.getElementById('walletText').textContent); });

  function fmt(sec){
    sec = Math.max(0, sec|0);
    var m = Math.floor(sec/60), s = sec%60;
    return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
  }
  function words(sec){
    sec = Math.max(0, sec|0);
    var m = Math.floor(sec/60), s = sec%60;
    return 'Please pay within ' + m + ' minutes and ' + s + ' seconds';
  }
  var timerEl = document.getElementById('timer');
  var hintEl = document.getElementById('timerHint');
  function tick(){
    if(timerEl) timerEl.textContent = fmt(remain);
    if(hintEl) hintEl.textContent = remain > 0 ? words(remain) : 'Payment window expired';
    if(remain > 0) remain--;
  }
  tick();
  setInterval(tick, 1000);

  var btn = document.getElementById('submitTx');
  var input = document.getElementById('txInput');
  if(btn && input){
    btn.addEventListener('click', function(){
      var tx = (input.value||'').trim();
      if(tx.length < 8){ toast('Enter valid TxID / hash'); return; }
      btn.disabled = true;
      showProcessing();
      fetch('/pay/usdt_submit.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json', 'Accept':'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('token') || '')},
        body: JSON.stringify({ orderNo: orderNo, order: orderNo, txId: tx, txid: tx })
      }).then(function(r){ return r.json(); }).then(function(j){
        if(j && (j.ok || j.status === true || j.success)){
          setTimeout(goHistory, 900);
        } else {
          if(processing) processing.classList.remove('show');
          toast((j && (j.msg || j.message)) || 'Submit failed');
          btn.disabled = false;
        }
      }).catch(function(){
        if(processing) processing.classList.remove('show');
        toast('Network error');
        btn.disabled = false;
      });
    });
  }
})();
</script>
</body>
</html>
    <?php
    exit;
}

// Existing order view
if ($orderNo !== '') {
    $stmt = $conn->prepare('SELECT balakedara,motta,sthiti,ullekha,mula,ekikrtapavati,dinankavannuracisi FROM thevani WHERE dharavahi=? LIMIT 1');
    $stmt->bind_param('s', $orderNo);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"/><title>USDT</title></head>'
            . '<body style="font-family:sans-serif;background:#f2f3f5;padding:20px">'
            . '<div style="max-width:420px;margin:0 auto;background:#fff;border-radius:14px;padding:18px;text-align:center">'
            . '<p>Order not found</p>'
            . '<p><a href="/#/wallet/Recharge" style="color:#e85d04;font-weight:700">Back to Deposit</a></p>'
            . '</div></body></html>';
        exit;
    }

    $status = ((int)$row['sthiti'] === 1)
        ? 'Paid'
        : (((string)$row['ullekha'] !== '' && (string)$row['ullekha'] !== '0') ? 'Pending review' : 'Waiting payment');
    $tx = (string)$row['ullekha'];

    /* U1: motta is INR credit; USDT face lives in ekikrtapavati usdt= */
    $metaSrc = (string)($row['ekikrtapavati'] ?? '') . ' ' . (string)($row['mula'] ?? '');
    $orderRate = $rate;
    if (preg_match('/rate=([0-9.]+)/', $metaSrc, $m)) {
        $orderRate = (float)$m[1];
    }
    $amt = (float)$row['motta'];
    if (preg_match('/usdt=([0-9.]+)/', $metaSrc, $mu)) {
        $amt = (float)$mu[1];
    } elseif ($amt > 0 && $amt < 1000 && $amt === (float)(int)$amt) {
        /* legacy: motta was USDT face */
    } elseif ($orderRate > 0 && $amt >= 1000) {
        /* motta is face INR (new) or legacy INR credit — prefer usdt= meta */
        $amt = round($amt / $orderRate, 2);
        if ($amt <= 0) {
            $amt = (float)$row['motta'];
        }
    }
    $orderInr = round($amt * $orderRate, 2);
    if (preg_match('/inr=([0-9.]+)/', $metaSrc, $m2)) {
        $orderInr = (float)$m2[1];
    }

    $createdAt = strtotime((string)($row['dinankavannuracisi'] ?? '')) ?: time();
    $remain = max(0, ($createdAt + ($expireMinutes * 60)) - time());
    $qr = usdt_qr_url($wallet, (string)($config['qr_image'] ?? ''));

    usdt_render_pay_page([
        'orderNo' => $orderNo,
        'amount' => $amt,
        'rate' => $orderRate,
        'inr' => $orderInr,
        'wallet' => $wallet,
        'network' => $network,
        'qr' => $qr,
        'remain' => $remain,
        'status' => $status,
        'tx' => $tx,
    ]);
}

if ($amountUsdt < $min || $uidStr === '' || $uidStr === '0') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'invalid amount or uid', 'min' => $min]);
    exit;
}

if (!pay_accept_uid_jwt($uidStr)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'unauthorized']);
    exit;
}
if (!pay_ensure_local_player($conn, $uidStr)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'user not found']);
    exit;
}

$stmt = $conn->prepare('SELECT mobile FROM shonu_subjects WHERE id=? LIMIT 1');
$stmt->bind_param('s', $uidStr);
$stmt->execute();
$stmt->bind_result($mobile);
$found = $stmt->fetch();
$stmt->close();
if (!$found) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => 'user not found']);
    exit;
}

$mchOrderNo = 'USDT' . date('YmdHis') . rand(1000, 9999);
$usdtFace = $amountUsdt;
$inr = round($usdtFace * $rate, 2);
$createdate = date('Y-m-d H:i:s');
$payid = 11;
/* Face INR only — Channel 2 promo is applied in deposit_apply_promo_and_turnover */
$mottaFloat = $inr;
$srl = $mchOrderNo;
$refNum = '';
$mula = $channel; // History Type = selected channel name
$sthiti = 0;
$madari = (string)$tyid;
$pav = 11;
$rateMeta = 'rate=' . $rate . ';inr=' . $inr . ';usdt=' . $usdtFace . ';bonus=' . $bonusRate . ';gateway=USDT-TRC20';

$ins = pay_insert_thevani_pending(
    $conn,
    $uidStr,
    $payid,
    $mottaFloat,
    $srl,
    $mula,
    $refNum,
    $mobile,
    $rateMeta,
    $createdate,
    $madari,
    $pav,
    $sthiti
);
if (empty($ins['ok'])) {
    error_log('usdt thevani insert fail uid=' . $uidStr . ' ' . (string)($ins['error'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:20px;background:#14082B;color:#fff">'
        . '<p>could not create deposit order</p>'
        . '<p><a style="color:#F5C24C" href="/#/wallet/Recharge">Back to Deposit</a></p></body></html>';
    exit;
}
deposit_bonus_stamp_method($conn, $mchOrderNo, deposit_bonus_method_from_request($channel));

header('Location: /pay/usdt.php?order=' . urlencode($mchOrderNo));
exit;
