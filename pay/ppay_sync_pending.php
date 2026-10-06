<?php
/**
 * Sync all pending PPay deposits for the authenticated user.
 * GET/POST with Authorization: Bearer <jwt>
 * Optional: ?uid= for same-user check after JWT decode.
 */
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
header('Access-Control-Allow-Credentials: true');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

include __DIR__ . '/../serive/samparka.php';
$config = include __DIR__ . '/ppay_config.php';
require_once __DIR__ . '/ppay_lib.php';
if (!is_array($config)) {
    $config = [];
}

/* JWT helpers live in deepanshu/functions2.php */
$fn2 = __DIR__ . '/../deepanshu/functions2.php';
if (is_file($fn2)) {
    require_once $fn2;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$author = '';
if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
    $author = $m[1];
} elseif ($authHeader !== '' && strpos($authHeader, '.') !== false) {
    $author = trim($authHeader);
}

$uid = 0;
if ($author !== '' && function_exists('is_jwt_valid')) {
    $dataAuth = json_decode(is_jwt_valid($author), true);
    if (is_array($dataAuth) && ($dataAuth['status'] ?? '') === 'Success') {
        $uid = (int)($dataAuth['payload']['id'] ?? 0);
        $chk = $conn->prepare('SELECT id FROM shonu_subjects WHERE id = ? AND akshinak = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('is', $uid, $author);
            $chk->execute();
            $ok = $chk->get_result()->num_rows === 1;
            $chk->close();
            if (!$ok) {
                $uid = 0;
            }
        }
    }
}

/* Fallback: explicit uid query param only if JWT matched same user */
$uidParam = (int)($_GET['uid'] ?? $_POST['uid'] ?? 0);
if ($uid > 0 && $uidParam > 0 && $uidParam !== $uid) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'uid_mismatch']);
    exit;
}
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'unauthorized']);
    exit;
}

// Keep short: local PHP built-in server is single-threaded; long gateway polls block the SPA.
@set_time_limit(12);
$sync = ppay_sync_pending_for_user($conn, $config, $uid, 3);
if (!empty($sync['credited'])) {
    foreach ($sync['credited'] as $row) {
        ppay_log_line($config, 'SYNC_PENDING credited uid=' . $uid . ' ' . json_encode($row));
    }
}
echo json_encode([
    'ok' => true,
    'uid' => $uid,
    'checked' => $sync['checked'],
    'credited' => $sync['credited'],
    'errors' => $sync['errors'],
]);
