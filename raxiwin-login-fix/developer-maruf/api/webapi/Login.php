<?php
/**
 * RaxiWin — Login endpoint.
 *
 * Hardened 2026-10-15 for the "login par Service not available (HTTP 503)" bug.
 *
 * Why the old file returned 503: everything inside the big try{} block could
 * throw (mysqli throws mysqli_sql_exception on PHP 8.1+ by default), and the
 * catch answered with HTTP 503 -> the bundled app maps 503 to the generic
 * "Service not available!" popup, so the real reason was invisible.
 *
 * What this version does instead:
 *   1. Reads the real columns of shonu_subjects first and only touches columns
 *      that exist, so a database that is one migration behind can not turn a
 *      login into a fatal error.
 *   2. Adds the missing optional legacy columns once (additive ALTER TABLE,
 *      never destructive) and widens akshinak when it is too small for a
 *      modern JWT ("Data too long for column 'akshinak'" was a classic 503).
 *   3. Stores the session with fallbacks: full set -> truncated token ->
 *      minimal set, skipping columns whose data type does not fit the value
 *      (for example a numeric ishonup / shonullgnt column).
 *   4. Verifies legacy password formats too (md5, sha1, sha256, crypt, plain
 *      dumps) and upgrades the row to a modern hash on the first good login.
 *   5. Answers every unexpected problem with HTTP 200 + code 1 + a readable
 *      message and a short reference id, and logs the technical detail:
 *      error_log('[Login] ref=XXXXXXXX ...'). So the app no longer shows the
 *      generic "Service not available!" popup for a fixable database problem.
 *
 * Diagnostic: upload raxiwin-login-doctor.php next to the site root and open
 * it with the key from READ-ME-FIRST.txt — it prints the exact failing query.
 */

require_once __DIR__ . '/_app_api.php';

date_default_timezone_set((string)app_setting('timezone', 'Asia/Kolkata'));
app_api_require_post();

if (!app_setting_bool('login_enabled', true)) {
    app_api_send(null, 12, 'Login is temporarily disabled', 200, 12);
}

/* =========================================================================
 * Local helpers (kept inside this file on purpose — nothing extra to upload)
 * ====================================================================== */

function rw_login_ref(): string
{
    try {
        return strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        return strtoupper(dechex(time()));
    }
}

function rw_login_log(string $ref, string $message): void
{
    error_log('[Login] ref=' . $ref . ' ' . $message);
}

/**
 * Log the technical reason and answer with HTTP 200 so the app can show a real
 * message (an HTTP 503 is always rendered as "Service not available!").
 */
function rw_login_abort(string $ref, string $message, string $detail = ''): void
{
    rw_login_log($ref, $message . ($detail !== '' ? ' :: ' . $detail : ''));
    app_api_send(['ref' => $ref], 1, $message . ' (ref ' . $ref . ')', 200, 5555);
}

/** Column inventory of a table: [lowercase name => ['type'=>, 'len'=>]]. */
function rw_login_columns($db, string $table, bool $refresh = false): array
{
    static $cache = [];
    if (!$refresh && isset($cache[$table])) {
        return $cache[$table];
    }
    $columns = [];
    try {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $res = $db->query('SHOW FULL COLUMNS FROM `' . $clean . '`');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $type = strtolower((string)($row['Type'] ?? ''));
                $length = null;
                if (preg_match('/\((\d+)/', $type, $m)) {
                    $length = (int)$m[1];
                }
                $columns[strtolower((string)$row['Field'])] = ['type' => $type, 'len' => $length];
            }
            $res->free();
        }
    } catch (Throwable $e) {
        rw_login_log('-', 'schema probe failed on ' . $table . ': ' . $e->getMessage());
    }
    return $cache[$table] = $columns;
}

