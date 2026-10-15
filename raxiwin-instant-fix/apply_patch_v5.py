#!/usr/bin/env python3
"""
RaxiWin instant-result patch — v5.

v4 tak ka problem: bet gate sirf EK label accept karta tha (draw shift ke hisaab
se), lekin app ke alag-alag bet paths alag labels bhejte hain
(assets/js/useWinGo3-*.js):
     Re(...)  -> issueNumber: K.value?.current?.issueNumber || B.value
     tt(...)  -> issueNumber: B.value
     Z(...)   -> issueNumber: d.value
jisme se ek "served current" hai aur do "displayed period" (= served - 1).

v5 ka fix: gate ab last 3 closed periods (raw current ke shift1..3) accept karta
hai — sab ke sab already-decided periods hain, isliye instant result dono
configurations me chalta hai:
   * draw shifted  (v4 behaviour) : app bhejta raw-2  -> accepted
   * draw unshifted (v2/toggle off): app bhejta raw-1  -> accepted
Aur bet response me instant win/loss sirf tab jodta hai jab feature ON ho.

Saath me: draw adapter self-contained (local shift helper) + ek build-check
script jo server par batata hai ki kaun se file ka kaun sa version live hai.

Usage: python3 apply_patch_v5.py <v4_site_dir> <v5_out_dir>
"""

import pathlib
import sys

BOOTSTRAPS = [
    'saas_lottery/bootstrap.php',
    'saas_lottery/bootstrap_live_v3.php',
    'saas_lottery/bootstrap_live_v4.php',
]
ADAPTERS = ['draw/index.php', 'draw-live-v3/index.php', 'draw-live-v4/index.php']

CANDIDATES_HELPER = '''/**
 * Instant-result mode me client jis period par bet bhej sakta hai: aakhri 3
 * CLOSED periods (raw provider current se ek-ek piche). App ke alag paths
 * "served current" aur "displayed period" — dono bhejte hain, aur dono isi set
 * me aate hain. Sabhi periods closed/settled hote hain, isliye bet lagte hi
 * result nikal jata hai.
 */
function sl_instant_bet_candidates($gameCode, $current)
{
    if (sl_game_family($gameCode) !== 'WinGo' || !is_array($current) || !isset($current['current']['issueNumber'])) {
        return array();
    }
    $candidates = array();
    $candidate = (string) $current['current']['issueNumber'];
    for ($back = 1; $back <= 3; $back++) {
        $candidate = sl_instant_shift_issue($gameCode, $candidate);
        if ($candidate === '') {
            break;
        }
        $candidates[] = $candidate;
    }
    return $candidates;
}

'''

GATE_OLD = """    $instantIssue = sl_instant_bettable_issue($gameCode, $current);
    $instantBet = ($instantIssue !== '' && $issue === $instantIssue);
"""

GATE_NEW = """    // App ke bet paths "served current" (raw-1) ya "displayed period" (raw-2)
    // bhejte hain, aur latency ke liye margin bhi — teeno accept karte hain.
    // Sabhi candidates closed periods hain, isliye result turant settle ho jata hai.
    $instantCandidates = sl_instant_result_enabled($gameCode) ? sl_instant_bet_candidates($gameCode, $current) : array();
    $instantBet = in_array($issue, $instantCandidates, true);
"""

RESPONSE_OLD = """        if ($instantBet) {
"""
RESPONSE_NEW = """        if ($instantBet && sl_instant_result_enabled($gameCode)) {
"""

# ------------------------------------------------ adapter (self-contained)
ADAPTER_HELPERS = '''/**
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

'''

OLD_CEILING_FN_START = '/**\n * History / last-result display ka ceiling.'
OLD_ADAPTER_SHIFT = """    // Instant-result mode: game ka period label ek period piche (settlement canonical).
    $payload = sl_display_previous_current($gameCode, $payload);
"""

NEW_ADAPTER_SHIFT = """    // Instant-result mode: public period label ek period piche (settlement canonical).
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
"""

LEGACY_SHIFT_GUARD_OLD = "    $payload = sl_display_previous_current($gameCode, $payload);\n"
LEGACY_SHIFT_GUARD_NEW = """    if (function_exists('sl_display_previous_current')) {
        $payload = sl_display_previous_current($gameCode, $payload);
    }
"""


