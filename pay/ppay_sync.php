<?php
@ini_set('display_errors', '0');
/**
 * Manual / emergency sync: query PPayPros and credit if paid.
 * Usage: /pay/ppay_sync.php?o=PP202607212059026879
 */
header('Content-Type: application/json; charset=utf-8');

include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/ppay_config.php';
require_once __DIR__ . '/ppay_lib.php';
if (!is_array($config)) {
    $config = [];
}

$mchOrderNo = trim((string)($_GET['o'] ?? $_GET['mchOrderNo'] ?? $_POST['mchOrderNo'] ?? ''));
if ($mchOrderNo === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'missing o']);
    exit;
}

$_dmgSec = __DIR__ . '/../deepanshu/helpers/dmg_critical_security.php';
if (is_readable($_dmgSec)) {
    require_once $_dmgSec;
}
$localOk = (PHP_SAPI === 'cli')
    || (function_exists('dmg_request_is_local_cli') && dmg_request_is_local_cli());
$adminToken = getenv('PPAY_RECONCILE_TOKEN') ?: 'dmgwin-ppay-reconcile';
$given = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$tokenOk = ($adminToken !== '' && $given !== '' && hash_equals((string) $adminToken, $given));
if (!$localOk && !$tokenOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'forbidden']);
    exit;
}

$q = ppay_query_order($config, $mchOrderNo);
if (!$q['ok']) {
    echo json_encode(['ok' => false, 'msg' => 'query_fail', 'raw' => $q['raw']]);
    exit;
}

if ((int)$q['state'] !== 2) {
    echo json_encode([
        'ok' => false,
        'msg' => 'not_paid',
        'state' => $q['state'],
        'payOrderId' => $q['payOrderId'],
    ]);
    exit;
}

$credit = ppay_credit_order($conn, $config, $mchOrderNo, (string)$q['payOrderId']);
echo json_encode([
    'ok' => true,
    'state' => 2,
    'payOrderId' => $q['payOrderId'],
    'credit' => $credit,
]);
