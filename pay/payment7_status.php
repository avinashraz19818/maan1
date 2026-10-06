<?php
/**
 * Rhinopay cashier poll + UTR note. Does not credit from UTR alone.
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

include __DIR__ . '/rhinopay_db.php';
$config = include __DIR__ . '/rhinopay_config.php';
require_once __DIR__ . '/rhinopay_lib.php';
if (!is_array($config)) {
    $config = [];
}

function payment7_json(array $out): void
{
    echo json_encode($out);
    exit;
}

$orderId = trim((string)($_GET['orderId'] ?? $_POST['orderId'] ?? ''));
$mchOrderNo = trim((string)($_GET['mchOrderNo'] ?? $_POST['mchOrderNo'] ?? ''));
$amount = number_format((float)($_GET['amount'] ?? $_POST['amount'] ?? 0), 2, '.', '');
$t = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$sign = trim((string)($_GET['sign'] ?? $_POST['sign'] ?? ''));

if (!rhinopay_cashier_verify($config, $orderId, $mchOrderNo, $amount, $t, $sign)) {
    payment7_json(['ok' => false, 'paid' => false, 'message' => 'invalid']);
}

$row = rhinopay_cashier_load($mchOrderNo);
if (!is_array($row) || (string)($row['orderId'] ?? '') !== $orderId) {
    payment7_json(['ok' => false, 'paid' => false, 'message' => 'not_found']);
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
if ($action === 'utr' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $utr = strtoupper(preg_replace('/\s+/', '', (string)($_POST['utr'] ?? '')));
    $utr = preg_replace('/[^A-Z0-9]/', '', $utr);
    if (strlen($utr) < 8 || strlen($utr) > 22) {
        payment7_json(['ok' => false, 'paid' => false, 'message' => 'Enter a valid UTR / UPI Ref No']);
    }
    $row['utr'] = $utr;
    rhinopay_cashier_save($mchOrderNo, $row);
    rhinopay_log($config, 'UTR_NOTE ' . $mchOrderNo . ' utr=' . $utr);
    $walletNo = trim((string)($row['walletOrderNo'] ?? ''));
    if ($walletNo === '') {
        $walletNo = rhinopay_wallet_code_from_url((string)($row['iframe'] ?? ''));
    }
    if ($walletNo !== '') {
        rhinopay_wallet_submit_utr($config, $walletNo, $utr);
    }
}

$paid = false;
$msg = 'pending';
if (isset($conn) && $conn instanceof mysqli) {
    $st = $conn->prepare('SELECT sthiti FROM thevani WHERE dharavahi=? LIMIT 1');
    if ($st) {
        $st->bind_param('s', $mchOrderNo);
        $st->execute();
        $st->bind_result($sthiti);
        if ($st->fetch() && (int)$sthiti === 1) {
            $paid = true;
            $msg = 'already_paid';
        }
        $st->close();
    }
    if (!$paid) {
        $q = rhinopay_query_order($config, $mchOrderNo);
        if (!empty($q['ok']) && !empty($q['paid'])) {
            $credit = rhinopay_credit_order($conn, $config, $mchOrderNo, (string)$q['tradeNo']);
            $paid = !empty($credit['credited']) || !empty($credit['already']);
            $msg = (string)($credit['msg'] ?? 'ok');
        }
    }
}

payment7_json([
    'ok' => true,
    'paid' => $paid,
    'message' => $action === 'utr'
        ? ($paid ? 'Payment received' : 'UTR saved. Waiting for payment confirmation.')
        : $msg,
]);
