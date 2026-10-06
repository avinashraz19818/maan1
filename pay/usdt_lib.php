<?php
/**
 * Shared USDT / admin-pending deposit credit.
 * Mirrors pay/ppay_credit_order + install/pay sat_credit_order pattern.
 * Do NOT require usdt.php (order-create page) from admin.
 */

require_once __DIR__ . '/ppay_lib.php';

function usdt_credit_log(string $msg): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir . '/usdt_credit.log';
    @file_put_contents($path, date('c') . ' | ' . $msg . PHP_EOL, FILE_APPEND);
}

/**
 * Credit a pending thevani row (admin Approve / manual).
 * $orderKey = dharavahi OR numeric shonu.
 * Optional $payRef written to ullekha on success.
 *
 * @return array{credited:bool,already:bool,found:bool,uid:int,amt:float,sid:int,msg:string,dharavahi:string}
 */
function usdt_credit_order(mysqli $conn, string $orderKey, string $payRef = ''): array
{
    $result = [
        'credited' => false,
        'already' => false,
        'found' => false,
        'uid' => 0,
        'amt' => 0.0,
        'sid' => 0,
        'msg' => '',
        'dharavahi' => '',
    ];
    $orderKey = trim($orderKey);
    if ($orderKey === '') {
        $result['msg'] = 'empty';
        return $result;
    }

    $cfg = ['LOG_FILE' => __DIR__ . '/logs/usdt_credit.log'];

    $conn->begin_transaction();

    $depSid = null;
    $balakedara = null;
    $motta = null;
    $sthiti = null;
    $depDate = null;
    $dharavahi = null;

    if (ctype_digit($orderKey)) {
        $sidInt = (int)$orderKey;
        $stmt = $conn->prepare(
            'SELECT shonu,balakedara,motta,sthiti,dinankavannuracisi,dharavahi
             FROM thevani WHERE shonu=? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            $conn->rollback();
            $result['msg'] = 'prepare_fail';
            return $result;
        }
        $stmt->bind_param('i', $sidInt);
    } else {
        $stmt = $conn->prepare(
            'SELECT shonu,balakedara,motta,sthiti,dinankavannuracisi,dharavahi
             FROM thevani WHERE dharavahi=? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            $conn->rollback();
            $result['msg'] = 'prepare_fail';
            return $result;
        }
        $stmt->bind_param('s', $orderKey);
    }

    $stmt->execute();
    $stmt->bind_result($depSid, $balakedara, $motta, $sthiti, $depDate, $dharavahi);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || $depSid === null) {
        $conn->rollback();
        $result['msg'] = 'not_found';
        usdt_credit_log('CREDIT NOT FOUND key=' . $orderKey);
        return $result;
    }

    $matchedOrder = (string)$dharavahi;
    $uid = (int)$balakedara;
    $sid = (int)$depSid;
    $creditAmt = (float)$motta;
    $dt = (string)($depDate ?: '');

    $result['found'] = true;
    $result['uid'] = $uid;
    $result['amt'] = $creditAmt;
    $result['sid'] = $sid;
    $result['dharavahi'] = $matchedOrder;

    if ((int)$sthiti === 1) {
        $conn->commit();
        $result['already'] = true;
        $result['msg'] = 'already_paid';
        deposit_run_paid_retry_hooks($conn, $cfg, $uid, $creditAmt, $sid, $matchedOrder);
        return $result;
    }

    if ((int)$sthiti !== 0) {
        $conn->rollback();
        $result['msg'] = 'not_pending';
        return $result;
    }

    if (!wallet_ensure_user_row($conn, $uid)) {
        $conn->rollback();
        $result['msg'] = 'wallet_row_fail';
        usdt_credit_log("WALLET_CREDIT_FAIL no_wallet_row key={$orderKey} uid={$uid}");
        return $result;
    }

    if (!wallet_credit_motta($conn, $uid, $creditAmt)) {
        $conn->rollback();
        $result['msg'] = 'wallet_update_fail';
        usdt_credit_log("WALLET_CREDIT_FAIL update key={$orderKey} uid={$uid}");
        return $result;
    }

    $ref = $payRef !== '' ? $payRef : $matchedOrder;
    $u = $conn->prepare(thevani_credit_update_sql($conn, 'shonu=? AND sthiti=0'));
    if (!$u) {
        $conn->rollback();
        $result['msg'] = 'prepare_fail';
        return $result;
    }
    $u->bind_param('si', $ref, $sid);
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

    ppay_run_post_credit_hooks($conn, $cfg, $uid, $creditAmt, $sid, $matchedOrder, $dt);

    $result['credited'] = true;
    $result['msg'] = 'credited';
    usdt_credit_log("CREDITED {$matchedOrder} sid={$sid} uid={$uid} amt={$creditAmt}");
    return $result;
}
