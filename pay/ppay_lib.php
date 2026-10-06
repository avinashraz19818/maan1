<?php
/**
 * Shared PPayPros helpers: MD5 sign, order query, wallet credit.
 */

require_once __DIR__ . '/../deepanshu/helpers/wallet_helper.php';
require_once __DIR__ . '/deposit_hooks.php';

function ppay_md5_sign(array $params, string $key): string
{
    $filtered = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        if (strtolower((string)$k) === 'sign') {
            continue;
        }
        if (is_array($v)) {
            continue;
        }
        $filtered[(string)$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
    }
    ksort($filtered);
    $parts = [];
    foreach ($filtered as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return strtoupper(md5(implode('&', $parts) . '&key=' . $key));
}

function ppay_log_line(array $config, string $msg): void
{
    deposit_log_line($config, $msg);
}

function ppay_claim_bonus_slot(mysqli $conn, string $orderId): ?bool
{
    return deposit_claim_bonus_slot($conn, $orderId);
}

function ppay_release_bonus_slot(mysqli $conn, string $orderId): void
{
    deposit_release_bonus_slot($conn, $orderId);
}

function ppay_run_deposit_bonus(
    mysqli $conn,
    string $dharavahi,
    int $uid,
    float $amt,
    $sid,
    ?array $config = null
): void {
    deposit_run_deposit_bonus($conn, $dharavahi, $uid, $amt, $sid, $config);
}

function ppay_run_post_credit_hooks(
    mysqli $conn,
    array $config,
    int $uid,
    float $creditAmt,
    int $sid,
    string $dharavahi,
    string $depDate = ''
): void {
    deposit_run_post_credit_hooks($conn, $config, $uid, $creditAmt, $sid, $dharavahi, $depDate);
}

/**
 * Query gateway order status.
 * @return array{ok:bool,state:int,payOrderId:string,amount:int,raw:?array,error:string}
 */
function ppay_query_order(array $config, string $mchOrderNo): array
{
    $out = [
        'ok' => false,
        'state' => -1,
        'payOrderId' => '',
        'amount' => 0,
        'raw' => null,
        'error' => '',
    ];
    $mchOrderNo = trim($mchOrderNo);
    if ($mchOrderNo === '') {
        $out['error'] = 'empty_order';
        return $out;
    }

    $apiUrl = (string)($config['query_url'] ?? 'https://pay.ppaypros.com/api/pay/query');
    $params = [
        'mchNo' => (string)($config['mch_no'] ?? ''),
        'appId' => (string)($config['app_id'] ?? ''),
        'mchOrderNo' => $mchOrderNo,
    ];
    $params['sign'] = ppay_md5_sign($params, (string)($config['md5_key'] ?? ''));

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CO Games-PPay/1.0)',
    ]);
    $response = curl_exec($ch);
    $cerr = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    ppay_log_line($config, 'QUERY ' . $mchOrderNo . ' http=' . $http
        . ' resp=' . substr((string)$response, 0, 800) . ' err=' . $cerr);

    $res = json_decode((string)$response, true);
    if (!is_array($res) || (int)($res['code'] ?? -1) !== 0) {
        $out['error'] = 'query_fail';
        $out['raw'] = is_array($res) ? $res : null;
        return $out;
    }

    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    $stateRaw = $data['state'] ?? $data['orderState'] ?? -1;
    $out['ok'] = true;
    $out['state'] = (int)$stateRaw;
    $out['payOrderId'] = (string)($data['payOrderId'] ?? $data['orderId'] ?? '');
    $out['amount'] = (int)($data['amount'] ?? 0);
    $out['raw'] = $res;
    return $out;
}

/** True when gateway reports a successful/paid order. */
function ppay_is_paid_state($stateRaw): bool
{
    if ($stateRaw === null || $stateRaw === '') {
        return false;
    }
    if ((int)$stateRaw === 2) {
        return true;
    }
    return in_array(strtoupper((string)$stateRaw), ['SUCCESS', 'PAID', 'SUCCESSFUL', '2'], true);
}