/** Additive, best-effort repair of the legacy user table. Nothing is dropped. */
function rw_login_repair_schema($db, array $columns): array
{
    $wanted = [
        'email' => 'VARCHAR(190) NULL',
        'ishonup' => 'VARCHAR(64) NULL',
        'codechorkamukala' => 'VARCHAR(190) NULL',
        'shonupwderr' => 'INT NOT NULL DEFAULT 0',
        'shonullgnt' => 'DATETIME NULL',
        'akshinak' => 'VARCHAR(512) NULL',
        'tnegaresunohs' => 'VARCHAR(255) NULL',
        'pwd' => "VARCHAR(255) NOT NULL DEFAULT ''",
    ];
    $report = ['added' => [], 'errors' => []];
    foreach ($wanted as $name => $definition) {
        if (isset($columns[$name])) {
            continue;
        }
        try {
            $db->query('ALTER TABLE `shonu_subjects` ADD COLUMN `' . $name . '` ' . $definition);
            $report['added'][] = $name;
        } catch (Throwable $e) {
            $report['errors'][] = $name . ': ' . $e->getMessage();
        }
    }
    if (isset($columns['akshinak']['len']) && $columns['akshinak']['len'] !== null
        && $columns['akshinak']['len'] < 512 && strpos($columns['akshinak']['type'], 'text') === false) {
        try {
            $db->query('ALTER TABLE `shonu_subjects` MODIFY COLUMN `akshinak` VARCHAR(512) NULL');
            $report['added'][] = 'akshinak widened to VARCHAR(512)';
        } catch (Throwable $e) {
            $report['errors'][] = 'akshinak widen: ' . $e->getMessage();
        }
    }
    return $report;
}

/** Legacy + modern password formats. Never throws. */
function rw_login_password_matches(string $plain, string $stored): bool
{
    if ($plain === '' || $stored === '') {
        return false;
    }
    try {
        if (function_exists('app_api_password_matches') && app_api_password_matches($plain, $stored)) {
            return true;
        }
    } catch (Throwable $e) {
        // fall through to the compatibility checks below
    }
    $value = trim($stored);
    if ($value === '') {
        return false;
    }
    foreach (['md5' => 32, 'sha1' => 40, 'sha256' => 64] as $algo => $length) {
        if (strlen($value) === $length && ctype_xdigit($value)) {
            if (hash_equals(strtolower($value), $algo($plain))) {
                return true;
            }
        }
    }
    if (strlen($value) === 13 || preg_match('/^\$[0-9a-zA-Z]{1,3}\$/', $value)) {
        try {
            if (hash_equals($value, crypt($plain, $value))) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore, handled by the plain fallback below
        }
    }
    return hash_equals($value, $plain);
}

/** Upgrade the stored hash after a successful legacy login. Never throws. */
function rw_login_rehash($db, int $userId, string $plain, array $columns, string $ref): void
{
    try {
        $hash = app_api_password_value($db, $plain);
        $sets = ['`password`=' . "'" . $db->real_escape_string($hash) . "'"];
        if (isset($columns['pwd'])) {
            $sets[] = "`pwd`=''";
        }
        $db->query('UPDATE `shonu_subjects` SET ' . implode(',', $sets) . ' WHERE `id`=' . $userId . ' LIMIT 1');
    } catch (Throwable $e) {
        rw_login_log($ref, 'password rehash skipped: ' . $e->getMessage());
    }
}

function rw_login_is_numeric_type(string $type): bool
{
    return strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false;
}

