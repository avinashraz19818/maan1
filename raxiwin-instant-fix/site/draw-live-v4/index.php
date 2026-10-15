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
function dlv4_history_with_boundary_wait($gameCode, $input)
{
    $expectedIssue = '';
    $pageNo = max(1, (int)($input['pageNo'] ?? 1));
    if ($pageNo === 1) {
        $current = sl_provider_current($gameCode);
        if (is_array($current) && isset($current['previous']['issueNumber'])) {
            $expectedIssue = (string)$current['previous']['issueNumber'];
        }
        if ($expectedIssue === '') {
            $local = sl_local_current($gameCode);
            $expectedIssue = (string)($local['previous']['issueNumber'] ?? '');
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
        // Page 1 par ek extra row maangte hain, kyunki abhi bet ke liye khula
        // period response se hata diya jata hai.
        $input['pageSize'] = $input['pageNo'] === 1 ? 11 : 10;
        // Preserve the exact-period admin overlay and settlement, then hide the
        // periods that are still open for betting.
        $resultSet = dlv4_history_with_boundary_wait($gameCode, $input);
        $data = isset($resultSet['data']) && is_array($resultSet['data']) ? $resultSet['data'] : array();
        if (isset($data['list']) && is_array($data['list'])) {
            $data['list'] = raxiwin_display_own_period_results($gameCode, $data['list'], (string) ($resultSet['ceiling'] ?? ''));
            if (count($data['list']) > 10) {
                $data['list'] = array_slice($data['list'], 0, 10);
            }
        }
        $data['totalPage'] = 50;
        $data['totalCount'] = 500;
        sl_send(array('code' => 0, 'msg' => 'success', 'data' => $data), 200);
    }

    $payload = sl_provider_current($gameCode);
    if (!$payload) {
        sl_fail(503, 'Real current draw is temporarily unavailable', 503, 503);
    }
    sl_send($payload, 200);
} catch (Throwable $e) {
    error_log('[draw-live-v4] ' . $e->getMessage());
    sl_fail(500, 'Real draw service is temporarily unavailable', 500, 500);
}
