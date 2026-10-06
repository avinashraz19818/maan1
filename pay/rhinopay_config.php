<?php
if (PHP_SAPI !== 'cli') {
    $self = realpath(__FILE__);
    $hit = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;
    if ($self && $hit && $self === $hit) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die('Forbidden');
    }
}
$domains = __DIR__ . '/../helpers/app_domains.php';
if (is_readable($domains)) {
    require_once $domains;
}
$base = function_exists('app_primary_origin') ? app_primary_origin() : 'https://wingoserver.trusheme.info';

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/rhinopay.log';
if ($logFile === '' || $logFile === '/rhinopay.log') {
    $tmp = rtrim((string)sys_get_temp_dir(), '/\\');
    $logFile = ($tmp !== '' ? $tmp : '/tmp') . '/rhinopay.log';
}

$primary = function_exists('app_primary_origin') ? app_primary_origin() : 'https://wingoserver.trusheme.info';
$notifyUrl = getenv('RHINOPAY_NOTIFY_URL') ?: ($primary . '/pay/rhinopay_callback.php');
$returnUrl = getenv('RHINOPAY_RETURN_URL') ?: ($primary . '/pay/rhinopay_return.php');
/* Telegram listed api.watchqlb.com — that hostname is NXDOMAIN.
   api.watchglb.com resolves to 18.141.88.123 (same IP as the callback whitelist). */
$apiBase = rtrim((string)(getenv('WATCHPAY_API_BASE') ?: 'https://api.watchglb.com'), '/');
if ($apiBase === ''
    || stripos($apiBase, 'watchpay.cc') !== false
    || stripos($apiBase, 'watchqlb.com') !== false) {
    $apiBase = 'https://api.watchglb.com';
}
$apiBaseAlt = rtrim((string)(getenv('WATCHPAY_API_BASE_ALT') ?: ''), '/');
if ($apiBaseAlt === '' || strcasecmp($apiBaseAlt, $apiBase) === 0) {
    $apiBaseAlt = '';
}

return [
    'display_name' => 'Rhinopay',
    'mch_id'       => getenv('RHINOPAY_MCH_ID') ?: '100789439',
    'md5_key'      => getenv('RHINOPAY_MD5_KEY') ?: '4e7b27d9db3748d1840a2f6c5910f68d',
    /* Official merchant.watchglb.com login / pay password. Docs pay/transfer
       uses MD5 sign only; merBack applytransfer still needs this payPasswd. */
    'merchant_pass'=> getenv('WATCHPAY_MERCHANT_PASS') ?: 'Mk+&KWU6',
    'send_password'=> getenv('WATCHPAY_SEND_PASSWORD') ?: 'Mk+&KWU6',
    'api_base'     => $apiBase,
    'api_base_alt' => $apiBaseAlt,
    'pay_url'      => $apiBase . '/pay/web',
    'query_url'    => $apiBase . '/query/transaction',
    'transfer_url' => $apiBase . '/pay/transfer',
    'transfer_query_url' => $apiBase . '/query/transfer',
    'balance_url'  => $apiBase . '/query/balance',
    'balance_url_alt' => $apiBaseAlt !== '' ? ($apiBaseAlt . '/query/balance') : '',
    /* 102 = docs "India UPI" but this mch returns MERCHANT_HAS_NO_GATEWAY / NOT_SUPPORT_ENTRANCE.
       101 is the assigned India channel (JSON SUCCESS + cashier). */
    'pay_type'     => '101',
    'api_version'  => '1.0',
    'public_base'  => $base,
    'NOTIFY_URL'   => $notifyUrl,
    'RETURN_URL'   => $returnUrl,
    'LOG_FILE'     => $logFile,
];