/** Persist the access token (akshinak) plus session metadata with fallbacks. */
function rw_login_store_session($db, int $userId, array $columns, string $token, string $now, string $ip, string $userAgent, string $ref): bool
{
    if (isset($columns['akshinak']) && $columns['akshinak']['len'] !== null
        && $columns['akshinak']['len'] < strlen($token) && strpos($columns['akshinak']['type'], 'text') === false) {
        try {
            $db->query('ALTER TABLE `shonu_subjects` MODIFY COLUMN `akshinak` VARCHAR(512) NULL');
            $columns['akshinak']['len'] = 512;
            rw_login_log($ref, 'akshinak widened to VARCHAR(512) before storing the token');
        } catch (Throwable $e) {
            rw_login_log($ref, 'akshinak widen failed: ' . $e->getMessage());
        }
    }

    $build = static function (bool $full) use ($columns, $token, $now, $ip, $userAgent): array {
        $sets = [];
        if (isset($columns['shonupwderr'])) {
            $sets['shonupwderr'] = 'INT:0';
        }
        if (isset($columns['ishonup']) && !rw_login_is_numeric_type($columns['ishonup']['type'])) {
            $sets['ishonup'] = $ip;
        }
        if (isset($columns['shonullgnt'])) {
            $sets['shonullgnt'] = rw_login_is_numeric_type($columns['shonullgnt']['type']) ? 'INT:' . time() : $now;
        }
        if (isset($columns['akshinak'])) {
            $sets['akshinak'] = $token;
        }
        if ($full && isset($columns['tnegaresunohs']) && !rw_login_is_numeric_type($columns['tnegaresunohs']['type'])) {
            $sets['tnegaresunohs'] = $userAgent;
        }
        return $sets;
    };

    $plans = [$build(true)];

    if (isset($columns['akshinak']['len']) && $columns['akshinak']['len'] !== null && $columns['akshinak']['len'] < strlen($token)) {
        $short = $build(true);
        $short['akshinak'] = substr($token, 0, max(8, (int)$columns['akshinak']['len']));
        $plans[] = $short;
    }

    $minimal = [];
    if (isset($columns['akshinak'])) {
        $minimal['akshinak'] = $token;
    }
    if (isset($columns['shonupwderr'])) {
        $minimal['shonupwderr'] = 'INT:0';
    }
    if ($minimal) {
        $plans[] = $minimal;
    }

    $errors = [];
    foreach ($plans as $index => $sets) {
        if (!$sets) {
            continue;
        }
        $parts = [];
        foreach ($sets as $column => $value) {
            if (strpos((string)$value, 'INT:') === 0) {
                $parts[] = '`' . $column . '`=' . (int)substr((string)$value, 4);
            } else {
                $parts[] = '`' . $column . "`='" . $db->real_escape_string((string)$value) . "'";
            }
        }
        $sql = 'UPDATE `shonu_subjects` SET ' . implode(',', $parts) . ' WHERE `id`=' . $userId . ' LIMIT 1';
        try {
            if ($db->query($sql) !== false) {
                return true;
            }
            $errors[] = 'plan' . $index . ' returned false';
        } catch (Throwable $e) {
            $errors[] = 'plan' . $index . ': ' . $e->getMessage();
        }
    }
    rw_login_log($ref, 'session store failed :: ' . implode(' | ', $errors));
    return false;
}

/* =========================================================================
 * Request validation (unchanged behaviour)
 * ====================================================================== */

$ref = rw_login_ref();

$body = app_api_input();
$required = ['language', 'logintype', 'phonetype', 'pwd', 'random', 'signature', 'timestamp', 'username'];
foreach ($required as $field) {
    if (!array_key_exists($field, $body)) {
        app_api_send(null, 7, 'Param is Invalid', 200, 6);
    }
}
app_api_require_signature($body);

$loginType = strtolower(trim((string)$body['logintype']));
$loginType = $loginType === 'email' ? 'email' : 'mobile';
$username = trim((string)$body['username']);
$passwordInput = (string)$body['pwd'];

if ($loginType === 'mobile') {
    $username = preg_replace('/[\s()\-]+/', '', $username);
    if (strpos($username, '+91') === 0) {
        $username = substr($username, 3);
    } elseif (strlen($username) > 10 && strpos($username, '91') === 0) {
        $username = substr($username, 2);
    }
    if (!preg_match('/^[0-9]{6,15}$/', $username)) {
        app_api_send(null, 7, 'Enter a valid mobile number', 200, 6);
    }
} elseif (!filter_var($username, FILTER_VALIDATE_EMAIL) || strlen($username) > 190) {
    app_api_send(null, 7, 'Enter a valid email address', 200, 6);
}

if ($passwordInput === '' || strlen($passwordInput) > 128) {
    app_api_send(null, 7, 'Password is required', 200, 6);
}

/* =========================================================================
 * Schema awareness
 * ====================================================================== */

