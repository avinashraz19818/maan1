<?php
/**
 * WatchPay payout (pay/transfer, query/transfer, query/balance).
 * Sign + POST reuse Rhinopay/WatchPay deposit helpers. Does not touch wallets.
 */
require_once __DIR__ . '/rhinopay_lib.php';

if (!function_exists('watchpay_cfg')) {
    function watchpay_cfg(): array
    {
        $cfg = include __DIR__ . '/rhinopay_config.php';
        return is_array($cfg) ? $cfg : [];
    }
}

if (!function_exists('watchpay_callback_url')) {
    function watchpay_callback_url(array $config): string
    {
        $env = trim((string) (getenv('WATCHPAY_TRANSFER_NOTIFY_URL') ?: ''));
        if ($env !== '') {
            return $env;
        }
        $base = rtrim((string) ($config['public_base'] ?? ''), '/');
        if ($base === '' && function_exists('app_primary_origin')) {
            $base = rtrim(app_primary_origin(), '/');
        }
        if ($base === '') {
            $base = 'https://wingoserver.trusheme.info';
        }
        return $base . '/pay/watchpay_transfer_callback.php';
    }
}

if (!function_exists('watchpay_default_bank_code')) {
    function watchpay_default_bank_code(): string
    {
        return trim((string) (getenv('WATCHPAY_PAYOUT_BANK_CODE') ?: ''));
    }
}

if (!function_exists('watchpay_filter_params')) {
    function watchpay_filter_params(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'sign' || $lk === 'signtype' || $lk === 'sign_type') {
                continue;
            }
            if ($v === null || $v === '' || is_array($v)) {
                continue;
            }
            $out[(string) $k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        return $out;
    }
}

if (!function_exists('watchpay_signed_post')) {
    function watchpay_signed_post(array $config, string $url, array $params): array
    {
        $key = (string) ($config['md5_key'] ?? '');
        $body = watchpay_filter_params($params);
        $body['sign_type'] = 'MD5';
        $body['sign'] = rhinopay_md5_sign($body, $key);
        $res = rhinopay_post_form($url, $body);
        $safe = $body;
        unset($safe['sign']);
        rhinopay_log($config, 'WATCHPAY-POST ' . $url . ' fields=' . implode(',', array_keys($safe))
            . ' http=' . (int) ($res['http'] ?? 0)
            . ' resp=' . substr((string) ($res['raw'] ?? ''), 0, 800));
        return $res;
    }
}

if (!function_exists('watchpay_ensure_payouts_table')) {
    function watchpay_ensure_payouts_table(mysqli $conn): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS watchpay_admin_payouts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_unohs VARCHAR(64) NOT NULL DEFAULT \'\',
            user_id INT NOT NULL DEFAULT 0,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            channel VARCHAR(16) NOT NULL DEFAULT \'upi\',
            receive_name VARCHAR(128) NOT NULL DEFAULT \'\',
            receive_account VARCHAR(128) NOT NULL DEFAULT \'\',
            bank_code VARCHAR(64) NOT NULL DEFAULT \'\',
            mch_transferid VARCHAR(64) NOT NULL,
            resp_code VARCHAR(32) NOT NULL DEFAULT \'\',
            trade_result VARCHAR(32) NOT NULL DEFAULT \'\',
            error_msg VARCHAR(255) NOT NULL DEFAULT \'\',
            raw_json MEDIUMTEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wp_mch_transferid (mch_transferid),
            KEY idx_wp_user (user_id),
            KEY idx_wp_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        return (bool) $conn->query($sql);
    }
}

if (!function_exists('watchpay_new_transfer_id')) {
    function watchpay_new_transfer_id(): string
    {
        return 'WPT' . date('YmdHis') . sprintf('%04d', random_int(0, 9999));
    }
}

if (!function_exists('watchpay_format_amount')) {
    function watchpay_format_amount(float $amount): string
    {
        $amount = round($amount, 2);
        if (abs($amount - (int) $amount) < 0.001) {
            return (string) (int) $amount;
        }
        return number_format($amount, 2, '.', '');
    }
}

if (!function_exists('watchpay_json_pick')) {
    function watchpay_json_pick(array $j, array $keys)
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $j) && $j[$k] !== null && $j[$k] !== '') {
                return $j[$k];
            }
        }
        $data = (isset($j['data']) && is_array($j['data'])) ? $j['data'] : [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                return $data[$k];
            }
        }
        return null;
    }
}

if (!function_exists('watchpay_parse_balance')) {
    function watchpay_parse_balance(array $res): array
    {
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        $resp = (string) watchpay_json_pick($j, ['respCode', 'resp_code']);
        $err = (string) watchpay_json_pick($j, ['errorMsg', 'error_msg', 'errMsg']);
        if ($err === '') {
            $err = (string) ($res['error'] ?? '');
        }
        $avail = watchpay_json_pick($j, ['availableAmount', 'available_amount', 'available']);
        $frozen = watchpay_json_pick($j, ['frozenAmount', 'frozen_amount', 'frozen']);
        $total = watchpay_json_pick($j, ['amount', 'Amount', 'totalAmount', 'total_amount']);
        $ok = (strcasecmp($resp, 'SUCCESS') === 0);
        return [
            'ok' => $ok,
            'http' => (int) ($res['http'] ?? 0),
            'resp_code' => $resp,
            'error' => $err,
            'amount' => $total,
            'frozen' => $frozen,
            'available' => $avail,
            'raw' => (string) ($res['raw'] ?? ''),
            'json' => $j,
        ];
    }
}

