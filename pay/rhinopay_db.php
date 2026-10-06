<?php
/**
 * Rhinopay MySQL — trusheme333_mssgame on VPS.
 */
mysqli_report(MYSQLI_REPORT_OFF);

$cred = __DIR__ . '/../helpers/db_credentials.php';
if (is_readable($cred)) {
    require_once $cred;
}

$_host = defined('DMG_DB_SERVER') ? (string) DMG_DB_SERVER : 'localhost';
$_user = defined('DMG_DB_USER') ? (string) DMG_DB_USER : 'trusheme333_mssgame';
$_pass = defined('DMG_DB_PASS') ? (string) DMG_DB_PASS : 'trusheme333_mssgame';
$_name = defined('DMG_DB_NAME') ? (string) DMG_DB_NAME : 'trusheme333_mssgame';

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = null;
    $hosts = [$_host, 'localhost', '127.0.0.1'];
    $seen = [];
    foreach ($hosts as $host) {
        if ($host === '' || isset($seen[$host])) {
            continue;
        }
        $seen[$host] = 1;
        $link = @mysqli_connect($host, $_user, $_pass, $_name);
        if ($link instanceof mysqli) {
            @mysqli_set_charset($link, 'utf8mb4');
            $conn = $link;
            break;
        }
        error_log('rhinopay_db connect fail ' . $host . ': ' . mysqli_connect_error());
    }
}
