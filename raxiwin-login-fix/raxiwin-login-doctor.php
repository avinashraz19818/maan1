<?php
/**
 * RaxiWin login doctor — read-only diagnostic for the "login par Service not
 * available (HTTP 503)" problem.
 *
 * HOW TO USE
 *   1. Upload this file into the site root (next to index.php).
 *   2. Open:  https://<your-domain>/raxiwin-login-doctor.php?key=b7f3c1a94e2d5068
 *   3. Read the VERDICT block at the bottom, or send it to me.
 *   4. DELETE this file when you are done.
 *
 * Add &fix=1 to the URL and the doctor will also run the safe, additive repairs
 * (add missing legacy columns, widen the akshinak token column) and then
 * re-test the login queries for you.
 *
 * Prints no password, no secret and no user data — only counts, column names
 * and SQL error messages.
 */

$DOCTOR_KEY = 'b7f3c1a94e2d5068';
$providedKey = (string)($_GET['key'] ?? '');
if (!hash_equals($DOCTOR_KEY, $providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}
$DOCTOR_FIX = (string)($_GET['fix'] ?? '') === '1';

header('Content-Type: text/plain; charset=utf-8');

function dr_line(string $text = ''): void { echo $text . "\n"; }
function dr_head(string $title): void { dr_line(); dr_line('=== ' . strtoupper($title) . ' ' . str_repeat('=', max(0, 60 - strlen($title)))); }
function dr_mask(string $value, int $keep = 4): string
{
    $value = (string)$value;
    if (strlen($value) <= $keep) return str_repeat('*', strlen($value));
    return substr($value, 0, $keep) . str_repeat('*', max(3, strlen($value) - $keep));
}
function dr_bool($value): string { return $value ? 'YES' : 'no'; }

dr_line('RAXIWIN LOGIN DOCTOR');
dr_line('time (server): ' . date('Y-m-d H:i:s') . '   php ' . PHP_VERSION . '   fix-mode: ' . dr_bool($DOCTOR_FIX));

/* ------------------------------------------------------------------ setup */
$configFile = __DIR__ . '/config/database.php';
$dbConfig = null;
if (is_readable($configFile)) {
    try { $dbConfig = include $configFile; } catch (Throwable $e) { $dbConfig = null; }
}
dr_head('database config');
dr_line('config/database.php : ' . (is_readable($configFile) ? 'found' : 'MISSING'));
dr_line('mysqli extension    : ' . dr_bool(function_exists('mysqli_init')));
if (!is_array($dbConfig)) {
    dr_line('VERDICT: config/database.php could not be read as an array. Nothing else can be tested.');
    exit;
}
$dbHost = (string)($dbConfig['host'] ?? '');
$dbPort = (int)($dbConfig['port'] ?? 3306);
$dbUser = (string)($dbConfig['user'] ?? '');
$dbPass = (string)($dbConfig['password'] ?? '');
$dbName = (string)($dbConfig['database'] ?? '');
dr_line('host/port           : ' . dr_mask($dbHost) . ':' . $dbPort);
dr_line('database            : ' . dr_mask($dbName));
dr_line('user                : ' . dr_mask($dbUser));

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();
$connected = false;
$connectError = '';
if ($conn === false) {
    $connectError = 'mysqli_init() failed';
} else {
    try {
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        $connected = (bool)@$conn->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        if (!$connected) $connectError = (string)$conn->connect_error;
    } catch (Throwable $e) {
        $connectError = $e->getMessage();
    }
}
dr_line('connection          : ' . ($connected ? 'OK' : 'FAILED - ' . $connectError));
if (!$connected) {
    dr_head('verdict');
    dr_line('VERDICT: database connection failed. Fix config/database.php (or the cPanel DB user) first.');
    exit;
}
dr_line('server version      : ' . (string)$conn->server_info);
$dbNameRow = dr_rows($conn, 'SELECT DATABASE() AS db_name');
dr_line('current database    : ' . (string)($dbNameRow[0]['db_name'] ?? '?'));

/** Run a statement, never fatal. */
function dr_query(mysqli $conn, string $sql)
{
    try {
        $res = $conn->query($sql);
        if ($res === false) return ['ok' => false, 'err' => (string)$conn->error];
        return ['ok' => true, 'res' => $res];
    } catch (Throwable $e) {
        return ['ok' => false, 'err' => $e->getMessage()];
    }
}
function dr_rows(mysqli $conn, string $sql): array
{
    $out = [];
    $r = dr_query($conn, $sql);
    if ($r['ok'] && $r['res'] instanceof mysqli_result) {
        while ($row = $r['res']->fetch_assoc()) $out[] = $row;
        $r['res']->free();
    }
    return $out;
}
/**
 * Type aware SET fragments for the session columns, so a numeric ishonup or a
 * datetime shonullgnt can not produce a false failure.
 */
function dr_session_set(array $columns): array
{
    $sets = [];
    $types = array_change_key_case($columns, CASE_LOWER);
    if (isset($types['shonupwderr'])) $sets[] = '`shonupwderr`=0';
    if (isset($types['ishonup'])) {
        $sets[] = preg_match('/int|decimal|float|double|bit/i', $types['ishonup']) ? '`ishonup`=1' : "`ishonup`='1.1.1.1'";
    }
    if (isset($types['shonullgnt'])) {
        $sets[] = preg_match('/int|decimal|float|double|bit/i', $types['shonullgnt']) ? '`shonullgnt`=' . time() : '`shonullgnt`=NOW()';
    }
    if (isset($types['akshinak'])) $sets[] = "`akshinak`='doctor'";
    if (isset($types['tnegaresunohs'])) {
        $sets[] = preg_match('/int|decimal|float|double|bit/i', $types['tnegaresunohs']) ? '`tnegaresunohs`=1' : "`tnegaresunohs`='doctor'";
    }
    return $sets;
}

function dr_count(mysqli $conn, string $table): string
{
    $r = dr_query($conn, 'SELECT COUNT(*) AS c FROM `' . $table . '`');
    if (!$r['ok']) return 'n/a (' . $r['err'] . ')';
    $row = $r['res']->fetch_assoc();
    $r['res']->free();
    return (string)($row['c'] ?? '?');
}

/* ---------------------------------------------------------------- tables */
dr_head('tables');
$tableList = dr_rows($conn, 'SHOW TABLES');
dr_line('tables in this database : ' . count($tableList));
$important = ['shonu_subjects', 'shonu_kaichila', 'thevani', 'nirvahaka_shonu', 'app_settings',
    'app_notifications', 'saas_lottery_bets', 'saas_lottery_results', 'saas_lottery_settings', 'vip'];
$missingTables = [];
foreach ($important as $table) {
    $exists = false;
    $r = dr_query($conn, "SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if ($r['ok'] && $r['res'] instanceof mysqli_result && $r['res']->num_rows > 0) $exists = true;
    if (!$exists) $missingTables[] = $table;
    dr_line(sprintf('  %-22s %-8s rows=%s', $table, $exists ? 'FOUND' : 'MISSING', $exists ? dr_count($conn, $table) : '-'));
}

/* ------------------------------------------------- shonu_subjects schema */
dr_head('shonu_subjects columns');
$userColumns = [];
$columnRows = dr_rows($conn, 'SHOW FULL COLUMNS FROM `shonu_subjects`');
foreach ($columnRows as $columnRow) {
    $userColumns[strtolower((string)$columnRow['Field'])] = [
        'type' => (string)$columnRow['Type'],
        'null' => (string)$columnRow['Null'],
        'default' => $columnRow['Default'],
    ];
}
if (!$userColumns) {
    dr_line('shonu_subjects is MISSING or could not be read. This is why login answers HTTP 503.');
} else {
    dr_line('columns found: ' . count($userColumns));
    $needed = ['id', 'mobile', 'email', 'password', 'pwd', 'status', 'ishonup', 'codechorkamukala',
        'shonupwderr', 'shonullgnt', 'akshinak', 'tnegaresunohs', 'createdate', 'code', 'owncode', 'privacy'];
    foreach ($needed as $name) {
        if (isset($userColumns[$name])) {
            dr_line(sprintf('  %-18s %-22s null=%-3s %s', $name, $userColumns[$name]['type'], $userColumns[$name]['null'],
                strpos($userColumns[$name]['type'], 'char') !== false && preg_match('/\((\d+)\)/', $userColumns[$name]['type'], $m) && (int)$m[1] < 512 && $name === 'akshinak' ? '<-- TOO SHORT for a JWT' : ''));
        } else {
            dr_line(sprintf('  %-18s MISSING', $name));
        }
    }
}

/* ----------------------------------------------------------- login tests */
dr_head('login query tests');
if ($userColumns) {
    $refTestMobile = '0000000000';

    $oldSelect = "SELECT id,mobile,password,status,ishonup,codechorkamukala,COALESCE(shonupwderr,0) FROM shonu_subjects WHERE mobile=? LIMIT 1";
    $stmt = dr_query($conn, 'SELECT 1');
    try {
        $prepared = $conn->prepare($oldSelect);
        if ($prepared === false) {
            dr_line('OLD login SELECT  : FAILED - ' . $conn->error);
        } else {
            $prepared->bind_param('s', $refTestMobile);
            $prepared->execute();
            dr_line('OLD login SELECT  : OK (no rows for a dummy number = normal)');
            $prepared->close();
        }
    } catch (Throwable $e) {
        dr_line('OLD login SELECT  : FAILED - ' . $e->getMessage());
    }

    $available = array_intersect(['password', 'pwd'], array_keys($userColumns));
    $newSelect = 'SELECT `id`,`mobile`' . ($available ? ',`' . implode('`,`', $available) . '`' : '')
        . (isset($userColumns['status']) ? ',`status`' : '')
        . (isset($userColumns['ishonup']) ? ',`ishonup`' : '')
        . (isset($userColumns['codechorkamukala']) ? ',`codechorkamukala`' : '')
        . (isset($userColumns['shonupwderr']) ? ',`shonupwderr`' : '')
        . " FROM `shonu_subjects` WHERE `mobile`='" . $conn->real_escape_string($refTestMobile) . "' LIMIT 1";
    $r = dr_query($conn, $newSelect);
    dr_line('NEW login SELECT  : ' . ($r['ok'] ? 'OK' : 'FAILED - ' . $r['err']));

    $sets = dr_session_set($userColumns);
    if ($sets) {
        $r = dr_query($conn, 'UPDATE `shonu_subjects` SET ' . implode(',', $sets) . ' WHERE `id`=0');
        dr_line('session UPDATE    : ' . ($r['ok'] ? 'OK (no rows touched)' : 'FAILED - ' . $r['err']));
    } else {
        dr_line('session UPDATE    : skipped, no session columns at all');
    }

    if (isset($userColumns['akshinak'])) {
        $long = str_repeat('x', 512);
        $r = dr_query($conn, "UPDATE `shonu_subjects` SET `akshinak`='" . $conn->real_escape_string($long) . "' WHERE `id`=0");
        dr_line('akshinak 512 test : ' . ($r['ok'] ? 'OK' : 'FAILED (token too long for this column) - ' . $r['err']));
    }

    /* token length vs column width */
    $functions = __DIR__ . '/developer-maruf/functions2.php';
    if (is_readable($functions)) {
        try {
            require_once $functions;
            if (function_exists('generate_jwt')) {
                $doctorToken = generate_jwt(['alg' => 'HS256', 'typ' => 'JWT'], ['id' => 1, 'mobile' => '0000000000', 'status' => 1, 'expire' => time() + 3600]);
                dr_line('JWT length        : ' . strlen($doctorToken) . ' characters');
                if (isset($userColumns['akshinak']) && preg_match('/\((\d+)\)/', $userColumns['akshinak']['type'], $m)) {
                    $declared = (int)$m[1];
                    dr_line('akshinak column   : ' . $userColumns['akshinak']['type'] . ($declared < strlen($doctorToken) ? '  <-- TOO SHORT, this causes "Data too long" -> HTTP 503' : '  (fits)'));
                }
            }
        } catch (Throwable $e) {
            dr_line('JWT test          : skipped - ' . $e->getMessage());
        }
    }

    /* password formats */
    $format = ['empty' => 0, 'md5' => 0, 'bcrypt' => 0, 'sha1_sha256' => 0, 'other' => 0];
    if (isset($userColumns['password'])) {
        $rows = dr_rows($conn, 'SELECT `password` FROM `shonu_subjects` LIMIT 1000');
        foreach ($rows as $row) {
            $value = (string)($row['password'] ?? '');
            if ($value === '') $format['empty']++;
            elseif (strpos($value, '$2') === 0) $format['bcrypt']++;
            elseif (preg_match('/^[a-f0-9]{32}$/i', $value)) $format['md5']++;
            elseif (preg_match('/^[a-f0-9]{40}$/i', $value) || preg_match('/^[a-f0-9]{64}$/i', $value)) $format['sha1_sha256']++;
            else $format['other']++;
        }
        dr_line('password formats  : total=' . count($rows) . ' ' . json_encode($format));
    }

    /* register dry-run, always rolled back */
    $registerColumns = ['mobile', 'email', 'password', 'code', 'code1', 'code2', 'code3', 'code4', 'code5',
        'owncode', 'privacy', 'status', 'createdate', 'ip', 'ishonup', 'pwd', 'shonullgnt', 'tnegaresunohs', 'codechorkamukala'];
    $insertable = array_values(array_intersect($registerColumns, array_keys($userColumns)));
    if ($insertable) {
        try {
            $conn->begin_transaction();
            $values = [];
            foreach ($insertable as $column) {
                if ($column === 'mobile') $values[] = "'9" . random_int(100000000, 999999999) . "'";
                elseif ($column === 'status') $values[] = '1';
                elseif (in_array($column, ['createdate', 'shonullgnt'], true)) $values[] = 'NOW()';
                elseif ($column === 'ip') $values[] = "'127.0.0.1'";
                else $values[] = "'doctor'";
            }
            $insertSql = 'INSERT INTO `shonu_subjects` (`' . implode('`,`', $insertable) . '`) VALUES (' . implode(',', $values) . ')';
            $r = dr_query($conn, $insertSql);
            dr_line('register dry-run  : ' . ($r['ok'] ? 'OK (rolled back, no user created)' : 'FAILED - ' . $r['err']));
            $conn->rollback();
        } catch (Throwable $e) {
            dr_line('register dry-run  : FAILED - ' . $e->getMessage());
            try { $conn->rollback(); } catch (Throwable $ignored) {}
        }
    }
}

/* -------------------------------------------------------------- error log */
dr_head('php error log (last matching lines)');
$logCandidates = array_filter([
    ini_get('error_log') ?: '',
    __DIR__ . '/error_log',
    dirname(__DIR__) . '/error_log',
    __DIR__ . '/php_errorlog',
    __DIR__ . '/logs/error_log',
    __DIR__ . '/admin/error_log',
]);
$foundLog = false;
foreach (array_unique($logCandidates) as $logPath) {
    if (!is_string($logPath) || $logPath === '' || !is_readable($logPath)) continue;
    $foundLog = true;
    dr_line('-- ' . $logPath);
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) { dr_line('   (not readable)'); continue; }
    $matched = [];
    foreach ($lines as $line) {
        if (stripos($line, '[Login]') !== false || stripos($line, '[database]') !== false || stripos($line, '[app_schema') !== false) {
            $matched[] = $line;
        }
    }
    foreach (array_slice($matched, -15) as $line) dr_line('   ' . $line);
    if (!$matched) dr_line('   (no [Login] / [database] / [app_schema] lines yet)');
}
if (!$foundLog) {
    dr_line('no readable error log found in the usual cPanel locations.');
    dr_line('ask the host for the PHP error log, or check cPanel > Metrics > Errors.');
}

/* ------------------------------------------------------------------- fix */
if ($DOCTOR_FIX) {
    dr_head('repair (fix=1)');
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
    foreach ($wanted as $name => $definition) {
        if (isset($userColumns[$name])) { dr_line(sprintf('  %-18s already there (%s)', $name, $userColumns[$name]['type'])); continue; }
        $r = dr_query($conn, 'ALTER TABLE `shonu_subjects` ADD COLUMN `' . $name . '` ' . $definition);
        dr_line(sprintf('  %-18s %s', $name, $r['ok'] ? 'ADDED' : 'FAILED - ' . $r['err']));
    }
    if (isset($userColumns['akshinak']) && preg_match('/\((\d+)\)/', $userColumns['akshinak']['type'], $m) && (int)$m[1] < 512) {
        $r = dr_query($conn, 'ALTER TABLE `shonu_subjects` MODIFY COLUMN `akshinak` VARCHAR(512) NULL');
        dr_line('  akshinak           : ' . ($r['ok'] ? 'widened to VARCHAR(512)' : 'FAILED - ' . $r['err']));
    }
    dr_line();
    dr_line('re-test after repair:');
    $columnsAfter = [];
    foreach (dr_rows($conn, 'SHOW FULL COLUMNS FROM `shonu_subjects`') as $columnRow) {
        $columnsAfter[strtolower((string)$columnRow['Field'])] = (string)$columnRow['Type'];
    }
    $prepared = $conn->prepare("SELECT id,mobile,password,status,ishonup,codechorkamukala,COALESCE(shonupwderr,0) FROM shonu_subjects WHERE mobile=? LIMIT 1");
    dr_line('  OLD login SELECT  : ' . ($prepared === false ? 'FAILED - ' . $conn->error : 'OK'));
    if ($prepared !== false) $prepared->close();
    $sets = dr_session_set($columnsAfter);
    if ($sets) {
        $r = dr_query($conn, 'UPDATE `shonu_subjects` SET ' . implode(',', $sets) . ' WHERE `id`=0');
        dr_line('  session UPDATE    : ' . ($r['ok'] ? 'OK' : 'FAILED - ' . $r['err']));
    }
    dr_line('  => ab login dobara try karo. Phir bhi problem ho to doctor dobara kholo aur VERDICT mujhe bhejo.');
}

/* --------------------------------------------------------------- verdict */
dr_head('verdict');
if (!$userColumns) {
    dr_line('VERDICT 1: table shonu_subjects is MISSING (or not readable) in this database.');
    dr_line('   Login and Register can only work after the legacy BDG user table is imported.');
    dr_line('   (This is a database import problem, not a code problem.)');
} elseif (!isset($userColumns['mobile'])) {
    dr_line('VERDICT 2: shonu_subjects has no mobile column - login by phone can not work.');
} elseif (!isset($userColumns['password']) && !isset($userColumns['pwd'])) {
    dr_line('VERDICT 3: shonu_subjects has no password column.');
} elseif (isset($userColumns['akshinak']) && preg_match('/\((\d+)\)/', $userColumns['akshinak']['type'], $m) && (int)$m[1] < 512) {
    dr_line('VERDICT 4: akshinak is ' . $userColumns['akshinak']['type'] . ' - a modern JWT does not fit, MySQL strict mode');
    dr_line('   raises "Data too long", the old Login.php turned that into HTTP 503.');
    dr_line('   Run this page with &fix=1 (or just use the new Login.php, it widens the column itself).');
} else {
    dr_line('VERDICT 5: the user table looks usable for login. If login still fails,');
    dr_line('   look at the [Login] ref=... line in the error log above and send it to me.');
}
dr_line();
dr_line('end of report  |  delete raxiwin-login-doctor.php after use');
