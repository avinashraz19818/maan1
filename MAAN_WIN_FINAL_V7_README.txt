MAAN WIN FINAL V7 HOT PATCH
===========================

Upload and extract this ZIP directly inside public_html so its .htaccess,
index.html, api, admin1, js, css, assets, images and img paths merge with the
existing installation. Choose overwrite when cPanel asks.

V7 fixes
---------
- Restores the missing WinGo Game history module used by the active bundle.
- Ships the complete JS/CSS runtime and all local theme/image compatibility
  aliases required by that runtime, including the broken timer/category icons.
- Changes Activity API data to the supplied current grouped response and adds
  both missing current MaanWin activity banners locally.
- Keeps admin-created promotions at the top of the first Activity group.
- Clears stale Activity/local API/cache data once on the first V7 load.
- Excludes /admin and /admin1 from the root game service worker.
- Preserves real server error pages instead of replacing them with game home.
- Keeps all prior register, same-domain API, instant WinGo result, 5D timer,
  content, database and admin-panel fixes.

After upload
------------
1. Extract with overwrite enabled.
2. Open the site once and refresh. V7 performs one automatic cache reset.
3. Test /register, /WinGo/WinGo_30S, /activity and /admin1/.

Security
--------
Remove old ZIP files from public_html after extraction. The bundled .htaccess
also blocks direct access to ZIP, SQL, log, config and maintenance files. This
public deployment note intentionally contains no database credentials.
