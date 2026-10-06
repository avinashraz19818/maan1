<?php
/**
 * Accept a local Deepanshu JWT or a remote Veergame JWT payload
 * so /pay cashiers can open after remote login.
 */
if (!function_exists('pay_b64url_decode')) {
    function pay_b64url_decode(string $str): string
    {
        $str = strtr($str, '-_', '+/');
        $pad = strlen($str) % 4;
        if ($pad) {
            $str .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($str, true);
        return $out === false ? (string) base64_decode($str) : $out;
    }
}

if (!function_exists('pay_request_jwt')) {
    function pay_request_jwt(): string
    {
        if (function_exists('dmg_request_jwt_token')) {
            $t = trim((string) dmg_request_jwt_token());
            if ($t !== '') {
                return $t;
            }
        }
        foreach (['sign', 'token', 'jwt'] as $k) {
            $v = trim((string) ($_GET[$k] ?? $_POST[$k] ?? ''));
            if ($v !== '' && substr_count($v, '.') === 2) {
                return preg_replace('/^Bearer\s+/i', '', $v);
            }
        }
        return '';
    }
}

if (!function_exists('pay_jwt_payload')) {
    function pay_jwt_payload(?string $token = null): array
    {
        $token = $token === null ? pay_request_jwt() : trim((string) $token);
        $token = preg_replace('/^Bearer\s+/i', '', $token);
        if ($token === '' || substr_count($token, '.') < 2) {
            return [];
        }
        $parts = explode('.', $token);
        $raw = pay_b64url_decode($parts[1] ?? '');
        $payload = json_decode($raw, true);
        return is_array($payload) ? $payload : [];
    }
}

if (!function_exists('pay_uid_digits')) {
    function pay_uid_digits($uid): string
    {
        $s = preg_replace('/\D+/', '', (string) $uid);
        return $s === null ? '' : $s;
    }
}

if (!function_exists('pay_jwt_uid_any')) {
    function pay_jwt_uid_any(?string $token = null): int
    {
        $s = pay_jwt_uid_str($token);
        if ($s === '' || $s === '0') {
            return 0;
        }
        if (strlen($s) > 18) {
            return 0;
        }
        return (int) $s;
    }
}

if (!function_exists('pay_jwt_uid_str')) {
    function pay_jwt_uid_str(?string $token = null): string
    {
        if (function_exists('dmg_jwt_uid')) {
            $local = pay_uid_digits(dmg_jwt_uid($token));
            if ($local !== '' && $local !== '0') {
                return $local;
            }
        }
        $p = pay_jwt_payload($token);
        foreach (['UserId', 'userId', 'userid', 'id', 'uid', 'Id'] as $k) {
            if (!isset($p[$k])) {
                continue;
            }
            $s = pay_uid_digits($p[$k]);
            if ($s !== '' && $s !== '0') {
                return $s;
            }
        }
        return '';
    }
}

if (!function_exists('pay_accept_uid_jwt')) {
    function pay_accept_uid_jwt($uid): bool
    {
        $want = pay_uid_digits($uid);
        if ($want === '' || $want === '0') {
            return false;
        }
        if (function_exists('dmg_require_uid_jwt')) {
            try {
                if (dmg_require_uid_jwt((int) $want)) {
                    return true;
                }
            } catch (Throwable $e) {
            }
        }
        return pay_jwt_uid_str() === $want;
    }
}

if (!function_exists('pay_widen_subject_id')) {
    function pay_widen_subject_id($conn): void
    {
        if (!$conn instanceof mysqli) {
            return;
        }
        @$conn->query('ALTER TABLE shonu_subjects MODIFY id BIGINT NOT NULL');
    }
}

if (!function_exists('pay_ensure_local_player')) {
    function pay_ensure_local_player($conn, $uid): bool
    {
        $uidStr = pay_uid_digits($uid);
        if (!$conn instanceof mysqli || $uidStr === '' || $uidStr === '0') {
            return false;
        }

        pay_widen_subject_id($conn);

        $stmt = $conn->prepare('SELECT id, mobile FROM shonu_subjects WHERE id=? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $uidStr);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $now = date('Y-m-d H:i:s');
        $payload = pay_jwt_payload();
        $mobile = '';
        foreach (['UserName', 'userName', 'username', 'mobile', 'phone', 'Mobile', 'Phone'] as $k) {
            if (!empty($payload[$k])) {
                $mobile = preg_replace('/\D+/', '', (string) $payload[$k]);
                if ($mobile !== '') {
                    break;
                }
            }
        }
        if ($mobile === '') {
            $mobile = $uidStr;
            if (strlen($mobile) > 15) {
                $mobile = substr($mobile, -15);
            }
        }

        if ($row) {
            $have = trim((string) ($row['mobile'] ?? ''));
            if ($have === '') {
                $up = $conn->prepare('UPDATE shonu_subjects SET mobile=? WHERE id=? LIMIT 1');
                if ($up) {
                    $up->bind_param('ss', $mobile, $uidStr);
                    $up->execute();
                    $up->close();
                }
            }
        } else {
            $nick = 'Member' . $uidStr;
            $own = 'MW' . substr($uidStr, -12);
            $hash = function_exists('dmg_password_hash_store')
                ? dmg_password_hash_store(bin2hex(random_bytes(8)))
                : password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
            $okIns = false;
            $ins = $conn->prepare(
                'INSERT INTO shonu_subjects
                    (id, mobile, email, password, code, owncode, privacy, status, createdate, ip, pwd, codechorkamukala)
                 VALUES (?, ?, \'\', ?, \'\', ?, \'on\', \'1\', ?, \'\', \'\', ?)'
            );
            if ($ins) {
                $ins->bind_param('ssssss', $uidStr, $mobile, $hash, $own, $now, $nick);
                $okIns = $ins->execute();
                $ins->close();
            }
            if (!$okIns) {
                $ins2 = $conn->prepare(
                    'INSERT INTO shonu_subjects (id, mobile, password, status, createdate, codechorkamukala)
                     VALUES (?, ?, ?, \'1\', ?, ?)'
                );
                if ($ins2) {
                    $ins2->bind_param('sssss', $uidStr, $mobile, $hash, $now, $nick);
                    $okIns = $ins2->execute();
                    $ins2->close();
                }
            }
            if (!$okIns) {
                $again = $conn->prepare('SELECT id FROM shonu_subjects WHERE id=? LIMIT 1');
                if (!$again) {
                    return false;
                }
                $again->bind_param('s', $uidStr);
                $again->execute();
                $got = $again->get_result();
                $ok = $got && $got->fetch_assoc();
                $again->close();
                if (!$ok) {
                    return false;
                }
            }
        }

        $w = $conn->prepare('SELECT balakedara FROM shonu_kaichila WHERE balakedara=? LIMIT 1');
        if ($w) {
            $w->bind_param('s', $uidStr);
            $w->execute();
            $wr = $w->get_result();
            $exists = $wr && $wr->fetch_assoc();
            $w->close();
            if (!$exists) {
                $zero = 0.0;
                $wi = $conn->prepare('INSERT INTO shonu_kaichila (balakedara, motta, dinankavannuracisi) VALUES (?, ?, ?)');
                if ($wi) {
                    $wi->bind_param('sds', $uidStr, $zero, $now);
                    $wi->execute();
                    $wi->close();
                }
            }
        }

        return true;
    }
}

if (!function_exists('pay_widen_thevani_uid')) {
    function pay_widen_thevani_uid($conn): void
    {
        if (!$conn instanceof mysqli) {
            return;
        }
        @$conn->query('ALTER TABLE thevani MODIFY balakedara VARCHAR(32) NOT NULL');
    }
}

if (!function_exists('pay_next_thevani_shonu')) {
    function pay_next_thevani_shonu($conn): int
    {
        $next = 1;
        if ($conn instanceof mysqli) {
            $mx = @$conn->query('SELECT IFNULL(MAX(shonu),0)+1 AS n FROM thevani');
            if ($mx && ($mr = $mx->fetch_assoc())) {
                $next = (int) $mr['n'];
            }
        }
        return $next < 1 ? 1 : $next;
    }
}

if (!function_exists('pay_insert_thevani_pending')) {
    /**
     * Insert pending thevani row. $uid must be the Maanwin digit string.
     * @return array{ok:bool,error:string}
     */
    function pay_insert_thevani_pending(
        $conn,
        $uid,
        $payid,
        $mottaFloat,
        $srl,
        $mula,
        $refNum,
        $mobile,
        $ekikrtapavati,
        $createdate,
        $madari,
        $pav,
        $sthiti
    ): array {
        if (!$conn instanceof mysqli) {
            return ['ok' => false, 'error' => 'no db'];
        }
        pay_widen_thevani_uid($conn);
        $uidStr = pay_uid_digits($uid);
        if ($uidStr === '' || $uidStr === '0') {
            return ['ok' => false, 'error' => 'bad uid'];
        }
        $sql = 'INSERT INTO thevani
            (shonu,payid,balakedara,motta,dharavahi,mula,ullekha,duravani,ekikrtapavati,dinankavannuracisi,madari,pavatiaidi,sthiti)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $payid = (int) $payid;
        $mottaFloat = (float) $mottaFloat;
        $srl = (string) $srl;
        $mula = (string) $mula;
        $refNum = (string) $refNum;
        $mobile = (string) $mobile;
        $ekikrtapavati = (string) $ekikrtapavati;
        $createdate = (string) $createdate;
        $madari = (string) $madari;
        $pav = (int) $pav;
        $sthiti = (int) $sthiti;

        $lastErr = '';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $nextShonu = pay_next_thevani_shonu($conn);
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return ['ok' => false, 'error' => (string) $conn->error];
            }
            $stmt->bind_param(
                'iisdsssssssii',
                $nextShonu,
                $payid,
                $uidStr,
                $mottaFloat,
                $srl,
                $mula,
                $refNum,
                $mobile,
                $ekikrtapavati,
                $createdate,
                $madari,
                $pav,
                $sthiti
            );
            $ok = $stmt->execute();
            if (!$ok) {
                $errno = (int) $conn->errno;
                $lastErr = (string) $stmt->error;
                if ($lastErr === '') {
                    $lastErr = (string) $conn->error;
                }
                if ($errno !== 0 && strpos($lastErr, (string) $errno) === false) {
                    $lastErr = trim($lastErr . ' errno=' . $errno);
                }
                $stmt->close();
                continue;
            }
            $stmt->close();
            return ['ok' => true, 'error' => ''];
        }

        return ['ok' => false, 'error' => $lastErr !== '' ? $lastErr : 'insert failed'];
    }
}
