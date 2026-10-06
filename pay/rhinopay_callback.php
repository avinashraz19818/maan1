<?php
/**
 * Rhinopay (WatchPay) pay-in notify.
 * Docs: POST form; tradeResult=1; reply exact "success".
 */
@ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);

include __DIR__ . '/rhinopay_db.php';
$config = include __DIR__ . '/rhinopay_config.php';
require_once __DIR__ . '/rhinopay_lib.php';

if (!is_array($config)) {
    $config = [];
}

function rhinopay_cb_ok(): void
{
    echo 'success';
    exit;
}

function rhinopay_cb_fail(): void
{
    echo 'fail';
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    rhinopay_log($config, 'NO DB');
    rhinopay_cb_fail();
}

$raw = file_get_contents('php://input');
$post = $_POST;
if (empty($post) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $post = $decoded;
    } else {
        parse_str($raw, $parsed);
        if (!empty($parsed)) {
            $post = $parsed;
        }
    }
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$clientIp = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
rhinopay_log($config, 'CALLBACK-ENTRY method=' . $method . ' ip=' . $clientIp . ' ct=' . $contentType
    . ' raw=' . substr((string)$raw, 0, 2000));

if (empty($post) && !empty($_GET)) {
    $post = $_GET;
}

$mchOrderNo = trim((string)($post['mchOrderNo'] ?? $post['mch_order_no'] ?? ''));
$tradeResult = $post['tradeResult'] ?? $post['trade_result'] ?? '';
$receivedSign = (string)($post['sign'] ?? '');
$payOrderId = (string)($post['orderNo'] ?? $post['tradeNo'] ?? $post['order_no'] ?? '');
$cbMch = trim((string)($post['mchId'] ?? $post['mch_id'] ?? ''));
$expectMch = (string)($config['mch_id'] ?? '');

if ($mchOrderNo === '') {
    rhinopay_log($config, 'CALLBACK empty order — ack');
    rhinopay_cb_ok();
}

if ($cbMch !== '' && $expectMch !== '' && $cbMch !== $expectMch) {
    rhinopay_log($config, "CALLBACK mch mismatch got={$cbMch}");
    rhinopay_cb_fail();
}

if (!rhinopay_is_paid($tradeResult)) {
    rhinopay_log($config, "SKIP not-paid tradeResult={$tradeResult} order={$mchOrderNo}");
    rhinopay_cb_ok();
}

$md5Key = (string)($config['md5_key'] ?? '');
$signOk = $receivedSign !== '' && rhinopay_verify_sign($post, $receivedSign, $md5Key);

if ($signOk) {
    rhinopay_log($config, "CALLBACK SIGN OK {$mchOrderNo}");
    $credit = rhinopay_credit_order($conn, $config, $mchOrderNo, $payOrderId);
    rhinopay_log($config, 'CALLBACK-CREDIT sign ' . $mchOrderNo . ' ' . json_encode($credit));
    if (!$credit['found']) {
        rhinopay_cb_fail();
    }
    if (empty($credit['credited']) && empty($credit['already'])) {
        rhinopay_cb_fail();
    }
    rhinopay_cb_ok();
}

rhinopay_log($config, "CALLBACK SIGN FAIL/MISS {$mchOrderNo} got=" . substr($receivedSign, 0, 32));
rhinopay_cb_fail();
