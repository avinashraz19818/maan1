<?php
/**
 * Test double for developer-maruf/api/webapi/_app_api.php (harness only).
 * The real file is NOT replaced by this — it only exists inside the WASM test FS.
 */

class DrStop extends Exception
{
    public array $payload;
    public function __construct(array $payload)
    {
        $this->payload = $payload;
        parent::__construct('app_api_send called');
    }
}

function app_api_send($data = null, int $code = 0, string $msg = 'Succeed', int $http = 200, ?int $msgCode = null): void
{
    throw new DrStop(['data' => $data, 'code' => $code, 'msg' => $msg, 'http' => $http, 'msgCode' => $msgCode]);
}

function app_api_input(): array
{
    global $RW_BODY;
    return is_array($RW_BODY) ? $RW_BODY : [];
}

function app_api_require_post(): void
{
}

function app_api_require_signature(array $body): void
{
}

function app_api_client_ip(): string
{
    return '10.0.0.9';
}

/** Same semantics as app_core.php: md5 (legacy) + password_hash (modern). */
function app_api_password_matches(string $plain, string $stored): bool
{
    if ($plain === '' || $stored === '') {
        return false;
    }
    $info = password_get_info($stored);
    if (!empty($info['algo'])) {
        return password_verify($plain, $stored);
    }
    return preg_match('/^[a-f0-9]{32}$/i', $stored) === 1 && hash_equals(strtolower($stored), md5($plain));
}

function app_api_password_value($db, string $plain): string
{
    global $RW_PASSWORD_COLUMN_LENGTH;
    return ((int)($RW_PASSWORD_COLUMN_LENGTH ?? 32)) >= 60 ? password_hash($plain, PASSWORD_DEFAULT) : md5($plain);
}

function app_setting(string $key, $default = null)
{
    global $RW_SETTINGS;
    return $RW_SETTINGS[$key] ?? $default;
}

function app_setting_bool(string $key, bool $default = false): bool
{
    global $RW_SETTINGS;
    if (!isset($RW_SETTINGS[$key])) {
        return $default;
    }
    return in_array(strtolower((string)$RW_SETTINGS[$key]), ['1', 'true', 'yes', 'on'], true);
}

function app_set_setting(string $key, string $value, string $group = 'General'): void
{
    global $RW_SETTINGS;
    $RW_SETTINGS[$key] = $value;
}

function app_table_exists(string $table): bool
{
    global $RW_TABLES;
    return in_array($table, is_array($RW_TABLES) ? $RW_TABLES : [], true);
}

function app_password_needs_upgrade($db, string $stored): bool
{
    global $RW_PASSWORD_COLUMN_LENGTH;
    if (((int)($RW_PASSWORD_COLUMN_LENGTH ?? 32)) < 60) {
        return false;
    }
    $info = password_get_info($stored);
    return empty($info['algo']) || password_needs_rehash($stored, PASSWORD_DEFAULT);
}

function app_password_value($db, string $plain, string $table = 'shonu_subjects', string $column = 'password'): string
{
    global $RW_PASSWORD_COLUMN_LENGTH;
    return ((int)($RW_PASSWORD_COLUMN_LENGTH ?? 32)) >= 60 ? password_hash($plain, PASSWORD_DEFAULT) : md5($plain);
}

function generate_jwt($headers, $payload, $secret = null): string
{
    $encode = static function ($value): string {
        return rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
    };
    return $encode($headers) . '.' . $encode($payload) . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', 'x', 'y', true)), '+/', '-_'), '=');
}

function is_jwt_valid($jwt, $secret = null): string
{
    return json_encode(['status' => 'Success', 'payload' => []]);
}
