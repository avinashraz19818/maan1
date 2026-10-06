<?php
include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/usdt_config.php';
$_dmgSec = __DIR__ . '/../deepanshu/helpers/dmg_critical_security.php';
if (is_readable($_dmgSec)) {
    require_once $_dmgSec;
}
if (!is_array($config)) {
    $config = [];
}

$order = trim((string)($_POST['order'] ?? $_GET['order'] ?? ''));
$txid = trim((string)($_POST['txid'] ?? $_GET['txid'] ?? ''));

$raw = file_get_contents('php://input');
$json = json_decode((string)$raw, true);
if (is_array($json)) {
    if ($order === '') {
        $order = trim((string)($json['orderNo'] ?? $json['order'] ?? ''));
    }
    if ($txid === '') {
        $txid = trim((string)($json['txId'] ?? $json['txid'] ?? ''));
    }
}

$wantsJson = (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
    || (isset($_GET['format']) && $_GET['format'] === 'json')
    || is_array($json);

function usdt_submit_log($msg) {
    global $config;
    $path = is_array($config) ? (string)($config['LOG_FILE'] ?? '') : '';
    if ($path === '') {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/usdt_pay.log';
    }
    if ($path !== '') {
        @file_put_contents($path, date('c') . ' | ' . $msg . PHP_EOL, FILE_APPEND);
    }
}

function usdt_submit_respond($ok, $message, $wantsJson, $order = '') {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'status' => $ok, 'msg' => $message, 'message' => $message]);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    if ($ok && $order !== '') {
        header('Location: /pay/usdt.php?order=' . urlencode($order));
        exit;
    }
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . ' <a href="/#/wallet/Recharge">Back</a></p>';
    exit;
}

if ($order === '' || $txid === '') {
    usdt_submit_respond(false, 'Missing order or TxID', $wantsJson);
}

$stmt = $conn->prepare('SELECT sthiti, balakedara FROM thevani WHERE dharavahi=? LIMIT 1');
$stmt->bind_param('s', $order);
$stmt->execute();
$stmt->bind_result($sthiti, $ownerRaw);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    usdt_submit_respond(false, 'Order not found', $wantsJson);
}

$ownerUid = (int)$ownerRaw;
$jwtUid = function_exists('dmg_jwt_uid') ? dmg_jwt_uid() : 0;
if ($jwtUid <= 0 || $ownerUid <= 0 || $jwtUid !== $ownerUid) {
    usdt_submit_respond(false, 'unauthorized', $wantsJson);
}

if ((int)$sthiti === 1) {
    usdt_submit_respond(true, 'Already paid', $wantsJson, $order);
}

$u = $conn->prepare('UPDATE thevani SET ullekha=? WHERE dharavahi=? AND sthiti=0');
$u->bind_param('ss', $txid, $order);
$u->execute();
$u->close();

usdt_submit_log("TXID $order = $txid");
usdt_submit_respond(true, 'Submitted', $wantsJson, $order);
