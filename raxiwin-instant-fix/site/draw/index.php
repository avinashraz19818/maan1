<?php
/**
 * Same-origin draw adapter for the bundled Vue client.
 *
 * It keeps the public countdown/history contract compatible with the client,
 * while using the same source and result lock as authenticated bet settlement.
 */
require_once dirname(__DIR__) . '/saas_lottery/bootstrap.php';

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
        $input['pageSize'] = isset($_GET['pageSize']) ? min(100, max(1, (int) $_GET['pageSize'])) : 10;
        $data = sl_history_page($gameCode, $input);
        sl_send(array('code' => 0, 'msg' => 'success', 'data' => $data), 200);
    }

    $payload = sl_provider_current($gameCode);
    if (!$payload) {
        sl_fail(503, 'Current draw is temporarily unavailable', 503, 503);
    }
    sl_send($payload, 200);
} catch (Throwable $e) {
    error_log('[draw-adapter] ' . $e->getMessage());
    sl_fail(500, 'Draw service is temporarily unavailable', 500, 500);
}
