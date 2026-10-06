<?php
// ===============================
// Dhani Win Backend Config
// cPanel MySQL details yaha set hain.
// AUTO_INSTALL_TABLES=true hone par pehli API request par tables automatic create/seed ho jayengi.
//
// >>> APNI cPanel MySQL DETAILS BADALNE KE LIYE <<<
// Sabse aasan tarika: site ki root (public_html) me ek file banao
//   dw-db-config.php
// aur usme ye likho (<?php tag ke saath):
//   return [
//       'DB_HOST' => 'localhost',
//       'DB_NAME' => 'youruser_dbname',
//       'DB_USER' => 'youruser_dbuser',
//       'DB_PASS' => 'yourpassword',
//   ];
// Us file ko update overwrite nahi karta. File na ho to niche ki values use hoti hain.
// ===============================

$__dwOverrides = [];
foreach ([dirname(__DIR__, 2) . '/dw-db-config.php', __DIR__ . '/dw-db-config.php'] as $__dwFile) {
    if (is_file($__dwFile)) {
        $__tmp = @include $__dwFile;
        if (is_array($__tmp)) { $__dwOverrides = $__tmp; break; }
    }
}

$__dwValue = static function (string $envKey, string $cfgKey, string $default) use ($__dwOverrides): string {
    $env = getenv($envKey);
    if (is_string($env) && $env !== '') return $env;
    if (isset($__dwOverrides[$cfgKey]) && (string)$__dwOverrides[$cfgKey] !== '') return (string)$__dwOverrides[$cfgKey];
    return $default;
};

define('DB_HOST', $__dwValue('DW_DB_HOST', 'DB_HOST', 'localhost'));
define('DB_NAME', $__dwValue('DW_DB_NAME', 'DB_NAME', 'club532583_maanwin'));
define('DB_USER', $__dwValue('DW_DB_USER', 'DB_USER', 'club532583_maanwin'));
define('DB_PASS', $__dwValue('DW_DB_PASS', 'DB_PASS', 'club532583_maanwin'));

define('AUTO_INSTALL_TABLES', true);
define('AUTO_INSTALL_VERSION', '2026_07_22_v34_support_team_wheel');

define('APP_TENANT_ID', 6007);
define('APP_CURRENCY', 'INR');
define('APP_CURRENCY_SIGN', '₹');
define('APP_SECRET', getenv('DW_APP_SECRET') ?: 'b887fd8e108ea8bdd2587747c6cc1eec91806544edb76abe99d9ec703d8a23ad');
define('APP_TIMEZONE', 'Asia/Kolkata');

// true = project/demo virtual wallet only. Real-money payment gateway/live settlement connected nahi hai.
define('DEMO_MODE', true);

// true = register/login fail hone par MySQL ka error message response me dikhega
// (cPanel par problem debug karne ke liye). Site stable ho jaye to false kar dein.
define('APP_DEBUG_ERRORS', true);

@date_default_timezone_set(APP_TIMEZONE);
