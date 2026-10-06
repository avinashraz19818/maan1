<?php
/**
 * Reconcile PPay deposits: sync pending from gateway + repair paid-without-wallet.
 *
 * CLI:
 *   php pay/ppay_reconcile.php
 *   php pay/ppay_reconcile.php --repair-paid
 *   php pay/ppay_reconcile.php --order=PP202607250222181661
 *
 * HTTP (admin token):
 *   /pay/ppay_reconcile.php?token=SECRET&repair=1&limit=50
 */
@ini_set('display_errors', '0');

$isCli = (PHP_SAPI === 'cli');

include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/ppay_config.php';
require_once __DIR__ . '/ppay_lib.php';
if (!is_array($config)) {
    $config = [];
}

$adminToken = getenv('PPAY_RECONCILE_TOKEN') ?: 'dmgwin-ppay-reconcile';
$limit = 50;
$repairPaid = false;
$singleOrder = '';
$filterUid = 0;

if ($isCli) {
    foreach ($argv as $arg) {
        if ($arg === '--repair-paid') {
            $repairPaid = true;
        } elseif (strpos($arg, '--order=') === 0) {
            $singleOrder = trim(substr($arg, 8));
        } elseif (strpos($arg, '--uid=') === 0) {
            $filterUid = (int)substr($arg, 6);
        } elseif (strpos($arg, '--limit=') === 0) {
            $limit = max(1, min(200, (int)substr($arg, 8)));
        }
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    if ($token === '' || !hash_equals($adminToken, $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'forbidden']);
        exit;
    }
    $repairPaid = !empty($_GET['repair']) || !empty($_POST['repair']);
    $singleOrder = trim((string)($_GET['order'] ?? $_GET['o'] ?? ''));
    $filterUid = (int)($_GET['uid'] ?? $_POST['uid'] ?? 0);
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
}

$out = [
    'ok' => true,
    'pending_checked' => 0,
    'credited' => [],
    'repaired' => [],
    'errors' => [],
];

function ppay_reconcile_emit(array $out, bool $isCli): void
{
    if ($isCli) {
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }
    echo json_encode($out);
}

if ($singleOrder !== '') {
    $q = ppay_query_order($config, $singleOrder);
    if ($q['ok'] && ppay_is_paid_state($q['state'])) {
        $credit = ppay_credit_order($conn, $config, $singleOrder, (string)$q['payOrderId']);
        $out['credited'][] = ['o' => $singleOrder, 'credit' => $credit];
        if (empty($credit['credited']) && !empty($credit['already']) && $repairPaid) {
            $repair = ppay_repair_paid_wallet($conn, $config, $singleOrder);
            $out['repaired'][] = ['o' => $singleOrder, 'repair' => $repair];
        }
    } elseif ($repairPaid) {
        $repair = ppay_repair_paid_wallet($conn, $config, $singleOrder);
        $out['repaired'][] = ['o' => $singleOrder, 'repair' => $repair];
    } else {
        $out['errors'][] = $singleOrder . ':not_paid_or_query_fail';
    }
    ppay_reconcile_emit($out, $isCli);
    exit;
}

/* Prefer per-user sync path (e.g. stuck deposit for UID 2282271). */
if ($filterUid > 0) {
    $sync = ppay_sync_pending_for_user($conn, $config, $filterUid, $limit);
    $out['pending_checked'] = (int)$sync['checked'];
    $out['credited'] = $sync['credited'];
    $out['errors'] = $sync['errors'];
    if ($repairPaid) {
        $uidEsc = (int)$filterUid;
        $sqlPaid = "SELECT dharavahi FROM thevani
            WHERE sthiti = 1 AND dharavahi LIKE 'PP%'
              AND (balakedara = '{$uidEsc}' OR balakedara = {$uidEsc})
              AND (ullekha IS NULL OR ullekha NOT LIKE '%|WC%')
            ORDER BY shonu DESC LIMIT " . (int)$limit;
        $resPaid = $conn->query($sqlPaid);
        if ($resPaid) {
            while ($row = $resPaid->fetch_assoc()) {
                $o = trim((string)($row['dharavahi'] ?? ''));
                if ($o === '') {
                    continue;
                }
                $repair = ppay_repair_paid_wallet($conn, $config, $o);
                if (!empty($repair['repaired']) || !empty($repair['already'])) {
                    $out['repaired'][] = ['o' => $o, 'msg' => $repair['msg'] ?? ''];
                }
            }
        }
    }
    ppay_log_line($config, 'RECONCILE uid=' . $filterUid . ' ' . json_encode([
        'pending' => $out['pending_checked'],
        'credited' => count($out['credited']),
        'repaired' => count($out['repaired']),
        'errors' => count($out['errors']),
    ]));
    ppay_reconcile_emit($out, $isCli);
    exit;
}

$sql = "SELECT dharavahi FROM thevani
    WHERE sthiti = 0 AND (payid = 2 OR dharavahi LIKE 'PP%')
    ORDER BY shonu DESC LIMIT " . (int)$limit;
$res = $conn->query($sql);
$pending = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $o = trim((string)($row['dharavahi'] ?? ''));
        if ($o !== '') {
            $pending[] = $o;
        }
    }
}

foreach ($pending as $mchOrderNo) {
    $out['pending_checked']++;
    try {
        $q = ppay_query_order($config, $mchOrderNo);
        if (!$q['ok']) {
            $out['errors'][] = $mchOrderNo . ':query_fail';
            continue;
        }
        if (!ppay_is_paid_state($q['state'])) {
            continue;
        }
        $credit = ppay_credit_order($conn, $config, $mchOrderNo, (string)$q['payOrderId']);
        if (!empty($credit['credited']) || !empty($credit['already'])) {
            $out['credited'][] = ['o' => $mchOrderNo, 'msg' => $credit['msg'] ?? ''];
        } else {
            $out['errors'][] = $mchOrderNo . ':' . ($credit['msg'] ?? 'credit_fail');
        }
    } catch (Throwable $e) {
        $out['errors'][] = $mchOrderNo . ':' . $e->getMessage();
    }
}

if ($repairPaid) {
    $sqlPaid = "SELECT dharavahi FROM thevani
        WHERE sthiti = 1 AND dharavahi LIKE 'PP%'
          AND (ullekha IS NULL OR ullekha NOT LIKE '%|WC%')
        ORDER BY shonu DESC LIMIT " . (int)$limit;
    $resPaid = $conn->query($sqlPaid);
    if ($resPaid) {
        while ($row = $resPaid->fetch_assoc()) {
            $o = trim((string)($row['dharavahi'] ?? ''));
            if ($o === '') {
                continue;
            }
            try {
                $repair = ppay_repair_paid_wallet($conn, $config, $o);
                if (!empty($repair['repaired']) || !empty($repair['already'])) {
                    $out['repaired'][] = ['o' => $o, 'msg' => $repair['msg'] ?? ''];
                }
            } catch (Throwable $e) {
                $out['errors'][] = $o . ':repair:' . $e->getMessage();
            }
        }
    }
}

ppay_log_line($config, 'RECONCILE ' . json_encode([
    'pending' => $out['pending_checked'],
    'credited' => count($out['credited']),
    'repaired' => count($out['repaired']),
    'errors' => count($out['errors']),
]));

ppay_reconcile_emit($out, $isCli);
