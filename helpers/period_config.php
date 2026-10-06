<?php
/**
 * PERIOD OFFSET CONFIG
 * ====================
 * Upstream (live channel) se kitne period PICHE chalna hai.
 *
 *   -1  => 1 period piche   (DEFAULT — yahi chahiye)
 *    0  => upstream ke saath sync (koi shift nahi)
 *   -2  => 2 period piche
 *
 * Number badalne ke liye: ya to yahan PERIOD_OFFSET badlo,
 * ya helpers/period_offset.txt me likh do (utna hi -1 / -2 / 0).
 * Env var PERIOD_OFFSET sabse upar priority leta hai.
 *
 * ------------------------------------------------------------------
 * PERIOD_SHIFT_TIMES
 * ------------------------------------------------------------------
 *   0 (default) => sirf issueNumber shift hoga; startTime/endTime
 *                  WAHI rahenge jo upstream ne bheje.
 *                  Isse countdown kabhi negative nahi hota.
 *
 *   1           => startTime/endTime ko bhi offset*interval se shift
 *                  karega. DHYAN: isse countdown negative ho sakta hai
 *                  (kyunki pichla period ka window ab beet chuka hota
 *                  hai). Sirf tabhi chalao jab aapko timing bhi shift
 *                  karni ho.
 */
if (!defined('PERIOD_SHIFT_TIMES')) {
    define('PERIOD_SHIFT_TIMES', 0);
}

if (!function_exists('period_offset')) {
    function period_offset(): int
    {
        $env = getenv('PERIOD_OFFSET');
        if ($env !== false && $env !== '' && preg_match('/^-?\d+$/', (string) $env)) {
            return (int) $env;
        }

        $file = __DIR__ . '/period_offset.txt';
        if (is_file($file)) {
            $raw = trim((string) @file_get_contents($file));
            if (preg_match('/^-?\d+$/', $raw)) {
                return (int) $raw;
            }
        }

        return -1;
    }
}