def replace_once(text, old, new, label):
    if text.count(old) != 1:
        raise SystemExit(f'ABORT [{label}]: expected exactly 1 match, found {text.count(old)}')
    return text.replace(old, new)


def patch_bootstrap(text):
    anchor = '/**\n * Public draw payload ke previous/current/next labels'
    if text.count(anchor) != 1:
        raise SystemExit('ABORT [bootstrap]: shift helper docblock not found once')
    text = text.replace(anchor, CANDIDATES_HELPER + anchor)
    text = replace_once(text, GATE_OLD, GATE_NEW, 'gate')
    text = replace_once(text, RESPONSE_OLD, RESPONSE_NEW, 'instant response guard')
    if text.count('sl_instant_bet_candidates') != 2:
        raise SystemExit('ABORT [bootstrap]: candidates wiring wrong')
    return text


def patch_v4_adapter(text):
    # 1) self-contained helpers ki jagah bootstrap-dependent ceiling helper hata do
    start = text.find(OLD_CEILING_FN_START)
    if start == -1:
        raise SystemExit('ABORT [adapter]: ceiling helper docblock not found')
    end = text.find('function dlv4_history_with_boundary_wait', start)
    if end == -1 or end <= start:
        raise SystemExit('ABORT [adapter]: boundary-wait function not found after ceiling helper')
    text = text[:start] + ADAPTER_HELPERS + text[end:]

    # 2) draw shift via the local helper
    if text.count(OLD_ADAPTER_SHIFT.strip() + '\n') == 1:
        text = text.replace(OLD_ADAPTER_SHIFT.strip() + '\n', NEW_ADAPTER_SHIFT)
    else:
        raise SystemExit('ABORT [adapter]: draw shift call not found once')

    # 3) ceiling derived from what we actually serve
    old_ceiling = """        $current = sl_provider_current($gameCode);
        if (is_array($current) && isset($current['previous']['issueNumber'])) {
            $expectedIssue = (string)$current['previous']['issueNumber'];
        }
        if ($expectedIssue === '') {
            $local = sl_local_current($gameCode);
            $expectedIssue = (string)($local['previous']['issueNumber'] ?? '');
        }
        // App display se ek period piche rehta hai, to result bhi wahi tak.
        // (Fallback wale issue pe bhi lagta hai.)
        $expectedIssue = raxiwin_display_result_ceiling($gameCode, $expectedIssue);
    }
"""
    new_ceiling = """        $rawCurrent = '';
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
"""
    return replace_once(text, old_ceiling, new_ceiling, 'ceiling derivation')


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    src = pathlib.Path(sys.argv[1])
    dst = pathlib.Path(sys.argv[2])

    for rel in BOOTSTRAPS:
        text = (src / rel).read_text()
        if 'sl_instant_bettable_issue' not in text:
            raise SystemExit(f'ABORT: {rel} is not the v4 output')
        out = patch_bootstrap(text)
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(out)
        print(f'patched bootstrap {rel} (tolerant bet gate)')

    for rel in ADAPTERS:
        text = (src / rel).read_text()
        if rel == 'draw-live-v4/index.php':
            out = patch_v4_adapter(text)
        else:
            out = replace_once(text, LEGACY_SHIFT_GUARD_OLD, LEGACY_SHIFT_GUARD_NEW, f'{rel} guard')
        if rel in ('draw/index.php', 'draw-live-v3/index.php') and 'function_exists(\'sl_display_previous_current\')' not in out:
            raise SystemExit(f'ABORT: {rel} guard missing')
        target = dst / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(out)
        print(f'patched adapter {rel}')

    # build check script
    checker = pathlib.Path(__file__).resolve().parent / 'raxiwin-build-check.php'
    if not checker.is_file():
        raise SystemExit('ABORT: raxiwin-build-check.php missing (patcher ke folder me rakho)')
    (dst / 'raxiwin-build-check.php').write_text(checker.read_text())
    print('added raxiwin-build-check.php')
    print('v5 patch applied OK')


if __name__ == '__main__':
    main()
