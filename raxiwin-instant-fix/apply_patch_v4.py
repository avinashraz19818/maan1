#!/usr/bin/env python3
"""
RaxiWin instant-result patch — v4 ("result bhi ek period piche").

Kyun: app khud draw ke current se 1 ghata kar period banata hai
    (JS: e.issue = BigInt(issueData.current.issueNumber) - 1n).
v3 me draw ka label ek period piche hone se app ka displayed period = real-2 ho
gaya, lekin history/result display real-1 tak hi piche tha -> result ek period
aage dikh raha tha.

v4 = v3 + history/result ceiling bhi ek period piche, taaki:
    app ka displayed period  ==  history ka top row (apne hi result ke saath)
    bet lagate hi wahi result settle ho.

Usage: python3 apply_patch_v4.py <v3_site_dir> <v4_out_dir>
"""

import pathlib
import sys

ADAPTER = 'draw-live-v4/index.php'
BOOTSTRAPS = [
    'saas_lottery/bootstrap.php',
    'saas_lottery/bootstrap_live_v3.php',
    'saas_lottery/bootstrap_live_v4.php',
]

BETTABLE_HELPER = '''/**
 * App ka apna bettable period: JS `e.issue = BigInt(issueData.current.issueNumber) - 1`
 * (useLottery hook). Draw ka label bhi instant mode me ek period piche hota hai,
 * isliye public/bettable period = served current - 1 = raw current - 2.
 */
function sl_instant_bettable_issue($gameCode, $current)
{
    if (!sl_instant_result_enabled($gameCode) || !is_array($current) || !isset($current['current']['issueNumber'])) {
        return '';
    }
    $served = sl_instant_shift_issue($gameCode, (string) $current['current']['issueNumber']);
    return $served !== '' ? sl_instant_shift_issue($gameCode, $served) : '';
}

'''

GATE_OLD = """    $instantIssue = sl_instant_previous_issue($gameCode, $current);
"""
GATE_NEW = """    $instantIssue = sl_instant_bettable_issue($gameCode, $current);
"""


HELPER = '''/**
 * History / last-result display ka ceiling.
 *
 * App khud draw ke current issueNumber se 1 ghata kar apna period banata hai
 * (JS: BigInt(current.issueNumber) - 1), aur draw ka label bhi instant mode me
 * ek period piche hota hai. Isliye result/history bhi ek period aur piche tak
 * dikhana hoga, warna result game ke period se ek period aage dikhta hai.
 */
function raxiwin_display_result_ceiling($gameCode, $issue)
{
    $issue = (string) $issue;
    if ($issue === '' || strpos((string) $gameCode, 'WinGo_') !== 0) {
        return $issue;
    }
    if (!function_exists('sl_instant_shift_issue') || !sl_instant_result_enabled($gameCode)) {
        return $issue;
    }
    $shifted = sl_instant_shift_issue($gameCode, $issue);
    return $shifted !== '' ? $shifted : $issue;
}

'''

OLD_CEILING = """        if ($expectedIssue === '') {
            $local = sl_local_current($gameCode);
            $expectedIssue = (string)($local['previous']['issueNumber'] ?? '');
        }
    }
"""

NEW_CEILING = """        if ($expectedIssue === '') {
            $local = sl_local_current($gameCode);
            $expectedIssue = (string)($local['previous']['issueNumber'] ?? '');
        }
        // App display se ek period piche rehta hai, to result bhi wahi tak.
        // (Fallback wale issue pe bhi lagta hai.)
        $expectedIssue = raxiwin_display_result_ceiling($gameCode, $expectedIssue);
    }
"""


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    src = pathlib.Path(sys.argv[1])
    dst = pathlib.Path(sys.argv[2])

    text = (src / ADAPTER).read_text()
    if 'raxiwin_display_own_period_results' not in text:
        raise SystemExit('ABORT: input is not the v3 adapter')

    anchor = 'function dlv4_history_with_boundary_wait($gameCode, $input)'
    if text.count(anchor) != 1:
        raise SystemExit('ABORT: boundary-wait function not found once')
    text = text.replace(anchor, HELPER + anchor)

    if text.count(OLD_CEILING) != 1:
        raise SystemExit('ABORT: ceiling block not found once')
    text = text.replace(OLD_CEILING, NEW_CEILING)

    if text.count('raxiwin_display_result_ceiling($gameCode, $expectedIssue)') != 1:
        raise SystemExit('ABORT: ceiling call missing after patch')
    if 'raxiwin_display_own_period_results' not in text:
        raise SystemExit('ABORT: v3 own-period filter lost')

    target = dst / ADAPTER
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(text)

    # bootstraps: bettable-issue helper + gate alignment
    for rel in BOOTSTRAPS:
        text = (src / rel).read_text()
        anchor = '/**\n * Public draw payload ke previous/current/next labels'
        if text.count(anchor) != 1:
            raise SystemExit(f'ABORT [{rel}]: shift-helper docblock not found once')
        text = text.replace(anchor, BETTABLE_HELPER + anchor)
        if text.count(GATE_OLD) != 1:
            raise SystemExit(f'ABORT [{rel}]: instant issue line not found once')
        text = text.replace(GATE_OLD, GATE_NEW)
        if text.count('sl_instant_bettable_issue') != 2:
            raise SystemExit(f'ABORT [{rel}]: bettable helper wiring wrong')
        out = dst / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(text)

    # pageSize 12: page 1 par do row (raw current + served current) hat jati hain -> 10 bache
    text = (dst / ADAPTER).read_text()
    old_size = "        $input['pageSize'] = 11;"
    if text.count(old_size) != 1:
        raise SystemExit('ABORT: pageSize 11 not found once')
    text = text.replace(old_size, "        $input['pageSize'] = 12;")
    (dst / ADAPTER).write_text(text)

    for rel in ['draw/index.php', 'draw-live-v3/index.php']:
        source = src / rel
        out = dst / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(source.read_text())

    print('patched adapter draw-live-v4/index.php (result ceiling ek period piche)')
    print('v4 patch applied OK')


if __name__ == '__main__':
    main()
