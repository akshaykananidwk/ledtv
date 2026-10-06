# HotelCast — Hosting Setup Guide

This guide installs the HotelCast server (admin panel + TV API) on ordinary shared hosting
(cPanel / Plesk / DirectAdmin) or any Apache + PHP + MySQL server. No root access, Composer,
Node.js or command line is needed.

## 1. What you need

| Item | Minimum |
|------|---------|
| Web server | Apache 2.4 with `mod_rewrite` (LiteSpeed also works) |
| PHP | 8.1 or newer, extensions: `pdo_mysql`, `curl`, `zip`, `gd`, `mbstring`, `openssl`, `sodium`, `fileinfo` (all standard on cPanel) |
| Database | MySQL 5.7.8+ / 8.x or MariaDB 10.4+ (10.6 recommended) |
| Disk | 1 GB + your videos/images |
| SSL | Strongly recommended (free Let's Encrypt in cPanel → SSL/TLS Status) |

## 2. Create the database (cPanel)

1. cPanel → **MySQL® Databases**.
2. *Create New Database*: e.g. `myacct_hotelcast`.
3. *Add New User*: e.g. `myacct_hcuser` with a strong password.
4. *Add User To Database* → tick **ALL PRIVILEGES**.

Write down the database name, user and password.

## 3. Upload the files

1. Download `hotelcast-2.0.0.zip` (from `dist/`, or build it with `tools/build-release.sh`).
2. cPanel → **File Manager** → open `public_html` → **Upload** the zip → right-click → **Extract**.
   You now have `public_html/hotelcast/`.
   *(To run HotelCast on its own sub-domain, e.g. `tv.myhotel.com`, point the sub-domain's
   document root to the `hotelcast` folder.)*

## 4. Run the installer

Open `https://your-domain.com/hotelcast/install/` in a browser and follow the 7 steps:

| Step | What happens |
|------|--------------|
| 1. Requirements | PHP version, extensions, folder permissions and `.htaccess` protection are checked. Folders are created/fixed automatically where possible. |
| 2. Database | Enter host (`localhost`), name, user, password. *Test connection* checks it. The database is created automatically if your user is allowed to. `.env` and `config.php` are written for you. |
| 3. Tables | All tables are created automatically. Tick *Load demo data* for 20 sample rooms, a Dwarkadhish darshan timetable, welcome message, offer and a default playlist. |
| 4. Admin | Create the administrator login (password: 8+ characters, letters and numbers). It is the **platform admin** and the super admin of the first hotel. |
| 5. Hotel | Hotel name, logo, time zone, admin language (English / ગુજરાતી), the public address TVs will use, optional **product name** (white-label). |
| 6. Update & License | **Installation type**: *Platform (SaaS)* or *Self-hosted single hotel* with its **license key** and license server address (see section 11). Optional: GitHub repository, branch, app folder (`hotelcast`) and a Personal Access Token. Can be done later. |
| 7. Done | `installed.lock` is written and the `/install` folder deletes itself. The **TV registration key** is shown — note it down (also visible later in Admin → Settings → Devices). |

Nothing else is manual: no phpMyAdmin import, no editing of `.env`, no chmod.

## 5. Optional: cron job (more exact schedules)

HotelCast works without cron — schedules, offline detection and notifications run automatically
while TVs poll. For minute-exact scheduling even when all TVs are off, add in cPanel → **Cron Jobs**:

```
* * * * * php /home/<account>/public_html/hotelcast/cron.php >/dev/null 2>&1
```

## 6. Recommended PHP settings

The included `.htaccess` sets them on Apache + mod_php. On PHP-FPM hosts set them in
cPanel → **MultiPHP INI Editor**:

```
upload_max_filesize = 256M
post_max_size = 260M
max_execution_time = 300
memory_limit = 256M
```

## 7. Force HTTPS

After installing an SSL certificate, uncomment the three `RewriteCond/RewriteRule` lines under
*Force HTTPS* in `hotelcast/.htaccess`. HSTS headers are sent automatically on HTTPS requests.

## 8. Security checklist after installation

- `/install` folder is gone (the installer deletes it; if your host prevented that, delete it).
- `https://your-domain/hotelcast/.env`, `/config.php`, `/core/`, `/backups/`, `/logs/` must show **403 Forbidden**.
- Create separate **Manager** / **Staff** users for employees — do not share the super-admin login.
- Download a backup from Admin → Auto-Update → Backups once a month and keep it off the server.

## 9. Moving to another server

(Self-hosted installations: ask your provider to *reset the license domain* if the domain changes.)


1. Admin → Auto-Update → Backups → *Create backup now* (tick *Include media uploads*) → download.
2. Install HotelCast fresh on the new server (steps 2–4).
3. Admin → Auto-Update → Backups → *Upload backup* → *Restore* (files + database).
4. Change the server address on the TVs only if the domain changed (TV → Settings).

## 10. Troubleshooting

| Problem | Fix |
|---------|-----|
| Installer step 1 shows a red folder | Set the folder to 755 (or 775) in File Manager → Permissions, then *Check again*. |
| "Access denied" at step 2 | Wrong DB user/password, or the user is not added to the database with ALL PRIVILEGES. |
| TVs get 404 on `/api/...` | `mod_rewrite` is disabled — ask your host to enable it (or `AllowOverride All`). |
| Video upload fails | Raise `upload_max_filesize` / `post_max_size` (section 6). |
| Admin shows "Security token expired" | The page was open too long — reload and retry. |
| Logs | `hotelcast/logs/error.log`, `php_error.log`, `update.log`, `device.log` (also visible in Admin → Logs). |
| TVs show "Service paused" | The hotel is suspended / expired (Platform → Hotels) or, on a self-hosted install, the license is invalid (Admin → Platform settings → License). |
| TV registration says "TV limit reached" | `LICENSE_LIMIT`: the hotel has its maximum number of TVs (plan / hotel limit / license). Revoke an old TV or raise the limit. |

## 11. Installation types: SaaS platform vs. self-hosted (license)

HotelCast 2.0 runs in one of two modes, set by `'mode'` in `config.php` (the installer writes it):

| | `saas` (default) | `standalone` |
|---|---|---|
| Who | You host **one or many hotels** on your server | **One hotel** runs HotelCast on its own hosting |
| Hotels | Platform → Hotels: create hotels, each with its own login, rooms, TVs, content | Hotel #1 only |
| Billing | Plans, monthly invoices, resellers, auto-suspend | — (paid to the provider) |
| License | Not needed. This server **is the license server** (`/api/license/check`) | `license_key` + `license_server` in `config.php`, checked daily |

Every installation upgraded from 1.x is a `saas` platform with one hotel — nothing changes for it.

**Self-hosted license** — in `config.php`:

```php
'mode' => 'standalone',
'license_key' => 'HC-XXXXX-XXXXX-XXXXX-XXXXX',      // from your provider (Platform → Licenses)
'license_server' => 'https://tv.provider.com/hotelcast/',
```

* The key is checked once a day (cron or TV polls trigger it) and bound to your domain on the first
  check. *Admin → Platform settings → License → Check license now* checks immediately.
* If the license server cannot be reached the installation keeps working for **14 days** (offline
  grace; a yellow banner is shown).
* Invalid / expired / revoked license → TVs show the *service paused* screen, the admin panel shows a
  red banner.
* **No key** → demo mode: a banner is shown and at most **2 TVs** can register.

## 12. Upgrading from 1.x to 2.0

Use Admin → Auto-Update → *Update Now* as usual (backup and automatic rollback included). The
database is converted in place by `migrations/002_multitenancy.sql` + `002_multitenancy_upgrade.php`:

* all existing data becomes **hotel #1** (named after your hotel name setting), the registration key
  stays the same — TVs keep working without any change;
* the first Super Admin becomes **Platform Admin** (still manages the hotel); the GitHub / backup
  settings become platform settings;
* media files are **not moved**: existing paths `uploads/media/…` stay valid, new uploads go to
  `uploads/h<hotel_id>/…`;
* the conversion is resumable — if it is interrupted, simply run the update / migration again.