if (!function_exists('watchpay_money')) {
    function watchpay_money($v): string
    {
        if ($v === null || $v === '') {
            return '0.00';
        }
        return number_format((float) $v, 2, '.', ',');
    }
}

if (!function_exists('watchpay_balance_token')) {
    function watchpay_balance_token(array $config, int $hourOffset = 0): string
    {
        $slot = gmdate('YmdH', time() + ($hourOffset * 3600));
        return hash_hmac(
            'sha256',
            'wpbal|' . (string) ($config['mch_id'] ?? '') . '|' . $slot,
            (string) ($config['md5_key'] ?? '')
        );
    }
}

if (!function_exists('watchpay_balance_token_ok')) {
    function watchpay_balance_token_ok(array $config, string $token): bool
    {
        $token = strtolower(trim($token));
        if ($token === '' || strlen($token) < 32) {
            return false;
        }
        foreach ([0, -1, 1] as $off) {
            if (hash_equals(strtolower(watchpay_balance_token($config, $off)), $token)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('watchpay_balance_hop_urls')) {
    function watchpay_balance_hop_urls(array $config): array
    {
        $urls = [];
        $env = trim((string) (getenv('WATCHPAY_BALANCE_PROXY') ?: ''));
        if ($env !== '') {
            $urls[] = $env;
        }
        $base = rtrim((string) ($config['public_base'] ?? ''), '/');
        if ($base !== '' && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:|/|$)#i', $base)) {
            $urls[] = $base . '/pay/watchpay_balance_json.php';
        }
        return array_values(array_unique($urls));
    }
}

if (!function_exists('watchpay_query_balance_via_hop')) {
    function watchpay_query_balance_via_hop(array $config): ?array
    {
        $token = watchpay_balance_token($config);
        foreach (watchpay_balance_hop_urls($config) as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['token' => $token]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'X-WatchPay-Balance: ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            $raw = (string) curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            unset($ch);
            $j = json_decode($raw, true);
            if ($http === 200 && is_array($j) && array_key_exists('available', $j)) {
                return [
                    'ok' => !empty($j['ok']),
                    'http' => $http,
                    'resp_code' => (string) ($j['resp_code'] ?? ''),
                    'error' => (string) ($j['error'] ?? ''),
                    'amount' => $j['amount'] ?? null,
                    'frozen' => $j['frozen'] ?? null,
                    'available' => $j['available'] ?? null,
                    'raw' => $raw,
                    'json' => $j,
                    'via' => 'hop',
                ];
            }
            rhinopay_log($config, 'WATCHPAY-BAL-HOP ' . $url . ' http=' . $http
                . ' err=' . $cerr . ' body=' . substr($raw, 0, 200));
        }
        return null;
    }
}

if (!function_exists('watchpay_query_balance')) {
    function watchpay_query_balance(array $config, bool $allowHop = true): array
    {
        $mch = (string) ($config['mch_id'] ?? '');
        $ver = (string) ($config['api_version'] ?? '1.0');
        $urls = array_values(array_unique(array_filter([
            (string) ($config['balance_url'] ?? ''),
            (string) ($config['balance_url_alt'] ?? ''),
        ])));
        $attempts = [
            ['mch_id' => $mch, 'version' => $ver],
            ['mch_id' => $mch],
        ];
        $last = [
            'ok' => false,
            'http' => 0,
            'resp_code' => '',
            'error' => 'no balance url',
            'amount' => null,
            'frozen' => null,
            'available' => null,
            'raw' => '',
            'json' => [],
            'via' => 'direct',
        ];
        foreach ($urls as $url) {
            foreach ($attempts as $params) {
                $res = watchpay_signed_post($config, $url, $params);
                $parsed = watchpay_parse_balance($res);
                $parsed['via'] = 'direct';
                $parsed['url'] = $url;
                $last = $parsed;
                if ($parsed['ok'] || $parsed['available'] !== null || $parsed['amount'] !== null) {
                    return $last;
                }
            }
        }
        $ipBlocked = stripos((string) $last['error'], 'IP') !== false
            || stripos((string) $last['error'], 'ILLEGE') !== false;
        if ($allowHop && $ipBlocked) {
            $hop = watchpay_query_balance_via_hop($config);
            if (is_array($hop) && ($hop['ok'] || $hop['available'] !== null || $hop['amount'] !== null)) {
                return $hop;
            }
            if (is_array($hop) && ($hop['error'] ?? '') !== '') {
                $last['error'] = (string) $hop['error'];
                $last['resp_code'] = (string) ($hop['resp_code'] ?? $last['resp_code']);
            }
        }
        return $last;
    }
}

if (!function_exists('watchpay_merback_base')) {
    function watchpay_merback_base(): string
    {
        $env = rtrim((string) (getenv('WATCHPAY_MERCHANT_BASE') ?: ''), '/');
        return $env !== '' ? $env : 'https://merchant.watchglb.com';
    }
}

if (!function_exists('watchpay_merback_cookie_file')) {
    function watchpay_merback_cookie_file(array $config, string $kind = 'sess'): string
    {
        $mch = preg_replace('/[^0-9A-Za-z]/', '', (string) ($config['mch_id'] ?? 'mch'));
        $kind = $kind === 'captcha' ? 'captcha' : 'sess';
        return rtrim((string) sys_get_temp_dir(), '/\\') . '/wp_merback_' . $mch . '_' . $kind . '.cookie';
    }
}

if (!function_exists('watchpay_merback_curl')) {
    function watchpay_merback_curl(string $url, string $cookieFile, array $opts = []): array
    {
        $ch = curl_init($url);
        $base = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) RhinopayAdmin/1.0',
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json, text/javascript, */*; q=0.01'],
        ];
        foreach ($opts as $k => $v) {
            $base[$k] = $v;
        }
        curl_setopt_array($ch, $base);
        $raw = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        $json = json_decode($raw, true);
        return [
            'http' => $http,
            'raw' => $raw,
            'json' => is_array($json) ? $json : null,
            'error' => $err,
        ];
    }
}

if (!function_exists('watchpay_merback_fetch_captcha')) {
    function watchpay_merback_fetch_captcha(array $config): string
    {
        $file = watchpay_merback_cookie_file($config, 'captcha');
        $res = watchpay_merback_curl(
            watchpay_merback_base() . '/merBack/verify/loadImgCode?f=' . rawurlencode((string) microtime(true)),
            $file,
            [CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,*/*']]
        );
        return (string) ($res['raw'] ?? '');
    }
}