/**
 * Verify callback notify signature (multiple amount encodings).
 */
function ppay_verify_notify_sign(array $post, string $receivedSign, string $md5Key): bool
{
    $receivedSign = strtoupper(trim($receivedSign));
    if ($receivedSign === '' || $md5Key === '') {
        return false;
    }

    $variants = [$post];
    if (isset($post['amount'])) {
        $amt = $post['amount'];
        $variants[] = array_merge($post, ['amount' => (string)(int)$amt]);
        $variants[] = array_merge($post, ['amount' => (string)$amt]);
        if (is_numeric($amt) && (int)$amt > 1000) {
            $variants[] = array_merge($post, ['amount' => (string)intdiv((int)$amt, 100)]);
        }
    }

    foreach ($variants as $variant) {
        $expected = ppay_md5_sign($variant, $md5Key);
        if ($receivedSign === $expected) {
            return true;
        }
    }
    return false;
}

/**
 * Credit pending thevani row if still unpaid. Idempotent.
 * @return array{credited:bool,already:bool,found:bool,uid:int,amt:float,sid:int,msg:string}
 */
function ppay_credit_order(mysqli $conn, array $config, string $mchOrderNo, string $payOrderId = ''): array
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

    $candidates = [$mchOrderNo];
    if (substr($mchOrderNo, -1) === 'F') {
        $candidates[] = substr($mchOrderNo, 0, -1);
    } else {
        $candidates[] = $mchOrderNo . 'F';
    }

    $conn->begin_transaction();
    $depSid = null;
    $balakedara = null;
    $motta = null;
    $sthiti = null;
    $depDate = null;
    $matchedOrder = $mchOrderNo;

    foreach ($candidates as $cand) {
        $stmt = $conn->prepare(
            'SELECT shonu,balakedara,motta,sthiti,dinankavannuracisi FROM thevani WHERE dharavahi=? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            $conn->rollback();
            $result['msg'] = 'prepare_fail';
            return $result;
        }
        $stmt->bind_param('s', $cand);
        $stmt->execute();
        $stmt->bind_result($depSid, $balakedara, $motta, $sthiti, $depDate);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found) {
            $matchedOrder = $cand;
            break;
        }
        $depSid = $balakedara = $motta = $sthiti = $depDate = null;
    }

    if ($depSid === null) {
        $conn->rollback();
        $result['msg'] = 'not_found';
        ppay_log_line($config, 'CREDIT NOT FOUND ' . $mchOrderNo);
        return $result;
    }

    $result['found'] = true;
    $result['uid'] = (int)$balakedara;
    $result['amt'] = (float)$motta;
    $result['sid'] = (int)$depSid;

    $uid = (int)$balakedara;
    $sid = (int)$depSid;
    $creditAmt = (float)$motta;
    $dt = (string)($depDate ?: '');

    if ((int)$sthiti === 1) {
        $conn->commit();
        $result['already'] = true;
        $result['msg'] = 'already_paid';
        ppay_log_line($config, "ALREADY_PAID {$matchedOrder} uid={$uid}");
        deposit_run_paid_retry_hooks($conn, $config, $uid, $creditAmt, $sid, $matchedOrder);
        return $result;
    }

    if (!wallet_ensure_user_row($conn, $uid)) {
        $conn->rollback();
        $result['msg'] = 'wallet_row_fail';
        ppay_log_line($config, "WALLET_CREDIT_FAIL no_wallet_row {$matchedOrder} uid={$uid}");
        return $result;
    }

    if (!wallet_credit_motta($conn, $uid, $creditAmt)) {
        $conn->rollback();
        $result['msg'] = 'wallet_update_fail';
        ppay_log_line($config, "WALLET_CREDIT_FAIL update {$matchedOrder} uid={$uid}");
        return $result;
    }
    $walletAffected = 1;

    // |WC marks wallet credited — blocks ppay_repair_paid_wallet from double-crediting.
    $ref = ($payOrderId !== '' ? $payOrderId : $matchedOrder) . '|WC';
    $u = $conn->prepare(thevani_credit_update_sql($conn, 'dharavahi=? AND sthiti=0'));
    if (!$u) {
        $conn->rollback();
        $result['msg'] = 'thevani_prepare_fail';
        ppay_log_line($config, "WALLET_CREDIT_FAIL thevani_prepare {$matchedOrder}");
        return $result;
    }
    $u->bind_param('ss', $ref, $matchedOrder);
    $u->execute();
    $affected = $u->affected_rows;
    $u->close();

    if ($affected < 1) {
        $conn->rollback();
        $result['already'] = true;
        $result['msg'] = 'race_already';
        ppay_log_line($config, "RACE_ALREADY {$matchedOrder} uid={$uid}");
        return $result;
    }

    $conn->commit();

    ppay_run_post_credit_hooks($conn, $config, $uid, $creditAmt, $sid, $matchedOrder, $dt);

    $result['credited'] = true;
    $result['msg'] = 'credited';
    ppay_log_line($config, "WALLET_CREDIT_OK {$matchedOrder} uid={$uid} amt={$creditAmt} sid={$sid}");
    ppay_log_line($config, "CREDITED {$matchedOrder} uid={$uid} amt={$creditAmt} sid={$sid}");
    return $result;
}

