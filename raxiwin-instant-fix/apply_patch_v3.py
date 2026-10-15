#!/usr/bin/env python3
"""
RaxiWin instant-result patch — v3.

v2 ka problem: sirf history/result align hua tha, GAME (current period) shift
nahi hua. User ko chahiye "game ko 1 period piche" — yaani public current period
bhi ek period piche ho, aur history me bhi wahi ek-period-piche wala view ho, aur
bet lagate hi usi (already closed) period ka result aa jaye. Maanwin ka model.

v3 = v1 ka core (draw ka period label ek period piche + bet gate + turant settle)
     + v2 ka history fix (har row apne period ka result, future row hata di jati
       hai) — is se dono taraf ek hi period piche dikhta hai aur dono consistent
       rehte hain.

Input  : v2 sources (raxiwin-instant-fix/site)
Output : v3 sources

Usage: python3 apply_patch_v3.py <v2_site_dir> <v3_out_dir>
"""

import pathlib
import sys

BOOTSTRAPS = [
    'saas_lottery/bootstrap.php',
    'saas_lottery/bootstrap_live_v3.php',
    'saas_lottery/bootstrap_live_v4.php',
]
ADAPTERS = ['draw/index.php', 'draw-live-v3/index.php', 'draw-live-v4/index.php']

HEADER_OLD_START = '/**\n * ============================================================================\n *  INSTANT RESULT'
HEADER_NEW = """/**
 * ============================================================================
 *  INSTANT RESULT  ("1 period piche" mode)  —  WinGo
 * ============================================================================
 *  MaanWin jaisa behaviour: poora game — period label AUR uske saath dikhne
 *  wala result — ek period PICHE chalta hai.
 *
 *  Screen par jo period "current" dikhta hai, wo backend ke canonical current se
 *  ek period piche hota hai (draw API ka previous period). Us period ka result
 *  already decided/settled hota hai, isliye:
 *
 *    1) player us period par bet lagata hai,
 *    2) bet save hote hi backend usi period ki pending bet settle kar deta hai
 *       (sl_settle_issue) aur win/loss + naya balance USI bet response me chala
 *       jata hai (instantResult / number / isWin / winAmount / balance ...),
 *    3) response miss ho jaye to GetWinLossResult poll pehle hi call par settle
 *       kar deta hai.
 *
 *  Countdown/timer bilkul normal chalta hai — sirf period label ek piche hai,
 *  startTime/endTime ko chhua nahi jata.
 *
 *  History bhi wahi ek-period-piche wala view dikhati hai: har row apne hi
 *  period ka result, aur jo period abhi bet ke liye khula hai wo list se hata
 *  diya jata hai (draw-live-v4/index.php).
 *
 *  Settlement hamesha canonical issue number par hi hoti hai (jo client bhejta
 *  hai wahi canonical label hai), isliye admin history/records drift nahi karte.
 *
 *  Result source priority:
 *    1) real provider feed (sl_sync_results)
 *    2) deterministic local result (HMAC secret, wahi jo local mode me chalta hai)
 *
 *  OFF karne ke liye: admin setting  instant_result_enabled = 0
 * ============================================================================
 */
"""

SHIFT_HELPERS = '''/**
 * Ek issue label ko ek period piche karo — label ke apne window se (times se
 * nahi), taaki provider aur DB ke labelling offset se koi farak na pade.
 * Day boundary (seq 1 -> pichle din ka last period) bhi handle hota hai.
 */
function sl_instant_shift_issue($gameCode, $issue)
{
    $issue = (string) $issue;
    if (!preg_match('/^([0-9]{8})[0-9]{9}$/', $issue)) {
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

/**
 * Client ko jo "current period" dikhta hai (provider ke current label se ek
 * period piche) — ya ''. Yehi wo period hai jis par bet lagne par turant result
 * aata hai, kyunki uska result already decided hota hai.
 */
function sl_instant_previous_issue($gameCode, $current)
{
    if (!sl_instant_result_enabled($gameCode) || !is_array($current) || !isset($current['current']['issueNumber'])) {
        return '';
    }
    return sl_instant_shift_issue($gameCode, (string) $current['current']['issueNumber']);
}

/**
 * Public draw payload ke previous/current/next labels ek period piche karo.
 * startTime/endTime JAAN-BOOJH kar untouched hain, warna countdown bigad jata.
 */
function sl_display_previous_current($gameCode, $payload)
{
    if (!is_array($payload) || !sl_instant_result_enabled($gameCode)) {
        return $payload;
    }
    foreach (array('previous', 'current', 'next') as $key) {
        if (!isset($payload[$key]['issueNumber'])) {
            continue;
        }
        $shifted = sl_instant_shift_issue($gameCode, (string) $payload[$key]['issueNumber']);
        if ($shifted !== '') {
            $payload[$key]['issueNumber'] = $shifted;
        }
    }
    return $payload;
}

'''

