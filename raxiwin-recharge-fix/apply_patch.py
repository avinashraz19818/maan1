#!/usr/bin/env python3
"""
RaxiWin — "recharge required" gate OFF (game bina recharge ke khule).

Kya hota tha
------------
Jab koi bandha game kholta hai (WinGo / K3 / D5 / ...), app `GetAllowBetSetting`
ko call karta hai aur jawab ke hisaab se faisla karta hai. App ke JS
(assets/js/index-BVjtEoMt.js) me:

    const { allowNoRechargeGame: ae, userRechargeTimes: ue,
            lowestRechargeAmountToGame: ge, userRechargeAmount: Te,
            canDirectToGame: D } = te.data;
    if (ae == "1" || D) return k(U);                       // game kholo
    if (Number(ge) && Number(ge) > Te) return popup(gameT [₹ge]);   // "itna recharge karo"
    if (Number(ge) === 0 && ue === 0) return popup(code1003);       // "Game requires
                                                                    //  recharge to enter"

Purane file me `canDirectToGame = $userRechargeTimes > 1` (yaani 2 recharge ke baad hi
game khulta tha), `allowNoRechargeGame = "0"`, `lowestRechargeAmountToGame = "100"`.
Isi wajah se "Game requires recharge to enter" popup aata tha.

Ab kya hota hai
---------------
    canDirectToGame            => true      (har user ko seedha game me entry)
    allowNoRechargeGame        => "1"       (app ka pehla check isi par pass hota hai)
    lowestRechargeAmountToGame => "0"

Baki poora file waisa hi hai: signature check, JWT check, `thevani` se
userRechargeTimes / userRechargeAmount (report/dashboard ke liye wo values ab bhi
bhejta hai — sirf gate off hua hai). Isliye sach/settings kuch nahi badli.

GetLoadedSetting.php me sirf `needPopupFirstRecharge` ko true -> false kiya jata
hai (login ke baad "first recharge" popup band). Ye OPTIONAL hai — agar wo popup
rakhna ho to is file ko upload hi na karo.

Usage: python3 apply_patch.py <orig_dir> <out_dir>
"""

import pathlib
import sys

API = 'developer-maruf/api/webapi'

ALLOW_OLD = """\t\t\t\t\t\t// Prepare response data
\t\t\t\t\t\t$data = [
\t\t\t\t\t\t\t'canDirectToGame' => $userRechargeTimes > 1,
\t\t\t\t\t\t\t'userRechargeTimes' => $userRechargeTimes,
\t\t\t\t\t\t\t'allowNoRechargeGame' => "0",
\t\t\t\t\t\t\t'userRechargeAmount' => $userRechargeAmount,
\t\t\t\t\t\t\t'lowestRechargeAmountToGame' => "100"
\t\t\t\t\t\t];
"""

ALLOW_NEW = """\t\t\t\t\t\t// Prepare response data
\t\t\t\t\t\t// Recharge gate OFF: bina kisi recharge ke bhi game khulega.
\t\t\t\t\t\t// App ka gate: `if (allowNoRechargeGame == "1" || canDirectToGame) -> open`.
\t\t\t\t\t\t// userRechargeTimes / userRechargeAmount waise hi bhejte hain
\t\t\t\t\t\t// (reports/dashboard ke liye), sirf entry block nahi hoti.
\t\t\t\t\t\t$data = [
\t\t\t\t\t\t\t'canDirectToGame' => true,
\t\t\t\t\t\t\t'userRechargeTimes' => $userRechargeTimes,
\t\t\t\t\t\t\t'allowNoRechargeGame' => "1",
\t\t\t\t\t\t\t'userRechargeAmount' => $userRechargeAmount,
\t\t\t\t\t\t\t'lowestRechargeAmountToGame' => "0"
\t\t\t\t\t\t];
"""

LOADED_OLD = '"needPopupFirstRecharge": true'
LOADED_NEW = '"needPopupFirstRecharge": false'


def replace_once(text, old, new, label):
    if text.count(old) != 1:
        raise SystemExit(f'ABORT [{label}]: expected exactly 1 match, found {text.count(old)}')
    return text.replace(old, new)


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    src = pathlib.Path(sys.argv[1])
    dst = pathlib.Path(sys.argv[2])

    # 1) GetAllowBetSetting.php — asli gate
    rel = API + '/GetAllowBetSetting.php'
    text = (src / rel).read_text()
    if "'allowNoRechargeGame' => \"1\"" in text:
        raise SystemExit('ABORT: source already patched')
    out = replace_once(text, ALLOW_OLD, ALLOW_NEW, 'allow-bet gate')
    for needle in ("'allowNoRechargeGame' => \"1\"", "'canDirectToGame' => true", 'is_jwt_valid', 'FROM thevani'):
        if needle not in out:
            raise SystemExit(f'ABORT: patched file me "{needle}" nahi mila')
    if 'alle?NoRecharge' in out:
        raise SystemExit('ABORT: patched file me typo')
    target = dst / rel
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(out)
    print(f'patched {rel} (recharge gate OFF)')

    # 2) GetLoadedSetting.php — optional first-recharge popup off
    rel2 = API + '/GetLoadedSetting.php'
    text2 = (src / rel2).read_text()
    out2 = replace_once(text2, LOADED_OLD, LOADED_NEW, 'first-recharge popup')
    target2 = dst / rel2
    target2.parent.mkdir(parents=True, exist_ok=True)
    target2.write_text(out2)
    print(f'patched {rel2} (first-recharge popup OFF — optional file)')

    # 3) verify page
    checker = pathlib.Path(__file__).resolve().parent / 'raxiwin-recharge-check.php'
    if not checker.is_file():
        raise SystemExit('ABORT: raxiwin-recharge-check.php missing (patcher ke folder me rakho)')
    (dst / 'raxiwin-recharge-check.php').write_text(checker.read_text())
    print('added raxiwin-recharge-check.php')
    print('recharge-gate patch applied OK')


if __name__ == '__main__':
    main()