if (!function_exists('watchpay_walk_amounts')) {
    function watchpay_walk_amounts($node, array &$found): void
    {
        if (!is_array($node)) {
            return;
        }
        $map = [
            'available' => ['availableamount', 'available_amount', 'available', 'avlbal', 'avlamount', 'usableamount', 'useamount', 'keyongjine'],
            'frozen' => ['frozenamount', 'frozen_amount', 'frozen', 'freezeamount', 'unsettleamount', 'unsettledamount', 'unsettlementamount', 'pendingamount', 'blockamount', 'weijiesuan'],
            'amount' => ['amount', 'totalamount', 'total_amount', 'balance', 'acctbalance', 'totalbal', 'zongjine'],
        ];
        foreach ($node as $k => $v) {
            $lk = strtolower((string) $k);
            if (is_array($v)) {
                watchpay_walk_amounts($v, $found);
                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            if (!is_numeric($v) && !preg_match('/^-?\d+(\.\d+)?$/', (string) $v)) {
                continue;
            }
            foreach ($map as $slot => $keys) {
                if (in_array($lk, $keys, true) && !isset($found[$slot])) {
                    $found[$slot] = $v;
                }
            }
        }
    }
}

if (!function_exists('watchpay_merback_parse_balance')) {
    function watchpay_merback_parse_balance(array $res): array
    {
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        $code = (string) ($j['respcode'] ?? $j['respCode'] ?? '');
        $msg = (string) ($j['respMsg'] ?? $j['errorMsg'] ?? $res['error'] ?? '');
        $found = [];
        watchpay_walk_amounts($j, $found);
        $ok = ($code === '0' || $code === '00') && ($found !== []);
        return [
            'ok' => $ok,
            'http' => (int) ($res['http'] ?? 0),
            'resp_code' => $ok ? 'SUCCESS' : (string) ($code !== '' ? $code : 'FAIL'),
            'error' => $ok ? '' : $msg,
            'amount' => $found['amount'] ?? null,
            'frozen' => $found['frozen'] ?? null,
            'available' => $found['available'] ?? null,
            'raw' => (string) ($res['raw'] ?? ''),
            'json' => $j,
            'via' => 'merback',
        ];
    }
}

if (!function_exists('watchpay_merback_login')) {
    function watchpay_merback_login(array $config, string $password, string $captcha): array
    {
        $mch = (string) ($config['mch_id'] ?? '');
        $password = trim($password);
        $captcha = trim($captcha);
        if ($mch === '' || $password === '' || $captcha === '') {
            return ['ok' => false, 'error' => 'merchant password and captcha required'];
        }
        if (strlen($password) < 6 || strlen($password) > 16) {
            return ['ok' => false, 'error' => 'merchant password must be 6-16 characters'];
        }
        $file = watchpay_merback_cookie_file($config, 'captcha');
        $userId = 'admin' . substr($mch, -3);
        $res = watchpay_merback_curl(
            watchpay_merback_base() . '/merBack/login/loginjson',
            $file,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'merchantNo' => $mch,
                    'passwd' => $password,
                    'valcode' => $captcha,
                    'validCodeType' => '2',
                    'googleCode' => '123456',
                    'userId' => $userId,
                ]),
            ]
        );
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        $code = (string) ($j['respcode'] ?? '');
        $msg = (string) ($j['respMsg'] ?? $res['error'] ?? 'login failed');
        rhinopay_log($config, 'WATCHPAY-MERBACK-LOGIN resp=' . substr((string) $res['raw'], 0, 200));
        if ($code === '0' && is_readable($file)) {
            @copy($file, watchpay_merback_cookie_file($config, 'sess'));
        }
        return [
            'ok' => $code === '0',
            'error' => $code === '0' ? '' : $msg,
            'raw' => (string) $res['raw'],
        ];
    }
}