/**
 * Repair wallet for order already marked paid (sthiti=1) but wallet never credited.
 * Idempotent via ullekha |WC marker.
 */
function ppay_repair_paid_wallet(mysqli $conn, array $config, string $mchOrderNo): array
{
    $result = [
        'repaired' => false,
        'already' => false,
        'found' => false,
        'uid' => 0,
        'amt' => 0.0,
        'msg' => '',
    ];
    $mchOrderNo = trim($mchOrderNo);
    if ($mchOrderNo === '') {
        $result['msg'] = 'empty';
        return $result;
    }

    $candidates = [$mchOrderNo];
    if (substr($mchOrderNo, -1) === 'F') {
        $candidates[] = substr($mchOrderNo, 0, -1);
    } else {
        $candidates[] = $mchOrderNo . 'F';
    }

    $conn->begin_transaction();
    $depSid = null;
    $balakedara = null;
    $motta = null;
    $sthiti = null;
    $ullekha = null;
    $matchedOrder = $mchOrderNo;

    foreach ($candidates as $cand) {
        $stmt = $conn->prepare(
            'SELECT shonu,balakedara,motta,sthiti,ullekha FROM thevani WHERE dharavahi=? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            $conn->rollback();
            $result['msg'] = 'prepare_fail';
            return $result;
        }
        $stmt->bind_param('s', $cand);
        $stmt->execute();
        $stmt->bind_result($depSid, $balakedara, $motta, $sthiti, $ullekha);
        $found = $stmt->fetch();
        $stmt->close();
        if ($found) {
            $matchedOrder = $cand;
            break;
        }
        $depSid = $balakedara = $motta = $sthiti = $ullekha = null;
    }

    if ($depSid === null) {
        $conn->rollback();
        $result['msg'] = 'not_found';
        return $result;
    }

    $result['found'] = true;
    $uid = (int)$balakedara;
    $creditAmt = (float)$motta;
    $result['uid'] = $uid;
    $result['amt'] = $creditAmt;

    if ((int)$sthiti !== 1) {
        $conn->rollback();
        $result['msg'] = 'not_paid';
        return $result;
    }

    $ullekhaStr = (string)($ullekha ?? '');
    if (strpos($ullekhaStr, '|WC') !== false) {
        $conn->rollback();
        $result['already'] = true;
        $result['msg'] = 'already_repaired';
        return $result;
    }

    if (!wallet_ensure_user_row($conn, $uid)) {
        $conn->rollback();
        $result['msg'] = 'wallet_row_fail';
        return $result;
    }

    if (!wallet_credit_motta($conn, $uid, $creditAmt)) {
        $conn->rollback();
        $result['msg'] = 'wallet_update_fail';
        return $result;
    }

    $newRef = $ullekhaStr . '|WC';
    $u = $conn->prepare('UPDATE thevani SET ullekha=? WHERE dharavahi=? AND sthiti=1 LIMIT 1');
    if (!$u) {
        $conn->rollback();
        $result['msg'] = 'thevani_prepare_fail';
        return $result;
    }
    $u->bind_param('ss', $newRef, $matchedOrder);
    $u->execute();
    $u->close();
    $conn->commit();

    $sid = (int)$depSid;
    ppay_run_post_credit_hooks($conn, $config, $uid, $creditAmt, $sid, $matchedOrder, '');

    $result['repaired'] = true;
    $result['msg'] = 'repaired';
    ppay_log_line($config, "REPAIR_WALLET {$matchedOrder} uid={$uid} amt={$creditAmt}");
    return $result;
}