GATE_OLD = """    if ($currentIssue !== $issue || $endTime <= sl_now_ms() + ($lockSeconds * 1000)) {
"""

GATE_NEW = """    // Instant-result mode: client jis period par bet bhejta hai wo display ka
    // current period hai (canonical current se ek period piche) — wo already
    // closed period hai, isliye issue mismatch par block nahi karte.
    $instantIssue = sl_instant_previous_issue($gameCode, $current);
    $instantBet = ($instantIssue !== '' && $issue === $instantIssue);
    if (!$instantBet && ($currentIssue !== $issue || $endTime <= sl_now_ms() + ($lockSeconds * 1000))) {
"""

RESPONSE_V2_START = "        // Bet lagte hi result: WinGo me jis period par bet lagti hai uska result\n"
RESPONSE_V2_END = """                $response['balance'] = sl_wallet_balance($userId);
            }
        }
        return $response;
"""

RESPONSE_V3 = """        if ($instantBet) {
            // Bet lagte hi result: iss period ka result already decided hai,
            // isliye turant settle karke win/loss + naya balance bhejte hain.
            $outcome = sl_instant_settle($gameCode, $issue);
            $response['instantResult'] = true;
            $response['issueNumber'] = (string) $issue;
            $response['premium'] = (string) $outcome['premium'];
            $response['number'] = (string) $outcome['premium'];
            $response['resultNumber'] = $outcome['premium'] !== '' ? (int) $outcome['premium'] : null;
            $response['color'] = (string) $outcome['color'];
            $response['settled'] = (bool) $outcome['settled'];
            $response['isWin'] = $outcome['won'];
            $response['status'] = $outcome['won'] === null ? null : ((int) $outcome['won'] === 1 ? 1 : 0);
            $response['winAmount'] = (float) $outcome['payout'];
            $response['winLoseAmount'] = (float) $outcome['net'];
            $response['balance'] = sl_wallet_balance($userId);
        }
        return $response;
"""

ADAPTER_CALL = "    // Instant-result mode: game ka period label ek period piche (settlement canonical).\n    $payload = sl_display_previous_current($gameCode, $payload);\n"


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'ABORT [{label}]: expected exactly 1 match, found {count}')
    return text.replace(old, new)


def cut_block(text, start_index, end_index, label):
    if not (0 <= start_index < end_index <= len(text)):
        raise SystemExit(f'ABORT [{label}]: bad indices')
    return text[:start_index] + text[end_index:]