if (!function_exists('watchpay_merback_balance')) {
    function watchpay_merback_balance(array $config): array
    {
        $file = watchpay_merback_cookie_file($config, 'sess');
        $urls = [
            '/merBack/settle/account/findInfo',
            '/merBack/login/findAllMerchantInfo',
        ];
        $last = [
            'ok' => false,
            'http' => 0,
            'resp_code' => '',
            'error' => 'merchant panel not connected',
            'amount' => null,
            'frozen' => null,
            'available' => null,
            'raw' => '',
            'json' => [],
            'via' => 'merback',
        ];
        foreach ($urls as $path) {
            $res = watchpay_merback_curl(
                watchpay_merback_base() . $path,
                $file,
                [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '']
            );
            $parsed = watchpay_merback_parse_balance($res);
            $last = $parsed;
            $msg = (string) ($parsed['error'] ?? '');
            if (strpos($msg, '没有登录') !== false) {
                $last['error'] = 'merchant panel session expired';
                return $last;
            }
            if ($parsed['ok'] || $parsed['available'] !== null || $parsed['amount'] !== null) {
                if ($parsed['frozen'] === null) {
                    $parsed['frozen'] = 0;
                }
                if ($parsed['available'] !== null && $parsed['amount'] === null) {
                    $parsed['amount'] = $parsed['available'];
                }
                $parsed['ok'] = true;
                $parsed['resp_code'] = 'SUCCESS';
                $parsed['via'] = 'merback';
                rhinopay_log($config, 'WATCHPAY-MERBACK-BAL ' . $path . ' avail=' . $parsed['available']
                    . ' frozen=' . $parsed['frozen'] . ' total=' . $parsed['amount']);
                return $parsed;
            }
        }
        return $last;
    }
}

if (!function_exists('watchpay_outbound_ip')) {
    function watchpay_outbound_ip(): string
    {
        $ch = curl_init('https://api.ipify.org');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw = trim((string) curl_exec($ch));
        unset($ch);
        return filter_var($raw, FILTER_VALIDATE_IP) ? $raw : '';
    }
}

if (!function_exists('watchpay_admin_balance')) {
    function watchpay_admin_balance(mysqli $conn, array $config): array
    {
        $sessFile = watchpay_merback_cookie_file($config, 'sess');
        if (is_readable($sessFile)) {
            $portal = watchpay_merback_balance($config);
            if (!empty($portal['ok']) || $portal['available'] !== null || $portal['amount'] !== null) {
                $portal['ok'] = true;
                $portal['source'] = 'live';
                watchpay_balance_cache_save($conn, $portal);
                return $portal;
            }
        }
        $balance = watchpay_query_balance($config, true);
        if (!empty($balance['ok'])) {
            watchpay_balance_cache_save($conn, $balance);
            $balance['source'] = (!empty($balance['via']) && $balance['via'] === 'hop')
                ? 'live-hop'
                : 'live';
            return $balance;
        }
        $portal = watchpay_merback_balance($config);
        if (!empty($portal['ok']) || $portal['available'] !== null || $portal['amount'] !== null) {
            $portal['ok'] = true;
            watchpay_balance_cache_save($conn, $portal);
            $portal['source'] = 'live';
            return $portal;
        }
        if ($balance['available'] === null && $balance['amount'] === null) {
            $cached = watchpay_balance_cache_load($conn);
            if ($cached) {
                $balance['available'] = $cached['available'];
                $balance['frozen'] = $cached['frozen'];
                $balance['amount'] = $cached['amount'];
                $balance['cached_at'] = $cached['updated_at'];
                $balance['source'] = 'cache';
            }
        }
        if (empty($balance['source'])) {
            $balance['source'] = 'none';
        }
        return $balance;
    }
}

