<?php
header('Content-Type: application/json; charset=utf-8');
$config = include __DIR__ . '/usdt_config.php';
$rate = (float)($config['default_rate'] ?? 97);
$bonus = (float)($config['deposit_bonus_rate'] ?? 0.07);
if (!is_finite($bonus) || $bonus < 0 || $bonus > 1) {
    $bonus = 0.07;
}
try {
    include __DIR__ . '/../serive/samparka.php';
    require_once __DIR__ . '/usdt_inr_helper.php';
    if (isset($conn) && $conn) {
        $rate = pay_get_usdt_inr_rate($conn);
    }
} catch (Throwable $e) {
    // keep fallback rate
}
if (!is_finite($rate) || $rate <= 0) {
    $rate = 97.0;
}
echo json_encode([
    'ok' => true,
    'rate' => $rate,
    'currency' => 'INR',
    'bonusRate' => $bonus,
]);
