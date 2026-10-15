<?php
/**
 * Uncached authoritative SaaS draw adapter.
 *
 * Public animation polling must stay independent from schema migrations and
 * wallet settlement. Authenticated API requests still persist and settle the
 * same provider results, while this route returns the real feed immediately.
 */
require_once dirname(__DIR__) . '/saas_lottery/bootstrap_live_v4.php';

/**
 * Public history rows: har period apne hi result ke saath.
 *
 * Pehle yahan har row ka issueNumber ek period PEEGHE kar diya jata tha aur
 * premium/number waise hi rehte the — matlab period X ka result label X-1 ke
 * saamne dikhta tha, isliye result ek period "aage" chalta hua lagta tha.
 *
 * Ab: label aur number dono apne period ke rehte hain (yehi official feed ka
 * pairing hai, aur yehi settlement bhi use karta hai), aur sirf woh rows hata di
 * jati hain jinka period abhi bet ke liye khula hai (issueNumber > ceiling).
 */
function raxiwin_display_own_period_results($gameCode, $list, $ceiling = '')
{
    if (!is_array($list) || !$list || strpos((string) $gameCode, 'WinGo_') !== 0) {
        return $list;
    }
    $ceiling = (string) $ceiling;
    if ($ceiling === '') {
        return $list;
    }
    $out = array();
    foreach ($list as $item) {
        $issue = isset($item['issueNumber']) ? (string) $item['issueNumber'] : '';
        if ($issue !== '' && preg_match('/^\d{8,40}$/', $issue) && strcmp($issue, $ceiling) > 0) {
            continue;
        }
        $out[] = $item;
    }
    // Safety: agar ceiling ki wajah se poori list khaali ho jaye to original hi bhejo.
    return $out ? $out : $list;
}

/**
 * Near a timer boundary the browser may ask for history a few milliseconds
 * before the provider publishes the closing result. Hold that one request for
 * a short bounded window so its response already contains the period that just
 * ended; normal history requests still return immediately.
 */
/**
 * Label-based period shift — adapter ke andar hi, taaki purane bootstrap ke
 * saath bhi draw endpoint theek chale (kisi extra function ki zaroorat nahi).
 */
function rw5_shift_issue($gameCode, $issue)
{
    $issue = (string) $issue;
    if (!preg_match('/^([0-9]{8})[0-9]{9}$/', $issue) || !function_exists('sl_interval_seconds')) {
        return '';
    }
    $interval = sl_interval_seconds($gameCode);
    $date = substr($issue, 0, 4) . '-' . substr($issue, 4, 2) . '-' . substr($issue, 6, 2);
    $sequence = (int) substr($issue, -4);
    $dayStart = strtotime($date . ' 00:00:00 UTC');
    if ($dayStart === false || $sequence < 1) {
        return '';
    }
    return (string) sl_issue_number_for_start($gameCode, $dayStart + (($sequence - 1) * $interval) - $interval);
}

/** Instant-result mode ON hai? (bootstrap helper ho to wahi, warna setting se.) */
function rw5_instant_on($gameCode)
{
    if (function_exists('sl_instant_result_enabled')) {
        return sl_instant_result_enabled($gameCode);
    }
    return strpos((string) $gameCode, 'WinGo_') === 0 && app_setting_bool('instant_result_enabled', true);
}

/** Jo label public draw me "current" ban kar jayega. */
function rw5_served_current($gameCode, $rawCurrent)
{
    $rawCurrent = (string) $rawCurrent;
    if ($rawCurrent === '' || !rw5_instant_on($gameCode)) {
        return $rawCurrent;
    }
    $shifted = rw5_shift_issue($gameCode, $rawCurrent);
    return $shifted !== '' ? $shifted : $rawCurrent;
}

