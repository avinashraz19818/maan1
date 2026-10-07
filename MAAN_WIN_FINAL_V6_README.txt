MAAN WIN FINAL V6 PATCH
=======================

Target: cPanel public_html for https://maanwin.club9.eu.cc/

INSTALL
1. Take a backup of the current public_html files and database.
2. Upload this ZIP inside public_html.
3. Extract it with overwrite enabled. The ZIP already contains public_html-relative paths.
4. Open the site once in a fresh/private tab. V6 automatically removes the old service-worker cache and the stale api.55ak.xyz override.

FIXES INCLUDED
- Register page form restored by enabling mobile registration in both runtime config blocks.
- Register privacy/terms API implemented.
- Login/API hang reduced by skipping repeated full database migration checks after the installed migration version matches.
- WinGo/K3/5D closed-period result now polls every 250 ms (up to 3 seconds) until the exact result is available, and history updates immediately.
- Lottery intervalMinute unit corrected, fixing 5D/K3 timer/progress behavior.
- API and lottery history remain same-origin on maanwin.club9.eu.cc.
- Current visible MaanWin homepage data: 13 Lottery, 49 Crash, 2109 Slots, 5 Sports, 45 Fishing, 644 Casino, 75 Poker.
- Current Popular Games, Featured Games, 10 hero banners and 12 Promotions cards synchronized.
- Current game/banner/promotion artwork stored locally; missing PWA, sound, no-data, hot/popular and provider-rack assets included.
- Admin-created active promotions still appear before the synchronized promotion catalog.
- Service-worker/cache revision advanced to V6.

NOTES
- Database name/user/password remain set in api/_core/config.php as requested.
- The patch does not replace wallet/lottery calculation logic with the reference site's backend; calculations remain local.
- Third-party games still require valid provider credentials configured in the admin backend.
