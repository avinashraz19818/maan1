#!/usr/bin/env python3
"""
RaxiWin instant-result patch — v2 delta (history / result alignment).

Ye script v1 (instant-result) sources par chalta hai aur v2 delta lagata hai:

  1) saas_lottery/bootstrap*.php
       - `sl_instant_previous_issue()` / `sl_display_previous_current()` hata deta hai
         (draw ka label ab shift nahi hota; app naturally ek period piche chalta hai
         kyunki results table feed se ek period aage rehta hai).
       - bet gate wapas normal (jaisa site me tha).
       - bet ke response me instant win/loss tabhi jodta hai jab us period ka result
         DB me pehle se decided ho (naya helper `rw_instant_result_item`) + us bet ko
         turant settle kar deta hai (`sl_settle_issue`).
       - `sl_win_loss()` ka settle hook aur `sl_instant_settle()` waise hi rehte hain.

  2) draw-live-v4/index.php
       - `raxiwin_display_previous_periods()` (jo har row ka label ek period piche kar
         deta tha) ki jagah `raxiwin_display_own_period_results()`: har row apne hi
         period ka result dikhata hai, aur jo periods abhi bet ke liye khule hain
         (issueNumber > feed ka previous) wo history se hat jaate hain.
       - pageSize/ceiling/slice handling.

  3) draw/index.php + draw-live-v3/index.php
       - `sl_display_previous_current()` call hata deta hai (unme label shift nahi tha).

Usage:
    python3 apply_patch_v2.py <v1_sources_dir> <output_dir>

Har replacement assert karta hai ki target exactly ek baar mila ho — warna script
bina kuch likhe fail ho jata hai (safe / idempotent-ish).
"""
import pathlib
import re
import sys

BOOTSTRAPS = [
    'saas_lottery/bootstrap.php',
    'saas_lottery/bootstrap_live_v3.php',
    'saas_lottery/bootstrap_live_v4.php',
]
ADAPTERS_NO_SHIFT = ['draw/index.php', 'draw-live-v3/index.php']
ADAPTER_V4 = 'draw-live-v4/index.php'


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'ABORT [{label}]: expected exactly 1 match, found {count}')
    return text.replace(old, new)


def cut_between(text, start_marker, end_marker, label):
    start = text.find(start_marker)
    end = text.find(end_marker)
    if start == -1 or end == -1 or end <= start:
        raise SystemExit(f'ABORT [{label}]: markers not found in order')
    return text[:start] + text[end:]


# --------------------------------------------------------------- bootstraps
GATE_OLD = """    // Instant-result mode: client jis period par bet bhejta hai wo real current se
    // ek period piche (already closed) hota hai — uski bet turant settle hoti hai.
    $instantIssue = sl_instant_previous_issue($gameCode, $current);
    $instantBet = ($instantIssue !== '' && $issue === $instantIssue);
    if (!$instantBet && ($currentIssue !== $issue || $endTime <= sl_now_ms() + ($lockSeconds * 1000))) {
"""
GATE_NEW = """    if ($currentIssue !== $issue || $endTime <= sl_now_ms() + ($lockSeconds * 1000)) {
"""

RESPONSE_OLD = """        if ($instantBet) {
            // Bet lagte hi result: win/loss + updated balance isi response me.
            $outcome = sl_instant_settle($gameCode, $issue);
            $response['instantResult'] = true;
"""
RESPONSE_NEW = """        // Bet lagte hi result: WinGo me jis period par bet lagti hai uska result
        // pehle se decided hota hai (is app me current period ka result already
        // settle ho chuka rehta hai), isliye usi response me win/loss + updated
        // balance bhej dete hain. Result DB me na mile to kuch nahi karte —
        // normal GetWinLossResult poll use settle kar deta hai.
        if (sl_instant_result_enabled($gameCode)) {
            $instantItem = rw_instant_result_item($gameCode, $issue);
            if ($instantItem !== null) {
                try {
                    sl_settle_issue($gameCode, $issue, $instantItem);
                } catch (Throwable $instantError) {
                    error_log('[instant-result settle] ' . $instantError->getMessage());
                }
                $outcome = sl_issue_outcome($gameCode, $issue);
            $response['instantResult'] = true;
"""

RESPONSE_TAIL_OLD = """            $response['balance'] = sl_wallet_balance($userId);
        }
        return $response;
"""
RESPONSE_TAIL_NEW = """            $response['balance'] = sl_wallet_balance($userId);
            }
        }
        return $response;
"""

HELPER = '''/**
 * Is period ka already-decided result (agar saas_lottery_results me hai) — warna null.
 * Bet lagte hi isi item se us period ki pending bets settle kar dete hain.
 */
function rw_instant_result_item($gameCode, $issue)
{
    global $conn;
    $item = null;
    $stmt = $conn->prepare('SELECT issue_number,premium,number,color,result_sum FROM saas_lottery_results WHERE game_code=? AND issue_number=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ss', $gameCode, $issue);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $item = array(
                'issueNumber' => (string) $row['issue_number'],
                'number' => (string) $row['number'],
                'color' => (string) $row['color'],
                'premium' => (string) $row['premium'],
                'sum' => (int) $row['result_sum'],
            );
        }
    }
    return $item;
}

'''



