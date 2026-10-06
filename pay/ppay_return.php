<?php
/**
 * Browser return after PPay cashier.
 * Redirect only — wallet credit is webhook / JWT sync_pending.
 */
@ob_start();
include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/ppay_config.php';
require_once __DIR__ . '/ppay_lib.php';
if (!is_array($config)) {
    $config = [];
}

$mchOrderNo = trim((string)($_GET['o'] ?? $_GET['mchOrderNo'] ?? $_GET['mch_order_no'] ?? ''));
if ($mchOrderNo === '') {
    $mchOrderNo = trim((string)($_POST['mchOrderNo'] ?? ''));
}

ppay_log_line($config, 'RETURN o=' . $mchOrderNo . ' qs=' . substr((string)($_SERVER['QUERY_STRING'] ?? ''), 0, 300));

$creditMsg = 'pending';
if ($mchOrderNo !== '' && isset($conn) && $conn instanceof mysqli) {
    $st = $conn->prepare('SELECT sthiti FROM thevani WHERE dharavahi=? LIMIT 1');
    if ($st) {
        $st->bind_param('s', $mchOrderNo);
        $st->execute();
        $st->bind_result($sthiti);
        if ($st->fetch() && (int)$sthiti === 1) {
            $creditMsg = 'already_paid';
        }
        $st->close();
    }
}

$dest = '/#/wallet/RechargeHistory?synced=1&ppay=' . rawurlencode($creditMsg);

/* Prefer Location redirect; if headers already sent, HTML + JS fallback. */
if (!headers_sent()) {
    header('Location: ' . $dest);
    exit;
}

@ob_end_clean();
$safeDest = htmlspecialchars($dest, ENT_QUOTES, 'UTF-8');
$safeMsg = htmlspecialchars($creditMsg, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>'
    . '<title>Payment</title>'
    . '<meta http-equiv="refresh" content="1;url=' . $safeDest . '"/>'
    . '<style>body{font-family:sans-serif;background:#14082B;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px}'
    . '.box{max-width:360px}.ok{color:#4ade80;font-size:48px;line-height:1;margin-bottom:12px}a{color:#F5C24C}</style>'
    . '</head><body><div class="box">'
    . '<div class="ok">✓</div>'
    . '<h1 style="font-size:20px;margin:0 0 8px">Payment received</h1>'
    . '<p style="opacity:.85;margin:0 0 16px">Syncing wallet… (' . $safeMsg . ')</p>'
    . '<p><a href="' . $safeDest . '">Open deposit history</a></p>'
    . '</div><script>setTimeout(function(){location.replace(' . json_encode($dest) . ');},800);</script>'
    . '</body></html>';
exit;