$userColumns = rw_login_columns($conn, 'shonu_subjects');
if (!$userColumns) {
    rw_login_abort($ref, 'Login is not available: the user table (shonu_subjects) is missing in this database');
}

$tracked = ['email', 'ishonup', 'codechorkamukala', 'shonupwderr', 'shonullgnt', 'akshinak', 'tnegaresunohs'];
$missing = [];
foreach ($tracked as $column) {
    if (!isset($userColumns[$column])) {
        $missing[] = $column;
    }
}
$narrowToken = isset($userColumns['akshinak']['len']) && $userColumns['akshinak']['len'] !== null
    && $userColumns['akshinak']['len'] < 512 && strpos($userColumns['akshinak']['type'], 'text') === false;

if ($missing || $narrowToken) {
    $lastRepair = (int)(string)app_setting('login_schema_repair_at', '0');
    if ((time() - $lastRepair) > 600) {
        $repair = rw_login_repair_schema($conn, $userColumns);
        rw_login_log($ref, 'schema repair added=[' . implode(',', $repair['added']) . '] errors=[' . implode(' | ', $repair['errors']) . ']');
        try {
            app_set_setting('login_schema_repair_at', (string)time(), 'Lottery');
        } catch (Throwable $e) {
            // the marker is only a throttle, ignore write problems
        }
        $userColumns = rw_login_columns($conn, 'shonu_subjects', true);
    }
}

$loginColumn = '';
if ($loginType === 'email') {
    if (isset($userColumns['email'])) {
        $loginColumn = 'email';
    } elseif (preg_match('/^[0-9]{6,15}$/', preg_replace('/[\s()\-]+/', '', $username))) {
        // email login not configured on this database, the value is a phone number
        $loginType = 'mobile';
    } else {
        rw_login_abort($ref, 'Login is not available: this account database has no email column');
    }
}
if ($loginColumn === '') {
    foreach (['mobile', 'phoneno', 'phone', 'duravani', 'username', 'whatsapp'] as $candidate) {
        if (isset($userColumns[$candidate])) {
            $loginColumn = $candidate;
            break;
        }
    }
}
if ($loginColumn === '') {
    rw_login_abort($ref, 'Login is not available: no mobile/email column found on the user table');
}

$passwordColumns = [];
foreach (['password', 'pwd', 'pass', 'guptapada'] as $candidate) {
    if (isset($userColumns[$candidate])) {
        $passwordColumns[] = $candidate;
    }
}
if (!$passwordColumns) {
    rw_login_abort($ref, 'Login is not available: no password column found on the user table');
}

/* =========================================================================
 * Look the account up (only existing columns are selected)
 * ====================================================================== */

$select = ['`id`'];
$select[] = '`' . $loginColumn . '` AS `login_name`';
$select[] = isset($userColumns['status']) ? '`status` AS `account_status`' : '1 AS `account_status`';
$select[] = isset($userColumns['ishonup']) ? '`ishonup` AS `login_ip`' : "'' AS `login_ip`";
$select[] = isset($userColumns['codechorkamukala']) ? '`codechorkamukala` AS `nickname`' : "'' AS `nickname`";
$select[] = isset($userColumns['shonupwderr']) ? 'COALESCE(`shonupwderr`,0) AS `password_errors`' : '0 AS `password_errors`';
foreach ($passwordColumns as $index => $column) {
    $select[] = '`' . $column . '` AS `stored_' . $index . '`';
}

$row = null;
try {
    $sql = 'SELECT ' . implode(',', $select)
        . ' FROM `shonu_subjects` WHERE `' . $loginColumn . "`='" . $conn->real_escape_string($username) . "' LIMIT 1";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $result->free();
    }
} catch (Throwable $e) {
    rw_login_abort($ref, 'Login is not available: the account table could not be read', $e->getMessage());
}

if (!$row) {
    app_api_send(null, 1, 'User not exists', 200, 101);
}

$userId = (int)$row['id'];

/* =========================================================================
 * Password check (legacy formats supported)
 * ====================================================================== */

