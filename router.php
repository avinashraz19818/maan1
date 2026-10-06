<?php
/**
 * PHP built-in server router (mirrors .htaccess for local dev).
 * /api is served by the LOCAL PHP backend (api/_router.php + MySQL).
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = __DIR__;
$file = $root . $uri;

if ($uri === '/web/config' || $uri === '/web/config/') {
    require $root . '/web/config.php';
    return true;
}

if (preg_match('#^/api/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/?$#', $uri, $m)) {
    $localPhp = $root . '/api/' . $m[1] . '/' . $m[2] . '.php';
    if (is_file($localPhp)) {
        require $localPhp;
        return true;
    }
    $_GET['path'] = $m[1] . '/' . $m[2];
    require $root . '/api/_router.php';
    return true;
}

if (preg_match('#^/api/[A-Za-z0-9_/]+#', $uri)) {
    $_GET['path'] = ltrim(substr($uri, 4), '/');
    require $root . '/api/_router.php';
    return true;
}

if (preg_match('#^/webapi/kv/issue/[A-Za-z0-9_]+#', $uri)) {
    require $root . '/webapi_proxy.php';
    return true;
}

if (preg_match('#^/lottery-draw(/|$)#', $uri)) {
    require $root . '/lottery_draw_proxy.php';
    return true;
}

if (preg_match('#^/img/.+#', $uri) && !is_file($file)) {
    require $root . '/img_proxy.php';
    return true;
}

if (preg_match('#^/assets/.+#', $uri) && !is_file($file)) {
    require $root . '/assets_proxy.php';
    return true;
}

if (preg_match('#^/deepanshu/api/webapi/([A-Za-z0-9_]+)/?$#', $uri, $m)) {
    $php = $root . '/deepanshu/api/webapi/' . $m[1] . '.php';
    if (is_file($php)) {
        require $php;
        return true;
    }
}

if ($uri !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, ['js', 'css', 'html'], true)) {
        $mimes = [
            'js' => 'application/javascript; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
        ];
        header('Content-Type: ' . $mimes[$ext]);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($file);
        return true;
    }
    return false;
}

if (is_dir($file)) {
    foreach (['index.php', 'index.html'] as $idx) {
        if (is_file(rtrim($file, '/') . '/' . $idx)) {
            return false;
        }
    }
}

if (preg_match('#^/(assets|css|js|img|images|webapi)(/|$)#', $uri)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    return true;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require $root . '/index.html';
return true;
