#!/usr/bin/env python3
"""
DMFirst — "recharge required" gate OFF (game bina recharge ke khule).

Kya ho raha tha
---------------
App (assets/js/index-DHU_0nuy.js) game kholne se pehle `GetAllowBetSetting` se
poochta hai, aur jawab par faisla karta hai:

    const { allowNoRechargeGame: se, userRechargeTimes: de,
            lowestRechargeAmountToGame: ge, userRechargeAmount: _e,
            canDirectToGame: D } = te.data;
    if (se == "1" || D) return k(G);                        // game kholo
    if (Number(ge) && Number(ge) > _e) -> "₹ge recharge karo" popup
    if (Number(ge) === 0 && de === 0)  -> "Game requires recharge to enter" (code1003)

Server purane values bhej raha tha:
    canDirectToGame            = (bool) web_setting.allowbet     // often 0
    allowNoRechargeGame        = '0'                              // hardcoded
    lowestRechargeAmountToGame = web_setting.min_recharge          // often 100
    userRechargeTimes/Amount   = 0 / 0.0                           // hardcoded

Isi liye naya (0-recharge) user WinGo kholte hi recharge popup dekhta tha.
Aur jo user recharge kar chuka tha, usko is file ka `else` branch galti se
GAME-CATEGORY LIST bhej deta tha (junk — is endpoint ka kaam nahi).

Ab kya hota hai
---------------
Dono branches ki jagah ek hi saaf payload:

    canDirectToGame            => true
    allowNoRechargeGame        => "1"
    lowestRechargeAmountToGame => "0"
    userRechargeTimes          => (thevani se asli count, sthiti=1)
    userRechargeAmount         => (thevani se asli sum, sthiti=1)

App ka pehla check (`se == "1" || D`) har user ke liye pass ho jata hai, isliye
koi recharge popup nahi aata. Baki poora file (signature check, JWT, error
codes 5/7, 405) waisa hi rehta hai.

Optional: GetLoadedSetting.php me `needPopupFirstRecharge` true -> false
(login ke baad wala "first recharge" popup).

Usage: python3 apply_patch.py <orig_dir> <out_dir>
"""

import pathlib
import re
import sys

API = 'devmaruf/api/webapi'

NEW_PAYLOAD = """// ---------------------------------------------------------------------------
// Recharge gate OFF: bina kisi recharge ke bhi har user game khol sakta hai.
// App ka gate (assets/js/index-DHU_0nuy.js):
//     if (allowNoRechargeGame == "1" || canDirectToGame) -> game open
//     warna min-recharge / "Game requires recharge to enter" popup
// Isliye neeche wali teeno values gate ko band kar deti hain. Recharge count /
// amount aaj bhi thevani se asli nikalte hain (reports ke liye sahi rahein).
// ---------------------------------------------------------------------------
$userId = (int) $byabaharkarta;
$userRechargeTimes = 0;
$userRechargeAmount = 0.0;
$rechargeSummary = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(motta), 0) AS amt FROM thevani WHERE balakedara = '$userId' AND sthiti = 1");
if ($rechargeSummary && ($summaryRow = $rechargeSummary->fetch_assoc())) {
    $userRechargeTimes = (int) $summaryRow['cnt'];
    $userRechargeAmount = (float) $summaryRow['amt'];
}

$res['data'] = [
    'canDirectToGame' => true,
    'userRechargeTimes' => $userRechargeTimes,
    'allowNoRechargeGame' => "1",
    'userRechargeAmount' => $userRechargeAmount,
    'lowestRechargeAmountToGame' => "0"
];
$res['code'] = 0;
$res['msg'] = 'Succeed';
$res['msgCode'] = 0;
$res['serviceNowTime'] = $shnunc;
http_response_code(200);
echo json_encode($res);
"""

# Sirf yeh poora if/else (gate branch + junk category branch) ek payload me badalta hai.
BLOCK_RE = re.compile(
    r"// Fetch `allowbet` and `min_recharge` column values from database.*?\n            \}\n        \} else \{\n            // Invalid signature",
    re.S,
)

LOADED_OLD = '"needPopupFirstRecharge": true'
LOADED_NEW = '"needPopupFirstRecharge": false'


def main():
    if len(sys.argv) != 3:
        raise SystemExit(__doc__)
    src = pathlib.Path(sys.argv[1])
    dst = pathlib.Path(sys.argv[2])

    rel = API + '/GetAllowBetSetting.php'
    text = (src / rel).read_text()
    if "allowNoRechargeGame' => \"1\"" in text:
        raise SystemExit('ABORT: source already patched')
    if "'allowNoRechargeGame' => '0'" not in text:
        raise SystemExit('ABORT: yeh file expected version nahi hai (gate block nahi mila)')
    match = BLOCK_RE.search(text)
    if not match:
        raise SystemExit('ABORT: gate branch + junk branch ka block nahi mila')
    out = text[:match.start()] + NEW_PAYLOAD + "\n        } else {\n            // Invalid signature" + text[match.end():]

    # ab bekaar wali purani recharge probe lines bhi hata do (naya summary query laga hai)
    out = re.sub(
        r"\n *\$rechargeQuery = \"SELECT 1 FROM thevani.*?\n *\$rechargeResult = mysqli_query\(\$conn, \$rechargeQuery\);\n(?: *\n)*",
        "\n",
        out,
        count=1,
        flags=re.S,
    )

    # sanity: junk hat gaya, gate OFF, baki flow intact
    for needle in ('typeNameCode', "'allowNoRechargeGame' => '0'", 'min_recharge FROM web_setting'):
        if needle in out:
            raise SystemExit(f'ABORT: purana hissa abhi bhi file me hai -> {needle}')
    for needle in ("'allowNoRechargeGame' => \"1\"", "'canDirectToGame' => true", 'is_jwt_valid',
                   'Wrong signature', 'Param is Invalid', "'Method not allowed'", 'FROM thevani'):
        if needle not in out:
            raise SystemExit(f'ABORT: zaroori hissa gayab -> {needle}')
    if out.count('echo json_encode($res);') != 4:
        raise SystemExit('ABORT: response paths ki ginti badal gayi')
    target = dst / rel
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(out)
    print(f'patched {rel} (recharge gate OFF + junk branch hataya)')

    rel2 = API + '/GetLoadedSetting.php'
    text2 = (src / rel2).read_text()
    if text2.count(LOADED_OLD) != 1:
        raise SystemExit('ABORT: needPopupFirstRecharge match nahi mila')
    out2 = text2.replace(LOADED_OLD, LOADED_NEW)
    target2 = dst / rel2
    target2.parent.mkdir(parents=True, exist_ok=True)
    target2.write_text(out2)
    print(f'patched {rel2} (first-recharge popup OFF — optional file)')

    checker = pathlib.Path(__file__).resolve().parent / 'dmfirst-recharge-check.php'
    if not checker.is_file():
        raise SystemExit('ABORT: dmfirst-recharge-check.php missing (patcher ke folder me rakho)')
    (dst / 'dmfirst-recharge-check.php').write_text(checker.read_text())
    print('added dmfirst-recharge-check.php')
    print('dmfirst recharge-gate patch applied OK')


if __name__ == '__main__':
    main()