$storedPassword = '';
$storedAlternatives = [];
foreach ($passwordColumns as $index => $column) {
    $value = (string)($row['stored_' . $index] ?? '');
    if ($index === 0) {
        $storedPassword = $value;
    } else {
        $storedAlternatives[$column] = $value;
    }
}

$matched = rw_login_password_matches($passwordInput, $storedPassword);
if (!$matched) {
    foreach ($storedAlternatives as $value) {
        if (rw_login_password_matches($passwordInput, $value)) {
            $matched = true;
            break;
        }
    }
}

if (!$matched) {
    $attempts = min(30, max(0, (int)$row['password_errors']) + 1);
    if (isset($userColumns['shonupwderr'])) {
        try {
            $conn->query('UPDATE `shonu_subjects` SET `shonupwderr`=LEAST(COALESCE(`shonupwderr`,0)+1,30) WHERE `id`=' . $userId . ' LIMIT 1');
        } catch (Throwable $e) {
            rw_login_log($ref, 'password counter skipped: ' . $e->getMessage());
        }
    }
    app_api_send([
        'tokenHeader' => 'Bearer ',
        'token' => null,
        'expiresIn' => 0,
        'refreshToken' => null,
        'passwordErrorNum' => $attempts,
        'passwordErrorMaxNum' => 30,
    ], 1, 'Password does not correct', 200, 117);
}

if ((int)$row['account_status'] !== 1) {
    app_api_send(null, 1, 'User suspended', 200, 116);
}

$needsUpgrade = false;
try {
    $needsUpgrade = function_exists('app_password_needs_upgrade') && app_password_needs_upgrade($conn, $storedPassword);
} catch (Throwable $e) {
    $needsUpgrade = false;
}
if ($needsUpgrade) {
    rw_login_rehash($conn, $userId, $passwordInput, $userColumns, $ref);
}

/* =========================================================================
 * Tokens + session
 * ====================================================================== */

$now = date('Y-m-d H:i:s');
$expiresIn = time() + 86400;
$mobile = (string)$row['login_name'];
$header = ['alg' => 'HS256', 'typ' => 'JWT'];

try {
    $token = generate_jwt($header, [
        'id' => $userId,
        'mobile' => $mobile,
        'status' => 1,
        'expire' => $expiresIn,
        'ishonup' => (string)$row['login_ip'],
        'codechorkamukala' => (string)$row['nickname'],
    ]);
    $refreshToken = generate_jwt($header, [
        'id' => $userId,
        'mobile' => $mobile,
        'status' => 1,
        'expire' => $expiresIn,
        'purpose' => 'refresh',
        // Bind the refresh token to the currently stored access session. A
        // logout or a newer login immediately invalidates this token.
        'session' => hash('sha256', $token),
    ]);
} catch (Throwable $e) {
    rw_login_abort($ref, 'Login could not be completed: the session token could not be created', $e->getMessage());
}

$ipAddress = app_api_client_ip();
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

if (!rw_login_store_session($conn, $userId, $userColumns, $token, $now, $ipAddress, $userAgent, $ref)) {
    rw_login_abort($ref, 'Login could not be completed: the session could not be saved in the database');
}

/* =========================================================================
 * Optional login alert (never blocks the login)
 * ====================================================================== */

try {
    if (app_setting_bool('notifications_enabled', true) && app_table_exists('app_notifications')) {
        $title = 'Login Alert';
        $message = "Your account was logged in on {$now}. Contact support if this was not you.";
        $type = 'security';
        $notice = $conn->prepare('INSERT INTO app_notifications(user_id,title,message,type,enabled,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),NOW())');
        if ($notice) {
            $notice->bind_param('isss', $userId, $title, $message, $type);
            $notice->execute();
            $notice->close();
        }
    }
} catch (Throwable $noticeError) {
    error_log('[Login notification] ' . $noticeError->getMessage());
}

app_api_send([
    'expiresIn' => $expiresIn,
    'tokenHeader' => 'Bearer ',
    'token' => $token,
    'refreshToken' => $refreshToken,
    'passwordErrorNum' => 0,
    'passwordErrorMaxNum' => 30,
]);
