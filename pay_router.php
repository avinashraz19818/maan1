<?php
/**
 * PHP sidecar router for /pay/* (Rhinopay cashier).
 * Used as: php -S 127.0.0.1:8101 pay_router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$root = __DIR__;

if (!preg_match('#^/pay(/|$)#', $uri)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    return true;
}

$map = [
    '/pay/payment7' => $root . '/pay/payment7.php',
    '/pay/payment7/' => $root . '/pay/payment7.php',
    '/pay/payment7_status' => $root . '/pay/payment7_status.php',
    '/pay/payment7_status/' => $root . '/pay/payment7_status.php',
];
if (isset($map[$uri])) {
    require $map[$uri];
    return true;
}

$file = $root . $uri;
if (is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        require $file;
        return true;
    }
    $mimes = [
        'js' => 'application/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'json' => 'application/json; charset=UTF-8',
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        readfile($file);
        return true;
    }
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not found';
return true;