/**
 * Query gateway for user's pending PPay rows and credit any that are paid.
 * @return array{checked:int,credited:list<array>,errors:list<string>}
 */
function ppay_sync_pending_for_user(mysqli $conn, array $config, int $uid, int $limit = 10): array
{
    @ini_set('display_errors', '0');
    $out = ['checked' => 0, 'credited' => [], 'errors' => []];
    if ($uid <= 0) {
        return $out;
    }
    $limit = max(1, min(15, $limit));
    $uidStr = (string)$uid;
    /* balakedara may be stored as string or int depending on insert path */
    $sql = "SELECT dharavahi FROM thevani
         WHERE (balakedara = ? OR balakedara = ?)
           AND sthiti = 0
           AND (payid = 2 OR dharavahi LIKE 'PP%')
         ORDER BY shonu DESC LIMIT " . (int)$limit;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $out['errors'][] = 'prepare_fail';
        return $out;
    }
    $stmt->bind_param('si', $uidStr, $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $orders = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $o = trim((string)($row['dharavahi'] ?? ''));
            if ($o !== '') {
                $orders[] = $o;
            }
        }
    }
    $stmt->close();

    foreach ($orders as $mchOrderNo) {
        $out['checked']++;
        try {
            $q = ppay_query_order($config, $mchOrderNo);
            if (!$q['ok']) {
                /* Also try F-suffix / bare variants */
                $alt = (substr($mchOrderNo, -1) === 'F')
                    ? substr($mchOrderNo, 0, -1)
                    : ($mchOrderNo . 'F');
                $q = ppay_query_order($config, $alt);
                if ($q['ok'] && ppay_is_paid_state($q['state'])) {
                    $mchOrderNo = $alt;
                } else {
                    $out['errors'][] = $mchOrderNo . ':query_fail';
                    continue;
                }
            }
            if (!ppay_is_paid_state($q['state'])) {
                continue;
            }
            $credit = ppay_credit_order($conn, $config, $mchOrderNo, (string)$q['payOrderId']);
            if (!empty($credit['credited']) || !empty($credit['already'])) {
                $out['credited'][] = [
                    'o' => $mchOrderNo,
                    'amt' => $credit['amt'] ?? 0,
                    'msg' => $credit['msg'] ?? '',
                ];
                ppay_log_line($config, 'SYNC_OK uid=' . $uid . ' ' . json_encode($out['credited'][count($out['credited']) - 1]));
            } else {
                $out['errors'][] = $mchOrderNo . ':' . ($credit['msg'] ?? 'credit_fail');
                ppay_log_line($config, 'SYNC_FAIL ' . $mchOrderNo . ' ' . json_encode($credit));
            }
        } catch (Throwable $e) {
            $out['errors'][] = $mchOrderNo . ':' . $e->getMessage();
        }
    }
    return $out;
}