if (!function_exists('watchpay_balance_cache_save')) {
    function watchpay_balance_cache_save(mysqli $conn, array $bal): void
    {
        if (empty($bal['ok'])) {
            return;
        }
        $conn->query(
            'CREATE TABLE IF NOT EXISTS watchpay_balance_cache (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                available VARCHAR(32) NOT NULL DEFAULT \'0\',
                frozen VARCHAR(32) NOT NULL DEFAULT \'0\',
                amount VARCHAR(32) NOT NULL DEFAULT \'0\',
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $a = (string) ($bal['available'] ?? '0');
        $f = (string) ($bal['frozen'] ?? '0');
        $t = (string) ($bal['amount'] ?? '0');
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            'INSERT INTO watchpay_balance_cache (id, available, frozen, amount, updated_at)
             VALUES (1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE available = VALUES(available), frozen = VALUES(frozen),
                amount = VALUES(amount), updated_at = VALUES(updated_at)'
        );
        if ($stmt) {
            $stmt->bind_param('ssss', $a, $f, $t, $now);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('watchpay_balance_cache_load')) {
    function watchpay_balance_cache_load(mysqli $conn): ?array
    {
        $r = @$conn->query('SELECT available, frozen, amount, updated_at FROM watchpay_balance_cache WHERE id = 1 LIMIT 1');
        $row = $r ? $r->fetch_assoc() : null;
        return $row ?: null;
    }
}

if (!function_exists('watchpay_query_transfer')) {
    function watchpay_query_transfer(array $config, string $mchTransferId): array
    {
        $url = (string) ($config['transfer_query_url'] ?? '');
        $res = watchpay_signed_post($config, $url, [
            'mch_id' => (string) ($config['mch_id'] ?? ''),
            'mch_transferid' => $mchTransferId,
        ]);
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        return [
            'ok' => !empty($res['ok']),
            'http' => (int) ($res['http'] ?? 0),
            'resp_code' => (string) ($j['respCode'] ?? $j['resp_code'] ?? ''),
            'trade_result' => (string) ($j['tradeResult'] ?? $j['trade_result'] ?? ''),
            'error' => (string) ($j['errorMsg'] ?? $j['error_msg'] ?? $res['error'] ?? ''),
            'raw' => (string) ($res['raw'] ?? ''),
            'json' => $j,
        ];
    }
}

if (!function_exists('watchpay_xfer_ip_blocked')) {
    function watchpay_xfer_ip_blocked(array $xfer): bool
    {
        $blob = strtolower(
            (string) ($xfer['error'] ?? '') . ' '
            . (string) ($xfer['resp_code'] ?? '') . ' '
            . (string) ($xfer['raw'] ?? '')
        );
        if (strpos($blob, 'ip') !== false && (strpos($blob, 'illeg') !== false || strpos($blob, 'white') !== false)) {
            return true;
        }
        if (strpos($blob, 'mer_ip') !== false) {
            return true;
        }
        $http = (int) ($xfer['http'] ?? 0);
        $raw = trim((string) ($xfer['raw'] ?? ''));
        return $http === 0 && $raw === '';
    }
}

if (!function_exists('watchpay_xfer_should_merback')) {
    function watchpay_xfer_should_merback(array $xfer): bool
    {
        if (watchpay_xfer_ip_blocked($xfer)) {
            return true;
        }
        $blob = strtolower(
            (string) ($xfer['error'] ?? '') . ' '
            . (string) ($xfer['resp_code'] ?? '') . ' '
            . (string) ($xfer['raw'] ?? '')
        );
        foreach ([
            'cannot be empty',
            'can not be empty',
            'order number',
            'mch_order',
            'not support',
            'no_gateway',
            'no gateway',
            'not_support',
        ] as $needle) {
            if ($needle !== '' && strpos($blob, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('watchpay_merback_session_ready')) {
    function watchpay_merback_session_ready(array $config): bool
    {
        $file = watchpay_merback_cookie_file($config, 'sess');
        return is_readable($file) && filesize($file) > 20;
    }
}

if (!function_exists('watchpay_merback_norm')) {
    function watchpay_merback_norm(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        return trim((string) $s);
    }
}

if (!function_exists('watchpay_merback_bank_list')) {
    function watchpay_merback_bank_list(array $config, string $currency = 'INR'): array
    {
        $file = watchpay_merback_cookie_file($config, 'sess');
        $res = watchpay_merback_curl(
            watchpay_merback_base() . '/merBack/sys/sys/findTransferBankInfo',
            $file,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['currency' => $currency]),
                CURLOPT_HTTPHEADER => [
                    'X-Requested-With: XMLHttpRequest',
                    'Accept: application/json, text/javascript, */*; q=0.01',
                    'Referer: ' . watchpay_merback_base() . '/wkweb/settlement/applytransfer.jsp',
                ],
            ]
        );
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        $rows = (isset($j['data']) && is_array($j['data'])) ? $j['data'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['bankCode'] ?? ''));
            $name = trim((string) ($row['bankName'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[] = ['code' => $code, 'name' => $name];
        }
        return $out;
    }
}

if (!function_exists('watchpay_merback_resolve_bank')) {
    /**
     * Map admin IFSC / bank name / UPI to merBack receiveBankCode.
     * UPI channel uses merchant list code IDPT0099.
     *
     * @return array{code:string,name:string,remark:string}
     */
    function watchpay_merback_resolve_bank(array $config, array $in): array
    {
        $channel = strtolower(trim((string) ($in['channel'] ?? 'upi')));
        $ifsc = trim((string) ($in['bank_code'] ?? ''));
        $bankName = trim((string) ($in['bank_name'] ?? ''));
        if ($channel === 'upi') {
            return ['code' => 'IDPT0099', 'name' => 'UPI', 'remark' => 'UPI_TRANSFER'];
        }
        $banks = watchpay_merback_bank_list($config, 'INR');
        $want = watchpay_merback_norm($bankName);
        if ($want !== '') {
            foreach ($banks as $b) {
                if (watchpay_merback_norm((string) $b['name']) === $want) {
                    return ['code' => $b['code'], 'name' => $b['name'], 'remark' => $ifsc];
                }
            }
            foreach ($banks as $b) {
                $bn = watchpay_merback_norm((string) $b['name']);
                if ($bn !== '' && (strpos($bn, $want) !== false || strpos($want, $bn) !== false)) {
                    return ['code' => $b['code'], 'name' => $b['name'], 'remark' => $ifsc];
                }
            }
        }
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $ifsc) ?? '', 0, 4));
        $ifscHints = [
            'KKBK' => 'kotak',
            'BARB' => 'baroda',
            'HDFC' => 'hdfc',
            'ICIC' => 'icici',
            'SBIN' => 'state bank',
            'UTIB' => 'axis',
            'PUNB' => 'punjab national',
            'CNRB' => 'canara',
            'UBIN' => 'union bank',
            'IDIB' => 'indian bank',
            'IOBA' => 'indian overseas',
            'FDRL' => 'federal',
            'DCBL' => 'dcb',
            'IDFB' => 'idfc',
            'KVBL' => 'karur',
            'KARB' => 'karnataka',
            'UCBA' => 'uco',
            'SYNB' => 'syndicate',
            'CBIN' => 'central bank',
            'BDBL' => 'bandhan',
            'ANDB' => 'andhra',
            'SCBL' => 'standard chartered',
            'SIBL' => 'south indian',
        ];
        $hint = $ifscHints[$prefix] ?? '';
        if ($hint !== '') {
            foreach ($banks as $b) {
                $bn = watchpay_merback_norm((string) $b['name']);
                if ($bn !== '' && strpos($bn, $hint) !== false) {
                    return ['code' => $b['code'], 'name' => $b['name'], 'remark' => $ifsc];
                }
            }
        }
        foreach ($banks as $b) {
            if (strcasecmp((string) $b['code'], $ifsc) === 0) {
                return ['code' => $b['code'], 'name' => $b['name'], 'remark' => $ifsc];
            }
        }
        return [
            'code' => $ifsc !== '' ? $ifsc : '',
            'name' => $bankName !== '' ? $bankName : $ifsc,
            'remark' => $ifsc,
        ];
    }
}

if (!function_exists('watchpay_merback_parse_apply')) {
    function watchpay_merback_parse_apply(array $res, string $tid): array
    {
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        $code = (string) ($j['respcode'] ?? $j['respCode'] ?? '');
        $msg = (string) ($j['respMsg'] ?? $j['errorMsg'] ?? $j['retMsg'] ?? $res['error'] ?? '');
        $mbId = watchpay_json_pick($j, [
            'orderNo', 'transferId', 'mchOrderNo', 'mch_transferid', 'id', 'settleNo',
        ]);
        if (is_string($mbId) && $mbId !== '') {
            $tid = $mbId;
        }
        $loginLost = (strpos($msg, '没有登录') !== false || strpos($msg, 'not login') !== false);
        $ok = ($code === '0' || $code === '00');
        if ($loginLost) {
            $msg = 'merchant panel session expired — connect WatchPay merchant again';
        }
        return [
            'ok' => $ok,
            'http' => (int) ($res['http'] ?? 0),
            'resp_code' => $ok ? 'SUCCESS' : (string) ($code !== '' ? $code : 'FAIL'),
            'error' => $ok ? '' : ($msg !== '' ? $msg : 'merBack applytransfer failed'),
            'mch_transferid' => $tid,
            'raw' => (string) ($res['raw'] ?? ''),
            'json' => $j,
            'via' => 'merback',
        ];
    }
}

if (!function_exists('watchpay_merback_apply')) {
    /**
     * Merchant-dashboard applytransfer (same cookie as live balance).
     * Bank: /merBack/settle/settle/requestTransfer
     * UPI:  /merBack/settle/settle/requestBatchTransferForm  bankCode IDPT0099
     */
    function watchpay_merback_apply(array $config, array $in, string $tid): array
    {
        $file = watchpay_merback_cookie_file($config, 'sess');
        if (!is_readable($file)) {
            return [
                'ok' => false,
                'http' => 0,
                'resp_code' => 'FAIL',
                'error' => 'Connect WatchPay merchant first so Send can use merBack applytransfer from this machine.',
                'mch_transferid' => $tid,
                'raw' => '',
                'json' => [],
                'via' => 'merback',
            ];
        }
        $channel = strtolower(trim((string) ($in['channel'] ?? 'upi')));
        if ($channel !== 'bank') {
            $channel = 'upi';
        }
        $bank = watchpay_merback_resolve_bank($config, $in + ['channel' => $channel]);
        $name = trim((string) ($in['receive_name'] ?? ''));
        $acc = trim((string) ($in['receive_account'] ?? ''));
        $amount = watchpay_format_amount((float) ($in['amount'] ?? 0));
        $payPass = trim((string) ($in['pay_passwd'] ?? ''));
        if ($payPass === '') {
            $payPass = trim((string) ($config['merchant_pass'] ?? ''));
        }
        $secure = trim((string) ($in['secure_code'] ?? ''));
        if ($secure === '') {
            $secure = '123456';
        }
        $commonHdr = [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        ];
        if ($channel === 'upi') {
            $row = [
                'amount' => $amount,
                'charge' => '0',
                'receiveBankCode' => 'IDPT0099',
                'receiveBranch' => 'zijin',
                'receiverBankAcocunt' => $acc,
                'reciverBankName' => 'UPI',
                'reciverName' => $name,
                'remark' => 'UPI_TRANSFER',
                'receiveUniteBankId' => '0',
                'processingType' => '0',
                'receiverCity' => '1000',
                'receiverCityName' => 'beijing',
                'receiverProvince' => '11',
                'receiverProvinceName' => 'beijing',
            ];
            $res = watchpay_merback_curl(
                watchpay_merback_base() . '/merBack/settle/settle/requestBatchTransferForm',
                $file,
                [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'payPasswd' => $payPass,
                        'validCodeType' => '2',
                        'secureCode' => $secure,
                        'batchTransferInfo' => json_encode([$row], JSON_UNESCAPED_UNICODE),
                    ]),
                    CURLOPT_HTTPHEADER => array_merge($commonHdr, [
                        'Referer: ' . watchpay_merback_base() . '/wkweb/settlement/applytranferUPI.jsp',
                    ]),
                ]
            );
            rhinopay_log($config, 'WATCHPAY-MERBACK-UPI http=' . (int) ($res['http'] ?? 0)
                . ' resp=' . substr((string) ($res['raw'] ?? ''), 0, 400));
            return watchpay_merback_parse_apply($res, $tid);
        }
        $fields = [
            'receiveUniteBankId' => '0',
            'charge' => '0',
            'reciverName' => $name,
            'receiveBankCode' => $bank['code'],
            'receiverBankAcocunt' => $acc,
            'receiverBankAcocunt2' => $acc,
            'amount' => $amount,
            'remark' => $bank['remark'] !== '' ? $bank['remark'] : (string) ($in['bank_code'] ?? ''),
            'payPasswd' => $payPass,
            'validCodeType' => '2',
            'secureCode' => $secure,
            'receiverProvince' => '11',
            'receiverCity' => '1000',
            'receiveBranch' => '紫金支行',
            'processingType' => '0',
            'receiverProvinceName' => 'beijing',
            'receiverCityName' => 'beijing',
            'reciverBankName' => $bank['name'],
        ];
        $res = watchpay_merback_curl(
            watchpay_merback_base() . '/merBack/settle/settle/requestTransfer',
            $file,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
                CURLOPT_HTTPHEADER => array_merge($commonHdr, [
                    'Referer: ' . watchpay_merback_base() . '/wkweb/settlement/applytransfer.jsp',
                ]),
            ]
        );
        rhinopay_log($config, 'WATCHPAY-MERBACK-BANK http=' . (int) ($res['http'] ?? 0)
            . ' bank=' . $bank['code'] . ' resp=' . substr((string) ($res['raw'] ?? ''), 0, 400));
        return watchpay_merback_parse_apply($res, $tid);
    }
}

