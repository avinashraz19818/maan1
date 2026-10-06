<?php
/**
 * Shared deposit credit side-effects for every gateway (PPay, USDT, Rhinopay).
 * Gateways parse their own notify, credit thevani + wallet, then call these hooks.
 */

if (!function_exists('deposit_log_line')) {
    function deposit_log_line(array $config, string $msg): void
    {
        $path = (string)($config['LOG_FILE'] ?? '');
        if ($path === '') {
            return;
        }
        @file_put_contents($path, date('c') . ' | ' . $msg . PHP_EOL, FILE_APPEND);
    }
}

/**
 * Atomically claim deposit-bonus slot.
 * @return bool|null true = claimed; false = skip; null = column missing — run bonus without claim
 */
if (!function_exists('deposit_claim_bonus_slot')) {
    function deposit_claim_bonus_slot(mysqli $conn, string $orderId): ?bool
    {
        $upd = $conn->prepare(
            "UPDATE thevani SET bonus_processed = 1
             WHERE dharavahi = ? AND sthiti = '1' AND IFNULL(bonus_processed, 0) = 0
             LIMIT 1"
        );
        if (!$upd) {
            if ((int)$conn->errno === 1054) {
                return null;
            }
            return false;
        }
        $upd->bind_param('s', $orderId);
        if (!$upd->execute()) {
            $errno = (int)$upd->errno;
            $upd->close();
            if ($errno === 1054) {
                return null;
            }
            return false;
        }
        $ok = $upd->affected_rows > 0;
        $upd->close();
        return $ok;
    }
}

if (!function_exists('deposit_release_bonus_slot')) {
    function deposit_release_bonus_slot(mysqli $conn, string $orderId): void
    {
        $upd = $conn->prepare('UPDATE thevani SET bonus_processed = 0 WHERE dharavahi = ? LIMIT 1');
        if ($upd) {
            $upd->bind_param('s', $orderId);
            $upd->execute();
            $upd->close();
        }
    }
}

if (!function_exists('deposit_run_deposit_bonus')) {
    function deposit_run_deposit_bonus(
        mysqli $conn,
        string $dharavahi,
        int $uid,
        float $amt,
        $sid,
        ?array $config = null
    ): void {
        $cfg = is_array($config) ? $config : [];
        $claim = deposit_claim_bonus_slot($conn, $dharavahi);
        if ($claim === false) {
            deposit_log_line($cfg, 'bonus_skip already_processed order=' . $dharavahi);
            return;
        }

        $depositKey = ($sid !== null && $sid !== '' && (string)$sid !== '0')
            ? (string)$sid
            : $dharavahi;

        $referBonus = __DIR__ . '/../includes/refer_bonus.php';
        if (!is_file($referBonus)) {
            if ($claim === true) {
                deposit_release_bonus_slot($conn, $dharavahi);
            }
            deposit_log_line($cfg, 'refer_bonus missing: ' . $referBonus);
            return;
        }

        try {
            require_once $referBonus;
            if (!function_exists('processDepositBonus')) {
                if ($claim === true) {
                    deposit_release_bonus_slot($conn, $dharavahi);
                }
                deposit_log_line($cfg, 'processDepositBonus missing');
                return;
            }
            $res = processDepositBonus($uid, $amt, $depositKey, $conn);
            if ($res === false && $claim === true) {
                deposit_release_bonus_slot($conn, $dharavahi);
                deposit_log_line($cfg, 'bonus_released after false order=' . $dharavahi);
            }
        } catch (Throwable $e) {
            if ($claim === true) {
                deposit_release_bonus_slot($conn, $dharavahi);
            }
            deposit_log_line($cfg, 'refer_bonus: ' . $e->getMessage());
        }
    }
}

