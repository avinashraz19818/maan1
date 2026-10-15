<?php
/**
 * Harness runner (NOT shipped in the ZIP).
 *
 * Loads a scenario from /site/scenario.json, builds a fake-but-strict database
 * layer (unknown column -> error, data too long -> error, ALTER ADD/MODIFY
 * updates the table map) and then executes the real hardened Login.php.
 *
 * Prints one JSON blob; run.mjs asserts on it.
 */

ini_set('log_errors', '1');
ini_set('error_log', '/site/php-error.log');
@unlink('/site/php-error.log');

$scenario = json_decode((string)file_get_contents('/site/scenario.json'), true);
if (!is_array($scenario)) {
    echo json_encode(['fatal' => 'scenario.json could not be read']);
    exit;
}

/* ------------------------------------------------------------------ fake db */
class RwResult
{
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc(): ?array { return $this->rows ? array_shift($this->rows) : null; }
    public function free(): void {}
}

class FakeDb
{
    public array $columns = [];
    public array $rows = [];
    public array $failOn = [];
    public array $statements = [];
    public array $alters = [];
    public array $updates = [];

    public function __construct(array $columns, array $rows, array $failOn)
    {
        $this->columns = $columns;
        $this->rows = $rows;
        $this->failOn = $failOn;
    }

    public function real_escape_string(string $value): string
    {
        return addslashes($value);
    }

    private function fail(string $sql): ?string
    {
        foreach ($this->failOn as $pattern => $message) {
            if (stripos($sql, $pattern) !== false) {
                return $message;
            }
        }
        return null;
    }

    private function splitTop(string $list): array
    {
        $depth = 0;
        $current = '';
        $items = [];
        for ($i = 0, $len = strlen($list); $i < $len; $i++) {
            $char = $list[$i];
            if ($char === '(') $depth++;
            if ($char === ')') $depth--;
            if ($char === ',' && $depth === 0) { $items[] = $current; $current = ''; continue; }
            $current .= $char;
        }
        if (trim($current) !== '') $items[] = $current;
        return $items;
    }

    /** [expression, alias] for one SELECT list item. */
    private function parseItem(string $item): array
    {
        $item = trim($item);
        if (preg_match('/\s+AS\s+`([a-zA-Z0-9_]+)`\s*$/i', $item, $m)) {
            return [trim(substr($item, 0, strpos($item, $m[0]))), $m[1]];
        }
        return [$item, trim($item, "` \t")];
    }

    /** Only real column references are validated (aliases are not columns). */
    private function requireColumns(string $selectList): void
    {
        foreach ($this->splitTop($selectList) as $item) {
            [$expr] = $this->parseItem($item);
            if (!preg_match_all('/`([a-zA-Z0-9_]+)`/', $expr, $m)) {
                continue;
            }
            foreach ($m[1] as $column) {
                if ($column === 'shonu_subjects') {
                    continue;
                }
                if (!array_key_exists($column, $this->columns)) {
                    throw new Exception("Unknown column '" . $column . "' in 'field list'");
                }
            }
        }
    }

    /** Very small SELECT-list projector that honours "expr AS alias". */
    private function project(string $selectList, array $row): array
    {
        $out = [];
        foreach ($this->splitTop($selectList) as $item) {
            [$expr, $alias] = $this->parseItem($item);
            if (preg_match('/^`?([a-zA-Z0-9_]+)`?$/', $expr, $m)) {
                $value = $row[$m[1]] ?? null;
            } elseif (preg_match('/^COALESCE\(`?([a-zA-Z0-9_]+)`?,\s*0\)$/i', $expr, $m)) {
                $value = (int)($row[$m[1]] ?? 0);
            } elseif (preg_match("/^'([^']*)'$/", $expr, $m)) {
                $value = $m[1];
            } elseif (preg_match('/^(\d+)$/', $expr, $m)) {
                $value = (int)$m[1];
            } elseif (strtoupper($expr) === 'NOW()') {
                $value = date('Y-m-d H:i:s');
            } else {
                $value = null;
            }
            $out[$alias] = $value;
        }
        return $out;
    }

    private function applySet(string $setList): void
    {
        $items = $this->splitTop($setList);

        foreach ($items as $item) {
            if (!preg_match('/^\s*`([a-zA-Z0-9_]+)`\s*=\s*(.+)$/s', trim($item), $m)) {
                continue;
            }
            $column = $m[1];
            $raw = trim($m[2]);
            if (!array_key_exists($column, $this->columns)) {
                throw new Exception("Unknown column '" . $column . "' in 'field list'");
            }
            $type = $this->columns[$column];
            if ($raw === 'NOW()') continue;
            if (strpos($raw, "'") === 0) {
                $value = trim($raw, "'");
                $value = stripcslashes($value);
                if (preg_match('/\((\d+)\)/', $type, $tm) && stripos($type, 'int') === false && stripos($type, 'text') === false) {
                    if (strlen($value) > (int)$tm[1]) {
                        throw new Exception("Data too long for column '" . $column . "' at row 1");
                    }
                }
            } elseif (preg_match('/^\d+$/', $raw) && stripos($type, 'char') !== false) {
                // numeric value into a string column: fine
                continue;
            } elseif (preg_match('/^\d+$/', $raw)) {
                continue;
            }
            $this->updates[] = [$column => $raw];
        }
    }

