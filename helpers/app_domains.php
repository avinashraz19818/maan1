<?php
if (!function_exists('app_primary_origin')) {
    function app_primary_origin(): string
    {
        $env = getenv('APP_PRIMARY_ORIGIN');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'wingoserver.trusheme.info');
        if ($host === '' || $host === '127.0.0.1' || strpos($host, 'localhost') === 0) {
            return 'https://wingoserver.trusheme.info';
        }
        return ($https ? 'https://' : 'http://') . $host;
    }
}
