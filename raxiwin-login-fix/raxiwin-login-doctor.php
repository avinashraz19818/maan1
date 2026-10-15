<?php
/**
 * RaxiWin login doctor v2 — database / login diagnostic.
 *
 * WHY v2: v1 showed that the database this site is connected to
 * (club532583_rax1win) is a *fresh empty* database — it only contains the 31
 * tables that the app creates for itself, no legacy data at all (no
 * shonu_subjects, no shonu_kaichila, no saas_lottery_*). So v2 also scans every
 * other database of this cPanel account and tells you which one really holds
 * the users/bets, plus it can switch config/database.php over for you.
 *
 * HOW TO USE
 *   1. Upload into the site root (next to index.php).
 *   2. Open: https://<your-domain>/raxiwin-login-doctor.php?key=b7f3c1a94e2d5068
 *   3. Extra switches:
 *        &db=<database>    inspect that database instead of the configured one
 *        &setdb=<database> point config/database.php at that database
 *                          (a backup config/database.php.bak-<time> is written)
 *        &fix=1            safe additive repair of the inspected user table
 *   4. DELETE this file when you are done.
 *
 * Prints no password, no secret and no user data — only counts, names and SQL
 * error messages.
 */

$DOCTOR_KEY = 'b7f3c1a94e2d5068';
if (!hash_equals($DOCTOR_KEY, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}
$DOCTOR_FIX = (string)($_GET['fix'] ?? '') === '1';
$DOCTOR_DB = trim((string)($_GET['db'] ?? ''));
$DOCTOR_SETDB = trim((string)($_GET['setdb'] ?? ''));

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

dr_line('RAXIWIN LOGIN DOCTOR v2');
dr_line('time (server): ' . date('Y-m-d H:i:s') . '   php ' . PHP_VERSION . '   fix-mode: ' . dr_bool($DOCTOR_FIX)
    . ($DOCTOR_DB !== '' ? '   inspect-db: ' . $DOCTOR_DB : '')
    . ($DOCTOR_SETDB !== '' ? '   set-db: ' . $DOCTOR_SETDB : ''));

/* ------------------------------------------------------------------ config */
$configFile = __DIR__ . '/config/database.php';
$dbConfig = null;
if (is_readable($configFile)) {
    try { $dbConfig = include $configFile; } catch (Throwable $e) { $dbConfig = null; }
}
dr_head('database config');
dr_line('config/database.php : ' . (is_readable($configFile) ? 'found' : 'MISSING'));
dr_line('mysqli extension    : ' . dr_bool(function_exists('mysqli_init')));
if (!is_array($dbConfig)) {
    dr_line('VERDICT: config/database.php could not be read as an array. Fix that file first.');
    exit;
}
$dbHost = (string)($dbConfig['host'] ?? '');
$dbPort = (int)($dbConfig['port'] ?? 3306);
$dbUser = (string)($dbConfig['user'] ?? '');
$dbPass = (string)($dbConfig['password'] ?? '');
$dbName = (string)($dbConfig['database'] ?? '');
dr_line('host/port           : ' . dr_mask($dbHost) . ':' . $dbPort);
dr_line('database            : ' . $dbName);
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

/* ---------------------------------------------------------------- helpers */
function dr_query($conn, string $sql)
{
    try {
        $res = $conn->query($sql);
        if ($res === false) return array('ok' => false, 'err' => (string)$conn->error);
        return array('ok' => true, 'res' => $res);
    } catch (Throwable $e) {
        return array('ok' => false, 'err' => $e->getMessage());
    }
}

function dr_rows($conn, string $sql): array
{
    $out = array();
    $r = dr_query($conn, $sql);
    if ($r['ok'] && $r['res'] instanceof mysqli_result) {
        while ($row = $r['res']->fetch_assoc()) $out[] = $row;
        $r['res']->free();
    }
    return $out;
}

function dr_single($conn, string $sql, $default = 'n/a')
{
    $r = dr_query($conn, $sql);
    if (!$r['ok']) return $default;
    if (!($r['res'] instanceof mysqli_result)) return $default;
    $row = $r['res']->fetch_row();
    $r['res']->free();
    return $row ? $row[0] : $default;
}

/** Does this database have the legacy tables, and how many users does it hold? */
function dr_probe_database($conn, string $db): array
{
    $quoted = '`' . str_replace('`', '', $db) . '`';
    $out = array('users' => null, 'tables' => array(), 'total_tables' => null);
    $out['total_tables'] = dr_single($conn, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . "'" . $conn->real_escape_string($db) . "'", null);
    $out['users'] = dr_single($conn, 'SELECT COUNT(*) FROM ' . $quoted . '.`shonu_subjects`', null);
    foreach (array('shonu_kaichila', 'thevani', 'saas_lottery_bets', 'saas_lottery_results', 'saas_lottery_settings', 'nirvahaka_shonu') as $table) {
        $out['tables'][$table] = dr_single($conn, 'SELECT COUNT(*) FROM ' . $quoted . '.`' . $table . '`', null);
    }
    return $out;
}

/**
 * Type aware SET fragments for the session columns.
 */
function dr_session_set(array $columns): array
{
    $sets = array();
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

/* ------------------------------------------------- all databases of account */
dr_head('all databases of this cpanel account');
$databases = array();
$dbResult = dr_query($conn, 'SHOW DATABASES');
if ($dbResult['ok'] && $dbResult['res'] instanceof mysqli_result) {
    while ($row = $dbResult['res']->fetch_row()) $databases[] = (string)$row[0];
    $dbResult['res']->free();
}
$systemDbs = array('information_schema', 'mysql', 'performance_schema', 'sys');
$legacyCandidates = array();
dr_line(sprintf('  %-34s %-8s %-8s %-8s %s', 'database', 'users', 'wallets', 'bets', 'notes'));
foreach ($databases as $candidateDb) {
    if (in_array(strtolower($candidateDb), $systemDbs, true)) continue;
    $probe = dr_probe_database($conn, $candidateDb);
    $note = '';
    if ($probe['users'] !== null) {
        $note = 'HAS shonu_subjects';
        if ((int)$probe['users'] > 0) {
            $note = 'LEGACY DATA (users=' . $probe['users'] . ')';
            $legacyCandidates[] = array('db' => $candidateDb, 'users' => (int)$probe['users']);
        }
    } elseif ((int)$probe['total_tables'] > 0) {
        $note = 'no legacy user table';
    }
    dr_line(sprintf('  %-34s %-8s %-8s %-8s %s',
        $candidateDb,
        $probe['users'] === null ? '-' : $probe['users'],
        $probe['tables']['shonu_kaichila'] === null ? '-' : $probe['tables']['shonu_kaichila'],
        $probe['tables']['saas_lottery_bets'] === null ? '-' : $probe['tables']['saas_lottery_bets'],
        $note));
}

/* ------------------------------------------------------- inspect one db */
$inspectDb = $DOCTOR_DB !== '' ? $DOCTOR_DB : $dbName;
$inspectingOther = $inspectDb !== $dbName;
if ($inspectDb !== '') {
    $switched = dr_query($conn, 'USE `' . str_replace('`', '', $inspectDb) . '`');
    if (!$switched['ok']) {
        dr_line();
        dr_line('could not open database ' . $inspectDb . ' : ' . $switched['err']);
    }
} elseif (!$inspectDb) {
    dr_line();
    dr_line('config has no database name.');
}

dr_head('inspecting database: ' . $inspectDb . ($inspectingOther ? '  (from ?db=)' : '  (configured)'));
$currentDbName = (string)dr_single($conn, 'SELECT DATABASE()', '?');
dr_line('current database    : ' . $currentDbName);

$allTables = array();
$tableResult = dr_query($conn, 'SHOW TABLES');
if ($tableResult['ok'] && $tableResult['res'] instanceof mysqli_result) {
    while ($row = $tableResult['res']->fetch_row()) $allTables[] = (string)$row[0];
    $tableResult['res']->free();
}
dr_line('tables here         : ' . count($allTables));
dr_line('  ' . implode(', ', $allTables));

$systemTables = array('app_settings', 'app_payment_methods', 'app_deposit_requests', 'app_chat_sessions',
    'app_chat_messages', 'app_audit_logs', 'app_admin_permissions', 'app_user_presence', 'app_user_preferences',
    'app_system_events', 'app_support_tickets', 'app_game_control', 'app_bet_requests', 'app_turntable_draws',
    'app_provider_transactions', 'app_site_messages', 'app_notifications', 'app_notification_reads',
    'app_spin_prizes', 'app_channel_bonus_rules', 'vip', 'tb_agent', 'shonu_turntable', 'shonu_turntable_spins',
    'app_spin_daily_grants', 'app_spin_transfers', 'deyya', 'deyyamrici', 'upi_address', 'upi_withdrawal', 'demo');
$foreign = array_values(array_diff($allTables, $systemTables));
dr_line('non-app tables      : ' . count($foreign) . ($foreign ? '  -> ' . implode(', ', $foreign) : '  (only the tables the app creates for itself)'));
dr_line('legacy shonu_subjects present: ' . dr_bool(in_array('shonu_subjects', $allTables, true)));

/* ------------------------------------------------- shonu_subjects schema */
dr_head('shonu_subjects columns');
$userColumns = array();
foreach (dr_rows($conn, 'SHOW FULL COLUMNS FROM `shonu_subjects`') as $columnRow) {
    $userColumns[strtolower((string)$columnRow['Field'])] = (string)$columnRow['Type'];
}
if (!$userColumns) {
    dr_line('shonu_subjects is MISSING (or not readable) in ' . $inspectDb . '.');
} else {
    dr_line('columns found: ' . count($userColumns) . '   rows: ' . dr_single($conn, 'SELECT COUNT(*) FROM `shonu_subjects`'));
    $needed = array('id', 'mobile', 'email', 'password', 'pwd', 'status', 'ishonup', 'codechorkamukala',
        'shonupwderr', 'shonullgnt', 'akshinak', 'tnegaresunohs', 'createdate', 'code', 'owncode', 'privacy');
    foreach ($needed as $name) {
        if (isset($userColumns[$name])) {
            $flag = '';
            if ($name === 'akshinak' && preg_match('/\((\d+)\)/', $userColumns[$name], $m) && (int)$m[1] < 512) {
                $flag = '  <-- TOO SHORT for a JWT';
            }
            dr_line(sprintf('  %-18s %-22s%s', $name, $userColumns[$name], $flag));
        } else {
            dr_line(sprintf('  %-18s MISSING', $name));
        }
    }
}

/* ----------------------------------------------------------- login tests */
dr_head('login query tests');
if ($userColumns) {
    $dummyMobile = '0000000000';
    try {
        $prepared = $conn->prepare("SELECT id,mobile,password,status,ishonup,codechorkamukala,COALESCE(shonupwderr,0) FROM shonu_subjects WHERE mobile=? LIMIT 1");
        if ($prepared === false) {
            dr_line('OLD login SELECT  : FAILED - ' . $conn->error);
        } else {
            $prepared->bind_param('s', $dummyMobile);
            $prepared->execute();
            $prepared->close();
            dr_line('OLD login SELECT  : OK');
        }
    } catch (Throwable $e) {
        dr_line('OLD login SELECT  : FAILED - ' . $e->getMessage());
    }

    $newSelect = 'SELECT `id`,`mobile`'
        . (isset($userColumns['password']) ? ',`password`' : '')
        . (isset($userColumns['pwd']) ? ',`pwd`' : '')
        . (isset($userColumns['status']) ? ',`status`' : '')
        . (isset($userColumns['ishonup']) ? ',`ishonup`' : '')
        . (isset($userColumns['codechorkamukala']) ? ',`codechorkamukala`' : '')
        . (isset($userColumns['shonupwderr']) ? ',`shonupwderr`' : '')
        . " FROM `shonu_subjects` WHERE `mobile`='" . $conn->real_escape_string($dummyMobile) . "' LIMIT 1";
    $r = dr_query($conn, $newSelect);
    dr_line('NEW login SELECT  : ' . ($r['ok'] ? 'OK' : 'FAILED - ' . $r['err']));

    $sets = dr_session_set($userColumns);
    if ($sets) {
        $r = dr_query($conn, 'UPDATE `shonu_subjects` SET ' . implode(',', $sets) . ' WHERE `id`=0');
        dr_line('session UPDATE    : ' . ($r['ok'] ? 'OK (no rows touched)' : 'FAILED - ' . $r['err']));
    }

    if (isset($userColumns['akshinak'])) {
        $long = str_repeat('x', 512);
        $r = dr_query($conn, "UPDATE `shonu_subjects` SET `akshinak`='" . $conn->real_escape_string($long) . "' WHERE `id`=0");
        dr_line('akshinak 512 test : ' . ($r['ok'] ? 'OK' : 'FAILED (token too long for this column) - ' . $r['err']));
    }

    $functions = __DIR__ . '/developer-maruf/functions2.php';
    if (is_readable($functions)) {
        try {
            require_once $functions;
            if (function_exists('generate_jwt')) {
                $doctorToken = generate_jwt(array('alg' => 'HS256', 'typ' => 'JWT'), array('id' => 1, 'mobile' => $dummyMobile, 'status' => 1, 'expire' => time() + 3600));
                dr_line('JWT length        : ' . strlen($doctorToken) . ' characters');
                if (isset($userColumns['akshinak']) && preg_match('/\((\d+)\)/', $userColumns['akshinak'], $m)) {
                    $declared = (int)$m[1];
                    dr_line('akshinak column   : ' . $userColumns['akshinak'] . ($declared < strlen($doctorToken) ? '  <-- TOO SHORT -> "Data too long" -> HTTP 503' : '  (fits)'));
                }
            }
        } catch (Throwable $e) {
            dr_line('JWT test          : skipped - ' . $e->getMessage());
        }
    }

    $format = array('empty' => 0, 'md5' => 0, 'bcrypt' => 0, 'sha1_sha256' => 0, 'other' => 0);
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

    $registerColumns = array('mobile', 'email', 'password', 'code', 'code1', 'code2', 'code3', 'code4', 'code5',
        'owncode', 'privacy', 'status', 'createdate', 'ip', 'ishonup', 'pwd', 'shonullgnt', 'tnegaresunohs', 'codechorkamukala');
    $insertable = array_values(array_intersect($registerColumns, array_keys($userColumns)));
    if ($insertable) {
        try {
            $conn->begin_transaction();
            $values = array();
            foreach ($insertable as $column) {
                if ($column === 'mobile') $values[] = "'9" . random_int(100000000, 999999999) . "'";
                elseif ($column === 'status') $values[] = '1';
                elseif (in_array($column, array('createdate', 'shonullgnt'), true)) $values[] = 'NOW()';
                elseif ($column === 'ip') $values[] = "'127.0.0.1'";
                else $values[] = "'doctor'";
            }
            $r = dr_query($conn, 'INSERT INTO `shonu_subjects` (`' . implode('`,`', $insertable) . '`) VALUES (' . implode(',', $values) . ')');
            dr_line('register dry-run  : ' . ($r['ok'] ? 'OK (rolled back, no user created)' : 'FAILED - ' . $r['err']));
            $conn->rollback();
        } catch (Throwable $e) {
            dr_line('register dry-run  : FAILED - ' . $e->getMessage());
            try { $conn->rollback(); } catch (Throwable $ignored) {}
        }
    }
} else {
    dr_line('skipped - there is no shonu_subjects table in ' . $inspectDb . ' to test against.');
}

/* -------------------------------------------------------------- error log */
dr_head('php error log (last matching lines)');
$logCandidates = array_filter(array(
    ini_get('error_log') ? ini_get('error_log') : '',
    __DIR__ . '/error_log',
    dirname(__DIR__) . '/error_log',
    __DIR__ . '/php_errorlog',
    __DIR__ . '/logs/error_log',
    __DIR__ . '/admin/error_log',
));
$foundLog = false;
foreach (array_unique($logCandidates) as $logPath) {
    if (!is_string($logPath) || $logPath === '' || !is_readable($logPath)) continue;
    $foundLog = true;
    dr_line('-- ' . $logPath);
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) { dr_line('   (not readable)'); continue; }
    $matched = array();
    foreach ($lines as $line) {
        if (stripos($line, '[Login]') !== false || stripos($line, '[database]') !== false || stripos($line, '[app_schema') !== false) {
            $matched[] = $line;
        }
    }
    foreach (array_slice($matched, -15) as $line) dr_line('   ' . $line);
    if (!$matched) dr_line('   (no [Login] / [database] / [app_schema] lines yet)');
}
if (!$foundLog) dr_line('no readable error log found in the usual cPanel locations.');

/* ------------------------------------------------------------------- fix */
if ($DOCTOR_FIX && $userColumns) {
    dr_head('repair (fix=1) on ' . $inspectDb);
    $wanted = array(
        'email' => 'VARCHAR(190) NULL',
        'ishonup' => 'VARCHAR(64) NULL',
        'codechorkamukala' => 'VARCHAR(190) NULL',
        'shonupwderr' => 'INT NOT NULL DEFAULT 0',
        'shonullgnt' => 'DATETIME NULL',
        'akshinak' => 'VARCHAR(512) NULL',
        'tnegaresunohs' => 'VARCHAR(255) NULL',
        'pwd' => "VARCHAR(255) NOT NULL DEFAULT ''",
    );
    foreach ($wanted as $name => $definition) {
        if (isset($userColumns[$name])) { dr_line(sprintf('  %-18s already there (%s)', $name, $userColumns[$name])); continue; }
        $r = dr_query($conn, 'ALTER TABLE `shonu_subjects` ADD COLUMN `' . $name . '` ' . $definition);
        dr_line(sprintf('  %-18s %s', $name, $r['ok'] ? 'ADDED' : 'FAILED - ' . $r['err']));
    }
    if (isset($userColumns['akshinak']) && preg_match('/\((\d+)\)/', $userColumns['akshinak'], $m) && (int)$m[1] < 512) {
        $r = dr_query($conn, 'ALTER TABLE `shonu_subjects` MODIFY COLUMN `akshinak` VARCHAR(512) NULL');
        dr_line('  akshinak           : ' . ($r['ok'] ? 'widened to VARCHAR(512)' : 'FAILED - ' . $r['err']));
    }
} elseif ($DOCTOR_FIX) {
    dr_head('repair (fix=1)');
    dr_line('nothing to repair here - this database has no shonu_subjects table at all.');
}

/* --------------------------------------------------------- switch database */
if ($DOCTOR_SETDB !== '') {
    dr_head('switch config/database.php to ' . $DOCTOR_SETDB);
    $target = dr_probe_database($conn, $DOCTOR_SETDB);
    if ($target['users'] === null) {
        dr_line('REFUSED: ' . $DOCTOR_SETDB . ' has no shonu_subjects table (that would break the site too).');
    } elseif ((int)$target['users'] < 1) {
        dr_line('REFUSED: ' . $DOCTOR_SETDB . ' has a shonu_subjects table but 0 users in it.');
        dr_line('         (use it only if you really want that empty database.)');
    } else {
        dr_line('target looks good: shonu_subjects users=' . $target['users']);
        $stamp = date('Ymd-His');
        $backup = $configFile . '.bak-' . $stamp;
        $written = false;
        if (is_readable($configFile) && is_writable(dirname($configFile))) {
            try {
                if (@copy($configFile, $backup)) {
                    $newConfig = "<?php\n"
                        . "/**\n * Rewritten by raxiwin-login-doctor on " . date('Y-m-d H:i:s') . ".\n"
                        . " * Backup of the previous file: " . basename($backup) . "\n */\n"
                        . "return array(\n"
                        . "    'host' => " . var_export($dbHost, true) . ",\n"
                        . "    'port' => " . (int)$dbPort . ",\n"
                        . "    'user' => " . var_export($dbUser, true) . ",\n"
                        . "    'password' => " . var_export($dbPass, true) . ",\n"
                        . "    'database' => " . var_export($DOCTOR_SETDB, true) . ",\n"
                        . "    'charset' => 'utf8mb4',\n"
                        . ");\n";
                    $written = (bool)@file_put_contents($configFile, $newConfig);
                    dr_line($written ? 'config/database.php updated. Backup: ' . basename($backup)
                        : 'could not write config/database.php (permissions). Backup was created.');
                } else {
                    dr_line('could not create a backup - refusing to change the config.');
                }
            } catch (Throwable $e) {
                dr_line('config rewrite failed: ' . $e->getMessage());
            }
        } else {
            dr_line('config/database.php or its folder is not writable by PHP.');
            dr_line('Do it by hand in File Manager: set the "database" name to ' . $DOCTOR_SETDB);
        }
        if ($written) {
            dr_line();
            dr_line('NEXT: login dobara try karo. Problem rahe to doctor dobara kholo (bina setdb ke)');
            dr_line('      aur naya report bhej do. Backup se wapas jaana ho to config/database.php.bak-' . $stamp . ' ko config/database.php kar do.');
        }
    }
}

/* --------------------------------------------------------------- verdict */
dr_head('verdict');
$hasUserTable = in_array('shonu_subjects', $allTables, true);
$legacyLine = '';
if ($legacyCandidates) {
    $parts = array();
    foreach ($legacyCandidates as $candidate) $parts[] = $candidate['db'] . ' (users=' . $candidate['users'] . ')';
    $legacyLine = implode(', ', $parts);
}

if (!$hasUserTable) {
    dr_line('VERDICT A: database "' . $inspectDb . '" has NO shonu_subjects table.');
    dr_line('  tables here = ' . count($allTables) . ', non-app tables = ' . count($foreign) . '.');
    if (count($foreign) === 0) {
        dr_line('  -> Ye bilkul khali/naya database hai; isme sirf wahi tabel hain jo app khud banata hai.');
        dr_line('     Isi liye login aur register 503 dete hain: koi user, koi wallet, koi lottery table hi nahi hai.');
    }
    if ($legacyLine !== '') {
        dr_line('  LEGACY DATA IS IN: ' . $legacyLine);
        dr_line('  NEXT STEP: config/database.php me database name wahi kar do —');
        dr_line('     ya doctor ko aise chalao (khud backup lekar badal dega):');
        foreach ($legacyCandidates as $candidate) {
            dr_line('       raxiwin-login-doctor.php?key=' . $DOCTOR_KEY . '&setdb=' . $candidate['db']);
        }
    } else {
        dr_line('  Is cPanel account ki kisi bhi database me legacy tables nahi mile.');
        dr_line('  -> Aapko legacy BDG database import karna padega (jis site se ye script aaya hai');
        dr_line('     us DB ka .sql dump). Ye ek data/import problem hai, code se fix nahi hoti.');
        dr_line('  Agar aap chahte ho ki naya (khali) setup hi chale, to bhi legacy schema chahiye —');
        dr_line('  mujhe batao, main uske liye schema ka raasta de dunga.');
    }
} elseif (!isset($userColumns['mobile']) || (!isset($userColumns['password']) && !isset($userColumns['pwd']))) {
    dr_line('VERDICT B: shonu_subjects exists but mobile/password columns are missing - the table is not the expected legacy table.');
} elseif (isset($userColumns['akshinak']) && preg_match('/\((\d+)\)/', $userColumns['akshinak'], $m) && (int)$m[1] < 512) {
    dr_line('VERDICT C: akshinak is ' . $userColumns['akshinak'] . ' - a modern JWT does not fit, login answers HTTP 503.');
    dr_line('  Run this page with &fix=1 (or upload the new Login.php, it widens the column itself).');
} else {
    dr_line('VERDICT D: shonu_subjects looks usable in ' . $inspectDb . ' (users=' . dr_single($conn, 'SELECT COUNT(*) FROM `shonu_subjects`') . ').');
    dr_line('  Login ab kaam karna chahiye. Problem rahe to error log ki [Login] ref=... line bhejo.');
}
dr_line();
dr_line('end of report  |  delete raxiwin-login-doctor.php after use');
