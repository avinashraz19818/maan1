<?php
/**
 * Lottery helper for 1-period-behind logic (WinGo / TrxWinGo / VideoWinGo).
 * Shifts the live issue and draw results by 1 period so that this site
 * runs 1 period behind upstream API.
 */

if (!function_exists('getGameIntervalSeconds')) {
    function getGameIntervalSeconds(string $gameCode): int
    {
        if (stripos($gameCode, '30S') !== false) {
            return 30;
        }
        if (stripos($gameCode, '3M') !== false) {
            return 180;
        }
        if (stripos($gameCode, '5M') !== false) {
            return 300;
        }
        if (stripos($gameCode, '10M') !== false) {
            return 600;
        }
        return 60; // default for 1M
    }
}

if (!function_exists('shiftIssueNumber')) {
    function shiftIssueNumber(string $issue, int $offset = -1, string $gameCode = 'WinGo_1M'): string
    {
        $issue = trim($issue);
        if ($issue === '') {
            return $issue;
        }
        if (strlen($issue) < 17) {
            if ($offset < 0) {
                return (string) bcsub($issue, (string) abs($offset));
            } else {
                return (string) bcadd($issue, (string) $offset);
            }
        }

        $datePart = substr($issue, 0, 8);
        $prefix = substr($issue, 8, 5);
        $index = (int) substr($issue, 13);

        $totalPeriods = 1440;
        if (stripos($gameCode, '30S') !== false) {
            $totalPeriods = 2880;
        } elseif (stripos($gameCode, '3M') !== false) {
            $totalPeriods = 480;
        } elseif (stripos($gameCode, '5M') !== false) {
            $totalPeriods = 288;
        } elseif (stripos($gameCode, '10M') !== false) {
            $totalPeriods = 144;
        }

        $newIndex = $index + $offset;

        if ($newIndex >= 1 && $newIndex <= $totalPeriods) {
            return $datePart . $prefix . str_pad((string) $newIndex, 4, '0', STR_PAD_LEFT);
        }

        if ($newIndex < 1) {
            $dt = DateTime::createFromFormat('Ymd', $datePart, new DateTimeZone('UTC'));
            if ($dt) {
                $dt->modify('-1 day');
                $prevDate = $dt->format('Ymd');
            } else {
                $prevDate = $datePart;
            }
            $adjustedIndex = $totalPeriods + $newIndex;
            return $prevDate . $prefix . str_pad((string) $adjustedIndex, 4, '0', STR_PAD_LEFT);
        }

        if ($newIndex > $totalPeriods) {
            $dt = DateTime::createFromFormat('Ymd', $datePart, new DateTimeZone('UTC'));
            if ($dt) {
                $dt->modify('+1 day');
                $nextDate = $dt->format('Ymd');
            } else {
                $nextDate = $datePart;
            }
            $adjustedIndex = $newIndex - $totalPeriods;
            return $nextDate . $prefix . str_pad((string) $adjustedIndex, 4, '0', STR_PAD_LEFT);
        }

        return $issue;
    }
}

if (!function_exists('updateGameHistoryCache')) {
    function updateGameHistoryCache(string $gameCode, array $upstreamList): array
    {
        $cacheDir = __DIR__ . '/../webapi/kv';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cleanGame = preg_replace('/[^A-Za-z0-9_]/', '', $gameCode);
        $cacheFile = $cacheDir . '/history_' . $cleanGame . '.json';

        $cachedItems = [];
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false && $raw !== '') {
                $existing = json_decode($raw, true);
                if (is_array($existing)) {
                    $cachedItems = $existing;
                }
            }
        }

        $seen = [];
        $merged = [];

        foreach ($upstreamList as $item) {
            if (!empty($item['issueNumber']) && !isset($seen[$item['issueNumber']])) {
                $seen[$item['issueNumber']] = true;
                $merged[] = $item;
            }
        }

        foreach ($cachedItems as $item) {
            if (!empty($item['issueNumber']) && !isset($seen[$item['issueNumber']])) {
                $seen[$item['issueNumber']] = true;
                $merged[] = $item;
            }
        }

        usort($merged, function ($a, $b) {
            return strcmp((string) ($b['issueNumber'] ?? ''), (string) ($a['issueNumber'] ?? ''));
        });

        $merged = array_slice($merged, 0, 100);

        if (!empty($merged)) {
            @file_put_contents($cacheFile, json_encode($merged), LOCK_EX);
        }

        return $merged;
    }
}

if (!function_exists('getAdjustedHistoryList')) {
    function getAdjustedHistoryList(string $gameCode, array $upstreamList, int $pageNo = 1, int $pageSize = 10): array
    {
        $merged = updateGameHistoryCache($gameCode, $upstreamList);

        $interval = getGameIntervalSeconds($gameCode);
        $now = time();
        $elapsed = $now % $interval;
        $remaining = $interval - $elapsed;

        // When remaining <= 2, round is ending and result of current issue is revealed for ball animation.
        // When remaining > 2, betting is active so current issue is hidden from history list.
        $offset = ($remaining <= 2) ? 0 : 1;
        $finalOffset = ($pageNo - 1) * $pageSize + $offset;

        $out = array_slice($merged, $finalOffset, $pageSize);
        if (empty($out) && !empty($upstreamList)) {
            $out = array_slice($upstreamList, $offset, $pageSize);
        }
        return $out;
    }
}

