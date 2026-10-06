<?php
/**
 * Same-origin tenant image proxy.
 * /img/{rest} → https://pro-img.arsaaspub.com/{rest}
 * fallback → https://maanwin16.com/img/{rest}
 */
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$path = (string) parse_url($uri, PHP_URL_PATH);
$path = '/' . ltrim($path, '/');

if (!preg_match('#^/img/(.+)$#', $path, $m)) {
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

$targets = [
    'https://pro-img.arsaaspub.com/' . $rest,
    'https://maanwin16.com/img/' . $rest,
];

if (!function_exists('curl_init')) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'curl missing';
    exit;
}

$ok = false;
foreach ($targets as $target) {
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
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
    $ok = true;
    break;
}

if (!$ok) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
}