    public function query(string $sql)
    {
        $this->statements[] = $sql;
        $error = $this->fail($sql);
        if ($error !== null) {
            throw new Exception($error);
        }

        if (preg_match('/^\s*SHOW FULL COLUMNS FROM `([a-zA-Z0-9_]+)`/i', $sql)) {
            $rows = [];
            foreach ($this->columns as $name => $type) {
                $rows[] = ['Field' => $name, 'Type' => $type, 'Null' => 'YES', 'Default' => null];
            }
            return new RwResult($rows);
        }

        if (preg_match('/^\s*SELECT\s+(.+?)\s+FROM\s+`([a-zA-Z0-9_]+)`/is', $sql, $m)) {
            $this->requireColumns($m[1]);
            $projected = [];
            foreach ($this->rows as $row) {
                $projected[] = $this->project($m[1], $row);
            }
            return new RwResult($projected);
        }

        if (preg_match('/^\s*ALTER TABLE `shonu_subjects` ADD COLUMN `([a-zA-Z0-9_]+)` ([^,]+)/i', $sql, $m)) {
            if (array_key_exists($m[1], $this->columns)) {
                throw new Exception("Duplicate column name '" . $m[1] . "'");
            }
            $this->columns[$m[1]] = trim($m[2]);
            $this->alters[] = 'ADD ' . $m[1] . ' ' . trim($m[2]);
            return true;
        }

        if (preg_match('/^\s*ALTER TABLE `shonu_subjects` MODIFY COLUMN `([a-zA-Z0-9_]+)` ([A-Za-z]+\((\d+)\))/i', $sql, $m)) {
            if (!array_key_exists($m[1], $this->columns)) {
                throw new Exception("Unknown column '" . $m[1] . "'");
            }
            $this->columns[$m[1]] = $m[2];
            $this->alters[] = 'MODIFY ' . $m[1] . ' ' . $m[2];
            return true;
        }

        if (preg_match('/^\s*UPDATE\s+`?[a-zA-Z0-9_]+`?\s+SET\s+(.+?)\s+WHERE/is', $sql, $m)) {
            $this->applySet($m[1]);
            return true;
        }

        return true;
    }
}

/* ------------------------------------------------------------- environment */
$RW_SETTINGS = $scenario['settings'] ?? ['timezone' => 'Asia/Kolkata'];
$RW_TABLES = $scenario['tables'] ?? ['shonu_subjects', 'app_notifications', 'app_settings'];
$RW_PASSWORD_COLUMN_LENGTH = $scenario['passwordColumnLength'] ?? 255;
$RW_BODY = $scenario['body'] ?? [
    'language' => 'en',
    'logintype' => 'mobile',
    'phonetype' => '+91',
    'pwd' => 'secret123',
    'random' => 'abc1234567',
    'signature' => 'X',
    'timestamp' => (string)time(),
    'username' => '9876543210',
];

$hash = '';
$plain = $scenario['passwordPlain'] ?? 'secret123';
switch ($scenario['passwordFormat'] ?? 'bcrypt') {
    case 'md5': $hash = md5($plain); break;
    case 'sha1': $hash = sha1($plain); break;
    case 'sha256': $hash = hash('sha256', $plain); break;
    case 'plain': $hash = $plain; break;
    case 'empty': $hash = ''; break;
    default: $hash = password_hash($plain, PASSWORD_DEFAULT);
}

$rows = [];
if (($scenario['userExists'] ?? true) === true) {
    $rows[] = [
        'id' => 42,
        'mobile' => '9876543210',
        'email' => 'user@example.com',
        'password' => $hash,
        'pwd' => '',
        'status' => $scenario['userStatus'] ?? 1,
        'ishonup' => '10.1.1.1',
        'codechorkamukala' => 'MemberABC',
        'shonupwderr' => $scenario['passwordErrors'] ?? 0,
        'shonullgnt' => '2026-01-01 00:00:00',
        'akshinak' => '',
        'tnegaresunohs' => '',
        'createdate' => '2026-01-01 00:00:00',
    ];
}

$conn = new FakeDb($scenario['columns'] ?? [], $rows, $scenario['failOn'] ?? []);

$HARNESS = ['scenario' => $scenario['name'] ?? '?', 'response' => null, 'thrown' => null];
try {
    require '/site/developer-maruf/api/webapi/Login.php';
    $HARNESS['thrown'] = 'Login.php returned without calling app_api_send';
} catch (DrStop $stop) {
    $HARNESS['response'] = $stop->payload;
} catch (Throwable $error) {
    $HARNESS['thrown'] = get_class($error) . ': ' . $error->getMessage();
}

$HARNESS['alters'] = $conn->alters;
$HARNESS['updates'] = $conn->updates;
$HARNESS['statements'] = $conn->statements;
$HARNESS['errorLog'] = is_readable('/site/php-error.log') ? file('/site/php-error.log', FILE_IGNORE_NEW_LINES) : [];
$HARNESS['settings'] = $RW_SETTINGS;

echo json_encode($HARNESS, JSON_UNESCAPED_SLASHES);
