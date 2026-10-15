<?php
/**
 * Uncached authoritative SaaS draw adapter.
 *
 * This versioned route avoids stale PHP/HTTP caches from older /draw builds.
 */
require_once dirname(__DIR__) . '/saas_lottery/bootstrap_live_v3.php';

function dlv3_history_with_boundary_wait($gameCode, $input)
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
    return $data;
}

try {
    sl_install_schema();
    $gameCode = isset($_GET['gameCode']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['gameCode']) : '';
    $lottery = isset($_GET['lottery']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['lottery']) : '';
    $game = sl_game_config($gameCode);
    if (!$game || (string) $game['lottery'] !== $lottery) {
        sl_fail(7, 'Unsupported game code', 7, 404);
    }

    if (!empty($_GET['history'])) {
        $input = $_GET;
        $input['pageNo'] = isset($_GET['pageNo']) ? max(1, (int) $_GET['pageNo']) : 1;
        $input['pageSize'] = 10;
        $data = dlv3_history_with_boundary_wait($gameCode, $input);
        $data['totalPage'] = 50;
        $data['totalCount'] = 500;
        sl_send(array('code' => 0, 'msg' => 'success', 'data' => $data), 200);
    }

    $payload = sl_provider_current($gameCode);
    if (!$payload) {
        sl_fail(503, 'Real current draw is temporarily unavailable', 503, 503);
    }
    // Instant-result mode: game ka period label ek period piche (settlement canonical).
    $payload = sl_display_previous_current($gameCode, $payload);
    sl_send($payload, 200);
} catch (Throwable $e) {
    error_log('[draw-live-v3] ' . $e->getMessage());
    sl_fail(500, 'Real draw service is temporarily unavailable', 500, 500);
}