if (!function_exists('watchpay_create_transfer')) {
    /**
     * Official signed pay/transfer first (docs). On MER_IP_ILLEGE from localhost,
     * fall back to merBack applytransfer with the same session as live balance.
     *
     * @return array{ok:bool,http:int,resp_code:string,error:string,mch_transferid:string,raw:string,json:array,via:string}
     */
    function watchpay_create_transfer(array $config, array $in): array
    {
        $tid = trim((string) ($in['mch_transferid'] ?? ''));
        if ($tid === '') {
            $tid = watchpay_new_transfer_id();
        }
        $amount = watchpay_format_amount((float) ($in['amount'] ?? 0));
        $date = (string) ($in['apply_date'] ?? date('Y-m-d H:i:s'));
        $bank = trim((string) ($in['bank_code'] ?? ''));
        $name = trim((string) ($in['receive_name'] ?? ''));
        $acc = trim((string) ($in['receive_account'] ?? ''));
        $back = watchpay_callback_url($config);
        $mch = (string) ($config['mch_id'] ?? '');
        $ver = (string) ($config['api_version'] ?? '1.0');
        $url = (string) ($config['transfer_url'] ?? '');
        $attempts = [
            [
                'mch_id' => $mch,
                'mch_order_no' => $tid,
                'mch_transferid' => $tid,
                'transfer_amount' => $amount,
                'apply_date' => $date,
                'bank_code' => $bank,
                'receive_name' => $name,
                'receive_account' => $acc,
                'back_url' => $back,
                'version' => $ver,
            ],
            [
                'mchId' => $mch,
                'mchOrderNo' => $tid,
                'mchTransferId' => $tid,
                'transferAmount' => $amount,
                'applyDate' => $date,
                'bankCode' => $bank,
                'receiveName' => $name,
                'receiveAccount' => $acc,
                'backUrl' => $back,
                'version' => $ver,
            ],
        ];
        $official = [
            'ok' => false,
            'http' => 0,
            'resp_code' => '',
            'error' => 'no transfer url',
            'mch_transferid' => $tid,
            'raw' => '',
            'json' => [],
            'via' => 'official',
        ];
        foreach ($attempts as $params) {
            $res = watchpay_signed_post($config, $url, $params);
            $j = is_array($res['json'] ?? null) ? $res['json'] : [];
            $resp = (string) ($j['respCode'] ?? $j['resp_code'] ?? '');
            $err = (string) ($j['errorMsg'] ?? $j['error_msg'] ?? $res['error'] ?? '');
            $okHttp = (int) ($res['http'] ?? 0) >= 200 && (int) ($res['http'] ?? 0) < 300;
            $okBiz = (strcasecmp($resp, 'SUCCESS') === 0);
            $official = [
                'ok' => $okHttp && $okBiz,
                'http' => (int) ($res['http'] ?? 0),
                'resp_code' => $resp,
                'error' => $err,
                'mch_transferid' => $tid,
                'raw' => (string) ($res['raw'] ?? ''),
                'json' => $j,
                'via' => 'official',
            ];
            if (!empty($official['ok'])) {
                return $official;
            }
            $blob = strtolower($err . ' ' . $resp . ' ' . (string) ($res['raw'] ?? ''));
            if (strpos($blob, 'cannot be empty') === false && strpos($blob, 'order number') === false) {
                break;
            }
        }
        if (!empty($official['ok'])) {
            return $official;
        }
        if (!watchpay_xfer_should_merback($official)) {
            return $official;
        }
        if (!watchpay_merback_session_ready($config)) {
            $official['error'] = (($official['error'] ?? '') !== '' ? $official['error'] . ' · ' : '')
                . 'Connect WatchPay merchant first so Send can use merBack applytransfer from this machine.';
            return $official;
        }
        return watchpay_merback_apply($config, $in, $tid);
    }
}