HEADER_TEXT = """/**
 * ============================================================================
 *  INSTANT RESULT  ("1 period piche" mode)  —  WinGo
 * ============================================================================
 *  Is app me results table feed se ek period AAGE chalta hai: jo period screen
 *  par "current" dikhta hai, uska result backend me already decided/closed hota
 *  hai (sl_history_page ke 3-period backfill ki wajah se). Isliye player jis
 *  period par bet lagata hai, usi waqt uska result nikal aata hai — maanwin
 *  wala "1 period piche" behaviour.
 *
 *  Ye patch us waqt:
 *    1) bet save hone ke baad, us period ka result DB me milte hi us bet ko
 *       turant settle kar deta hai (sl_settle_issue) aur wahi win/loss + naya
 *       balance usi bet response me bhej deta hai,
 *    2) GetWinLossResult poll par bhi settle kar deta hai (agar bet pending reh
 *       gayi ho), taaki pehle hi poll par result mil jaye.
 *
 *  Period label kisi bhi jagah shift nahi hota — history/records/settlement sab
 *  canonical issue number par hi chalte hain.
 *
 *  Result source priority:
 *    1) real provider feed (sl_sync_results)
 *    2) deterministic local result (HMAC secret, wahi jo local mode me chalta hai)
 *
 *  OFF karne ke liye: admin setting  instant_result_enabled = 0
 * ============================================================================
 */
"""
HEADER_START = '/**\n * ============================================================================\n *  INSTANT RESULT'


def patch_bootstrap(text):
    # 0) truthful header docblock
    start = text.find(HEADER_START)
    end = text.find('function sl_instant_result_enabled($gameCode)')
    if start == -1 or end == -1 or end <= start:
        raise SystemExit('ABORT [header]: INSTANT RESULT docblock not found')
    text = text[:start] + HEADER_TEXT + text[end:]

    # 1) remove the label-shift helpers
    text = cut_between(
        text,
        '/**\n * Client ko jo "current period" dikhta hai',
        '/**\n * Ek issue ka settled outcome',
        'remove shift helpers',
    )
    # 2) normal bet gate
    text = replace_once(text, GATE_OLD, GATE_NEW, 'bet gate')
    # 3) instant outcome only when the result already exists
    text = replace_once(text, RESPONSE_OLD, RESPONSE_NEW, 'instant response head')
    text = replace_once(text, RESPONSE_TAIL_OLD, RESPONSE_TAIL_NEW, 'instant response tail')
    # 4) helper before sl_instant_settle
    marker = '/**\n * Bet lagte hi result: real feed sync'
    if text.count(marker) != 1:
        raise SystemExit('ABORT [helper insert]: sl_instant_settle docblock not found once')
    text = text.replace(marker, HELPER + marker)
    # 5) re-indent the instant response body (it moved one level deeper)
    head = "            $response['instantResult'] = true;"
    tail = "            $response['balance'] = sl_wallet_balance($userId);"
    if text.count(head) != 1 or text.count(tail) != 1:
        raise SystemExit('ABORT [reindent]: instant response markers not unique')
    start = text.index(head)
    end = text.index(tail) + len(tail)
    block = text[start:end]
    reindented = '\n'.join(('    ' + line) if line.strip() else line for line in block.split('\n'))
    text = text[:start] + reindented + text[end:]
    return text


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    src = pathlib.Path(sys.argv[1])
    dst = pathlib.Path(sys.argv[2])

    for rel in BOOTSTRAPS:
        text = (src / rel).read_text()
        if 'sl_instant_result_enabled' not in text:
            raise SystemExit(f'ABORT: {rel} is not the v1 instant-patched file')
        out = patch_bootstrap(text)
        if 'sl_display_previous_current' in out or 'sl_instant_previous_issue' in out:
            raise SystemExit(f'ABORT: {rel} still references the removed shift helpers')
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(out)
        print(f'patched bootstrap {rel}')

    for rel in ADAPTERS_NO_SHIFT:
        text = (src / rel).read_text()
        text = replace_once(
            text,
            "    // Instant-result mode: public period label ek period piche (settlement canonical).\n"
            "    $payload = sl_display_previous_current($gameCode, $payload);\n",
            '',
            f'{rel} draw shift removal',
        )
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)
        print(f'patched adapter {rel} (draw label shift removed)')

    # ---------------------------------------------------------- draw-live-v4
    text = (src / ADAPTER_V4).read_text()

    old_fn_start = text.index('/**\n * Near a timer boundary')
    old_fn_end = text.index('function dlv4_history_with_boundary_wait')
    new_fn = '''/**
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
        if ($issue !== '' && preg_match('/^\\d{8,40}$/', $issue) && strcmp($issue, $ceiling) > 0) {
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
'''
    text = text[:old_fn_start] + new_fn + text[old_fn_end:]

    text = replace_once(
        text,
        "    return $data;\n}\n\ntry {",
        "    return array('data' => $data, 'ceiling' => $expectedIssue);\n}\n\ntry {",
        'boundary wait return value',
    )

    text = replace_once(
        text,
        """        $input['pageSize'] = 10;
        // Preserve the exact-period admin overlay and settlement, then apply
        // one final period normalisation at the public response boundary.
        $data = dlv4_history_with_boundary_wait($gameCode, $input);
        // Keep database settlement canonical; shift only the public result label.
        if (isset($data['list']) && is_array($data['list'])) {
            $data['list'] = raxiwin_display_previous_periods($gameCode, $data['list']);
        }
""",
        """        // Page 1 par ek extra row maangte hain, kyunki abhi bet ke liye khula
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
""",
        'v4 history branch',
    )

    text = replace_once(
        text,
        "    // Instant-result mode: public period label ek period piche (settlement canonical).\n"
        "    $payload = sl_display_previous_current($gameCode, $payload);\n",
        '',
        'v4 draw shift removal',
    )

    if 'raxiwin_display_previous_periods' in text or 'sl_display_previous_current' in text:
        raise SystemExit('ABORT: draw-live-v4 still references removed helpers')

    target = dst / ADAPTER_V4
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(text)
    print(f'patched adapter {ADAPTER_V4} (own-period history)')
    print('v2 delta applied OK')


if __name__ == '__main__':
    main()
