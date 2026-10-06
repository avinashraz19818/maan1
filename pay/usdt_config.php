<?php
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/usdt_pay.log';
if ($logFile === '' || $logFile === '/usdt_pay.log') {
    $tmp = rtrim((string)sys_get_temp_dir(), '/\\');
    $logFile = ($tmp !== '' ? $tmp : '/tmp') . '/usdt_pay.log';
}

return [
    'wallet_address' => 'TTX7NLwJqMTZXBpaY6xMpiUx4SzrBRrvi2',
    'network'        => 'Tron (TRC20)',
    'network_short'  => 'TRC20',
    'expire_minutes' => 60,
    'qr_image'       => '',
    'min_usdt'       => 10,
    'default_rate'   => 97.0, // INR per 1 USDT (fallback if tbl_pg empty)
    'deposit_bonus_rate' => 0.07, // single source: UI + order credit (+7%)
    'LOG_FILE'       => $logFile,
];
