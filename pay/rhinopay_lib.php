<?php
/**
 * Rhinopay (WatchPay API) helpers. User-facing name is Rhinopay only.
 */

$_walletHelper = __DIR__ . '/../deepanshu/helpers/wallet_helper.php';
if (is_readable($_walletHelper)) {
    require_once $_walletHelper;
}
require_once __DIR__ . '/deposit_hooks.php';

function rhinopay_log(array $config, string $msg): void
{
    deposit_log_line($config, $msg);
}

/** WatchPay MD5: all submitted params except sign/signType; append &key= */
function rhinopay_md5_sign(array $params, string $key): string
{
    $filtered = [];
    foreach ($params as $k => $v) {
        $lk = strtolower((string)$k);
        if ($lk === 'sign' || $lk === 'signtype' || $lk === 'sign_type') {
            continue;
        }
        if ($v === null || is_array($v)) {
            continue;
        }
        $filtered[(string)$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
    }
    ksort($filtered);
    $parts = [];
    foreach ($filtered as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return md5(implode('&', $parts) . '&key=' . $key);
}

function rhinopay_verify_sign(array $post, string $receivedSign, string $md5Key): bool
{
    $receivedSign = strtolower(trim($receivedSign));
    if ($receivedSign === '' || $md5Key === '') {
        return false;
    }
    $expected = strtolower(rhinopay_md5_sign($post, $md5Key));
    return hash_equals($expected, $receivedSign);
}

function rhinopay_post_form(string $url, array $params): array
{
    $out = ['ok' => false, 'http' => 0, 'raw' => '', 'json' => null, 'error' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json, text/plain, */*',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Rhinopay/1.0)',
    ]);
    $response = curl_exec($ch);
    $out['error'] = curl_error($ch);
    $out['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $out['raw'] = (string)$response;
    $decoded = json_decode($out['raw'], true);
    if (is_array($decoded)) {
        $out['json'] = $decoded;
        $out['ok'] = true;
    }
    return $out;
}

function rhinopay_pick_pay_url(?array $json, string $raw = ''): string
{
    if (!is_array($json)) {
        if (preg_match('#https?://[^\s"\'<>]+#i', $raw, $m)) {
            return $m[0];
        }
        return '';
    }
    $keys = ['payUrl', 'pay_url', 'url', 'payData', 'pay_data', 'cashierUrl', 'redirectUrl', 'payInfo', 'pay_info'];
    foreach ($keys as $k) {
        $v = trim((string)($json[$k] ?? ''));
        if ($v !== '' && preg_match('#^https?://#i', $v)) {
            return $v;
        }
    }
    $data = is_array($json['data'] ?? null) ? $json['data'] : [];
    foreach ($keys as $k) {
        $v = trim((string)($data[$k] ?? ''));
        if ($v !== '' && preg_match('#^https?://#i', $v)) {
            return $v;
        }
        if ($v !== '' && ($k === 'payData' || $k === 'pay_data')) {
            return $v;
        }
    }
    if (preg_match('#https?://[^\s"\'<>]+#i', $raw, $m)) {
        return $m[0];
    }
    return '';
}

function rhinopay_cashier_dir(): string
{
    $dir = __DIR__ . '/logs/cashier';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function rhinopay_cashier_file(string $mchOrderNo): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $mchOrderNo);
    if ($safe === '') {
        $safe = 'unknown';
    }
    return rhinopay_cashier_dir() . '/' . $safe . '.json';
}

function rhinopay_cashier_load(string $mchOrderNo): ?array
{
    $path = rhinopay_cashier_file($mchOrderNo);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
}

function rhinopay_cashier_save(string $mchOrderNo, array $data): void
{
    $data['updated'] = time();
    @file_put_contents(rhinopay_cashier_file($mchOrderNo), json_encode($data, JSON_UNESCAPED_SLASHES));
}

function rhinopay_cashier_skin_list(): array
{
    return ['arupi', 'gaax', 'wiki'];
}

function rhinopay_normalize_gateway(string $tag): string
{
    $t = strtolower(trim($tag));
    if ($t === 'ppay' || $t === 'watchpay' || $t === 'rhinopay') {
        return $t;
    }
    return 'watchpay';
}

function rhinopay_next_cashier_skin(): string
{
    $skins = rhinopay_cashier_skin_list();
    $n = 0;
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir . '/cashier_skin_seq.txt';
    $fp = @fopen($path, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $n = (int)trim((string)$raw);
            $next = $n + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)$next);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    return $skins[$n % count($skins)];
}

function rhinopay_mask_vpa(string $vpa): string
{
    $vpa = trim($vpa);
    if ($vpa === '') {
        return '';
    }
    $at = strrpos($vpa, '@');
    if ($at === false) {
        $len = strlen($vpa);
        return str_repeat('*', max(8, min(12, $len)));
    }
    return str_repeat('*', 10) . substr($vpa, $at);
}

function rhinopay_public_order_id(): string
{
    return 'W' . date('mdHis') . strtoupper(bin2hex(random_bytes(12))) . (string)random_int(100000000, 999999999);
}

function rhinopay_cashier_sign(array $config, string $orderId, string $mchOrderNo, string $amount, string $t): string
{
    $key = (string)($config['md5_key'] ?? '');
    return hash_hmac('sha256', $orderId . '|' . $mchOrderNo . '|' . $amount . '|' . $t, $key);
}

function rhinopay_cashier_verify(array $config, string $orderId, string $mchOrderNo, string $amount, string $t, string $sign): bool
{
    if ($orderId === '' || $mchOrderNo === '' || $amount === '' || $t === '' || $sign === '') {
        return false;
    }
    $ts = (int)$t;
    if ($ts < 1 || abs(time() - $ts) > 7200) {
        return false;
    }
    $expect = rhinopay_cashier_sign($config, $orderId, $mchOrderNo, $amount, $t);
    return hash_equals($expect, strtolower($sign)) || hash_equals($expect, $sign);
}

function rhinopay_http_get(string $url, int $timeout = 20): array
{
    $out = ['ok' => false, 'http' => 0, 'raw' => '', 'final' => $url, 'error' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/json;q=0.9,*/*;q=0.8'],
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);
    $response = curl_exec($ch);
    $out['error'] = curl_error($ch);
    $out['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out['final'] = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    unset($ch);
    $out['raw'] = (string)$response;
    $out['ok'] = $out['http'] >= 200 && $out['http'] < 400 && $out['raw'] !== '';
    return $out;
}

function rhinopay_decode_maybe(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $prev = '';
    $cur = $s;
    for ($i = 0; $i < 3 && $cur !== $prev; $i++) {
        $prev = $cur;
        $try = rawurldecode($cur);
        if ($try !== '') {
            $cur = $try;
        }
    }
    return $cur;
}

function rhinopay_build_upi(string $vpa, string $amount, string $tn, string $pn = 'Deposit'): string
{
    $vpa = trim($vpa);
    if ($vpa === '' || strpos($vpa, '@') === false) {
        return '';
    }
    $q = http_build_query([
        'pa' => $vpa,
        'pn' => $pn,
        'am' => $amount,
        'cu' => 'INR',
        'tn' => $tn,
        'tr' => $tn,
    ], '', '&', PHP_QUERY_RFC3986);
    return 'upi://pay?' . $q;
}

function rhinopay_parse_pay_instruments(string $html, string $amount, string $tn): array
{
    $out = [
        'vpa' => '',
        'upi' => '',
        'paytm' => '',
        'phonepe' => '',
        'gpay' => '',
        'iframe' => '',
    ];
    $decoded = rhinopay_decode_maybe($html);

    if (preg_match('#<iframe[^>]+src=["\'](https?://[^"\']+)#i', $html, $im)) {
        $out['iframe'] = $im[1];
    }

    if (preg_match('#paytmmp://[^\s"\'<>\\\\]+#i', $decoded, $m)) {
        $out['paytm'] = rtrim($m[0], '.,);');
    }
    if (preg_match('#phonepe://[^\s"\'<>\\\\]+#i', $decoded, $m)) {
        $out['phonepe'] = rtrim($m[0], '.,);');
    }
    if (preg_match('#(?:tez|gpay)://[^\s"\'<>\\\\]+#i', $decoded, $m)) {
        $out['gpay'] = rtrim($m[0], '.,);');
    }
    if (preg_match('#upi://pay[^\s"\'<>\\\\]*#i', $decoded, $m)) {
        $out['upi'] = rtrim($m[0], '.,);');
    }

    if (preg_match('/[?&]pa=([A-Za-z0-9._\-]+@[A-Za-z0-9.\-_]+)/i', $decoded, $m)) {
        $out['vpa'] = $m[1];
    } elseif (preg_match('/["\']pa["\']\s*[:=]\s*["\']([A-Za-z0-9._\-]+@[A-Za-z0-9.\-_]+)/i', $decoded, $m)) {
        $out['vpa'] = $m[1];
    } elseif (preg_match('/\b([A-Za-z0-9._\-]{2,40}@(?:ybl|ibl|axl|okaxis|okicici|oksbi|okhdfcbank|paytm|upi|apl|yesbank|kbl|barodampay|naviaxis|okbizaxis)[A-Za-z0-9.\-_]*)\b/i', $decoded, $m)) {
        $out['vpa'] = $m[1];
    }

    if ($out['upi'] === '' && $out['vpa'] !== '') {
        $out['upi'] = rhinopay_build_upi($out['vpa'], $amount, $tn);
    }
    if ($out['vpa'] === '' && $out['upi'] !== '' && preg_match('/[?&]pa=([^&]+)/i', $out['upi'], $m)) {
        $out['vpa'] = rawurldecode($m[1]);
    }

    if ($out['paytm'] === '' && $out['vpa'] !== '') {
        $out['paytm'] = 'paytmmp://cash_wallet?' . http_build_query([
            'pa' => $out['vpa'],
            'pn' => 'Deposit',
            'tr' => $tn,
            'am' => $amount,
            'cu' => 'INR',
            'tn' => $tn,
            'featuretype' => 'money_transfer',
        ], '', '&', PHP_QUERY_RFC3986);
    }
    if ($out['phonepe'] === '' && $out['vpa'] !== '') {
        $out['phonepe'] = 'phonepe://pay?' . http_build_query([
            'pa' => $out['vpa'],
            'pn' => 'Deposit',
            'am' => $amount,
            'cu' => 'INR',
            'tn' => $tn,
        ], '', '&', PHP_QUERY_RFC3986);
    }
    if ($out['gpay'] === '' && $out['vpa'] !== '') {
        $out['gpay'] = 'gpay://upi/pay?' . http_build_query([
            'pa' => $out['vpa'],
            'pn' => 'Deposit',
            'am' => $amount,
            'cu' => 'INR',
            'tn' => $tn,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    return $out;
}

function rhinopay_http_post_json(string $url, array $payload): array
{
    $out = ['ok' => false, 'http' => 0, 'raw' => '', 'json' => null, 'error' => ''];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Rhinopay/1.0)',
    ]);
    $response = curl_exec($ch);
    $out['error'] = curl_error($ch);
    $out['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    $out['raw'] = (string)$response;
    $decoded = json_decode($out['raw'], true);
    if (is_array($decoded)) {
        $out['json'] = $decoded;
        $out['ok'] = true;
    }
    return $out;
}

function rhinopay_wallet_code_from_url(string $url): string
{
    if (preg_match('#to-wallet\.com/([A-Za-z0-9_-]+)#i', $url, $m)) {
        return $m[1];
    }
    if (preg_match('#/(TO[A-Za-z0-9]+)#', $url, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * @return array<string,mixed>
 */
function rhinopay_wallet_get_order(array $config, string $walletOrderNo): array
{
    $walletOrderNo = trim($walletOrderNo);
    $empty = [];
    if ($walletOrderNo === '') {
        return $empty;
    }
    $res = rhinopay_http_post_json(
        'https://fast.to-wallet.com/prod-api/api/wallet/getOrder',
        ['orderNo' => $walletOrderNo]
    );
    rhinopay_log($config, 'WALLET_GET ' . $walletOrderNo . ' http=' . $res['http']
        . ' resp=' . substr($res['raw'], 0, 400));
    $data = is_array($res['json']['data'] ?? null) ? $res['json']['data'] : [];
    if ((int)($res['json']['code'] ?? 0) !== 200 || $data === []) {
        return $empty;
    }
    $links = is_array($data['payUpiLinkList'] ?? null) ? $data['payUpiLinkList'] : [];
    return [
        'walletOrderNo' => $walletOrderNo,
        'vpa' => (string)($data['upi'] ?? ''),
        'upi' => (string)($data['payUrl'] ?? ($links['upi'] ?? '')),
        'paytm' => (string)($links['paytm'] ?? ''),
        'phonepe' => (string)($links['phonepe'] ?? ''),
        'gpay' => (string)($links['gpay'] ?? $links['upi'] ?? ''),
        'countDown' => (int)($data['countDown'] ?? 0),
        'walletStatus' => (string)($data['status'] ?? ''),
    ];
}

function rhinopay_wallet_submit_utr(array $config, string $walletOrderNo, string $utr): array
{
    $res = rhinopay_http_post_json(
        'https://fast.to-wallet.com/prod-api/api/wallet/submit',
        ['orderNo' => $walletOrderNo, 'utr' => $utr]
    );
    rhinopay_log($config, 'WALLET_UTR ' . $walletOrderNo . ' http=' . $res['http']
        . ' resp=' . substr($res['raw'], 0, 300));
    return is_array($res['json']) ? $res['json'] : [];
}

/**
 * @return array{vpa:string,upi:string,paytm:string,phonepe:string,gpay:string,iframe:string,payInfo:string,walletOrderNo:string,countDown:int}
 */
function rhinopay_extract_pay_instruments(array $config, string $payInfoUrl, string $amount, string $tn): array
{
    $blank = [
        'vpa' => '',
        'upi' => '',
        'paytm' => '',
        'phonepe' => '',
        'gpay' => '',
        'iframe' => '',
        'payInfo' => $payInfoUrl,
        'walletOrderNo' => '',
        'countDown' => 0,
    ];
    $url = trim($payInfoUrl);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return $blank;
    }
    $seen = [];
    for ($hop = 0; $hop < 4 && $url !== ''; $hop++) {
        if (isset($seen[$url])) {
            break;
        }
        $seen[$url] = true;
        $code = rhinopay_wallet_code_from_url($url);
        if ($code !== '') {
            $wallet = rhinopay_wallet_get_order($config, $code);
            if (($wallet['upi'] ?? '') !== '' || ($wallet['vpa'] ?? '') !== '') {
                $blank = array_merge($blank, $wallet);
                $blank['iframe'] = $url;
                $blank['payInfo'] = $payInfoUrl;
                return $blank;
            }
        }
        $res = rhinopay_http_get($url);
        rhinopay_log($config, 'EXTRACT hop=' . $hop . ' http=' . $res['http']
            . ' url=' . $url . ' len=' . strlen($res['raw']) . ' err=' . $res['error']);
        if (!$res['ok']) {
            break;
        }
        $parsed = rhinopay_parse_pay_instruments($res['raw'], $amount, $tn);
        foreach (['vpa', 'upi', 'paytm', 'phonepe', 'gpay'] as $k) {
            if ($parsed[$k] !== '') {
                $blank[$k] = $parsed[$k];
            }
        }
        if ($parsed['iframe'] !== '') {
            $blank['iframe'] = $parsed['iframe'];
        }
        $innerCode = rhinopay_wallet_code_from_url($blank['iframe']);
        if ($innerCode !== '') {
            $wallet = rhinopay_wallet_get_order($config, $innerCode);
            if (($wallet['upi'] ?? '') !== '' || ($wallet['vpa'] ?? '') !== '') {
                $blank = array_merge($blank, $wallet);
                $blank['payInfo'] = $payInfoUrl;
                return $blank;
            }
        }
        if ($blank['vpa'] !== '' || $blank['upi'] !== '') {
            break;
        }
        $json = json_decode($res['raw'], true);
        if (is_array($json)) {
            $next = rhinopay_pick_pay_url($json, $res['raw']);
            if ($next !== '' && $next !== $url) {
                $url = $next;
                continue;
            }
        }
        if ($parsed['iframe'] !== '' && $parsed['iframe'] !== $url) {
            $url = $parsed['iframe'];
            continue;
        }
        break;
    }
    return $blank;
}

function rhinopay_is_paid($tradeResult): bool
{
    if ($tradeResult === null || $tradeResult === '') {
        return false;
    }
    if (is_numeric($tradeResult) && (int)$tradeResult === 1) {
        return true;
    }
    $s = strtoupper(trim((string)$tradeResult));
    return $s === '1' || $s === 'SUCCESS' || $s === 'PAID' || $s === 'SUCCESSFUL';
}

/**
 * @return array{ok:bool,paid:bool,tradeNo:string,amount:string,raw:?array,error:string}
 */
function rhinopay_query_order(array $config, string $mchOrderNo): array
{
    $out = ['ok' => false, 'paid' => false, 'tradeNo' => '', 'amount' => '', 'raw' => null, 'error' => ''];
    $mchOrderNo = trim($mchOrderNo);
    if ($mchOrderNo === '') {
        $out['error'] = 'empty_order';
        return $out;
    }
    $params = [
        'mch_id' => (string)($config['mch_id'] ?? ''),
        'mch_order_no' => $mchOrderNo,
        'sign_type' => 'MD5',
    ];
    $params['sign'] = rhinopay_md5_sign($params, (string)($config['md5_key'] ?? ''));
    $url = (string)($config['query_url'] ?? '');
    $res = rhinopay_post_form($url, $params);
    rhinopay_log($config, 'QUERY ' . $mchOrderNo . ' http=' . $res['http']
        . ' resp=' . substr($res['raw'], 0, 800) . ' err=' . $res['error']);

    $json = $res['json'];
    if (!is_array($json)) {
        $out['error'] = 'query_fail';
        return $out;
    }
    $out['raw'] = $json;
    $code = strtoupper((string)($json['respCode'] ?? $json['code'] ?? ''));
    if ($code !== '' && $code !== 'SUCCESS' && $code !== '0') {
        $out['error'] = (string)($json['errorMsg'] ?? $json['msg'] ?? 'query_fail');
        return $out;
    }
    $out['ok'] = true;
    $trade = $json['tradeResult'] ?? $json['trade_result'] ?? '';
    if ($trade === '' && is_array($json['data'] ?? null)) {
        $trade = $json['data']['tradeResult'] ?? $json['data']['trade_result'] ?? '';
        $json = array_merge($json, $json['data']);
    }
    $out['paid'] = rhinopay_is_paid($trade);
    $out['tradeNo'] = (string)($json['orderNo'] ?? $json['tradeNo'] ?? $json['order_no'] ?? '');
    $out['amount'] = (string)($json['amount'] ?? $json['tradeAmount'] ?? '');
    return $out;
}

/**
 * Credit pending thevani if unpaid. Same wallet path as PPay, shared hooks.
 * @return array{credited:bool,already:bool,found:bool,uid:int,amt:float,sid:int,msg:string}
 */
function rhinopay_credit_order(mysqli $conn, array $config, string $mchOrderNo, string $payOrderId = ''): array
{
    $result = [
        'credited' => false,
        'already' => false,
        'found' => false,
        'uid' => 0,
        'amt' => 0.0,
        'sid' => 0,
        'msg' => '',
    ];
    $mchOrderNo = trim($mchOrderNo);
    if ($mchOrderNo === '') {
        $result['msg'] = 'empty';
        return $result;
    }

    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT shonu,balakedara,motta,sthiti,dinankavannuracisi FROM thevani WHERE dharavahi=? LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        $conn->rollback();
        $result['msg'] = 'prepare_fail';
        return $result;
    }
    $stmt->bind_param('s', $mchOrderNo);
    $stmt->execute();
    $stmt->bind_result($depSid, $balakedara, $motta, $sthiti, $depDate);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        $conn->rollback();
        $result['msg'] = 'not_found';
        rhinopay_log($config, 'CREDIT NOT FOUND ' . $mchOrderNo);
        return $result;
    }

    $result['found'] = true;
    $uid = (int)$balakedara;
    $sid = (int)$depSid;
    $creditAmt = (float)$motta;
    $dt = (string)($depDate ?: '');
    $result['uid'] = $uid;
    $result['amt'] = $creditAmt;
    $result['sid'] = $sid;

    if ((int)$sthiti === 1) {
        $conn->commit();
        $result['already'] = true;
        $result['msg'] = 'already_paid';
        rhinopay_log($config, "ALREADY_PAID {$mchOrderNo} uid={$uid}");
        deposit_run_paid_retry_hooks($conn, $config, $uid, $creditAmt, $sid, $mchOrderNo);
        return $result;
    }

    if (!wallet_ensure_user_row($conn, $uid)) {
        $conn->rollback();
        $result['msg'] = 'wallet_row_fail';
        rhinopay_log($config, "WALLET_CREDIT_FAIL no_wallet_row {$mchOrderNo} uid={$uid}");
        return $result;
    }
    if (!wallet_credit_motta($conn, $uid, $creditAmt)) {
        $conn->rollback();
        $result['msg'] = 'wallet_update_fail';
        rhinopay_log($config, "WALLET_CREDIT_FAIL update {$mchOrderNo} uid={$uid}");
        return $result;
    }

    $ref = ($payOrderId !== '' ? $payOrderId : $mchOrderNo) . '|WC';
    $u = $conn->prepare(thevani_credit_update_sql($conn, 'dharavahi=? AND sthiti=0'));
    if (!$u) {
        $conn->rollback();
        $result['msg'] = 'thevani_prepare_fail';
        return $result;
    }
    $u->bind_param('ss', $ref, $mchOrderNo);
    $u->execute();
    $affected = $u->affected_rows;
    $u->close();
    if ($affected < 1) {
        $conn->rollback();
        $result['already'] = true;
        $result['msg'] = 'race_already';
        return $result;
    }

    $conn->commit();
    deposit_run_post_credit_hooks($conn, $config, $uid, $creditAmt, $sid, $mchOrderNo, $dt);
    $result['credited'] = true;
    $result['msg'] = 'credited';
    rhinopay_log($config, "CREDITED {$mchOrderNo} uid={$uid} amt={$creditAmt} sid={$sid}");
    return $result;
}
