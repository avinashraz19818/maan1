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
$logFile = $logDir . '/ppay.log';
if ($logFile === '' || $logFile === '/ppay.log') {
    $tmp = rtrim((string)sys_get_temp_dir(), '/\\');
    $logFile = ($tmp !== '' ? $tmp : '/tmp') . '/ppay.log';
}

/* Notify MUST be public HTTPS apex — gateways reject localhost/www TLS mismatch */
$primary = function_exists('app_primary_origin') ? app_primary_origin() : 'https://wingoserver.trusheme.info';
$notifyUrl = getenv('PPAY_NOTIFY_URL') ?: ($primary . '/pay/ppay_callback.php');
$returnUrl = getenv('PPAY_RETURN_URL') ?: ($primary . '/pay/ppay_return.php');

return [
    'mch_no'      => getenv('PPAY_MCH_NO') ?: 'M666883',
    'app_id'      => getenv('PPAY_APP_ID') ?: '6a453b04559c616ac1618aea',
    'md5_key'     => getenv('PPAY_MD5_KEY') ?: 'S1ppf3WbwFTLXVuPBefykwIm2iVsHwX1Aac8hD8WbdBEiBbL5oO6l2sPGPEcVnRilvLtPtajD8iPmJVcnw3jaekBpQ7tzKoBFso1MLDRubMRwtD158H9fQNILOXPh5K7',
    'api_url'     => 'https://pay.ppaypros.com/api/pay/pay',
    'query_url'   => 'https://pay.ppaypros.com/api/pay/query',
    'way_code'    => '801',
    'currency'    => 'INR',
    'public_base' => $base,
    'NOTIFY_URL'  => $notifyUrl,
    /* User return → sync page queries gateway then redirects to history */
    'RETURN_URL'  => $returnUrl,
    'LOG_FILE'    => $logFile,
];
