#!/usr/bin/env python3
"""
RaxiWin instant-result patch — v6.

v5 tak: period + result ek period piche, tolerant bet gate (raw-1..raw-3), aur
draw adapter self-contained. Live site par bet lag rahi hai, lekin do problem
bach gayi:

  1) JO BET PURANE BUILD (ya running period) par lag chuki hai wo pending hi
     rehti hai: result sync sirf last 10 periods ka hota hai, aur settlement
     sirf usi issue par chalta hai jo client poll kare. Purane pending bets
     kabhi settle nahi hote -> "My History me kuch aa hi nahi raha".
     FIX: sl_settle_pending_bets() sweep (single provider sync + per-issue
     settle, na mile to instant mode me local deterministic result), jo
     My History aur GetWinLossResult par chalta hai.

  2) APP KO PURANA PERIOD/RESULT MIL SAKTA HAI: draw/history ke jawab cache ho
     jate hain (browser, host ka page cache, ya CDN). Live probe me plain URL
     ~6 minute purana payload de raha tha, jabki ?cb=<naya> fresh tha.
     FIX: sl_send() + draw adapter PHP se hi no-store/no-cache headers +
     X-Raxiwin-Build bhejta hai (taaki probe se pata chale ki live code v6 hai).

Saath me build-check v2: file hashes, opcache, pending bets, aur server se
khud HTTP self-probe (draw/history: plain URL vs fresh key) + verdict.

Usage: python3 apply_patch_v6.py <v5_site_dir> <v6_out_dir>
"""

import pathlib
import sys

BOOTSTRAPS = [
    'saas_lottery/bootstrap.php',
    'saas_lottery/bootstrap_live_v3.php',
    'saas_lottery/bootstrap_live_v4.php',
]
ADAPTER_V4 = 'draw-live-v4/index.php'

SEND_OLD = """function sl_send($payload, $httpStatus)
{
    http_response_code((int) $httpStatus);
"""

SEND_NEW = """function sl_send($payload, $httpStatus)
{
    // Draw/history/bet ke jawab cache NA hon — warna app purana period/result
    // dikhata rehta hai (browser cache, host ka page cache ya CDN ki purani copy).
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Raxiwin-Build: v6');
    }
    http_response_code((int) $httpStatus);
"""

SWEEP_FUNCTIONS = '''/**
 * Provider history ko page-by-page dekh kar kisi ek issue ka ASLI result
 * dhoondhta hai (top-10 se purane periods bhi), aur DB me save kar deta hai.
 * Mil gaya to row return, warna null (tab hi local fallback chalta hai).
 */
function sl_provider_result_lookup($gameCode, $issue, $maxPages = 6)
{
    $issue = (string) $issue;
    if ($issue === '' || !function_exists('sl_provider_history')) {
        return null;
    }
    for ($page = 1; $page <= max(1, (int) $maxPages); $page++) {
        $payload = null;
        try {
            $payload = sl_provider_history($gameCode, $page, 10);
        } catch (Throwable $e) {
            error_log('[result-lookup] page ' . $page . ': ' . $e->getMessage());
        }
        $list = isset($payload['data']['list']) && is_array($payload['data']['list']) ? $payload['data']['list'] : array();
        if (!$list) {
            return null;
        }
        try {
            sl_save_and_settle_results($gameCode, $list);
        } catch (Throwable $e) {
            error_log('[result-lookup save] ' . $e->getMessage());
        }
        $row = sl_result_row($gameCode, $issue);
        if ($row) {
            return $row;
        }
        // Page ki sabse purani row bhi is issue se purani hai? Aage dhoondhna bekaar.
        $oldest = (string) ($list[count($list) - 1]['issueNumber'] ?? '');
        if ($oldest !== '' && strcmp($oldest, $issue) <= 0) {
            return null;
        }
    }
    return null;
}

/**
 * Ek issue ka saved result row (provider/admin ka final number).
 */
function sl_result_row($gameCode, $issue)
{
    global $conn;
    $stmt = $conn->prepare('SELECT issue_number,premium,color FROM saas_lottery_results WHERE game_code=? AND issue_number=? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $gameCode, $issue);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? $row : null;
}

/**
 * Pending bets ka sweep: jinka result already aa chuka hai (provider feed ya
 * admin override), unko turant settle kar do. Purane build par lagayi gayi bets
 * aur running period par lagayi bets isi se settle hoti hain, isliye My History
 * me win/loss dikhta hai.
 *
 * Speed: provider sync ek hi baar (per-issue network call nahi), baaki settle
 * DB se. Instant mode me, jinka result DB me nahi mila unke liye local
 * deterministic result use hota hai — wahi number jo bet ke waqt decide hota hai.
 */
function sl_settle_pending_bets($gameCode, $limit = 200)
{
    global $conn;
    if (!app_setting_bool('auto_settlement_enabled', true)) {
        return 0;
    }
    $limit = max(1, min(500, (int) $limit));

    $issues = array();
    $stmt = $conn->prepare("SELECT DISTINCT issue_number FROM saas_lottery_bets WHERE game_code=? AND status='pending' ORDER BY issue_number DESC LIMIT " . $limit);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $gameCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $issues[] = (string) $row['issue_number'];
    }
    $stmt->close();
    if (!$issues) {
        return 0;
    }

    // Provider ka latest result ek hi baar laao (per-issue network call bachao).
    try {
        sl_sync_results($gameCode);
    } catch (Throwable $e) {
        error_log('[settle-sweep sync] ' . $gameCode . ': ' . $e->getMessage());
    }

    $settled = 0;
    foreach ($issues as $issue) {
        $row = sl_result_row($gameCode, $issue);
        if (!$row) {
            // Purane periods ka asli result provider ki paging se le aao —
            // fabricated number sirf aakhri fallback hona chahiye.
            $row = sl_provider_result_lookup($gameCode, $issue);
        }
        if (!$row) {
            if (!sl_instant_result_enabled($gameCode)) {
                continue; // provider ne abhi publish nahi kiya, intezaar karo
            }
            try {
                $row = sl_local_result($gameCode, $issue);
            } catch (Throwable $e) {
                $row = null;
            }
            if (!is_array($row) || !isset($row['issueNumber'])) {
                continue;
            }
        }
        try {
            sl_settle_issue($gameCode, $issue, $row);
            $settled++;
        } catch (Throwable $e) {
            error_log('[settle-sweep] ' . $gameCode . ' ' . $issue . ': ' . $e->getMessage());
        }
    }
    return $settled;
}

'''

