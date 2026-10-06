<?php
/**
 * Local wallet row helpers used by Rhinopay credit.
 */
if (!function_exists('wallet_ensure_user_row')) {
    function wallet_ensure_user_row($conn, int $uid): bool
    {
        if (!$conn instanceof mysqli || $uid <= 0) {
            return false;
        }
        $uidStr = (string) $uid;
        $sel = $conn->prepare('SELECT balakedara FROM shonu_kaichila WHERE balakedara=? LIMIT 1');
        if (!$sel) {
            return false;
        }
        $sel->bind_param('s', $uidStr);
        $sel->execute();
        $res = $sel->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $sel->close();
        if ($row) {
            return true;
        }
        $now = date('Y-m-d H:i:s');
        $zero = '0';
        $ins = $conn->prepare(
            'INSERT INTO shonu_kaichila (balakedara, motta, bonus, dinankavannuracisi) VALUES (?, ?, ?, ?)'
        );
        if (!$ins) {
            return false;
        }
        $ins->bind_param('ssss', $uidStr, $zero, $zero, $now);
        $ok = $ins->execute();
        $ins->close();
        return (bool) $ok;
    }
}

if (!function_exists('wallet_credit_motta')) {
    function wallet_credit_motta($conn, int $uid, float $amount): bool
    {
        if (!$conn instanceof mysqli || $uid <= 0 || $amount <= 0) {
            return false;
        }
        $uidStr = (string) $uid;
        $upd = $conn->prepare('UPDATE shonu_kaichila SET motta = motta + ? WHERE balakedara=? LIMIT 1');
        if (!$upd) {
            return false;
        }
        $upd->bind_param('ds', $amount, $uidStr);
        $ok = $upd->execute() && $upd->affected_rows > 0;
        $upd->close();
        return $ok;
    }
}
