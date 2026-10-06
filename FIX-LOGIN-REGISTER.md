# Login / Register Fix — cPanel patch

## 1. Problem kya thi (root cause)

Site ka frontend (`/js/index-MxYkJMUQ.js`) login/register same-origin `/api/...` par bhejta hai
(`baseURL: "/api"`), aur local PHP backend (`api/Home/Login.php` → `api/_router.php` → MySQL)
uske liye ready tha.

Lekin `.htaccess` me ye rule tha:

```apache
RewriteRule ^api(/.*)?$ api_proxy.php [L,QSA]
```

`api_proxy.php` har `/api/...` request ko **dusre server** `https://maanwin16.com` par forward
kar deta tha. Matlab aapka local PHP + MySQL kabhi call hi nahi hota tha.

Iska seedha asar:

| Symptom | Wajah |
|---|---|
| Register "successful" dikhta tha, par user database / admin panel me nahi aata tha | Account `maanwin16.com` ke database me ban raha tha, aapke MySQL me nahi |
| Database / admin me dikhai dene wala user login par "wrong password" / "user does not exist" deta tha | Login bhi `maanwin16.com` par check ho raha tha, jahan aapka user exist hi nahi karta |

## 2. Kya-kya fix kiya

1. **`.htaccess`** — `/api/...` ab remote proxy par nahi, local `api/_router.php` (aapke MySQL) par
   jaata hai. Remote proxy ka rule comment karke disabled kar diya gaya hai (zaroorat pade to
   wapas enable kar sakte hain).
2. **`router.php`** — local dev server (`php -S`) bhi ab local backend use karta hai.
3. **`api/_router.php`**
   - `handle_register()`: INSERT ab **column-aware** hai (imported/legacy table me missing ya extra
     NOT NULL column ki wajah se register chup-chaap fail nahi hota), duplicate check username +
     mobile dono par, legacy `password` (md5) column bhi support.
   - `handle_login()`: sirf bcrypt nahi — **bcrypt / md5 / sha256 / plaintext** sab verify hote hain,
     aur purana hash login ke baad automatically bcrypt me upgrade ho jaata hai.
     `password_hash` ke saath legacy `password` column dono dekhe jaate hain.
4. **`api/_core/bootstrap.php`**
   - `dw_verify_user_password()`, `dw_store_user_password()`, `dw_user_stored_hash()`,
     `dw_table_columns()` helpers.
   - `ensure_auth_columns()`: purani/imported `users` table me `password_hash`, `role`, `status`
     jaise zaroori columns missing hon to non-destructive tarike se add karta hai, aur legacy
     `password` value ko `password_hash` me copy kar deta hai.
   - `db_or_fail()`: DB connect na hone par saaf JSON error (`config.php` check karne ka message),
     HTML 500 ki jagah.
   - `bearer_token()` ab header case-insensitive dhundhta hai (LiteSpeed/CGI ke liye).
5. **`admin1/lib.php` + `admin1/actions.php`** — admin login aur "change password" bhi legacy
   md5/plain hash aur legacy column ke saath kaam karte hain.
6. **`api/_core/config.php`** — optional override file support: site root me `dw-db-config.php`
   rakh kar DB credentials set kar sakte hain (update se overwrite nahi hoti).

> Note: is ZIP me `api/_core/config.php` jaan-boojh kar **nahi** diya gaya, taaki aapke server par
> pehle se set MySQL credentials overwrite na ho jaayein. Credentials badalne ho to niche
> `dw-db-config.sample.php` ka tarika use karein.

## 3. Install (cPanel)

1. cPanel → **File Manager** → `public_html` kholiye.
2. **Pehle backup**: `public_html/.htaccess`, `api/_router.php`, `api/_core/bootstrap.php`,
   `admin1/lib.php`, `admin1/actions.php` — in files ko download karke rakh lijiye.
3. Ye ZIP `public_html` me upload karke **Extract** kar dijiye (same folder structure hai,
   files apni jagah overwrite ho jaayengi).
4. Browser me site kholiye: hard refresh (`Ctrl+F5`) / mobile me site ka cache clear.
   (Purana service worker `index.html` khud unregister kar deta hai.)
5. Test:
   - Naya account **Register** kariye → phir `admin1` panel → **Users** me wo user dikhna chahiye.
   - Usi account se **Login** kariye → success milna chahiye.
   - Admin panel (`/admin1/`) me login kariye.

## 4. MySQL credentials

Code `api/_core/config.php` se DB details padhta hai. Agar aapke cPanel ke DB name/user/password
alag hain to do tariko se set kar sakte hain:

**Tarika A (recommended):** `dw-db-config.sample.php` ka naam badal kar `dw-db-config.php` kijiye
aur site ki **root** (`public_html`) me rakhiye:

```php
<?php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'youruser_dbname',
    'DB_USER' => 'youruser_dbuser',
    'DB_PASS' => 'yourpassword',
];
```

**Tarika B:** `api/_core/config.php` me `DB_NAME` / `DB_USER` / `DB_PASS` directly badal dijiye.

Tables/columns pehli request par automatic verify/create ho jaate hain (`AUTO_INSTALL_TABLES=true`).

## 5. Zaroori baatein

- Jo accounts **fix se pehle** register hue the, wo `maanwin16.com` ke database me bane the —
  aapke MySQL me nahi. Un users ko dobara register karna padega (ya admin panel se banayein).
- Purane users jinka password database me md5/plain me tha, ab bina password badle login kar
  sakte hain; pehli successful login par hash bcrypt me upgrade ho jaata hai.
- `api/error_log` me agar `Access denied for user ...` aaye to MySQL credentials galat hain.

## 6. Verification (jo is patch par chalaya gaya)

PHP 8.3 runtime par repo ke asli functions execute karke 25 behaviour checks + 12 syntax/routing
checks chalaye gaye (sab pass):

- register → `users` table me `role='user'`, `status=1`, bcrypt `password_hash` ke saath row
- register ke baad wahi user login → `code: 0` + token
- galat password → `Password error`
- duplicate register → `User already exists`
- legacy md5 `password` column wala user → login success (purana code isi case me fail hota tha)
- legacy schema (`password_hash` column hi nahi) par register + login success
- `admin1` login: md5 aur bcrypt dono hashes, galat password reject
- `ensure_auth_columns()` missing `password_hash` add + legacy password copy karta hai
- DB connect fail → saaf JSON error
- frontend ke saare **168** `/api` endpoints local backend par resolve hote hain