RECORD_OLD = """    if ($gameCode !== '') {
        try {
            sl_sync_results($gameCode);
        } catch (Throwable $ignored) {
        }
    }
"""

RECORD_NEW = """    if ($gameCode !== '') {
        try {
            sl_sync_results($gameCode);
        } catch (Throwable $ignored) {
        }
        // Pending bets jo already-decided periods ke hain, abhi settle kar do —
        // isi se My History me win/loss turant dikhta hai (purane build ki bets
        // include). Kabhi fail ho to history phir bhi load ho jaye.
        try {
            sl_settle_pending_bets($gameCode);
        } catch (Throwable $ignored) {
        }
    }
"""

WINLOSS_OLD = """        if ($issue !== '' && sl_instant_result_enabled($gameCode)) {
            sl_instant_settle($gameCode, $issue);
        }
    }
"""

WINLOSS_NEW = """        if ($issue !== '' && sl_instant_result_enabled($gameCode)) {
            sl_instant_settle($gameCode, $issue);
        }
        // Baaki pending bets bhi settle kar do (issue alag ho to bhi).
        try {
            sl_settle_pending_bets($gameCode);
        } catch (Throwable $ignored) {
        }
    }
"""

PLACE_BET_ANCHOR = 'function sl_place_bet($user, $input)\n'

ADAPTER_REQUIRE_OLD = "require_once dirname(__DIR__) . '/saas_lottery/bootstrap_live_v4.php';\n"

ADAPTER_REQUIRE_NEW = """require_once dirname(__DIR__) . '/saas_lottery/bootstrap_live_v4.php';

// Caching band: draw/history ke jawab kabhi purani copy na hon (browser, host
// ka page cache ya CDN). With the bootstrap loaded this is also set by sl_send(),
// but keep it here so the adapter never serves cacheable JSON even on an older
// bootstrap.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Raxiwin-Build: v6');
}
"""


def replace_once(text, old, new, label):
    if text.count(old) != 1:
        raise SystemExit(f'ABORT [{label}]: expected exactly 1 match, found {text.count(old)}')
    return text.replace(old, new)


def patch_bootstrap(text):
    text = replace_once(text, SEND_OLD, SEND_NEW, 'sl_send headers')
    text = replace_once(text, PLACE_BET_ANCHOR, SWEEP_FUNCTIONS + PLACE_BET_ANCHOR, 'sweep functions')
    text = replace_once(text, RECORD_OLD, RECORD_NEW, 'sl_record_page sweep')
    text = replace_once(text, WINLOSS_OLD, WINLOSS_NEW, 'sl_win_loss sweep')
    if text.count('sl_settle_pending_bets($gameCode)') != 2:
        raise SystemExit('ABORT [bootstrap]: sweep wiring wrong')
    return text


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    src = pathlib.Path(sys.argv[1])
    dst = pathlib.Path(sys.argv[2])

    for rel in BOOTSTRAPS:
        text = (src / rel).read_text()
        if 'sl_instant_bet_candidates' not in text:
            raise SystemExit(f'ABORT: {rel} is not the v5 output')
        out = patch_bootstrap(text)
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(out)
        print(f'patched bootstrap {rel} (settle sweep + no-store headers)')

    for rel in ('draw/index.php', 'draw-live-v3/index.php'):
        text = (src / rel).read_text()
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)
        print(f'copied adapter {rel} (unchanged)')

    text = (src / ADAPTER_V4).read_text()
    out = replace_once(text, ADAPTER_REQUIRE_OLD, ADAPTER_REQUIRE_NEW, 'adapter no-store headers')
    target = dst / ADAPTER_V4
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(out)
    print(f'patched adapter {ADAPTER_V4} (no-store headers)')

    checker = pathlib.Path(__file__).resolve().parent / 'raxiwin-build-check.php'
    if not checker.is_file():
        raise SystemExit('ABORT: raxiwin-build-check.php missing (patcher ke folder me rakho)')
    (dst / 'raxiwin-build-check.php').write_text(checker.read_text())
    print('added raxiwin-build-check.php (v2: self-probe + pending bets)')
    print('v6 patch applied OK')


if __name__ == '__main__':
    main()