function dlv4_history_with_boundary_wait($gameCode, $input)
{
    $expectedIssue = '';
    $pageNo = max(1, (int)($input['pageNo'] ?? 1));
    if ($pageNo === 1) {
        $rawCurrent = '';
        $current = sl_provider_current($gameCode);
        if (is_array($current) && isset($current['current']['issueNumber'])) {
            $rawCurrent = (string)$current['current']['issueNumber'];
        }
        if ($rawCurrent === '') {
            $local = sl_local_current($gameCode);
            $rawCurrent = (string)($local['current']['issueNumber'] ?? '');
        }
        if ($rawCurrent !== '') {
            // Jo label hum public draw me "current" bhej rahe hain.
            $served = rw5_served_current($gameCode, $rawCurrent);
            if ($served === '') {
                $served = $rawCurrent;
            }
            // App display us se ek period piche hota hai — wahi result ceiling hai.
            $expectedIssue = rw5_shift_issue($gameCode, $served);
            if ($expectedIssue === '') {
                $expectedIssue = $served;
            }
        }
    }

    $deadline = microtime(true) + 3.0;
    $data = array('list'=>array(),'pageNo'=>$pageNo,'totalPage'=>0,'totalCount'=>0);
    do {
        $data = sl_history_page($gameCode, $input);
        $data['list'] = sl_rebind_wingo_history_periods($gameCode, $data['list'] ?? array());
        $latestIssue = isset($data['list'][0]['issueNumber']) ? (string)$data['list'][0]['issueNumber'] : '';
        if ($expectedIssue === '' || ($latestIssue !== '' && strcmp($latestIssue, $expectedIssue) >= 0)) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    return array('data' => $data, 'ceiling' => $expectedIssue);
}

try {
    $gameCode = isset($_GET['gameCode']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['gameCode']) : '';
    $lottery = isset($_GET['lottery']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['lottery']) : '';

    if (strcasecmp($gameCode, 'WinGo_30S') === 0) $gameCode = 'WinGo_30S';
    elseif (strcasecmp($gameCode, 'WinGo_1M') === 0) $gameCode = 'WinGo_1M';
    elseif (strcasecmp($gameCode, 'WinGo_3M') === 0) $gameCode = 'WinGo_3M';
    elseif (strcasecmp($gameCode, 'WinGo_5M') === 0) $gameCode = 'WinGo_5M';

    $game = sl_game_config($gameCode);
    if (!$game || strcasecmp((string) $game['lottery'], $lottery) !== 0) {
        sl_fail(7, 'Unsupported game code', 7, 404);
    }

    if (!empty($_GET['history'])) {
        $input = $_GET;
        $input['pageNo'] = isset($_GET['pageNo']) ? max(1, (int) $_GET['pageNo']) : 1;
        // Har page par ek extra row maangte hain (page 1 par khula period hata
        // diya jata hai), phir 10 rows hi bhejte hain — paging continuous rehti hai.
        $input['pageSize'] = 12;
        // Preserve the exact-period admin overlay and settlement, then hide the
        // periods that are still open for betting.
        $resultSet = dlv4_history_with_boundary_wait($gameCode, $input);
        $data = isset($resultSet['data']) && is_array($resultSet['data']) ? $resultSet['data'] : array();
        if (isset($data['list']) && is_array($data['list'])) {
            $data['list'] = raxiwin_display_own_period_results($gameCode, $data['list'], (string) ($resultSet['ceiling'] ?? ''));
            if (count($data['list']) > 10) {
                $data['list'] = array_slice($data['list'], 0, 10);
            }
            // (page 1: khula period hatne ke baad bhi 10 rows, baaki pages: 10)
            $data['pageNo'] = $input['pageNo'];
        }
        $data['totalPage'] = 50;
        $data['totalCount'] = 500;
        sl_send(array('code' => 0, 'msg' => 'success', 'data' => $data), 200);
    }

    $payload = sl_provider_current($gameCode);
    if (!$payload) {
        sl_fail(503, 'Real current draw is temporarily unavailable', 503, 503);
    }
        // Instant-result mode: public period label ek period piche (settlement canonical).
    // Adapter ka apna helper — purane bootstrap ke saath bhi kaam karta hai.
    if (rw5_instant_on($gameCode) && isset($payload['current']['issueNumber'])) {
        foreach (array('previous', 'current', 'next') as $key) {
            if (!isset($payload[$key]['issueNumber'])) {
                continue;
            }
            $shiftedLabel = rw5_shift_issue($gameCode, (string) $payload[$key]['issueNumber']);
            if ($shiftedLabel !== '') {
                $payload[$key]['issueNumber'] = $shiftedLabel;
            }
        }
    }
    sl_send($payload, 200);
} catch (Throwable $e) {
    error_log('[draw-live-v4] ' . $e->getMessage());
    sl_fail(500, 'Real draw service is temporarily unavailable', 500, 500);
}
