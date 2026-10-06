<?php
/**
 * Shared USDT→INR helpers for pay/*.php and admin deposit views.
 * Rate source matches deepanshu/api/webapi/GetUserInfo.php (tbl_pg.value = 'usdt').
 */
if (!function_exists('pay_get_usdt_inr_rate')) {
    /**
     * @param mysqli|null $conn
     */
    function pay_get_usdt_inr_rate($conn): float
    {
        if ($conn instanceof mysqli) {
            $r = @mysqli_query($conn, "SELECT rate FROM tbl_pg WHERE value = 'usdt' LIMIT 1");
            if ($r && $ro = mysqli_fetch_assoc($r)) {
                $v = (float) ($ro['rate'] ?? 0);
                if ($v > 0) {
                    return $v;
                }
            }
        }
        return 94.0;
    }

    /**
     * @param mysqli|null $conn
     */
    function pay_usdt_to_inr($conn, $usdt_amount): float
    {
        return round((float) $usdt_amount * pay_get_usdt_inr_rate($conn), 2);
    }

    /**
     * True when recharge row is USDT channel.
     * usdt.php stores mula as USDT-Bonus / USDT-NoBonus; older rows may be "usdt".
     */
    function pay_thevani_row_is_usdt(array $row): bool
    {
        $mula = isset($row['mula']) ? trim((string) $row['mula']) : '';
        $meta = isset($row['ekikrtapavati']) ? trim((string) $row['ekikrtapavati']) : '';
        $blob = $mula . ' ' . $meta;
        if ($mula !== '' && strcasecmp($mula, 'usdt') === 0) {
            return true;
        }
        if (preg_match('/^USDT(-|$)/i', $mula) || stripos($mula, 'USDT-') !== false) {
            return true;
        }
        if (stripos($blob, 'usdt=') !== false || stripos($blob, 'gateway=USDT') !== false) {
            return true;
        }
        return false;
    }

    /**
     * INR amount credited for this row + optional USDT face for display.
     * Prefer ekikrtapavati usdt=/inr=/rate= (new USDT rows: motta is INR).
     * Legacy motta-as-USDT-count only when those keys are missing.
     *
     * @return array{inr: float, usdt: float|null, legacy_raw_usdt: bool}
     */
    function pay_thevani_usdt_inr_parts($conn, array $row): array
    {
        $m = (float) ($row['motta'] ?? 0);
        if (!pay_thevani_row_is_usdt($row)) {
            return ['inr' => $m, 'usdt' => null, 'legacy_raw_usdt' => false];
        }

        $metaSrc = (string) ($row['ekikrtapavati'] ?? '') . ' ' . (string) ($row['mula'] ?? '');
        $rate = pay_get_usdt_inr_rate($conn);
        if (preg_match('/rate=([0-9.]+)/', $metaSrc, $rm)) {
            $parsedRate = (float) $rm[1];
            if ($parsedRate > 0) {
                $rate = $parsedRate;
            }
        }

        $metaUsdt = null;
        $metaInr = null;
        if (preg_match('/usdt=([0-9.]+)/', $metaSrc, $mu)) {
            $metaUsdt = (float) $mu[1];
        }
        if (preg_match('/inr=([0-9.]+)/', $metaSrc, $mi)) {
            $metaInr = (float) $mi[1];
        }

        if ($metaInr !== null && $metaInr > 0) {
            $usdt = $metaUsdt;
            if ($usdt === null && $rate > 0) {
                $usdt = round($metaInr / $rate, 2);
            }
            return ['inr' => $metaInr, 'usdt' => $usdt, 'legacy_raw_usdt' => false];
        }

        if ($metaUsdt !== null && $metaUsdt > 0) {
            $inr = $m > 0 ? $m : ($rate > 0 ? round($metaUsdt * $rate, 2) : $m);
            return ['inr' => $inr, 'usdt' => $metaUsdt, 'legacy_raw_usdt' => false];
        }

        // Heuristic: small whole number in motta for USDT row = old bug (stored USDT count)
        if ($rate > 0 && $m > 0 && $m < 1000 && $m === (float) (int) $m && $m <= 500) {
            return [
                'inr' => round($m * $rate, 2),
                'usdt' => $m,
                'legacy_raw_usdt' => true,
            ];
        }

        $usdt = $rate > 0 && $m > 0 ? round($m / $rate, 2) : null;

        return ['inr' => $m, 'usdt' => $usdt, 'legacy_raw_usdt' => false];
    }
}
