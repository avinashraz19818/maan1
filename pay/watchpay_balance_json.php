<?php
/**
 * Internal WatchPay balance hop. Production IP is usually whitelisted;
 * localhost is not. Admin page may POST here with an HMAC from rhinopay_config.
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

require_once __DIR__ . '/watchpay_transfer.php';
$config = watchpay_cfg();
$token = (string) ($_SERVER['HTTP_X_WATCHPAY_BALANCE'] ?? $_POST['token'] ?? '');
if (!watchpay_balance_token_ok($config, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$bal = watchpay_query_balance($config, false);
echo json_encode([
    'ok' => !empty($bal['ok']),
    'available' => $bal['available'] ?? null,
    'frozen' => $bal['frozen'] ?? null,
    'amount' => $bal['amount'] ?? null,
    'resp_code' => (string) ($bal['resp_code'] ?? ''),
    'error' => (string) ($bal['error'] ?? ''),
], JSON_UNESCAPED_SLASHES);
