<?php
/**
 * PPayPros payment notification → credit wallet.
 * Docs: POST application/x-www-form-urlencoded; state=2; reply exact "success".
 */
@ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);

include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/ppay_config.php';
require_once __DIR__ . '/ppay_lib.php';

if (!is_array($config)) {
    $config = [];
}

function ppay_cb_ok(): void
{
    echo 'success';
    exit;
}

function ppay_cb_fail(): void
{
    echo 'fail';
    exit;
}

function ppay_cb_credit_and_respond(mysqli $conn, array $config, string $mchOrderNo, string $payOrderId, string $via): void
{
    $credit = ppay_credit_order($conn, $config, $mchOrderNo, $payOrderId);
    ppay_log_line($config, "CALLBACK-CREDIT {$via} {$mchOrderNo} " . json_encode($credit));

    if (!$credit['found']) {
        ppay_log_line($config, "ORDER NOT FOUND {$mchOrderNo}");
        ppay_cb_fail();
    }

    if (empty($credit['credited']) && empty($credit['already'])) {
        ppay_log_line($config, "CREDIT FAIL {$mchOrderNo} msg=" . ($credit['msg'] ?? ''));
        ppay_cb_fail();
    }

    ppay_cb_ok();
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
ppay_log_line($config, 'CALLBACK-ENTRY method=' . $method . ' ip=' . $clientIp . ' ct=' . $contentType
    . ' raw=' . substr((string)$raw, 0, 2000));

if (empty($post) && !empty($_GET)) {
    $post = $_GET;
    ppay_log_line($config, 'CALLBACK-GET keys=' . implode(',', array_keys($post)));
}

$mchOrderNo = trim((string)($post['mchOrderNo'] ?? $post['mch_order_no'] ?? ''));
$stateRaw = $post['state'] ?? $post['orderState'] ?? -1;
$receivedSign = (string)($post['sign'] ?? '');

if ($mchOrderNo === '') {
    ppay_log_line($config, 'CALLBACK empty order — ack');
    ppay_cb_ok();
}

if (!ppay_is_paid_state($stateRaw)) {
    ppay_log_line($config, "SKIP not-paid state={$stateRaw} order={$mchOrderNo}");
    ppay_cb_ok();
}

$md5Key = (string)($config['md5_key'] ?? '');
$signOk = $receivedSign !== '' && ppay_verify_notify_sign($post, $receivedSign, $md5Key);
$payOrderId = (string)($post['payOrderId'] ?? $post['orderId'] ?? $post['channelOrderNo'] ?? '');

if ($signOk) {
    ppay_log_line($config, "CALLBACK SIGN OK {$mchOrderNo}");
    ppay_cb_credit_and_respond($conn, $config, $mchOrderNo, $payOrderId, 'sign');
}

ppay_log_line($config, "CALLBACK SIGN FAIL/MISS {$mchOrderNo} got=" . substr($receivedSign, 0, 32));
ppay_cb_fail();