if (!function_exists('watchpay_payout_save')) {
    function watchpay_payout_save(mysqli $conn, array $row): bool
    {
        if (!watchpay_ensure_payouts_table($conn)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            'INSERT INTO watchpay_admin_payouts
                (admin_unohs, user_id, amount, channel, receive_name, receive_account, bank_code,
                 mch_transferid, resp_code, trade_result, error_msg, raw_json, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                resp_code = VALUES(resp_code),
                trade_result = VALUES(trade_result),
                error_msg = VALUES(error_msg),
                raw_json = VALUES(raw_json),
                updated_at = VALUES(updated_at)'
        );
        if (!$stmt) {
            return false;
        }
        $admin = (string) ($row['admin_unohs'] ?? '');
        $uid = (int) ($row['user_id'] ?? 0);
        $amt = (float) ($row['amount'] ?? 0);
        $ch = (string) ($row['channel'] ?? 'upi');
        $rn = (string) ($row['receive_name'] ?? '');
        $ra = (string) ($row['receive_account'] ?? '');
        $bc = (string) ($row['bank_code'] ?? '');
        $tid = (string) ($row['mch_transferid'] ?? '');
        $rc = (string) ($row['resp_code'] ?? '');
        $tr = (string) ($row['trade_result'] ?? '');
        $em = (string) ($row['error_msg'] ?? '');
        $raw = (string) ($row['raw_json'] ?? '');
        $stmt->bind_param(
            'sidsssssssssss',
            $admin,
            $uid,
            $amt,
            $ch,
            $rn,
            $ra,
            $bc,
            $tid,
            $rc,
            $tr,
            $em,
            $raw,
            $now,
            $now
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('watchpay_payout_update_status')) {
    function watchpay_payout_update_status(mysqli $conn, string $mchTransferId, array $patch): bool
    {
        if ($mchTransferId === '' || !watchpay_ensure_payouts_table($conn)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            'UPDATE watchpay_admin_payouts
             SET resp_code = ?, trade_result = ?, error_msg = ?, raw_json = ?, updated_at = ?
             WHERE mch_transferid = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $rc = (string) ($patch['resp_code'] ?? '');
        $tr = (string) ($patch['trade_result'] ?? '');
        $em = (string) ($patch['error_msg'] ?? '');
        $raw = (string) ($patch['raw_json'] ?? '');
        $stmt->bind_param('ssssss', $rc, $tr, $em, $raw, $now, $mchTransferId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
