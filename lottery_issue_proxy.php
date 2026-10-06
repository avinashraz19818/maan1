<?php
/**
 * lottery_issue_proxy.php
 * =======================
 * Frontend in do pathon se current issue aur history mangta hai:
 *
 *   /{Lottery}/{gameCode}.json
 *        -> /api/Lottery/GetGameIssue?gameCode={gameCode}
 *
 *   /{Lottery}/{gameCode}/GetHistoryIssuePage.json
 *        -> /api/Lottery/GetHistoryIssuePage?gameCode={gameCode}&pageNo=&pageSize=
 *
 * maan1 ke .htaccess me in dono ka route hi nahi tha, isliye ye SPA fallback
 * me ja kar index.html serve kar rahe the (JSON ki jagah HTML -> parse error).
 *
 * Ye file upstream se data la kar us par period offset lagati hai
 * (dekho helpers/lottery_shift.php).
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/helpers/lottery_shift.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept-Language, X-App-Version');
    http_response_code(204);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => -1, 'msg' => 'Method not allowed', 'msgCode' => -1, 'data' => null]);
    exit;
}

$UPSTREAM = 'https://maanwin16.com';

$uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
$path = (string) parse_url($uri, PHP_URL_PATH);
$path = '/' . ltrim($path, '/');
$path = preg_replace('#\.json$#i', '', $path);
$path = rtrim($path, '/');

/**
 * /WinGo/WinGo_30S                    -> GetGameIssue
 * /WinGo/WinGo_30S/GetHistoryIssuePage -> GetHistoryIssuePage
 */
if (!preg_match('#^/([A-Za-z0-9]+)/([A-Za-z0-9_]+)(/GetHistoryIssuePage)?/?$#', $path, $m)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => -1, 'msg' => 'Not found', 'msgCode' => -1, 'data' => null]);
    exit;
}

$lotteryCode = $m[1];
$gameCode    = $m[2];
$isHistory   = isset($m[3]) && $m[3] !== '';

$params = ['gameCode' => $gameCode];
if ($isHistory) {
    $params['pageNo']   = (isset($_GET['pageNo'])   && (int) $_GET['pageNo'] > 0)   ? (int) $_GET['pageNo']   : 1;
    $params['pageSize'] = (isset($_GET['pageSize']) && (int) $_GET['pageSize'] > 0) ? (int) $_GET['pageSize'] : 10;
}

$action = $isHistory ? 'GetHistoryIssuePage' : 'GetGameIssue';
$target = $UPSTREAM . '/api/Lottery/' . $action . '?' . http_build_query($params);

$headers = [
    'Origin: ' . $UPSTREAM,
    'Referer: ' . $UPSTREAM . '/',
    'Accept: application/json, text/plain, */*',
];
if (!empty($_SERVER['HTTP_USER_AGENT'])) {
    $headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
}
if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $headers[] = 'Accept-Language: ' . $_SERVER['HTTP_ACCEPT_LANGUAGE'];
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
        if (strtolower((string) $k) === 'authorization') {
            $auth = (string) $v;
            break;
        }
    }
}
$auth = trim((string) $auth);
if ($auth !== '' && $auth !== 'undefined' && $auth !== 'null' && $auth !== 'Bearer') {
    $headers[] = 'Authorization: ' . $auth;
}

if (!function_exists('curl_init')) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 1, 'msg' => 'curl missing', 'msgCode' => 1, 'data' => null]);
    exit;
}

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_ENCODING       => '',
]);

$raw = curl_exec($ch);
$errno = curl_errno($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($raw === false || $errno) {
    // Upstream fail -> local engine se fallback (agar maujood ho)
    $fallback = __DIR__ . '/api/_core/lottery_engine.php';
    if (is_file($fallback)) {
        require_once $fallback;
        if (function_exists('le_get_game_issue')) {
            header('Content-Type: application/json; charset=utf-8');
            echo ls_shift_json_body((string) json_encode(le_get_game_issue($gameCode)), '/' . $lotteryCode . '/' . $gameCode);
            exit;
        }
    }
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 1, 'msg' => 'ProxyError', 'msgCode' => 1, 'data' => null]);
    exit;
}

$respHeaders = substr($raw, 0, $headerSize);
$respBody    = substr($raw, $headerSize);
http_response_code($status > 0 ? $status : 200);

$sentType = false;
foreach (explode("\r\n", $respHeaders) as $line) {
    if (stripos($line, 'Content-Type:') === 0) {
        header($line, true);
        $sentType = true;
    }
}
if (!$sentType) {
    header('Content-Type: application/json; charset=utf-8');
}

echo ls_shift_json_body($respBody, '/' . $lotteryCode . '/' . $gameCode);