if (!function_exists('thevani_ensure_credited_at')) {
    function thevani_ensure_credited_at(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $q = @$conn->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'thevani'
               AND COLUMN_NAME = 'credited_at'
             LIMIT 1"
        );
        if ($q && $q->num_rows > 0) {
            $ok = true;
            return true;
        }
        $ok = (bool)@$conn->query('ALTER TABLE thevani ADD COLUMN credited_at DATETIME NULL DEFAULT NULL');
        return $ok;
    }
}

if (!function_exists('thevani_credit_update_sql')) {
    function thevani_credit_update_sql(mysqli $conn, string $where): string
    {
        @$conn->query("SET time_zone = '+05:30'");
        $set = 'ullekha=?,sthiti=1';
        if (thevani_ensure_credited_at($conn)) {
            $set .= ',credited_at=NOW()';
        }
        return 'UPDATE thevani SET ' . $set . ' WHERE ' . $where;
    }
}

if (!function_exists('deposit_run_paid_retry_hooks')) {
    /**
     * Already-paid notify/admin retry: heal deposit flags + premium, never re-credit wallet.
     * Deposit bonus stays claim-slot protected.
     */
    function deposit_run_paid_retry_hooks(
        mysqli $conn,
        array $config,
        int $uid,
        float $creditAmt,
        $sid,
        string $dharavahi
    ): void {
        try {
            require_once __DIR__ . '/../deepanshu/helpers/deposit_gate_helper.php';
            deposit_gate_heal_from_thevani($conn, $uid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'deposit_gate_heal: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/invitation_bonus_helper.php';
            invitation_bonus_on_invitee_credit($conn, $uid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'invitation_bonus_heal: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/affiliate_helper.php';
            affiliate_premium_try_user($conn, $uid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'affiliate_premium_retry: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/rex_agent_deposit_hook.php';
            rex_agent_try_record_deposit($conn, $uid, $creditAmt, (string)$sid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'rex_agent_retry: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/commission_helper.php';
            commission_calculate_for_confirmed_deposit($conn, $uid, $creditAmt, (string)$sid, date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            deposit_log_line($config, 'commission_retry: ' . $e->getMessage());
        }
        deposit_run_deposit_bonus($conn, $dharavahi, $uid, $creditAmt, $sid, $config);
    }
}

if (!function_exists('deposit_run_post_credit_hooks')) {
    function deposit_run_post_credit_hooks(
        mysqli $conn,
        array $config,
        int $uid,
        float $creditAmt,
        int $sid,
        string $dharavahi,
        string $depDate = ''
    ): void {
        try {
            require_once __DIR__ . '/../deepanshu/helpers/deposit_gate_helper.php';
            mark_user_deposit_success($conn, $uid, $creditAmt);
        } catch (Throwable $e) {
            deposit_log_line($config, 'deposit_gate: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/invitation_bonus_helper.php';
            invitation_bonus_on_invitee_credit($conn, $uid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'invitation_bonus_heal: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/affiliate_helper.php';
            affiliate_premium_try_user($conn, $uid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'affiliate_premium: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/deposit_bonus.php';
            deposit_bonus_apply($conn, $uid, $sid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'wagering: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/rex_agent_deposit_hook.php';
            rex_agent_try_record_deposit($conn, $uid, $creditAmt, (string)$sid);
        } catch (Throwable $e) {
            deposit_log_line($config, 'rex_agent: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/commission_helper.php';
            $dt = $depDate !== '' ? $depDate : date('Y-m-d H:i:s');
            commission_calculate_for_confirmed_deposit($conn, $uid, $creditAmt, (string)$sid, $dt);
        } catch (Throwable $e) {
            deposit_log_line($config, 'commission: ' . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../deepanshu/helpers/invited_wheel_helper.php';
            invited_wheel_grant_spin_for_referral_deposit($conn, $uid, (string)$sid, $creditAmt, INVITED_WHEEL_MIN_DEPOSIT);
        } catch (Throwable $e) {
            deposit_log_line($config, 'invite_spin: ' . $e->getMessage());
        }
        deposit_run_deposit_bonus($conn, $dharavahi, $uid, $creditAmt, $sid, $config);
    }
}