def patch_bootstrap(text):
    # 1) truthful v3 header
    start = text.find(HEADER_OLD_START)
    end = text.find('function sl_instant_result_enabled($gameCode)')
    if start == -1 or end == -1 or end <= start:
        raise SystemExit('ABORT [header]: INSTANT RESULT docblock not found')
    text = text[:start] + HEADER_NEW + text[end:]

    # 2) bring back the period-label shift helpers (after sl_instant_result_enabled)
    anchor = '/**\n * Ek issue ka settled outcome'
    if text.count(anchor) != 1:
        raise SystemExit('ABORT [helpers]: sl_issue_outcome docblock not found once')
    text = text.replace(anchor, SHIFT_HELPERS + anchor)

    # 3) bet gate: allow the displayed (one period back) current period
    text = replace_once(text, GATE_OLD, GATE_NEW, 'bet gate')

    # 4) bet response: instant settle + outcome (v1 behaviour)
    start = text.find(RESPONSE_V2_START)
    if start == -1:
        raise SystemExit('ABORT [response]: v2 instant block not found')
    end = text.find(RESPONSE_V2_END, start)
    if end == -1:
        raise SystemExit('ABORT [response]: v2 instant block end not found')
    end += len(RESPONSE_V2_END)
    text = text[:start] + RESPONSE_V3 + text[end:]

    # 5) drop the v2-only helper (instant settle already reads the result itself)
    helper_start = text.find('/**\n * Is period ka already-decided result')
    helper_end = text.find('/**\n * Bet lagte hi result: real feed sync')
    if helper_start == -1 or helper_end == -1 or helper_end <= helper_start:
        raise SystemExit('ABORT [helper removal]: rw_instant_result_item block not found')
    text = text[:helper_start] + text[helper_end:]

    for required in ('sl_display_previous_current', 'sl_instant_previous_issue', 'sl_instant_settle($gameCode, $issue);'):
        if required not in text:
            raise SystemExit(f'ABORT: {required} missing after patch')
    if 'rw_instant_result_item' in text:
        raise SystemExit('ABORT: rw_instant_result_item still referenced')
    return text


def patch_adapter(text, rel):
    if rel == 'draw-live-v4/index.php':
        text = replace_once(
            text,
            "    $payload = sl_provider_current($gameCode);\n    if (!$payload) {\n        sl_fail(503, 'Real current draw is temporarily unavailable', 503, 503);\n    }\n    sl_send($payload, 200);",
            "    $payload = sl_provider_current($gameCode);\n    if (!$payload) {\n        sl_fail(503, 'Real current draw is temporarily unavailable', 503, 503);\n    }\n" + ADAPTER_CALL + "    sl_send($payload, 200);",
            'v4 draw shift',
        )
        # pageSize 11 on every page + always slice to 10 (page 1 had a future row)
        text = replace_once(
            text,
            "        // Page 1 par ek extra row maangte hain, kyunki abhi bet ke liye khula\n        // period response se hata diya jata hai.\n        $input['pageSize'] = $input['pageNo'] === 1 ? 11 : 10;",
            "        // Har page par ek extra row maangte hain (page 1 par khula period hata\n        // diya jata hai), phir 10 rows hi bhejte hain — paging continuous rehti hai.\n        $input['pageSize'] = 11;",
            'v4 pageSize',
        )
        text = replace_once(
            text,
            "            if (count($data['list']) > 10) {\n                $data['list'] = array_slice($data['list'], 0, 10);\n            }",
            "            if (count($data['list']) > 10) {\n                $data['list'] = array_slice($data['list'], 0, 10);\n            }\n            // (page 1: khula period hatne ke baad bhi 10 rows, baaki pages: 10)\n            $data['pageNo'] = $input['pageNo'];",
            'v4 slice',
        )
        return text

    marker = "    sl_send($payload, 200);"
    if text.count(marker) != 1:
        raise SystemExit(f'ABORT [{rel}]: sl_send($payload, 200) not found once')
    return text.replace(marker, ADAPTER_CALL + marker)


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    src = pathlib.Path(sys.argv[1])
    dst = pathlib.Path(sys.argv[2])

    for rel in BOOTSTRAPS:
        text = (src / rel).read_text()
        if 'rw_instant_result_item' not in text:
            raise SystemExit(f'ABORT: {rel} is not the v2 output')
        out = patch_bootstrap(text)
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(out)
        print(f'patched bootstrap {rel} (period + result ek period piche)')

    for rel in ADAPTERS:
        text = (src / rel).read_text()
        out = patch_adapter(text, rel)
        if 'sl_display_previous_current($gameCode, $payload)' not in out:
            raise SystemExit(f'ABORT: {rel} missing the draw shift call')
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(out)
        print(f'patched adapter {rel} (draw label ek period piche)')

    print('v3 patch applied OK')


if __name__ == '__main__':
    main()
