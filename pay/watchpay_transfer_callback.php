<?php
/**
 * WatchPay payout async notify (back_url).
 * Reply exact "success" so the platform stops retrying.
 */
@ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);

include __DIR__ . '/rhinopay_db.php';
$config = include __DIR__ . '/rhinopay_config.php';
require_once __DIR__ . '/rhinopay_lib.php';
require_once __DIR__ . '/watchpay_transfer.php';

if (!is_array($config)) {
    $config = [];
}

function watchpay_xfer_cb_ok(): void
{
    echo 'success';
    exit;
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
if (empty($post) && !empty($_GET)) {
    $post = $_GET;
}

$receivedSign = (string) ($post['sign'] ?? '');
$md5Key = (string) ($config['md5_key'] ?? '');
if ($md5Key === '' || $receivedSign === '' || !rhinopay_verify_sign($post, $receivedSign, $md5Key)) {
    rhinopay_log($config, 'WATCHPAY-XFER-CB bad or empty sign');
    echo 'fail';
    exit;
}

$tid = trim((string) ($post['merTransferId'] ?? $post['mch_transferid'] ?? $post['mchTransferId'] ?? ''));
$resp = (string) ($post['respCode'] ?? $post['resp_code'] ?? '');
$trade = (string) ($post['tradeResult'] ?? $post['trade_result'] ?? '');
$err = (string) ($post['errorMsg'] ?? $post['error_msg'] ?? '');

rhinopay_log($config, 'WATCHPAY-XFER-CB tid=' . $tid . ' resp=' . $resp . ' trade=' . $trade);

if ($tid !== '' && isset($conn) && $conn instanceof mysqli) {
    watchpay_payout_update_status($conn, $tid, [
        'resp_code' => $resp,
        'trade_result' => $trade,
        'error_msg' => $err,
        'raw_json' => json_encode($post, JSON_UNESCAPED_SLASHES),
    ]);
}

watchpay_xfer_cb_ok();
