<?php
/**
 * Same-origin missing /assets proxy (VIP badges, skin webp).
 * Local file wins; otherwise GET https://maanwin16.com/assets/...
 */
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$path = (string) parse_url($uri, PHP_URL_PATH);
$path = '/' . ltrim($path, '/');

if (!preg_match('#^/assets/(.+)$#', $path, $m)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$rest = $m[1];
if (strpos($rest, '..') !== false) {
    http_response_code(400);
    exit;
}

if (!preg_match('#\.(webp|png|jpe?g|gif|svg|ico|woff2?|ttf)$#i', $rest)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$local = __DIR__ . '/assets/' . $rest;
if (is_file($local)) {
    $ext = strtolower(pathinfo($local, PATHINFO_EXTENSION));
    $types = [
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    readfile($local);
    exit;
}

$candidates = ['/assets/' . $rest];
$themeFile = __DIR__ . '/themes-00e063ad.json';
if (is_file($themeFile)) {
    $rawTheme = @file_get_contents($themeFile);
    $theme = $rawTheme ? json_decode($rawTheme, true) : null;
    $assetsMap = [];
    if (is_array($theme) && !empty($theme['themes'][0]['assets']) && is_array($theme['themes'][0]['assets'])) {
        $assetsMap = $theme['themes'][0]['assets'];
    }
    $fileName = basename($path);
    $key = preg_replace('/\.(webp|png|jpe?g|gif|svg|ico|woff2?|ttf)$/i', '', $fileName);
    $key = preg_replace('/-[a-f0-9]{6,}$/i', '', $key);
    if ($key !== '' && !empty($assetsMap[$key])) {
        $hashed = '/' . ltrim((string) $assetsMap[$key], '/');
        if (!in_array($hashed, $candidates, true)) {
            $candidates[] = $hashed;
        }
    }
}

if (!function_exists('curl_init')) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'curl missing';
    exit;
}

$ok = false;
$respHeaders = '';
$respBody = '';
$status = 0;
foreach ($candidates as $candPath) {
    $target = 'https://maanwin16.com' . $candPath;
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Origin: https://maanwin16.com',
            'Referer: https://maanwin16.com/',
            'User-Agent: Mozilla/5.0',
        ],
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false || $errno || $status < 200 || $status >= 300) {
        continue;
    }
    $respHeaders = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);
    if ($respBody === '' || strncasecmp(ltrim($respBody), '<!DOCTYPE', 9) === 0 || strncasecmp(ltrim($respBody), '<html', 5) === 0) {
        continue;
    }
    $ok = true;
    break;
}

if (!$ok) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

http_response_code($status);
$sentType = false;
foreach (explode("\r\n", $respHeaders) as $line) {
    if (stripos($line, 'Content-Type:') === 0) {
        header($line, true);
        $sentType = true;
    }
}
if (!$sentType) {
    header('Content-Type: image/webp');
}
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
echo $respBody;
