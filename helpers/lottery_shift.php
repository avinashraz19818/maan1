<?php
/**
 * lottery_shift.php — "1 period piche" wiring
 * ===========================================
 * helpers/lottery_helper.php me shiftIssueNumber() pehle se likha tha,
 * par kahin include/use hi nahi ho raha tha — isliye site upstream ke
 * saath sync (0 period piche) chal rahi thi.
 *
 * Ye file usko wire karti hai: upstream se aaye har JSON me jahan bhi
 * issueNumber ho, us par offset laga deti hai.
 *
 * Offset:  helpers/period_config.php  ya  helpers/period_offset.txt
 *          (default -1  =>  1 period piche)
 */

require_once __DIR__ . '/lottery_helper.php';
require_once __DIR__ . '/period_config.php';

if (!function_exists('ls_issue_fields')) {
    /** Ye fields issue number rakhte hain (JSON me kahin bhi nested ho). */
    function ls_issue_fields(): array
    {
        return ['issueNumber', 'issue', 'issueNo', 'issueId', 'period', 'periodNo'];
    }
}

if (!function_exists('ls_time_fields')) {
    function ls_time_fields(): array
    {
        return ['startTime', 'endTime', 'openTime', 'closeTime', 'drawTime', 'saleEndTime'];
    }
}

if (!function_exists('ls_shift_issue')) {
    function ls_shift_issue($issue, string $gameCode = '')
    {
        if (!is_string($issue) && !is_numeric($issue)) {
            return $issue;
        }
        $s = trim((string) $issue);
        if ($s === '' || !preg_match('/^\d+$/', $s)) {
            return $issue;                      // sirf numeric issue shift hoti hai
        }
        $offset = period_offset();
        if ($offset === 0) {
            return $issue;
        }
        return shiftIssueNumber($s, $offset, $gameCode);
    }
}

if (!function_exists('ls_shift_node')) {
    /**
     * Recursively har issue-field par offset lagao.
     * Time fields sirf tab shift hote hain jab PERIOD_SHIFT_TIMES = 1.
     */
    function ls_shift_node($node, string $gameCode = '', int $timeShiftMs = 0)
    {
        if (!is_array($node)) {
            return $node;
        }

        foreach ($node as $k => $v) {
            if (is_string($k) && in_array($k, ls_issue_fields(), true)) {
                $node[$k] = ls_shift_issue($v, $gameCode);
            } elseif (is_string($k) && $timeShiftMs !== 0 && in_array($k, ls_time_fields(), true) && is_numeric($v)) {
                $node[$k] = (int) $v + $timeShiftMs;
            } else {
                $node[$k] = ls_shift_node($v, $gameCode, $timeShiftMs);
            }
        }

        return $node;
    }
}

if (!function_exists('ls_game_code_from_path')) {
    function ls_game_code_from_path(string $path): string
    {
        // /webapi/kv/issue/WinGo_30S | /WinGo/WinGo_30S.json | /WinGo/WinGo_30S/GetHistoryIssuePage
        $path  = preg_replace('#\.json$#i', '', (string) $path);
        $parts = array_values(array_filter(explode('/', $path), 'strlen'));
        if (!$parts) {
            return 'WinGo_1M';
        }

        $last = end($parts);
        if (is_string($last) && preg_match('/^[A-Za-z0-9_]+$/', $last) && strpos($last, '_') !== false) {
            return $last;
        }

        foreach (array_reverse($parts) as $p) {
            if (preg_match('/^(WinGo|TrxWinGo|VideoWinGo|K3|D5|5D|MotoRace|MotoRacing)_[A-Za-z0-9]+$/i', $p)) {
                return $p;
            }
        }

        return 'WinGo_1M';
    }
}

if (!function_exists('ls_shift_json_body')) {
    /**
     * @param string $body  raw upstream JSON
     * @param string $path  request path (game code nikalne ke liye)
     * @return string       shifted JSON (ya original agar parse na ho)
     */
    function ls_shift_json_body(string $body, string $path = ''): string
    {
        $body = trim($body);
        if ($body === '' || ($body[0] !== '{' && $body[0] !== '[')) {
            return $body;                       // JSON nahi hai, jaisa hai waise bhej do
        }
        if (period_offset() === 0) {
            return $body;                       // shift off
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || $data === null) {
            return $body;                       // parse fail -> original
        }

        $gameCode    = ls_game_code_from_path($path);
        $interval    = getGameIntervalSeconds($gameCode);
        $timeShiftMs = PERIOD_SHIFT_TIMES ? (period_offset() * $interval * 1000) : 0;

        $data = ls_shift_node($data, $gameCode, $timeShiftMs);

        $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($out === false) ? $body : $out;
    }
}
