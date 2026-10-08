# Krishna Cloud TV Management (code name HotelCast) — Developer Guide

> **Terminology (2.5):** in the UI a `hotels` row is a **customer** and a `rooms` row is a **screen**
> (`room_number` = screen name / ID). Keep the database / API names, use the UI words in every new string —
> see [modules/terminology.md](modules/terminology.md).

PHP 8.1+, MySQL 8 / MariaDB 10.4+, no Composer, no build step. Classes live in `hotelcast/core/` and
are autoloaded by name (`core/<Class>.php`, then `core/Extensions/<Class>.php`, `core/Tasks/<Class>.php`).

This guide explains the **multi-hotel (tenant) model** every module must follow and the **extension
points** that let new modules (guests / PMS, room service, ads & analytics, templates, PWA / push,
support tools…) plug in **without editing shared files**.

---------------------------------------------------------------------------------------------------
## 1. Tenancy — the #1 rule

A platform hosts many hotels. **Every query on hotel data must be scoped to the current hotel.**

### 1.1 The current hotel: `Tenant`

| | |
|---|---|
| `Tenant::id(): int` | current hotel id — **throws `TenantException` when no hotel is selected** (fail loudly, never leak) |
| `Tenant::current(): ?int` / `Tenant::has()` | without throwing |
| `Tenant::set(?int)` / `Tenant::run($hotelId, fn)` | switch context (run restores the previous one) |
| `Tenant::each(fn ($hotelId) => …, $activeOnly)` | loop over hotels (cron jobs, tasks) |
| `Tenant::hotel()` | hotel row + plan fields (cached per request) |
| `Tenant::state()` / `isActive()` | `active` / `suspended` / `expired` (status, expiry date, self-hosted license) |
| `Tenant::requireActive()` | API: `403 HOTEL_SUSPENDED` when not active |
| `Tenant::maxTvs()` / `tvCount()` | TV limit (hotel → plan → license) and active TVs |
| `Tenant::feature('guests')` | is a plan module enabled (no plan / no list = everything) |
| `Tenant::find($table, $id, $extraWhere)` | row of the current hotel; **404 + security log when the id belongs to another hotel** (CLI: throws) |
| `Tenant::assertOwnsAll($table, $ids)` | returns the owned ids; 404 if any id belongs to another hotel |
| `Tenant::deny()` | log + 404 (JSON for API / AJAX) |

Who sets it:

* **Admin request** — `Auth::user()`: hotel roles always get `users.hotel_id` (never from the session);
  a platform admin gets the hotel it *entered* (session `hc_hotel`) or its own `hotel_id`; a reseller
  only a hotel whose `reseller_id` is theirs. Hotel pages (`Auth::require('rooms.view')` etc.) redirect
  platform users without a hotel to their home page.
* **Device API** — `DeviceManager::authenticate()` → `Tenant::forDevice($device)`.
* **Registration** — the registration key identifies the hotel (`hotels.registration_key`, unique).
* **Scheduler / cron** — no context; per-hotel work runs inside `Tenant::each()`.

### 1.2 Writing queries

```php
// Reads: always add the hotel condition yourself.
$rows = DB::all('SELECT * FROM guests WHERE hotel_id = :hid AND room_id = :r', ['hid' => Tenant::id(), 'r' => $roomId]);
// In admin pages the helper hid() returns ['hid' => Tenant::id()].
// Single row by id (404 for another hotel's id):
$room = Tenant::find('rooms', $id);
// Ids from a form:
$ids = Tenant::assertOwnsAll('rooms', $_POST['room_ids'] ?? []);
```

**Safety net**: `DB::insert()` fills `hotel_id` with `Tenant::id()`, and `DB::update()` / `DB::delete()`
append `AND hotel_id = <current>` for every table in `Tenant::TABLES` **and every table a module
registers** with `Tenant::registerTable('guests')` (do this in a `core/boot.d/*.php` file, see 2.8).
Raw SQL (`DB::query/all/one/value/column`) is never rewritten — scope it explicitly. Platform code
that really works across hotels wraps writes in `DB::unscoped(fn () => …)` and passes explicit ids.

New tenant tables: `hotel_id INT UNSIGNED NOT NULL DEFAULT 1`, an index, and
`FOREIGN KEY (hotel_id) REFERENCES hotels(id)`. Unique keys include `hotel_id`. Child tables
(e.g. order items) may rely on their parent's hotel, but then **always verify the parent with
`Tenant::find()` first**.

Tests must prove isolation (see `tests/Integration/TenancyTest.php`): two hotels, cross-hotel ids must
answer 403/404 and leave the other hotel's data unchanged.

### 1.3 Settings

`system_settings` has the primary key `(hotel_id, setting_key)`.

* `Settings::get($key)` — current hotel's value → platform value (hotel 0) → `Settings::DEFAULTS`.
* `Settings::set($key, $v)` — writes the current hotel (throws without a hotel).
* Platform keys (`Settings::PLATFORM_KEYS`, every `platform_*` and `task_last_*` key) always live in
  hotel 0: `Settings::platform()`, `Settings::setPlatform()`.
* `Settings::getFor($hotelId, …)` / `setFor($hotelId, …)` for platform pages.
* Module defaults: just pass a default to `get()`, or store them on hotel 0 as platform-wide fallback.

### 1.4 Files and cache

* Uploads: `Uploader::handle($file, 'image')` stores under `uploads/h{hotel}/media/YYYY/MM/…`, logos under
  `uploads/h{hotel}/branding/…`, APKs under `storage/apk/h{hotel}/…`; `Uploader::handle(…, 'logo', 'platform')`
  → `uploads/platform/…`. Stored paths are relative to `uploads/` — paths created before 2.0
  (`media/…`) remain valid; never move files in a migration.
* Cache: per-hotel namespaces `Cache::hotelNs('content')` → `content_h3`. `Settings::bumpContentVersion()`
  invalidates the current hotel's TV content.

### 1.5 Roles & permissions

Hotel roles (levels): `reception` 1 < `staff` 2 < `manager` 3 < `super_admin` 4 (`Auth::PERMISSIONS`
maps a permission to the minimum role). Platform roles (`Auth::PLATFORM_PERMISSIONS`, list of roles):
`platform_admin` (acts as super admin inside any hotel) and `reseller` (inside its own hotels).
Already reserved: `guests.manage`, `services.manage` (reception+), `billing.view` (super admin).
Modules add permissions with `Auth::registerPermission('ads.manage', 'manager')` or
`Auth::registerPermission('support.view', ['platform_admin'])` in a boot file.

Suspended / expired hotels are **read-only** for hotel users: `Auth::require()` refuses POSTs except
for scripts in `Auth::SUSPENDED_ALLOWED_SCRIPTS` (billing, invoice, profile, logout).

---------------------------------------------------------------------------------------------------
## 2. Extension points

Each new module adds **its own files** in these folders — no shared file needs to change.

### 2.1 Admin menu — `admin/partials/nav.d/*.php`

Each file returns a list of items `[key, file, permission, icon, label, section]` (+ optional
`['saas' => true]` to hide it on self-hosted installs). Sections: `hotel` (needs a hotel context),
`reseller`, `platform`. Files load in name order; a later item with the same key replaces an earlier one.
An item is shown only if the user has the permission and the page file exists.

```php
<?php // admin/partials/nav.d/20_guests.php
return [
    ['guests', 'guests.php', 'guests.manage', 'bi-person-vcard', __('Guests'), 'hotel'],
];
```

Set `$activeNav = 'guests'` in the page. Existing items: `10_hotel.php`, `50_platform.php`,
`80_platform_support.php` (reserved for the support dashboard: replace `admin/platform_support.php`).

### 2.2 Translations — `lang/<lang>_<module>.php`

`lang/gu.php` + every `lang/gu_*.php` are merged (same for `hi`). Keys are the English strings; use
`__('Text :n', ['n' => 3])`. Admin UI languages: `I18n::LANGUAGES` (en, gu). Guest-facing text also
supports Hindi: `I18n::GUEST_LANGUAGES` (en, gu, hi) — translate for a guest independent of the admin
session with `I18n::translate('Welcome', 'hi')`. Platform strings: `lang/gu_platform.php`.

### 2.3 TV content — `core/Extensions/*.php` (`ContentExtension`)

After `ContentResolver` decided what a room shows (and added `branding`), every class in
`core/Extensions/` implementing `ContentExtension` (class name = file name, sorted by file name) may
change the Content object before it is hashed. Runs with the room's hotel as tenant.

```php
<?php // core/Extensions/GuestWelcomeExtension.php
final class GuestWelcomeExtension implements ContentExtension
{
    public function apply(array &$content, array $room): void
    {
        if (!Tenant::feature('guests') || $content['mode'] === 'suspended') {
            return;
        }
        $content['guest'] = …;  // see V2_SPEC "TV contract"
    }
}
```

Keep it deterministic and fast (TV polls; content is cached ~15 s, invalidated by
`Settings::bumpContentVersion()`). Exceptions are logged and ignored.

### 2.4 API routes — `api/routes/*.php`

`api/index.php` handles the built-in routes, then calls each file in `api/routes/` (name order). A
file returns `callable(string $route, array $parts, string $method): bool`; respond with
`Api::ok()` / `Api::error()` (they exit) or return `false` when the route is not yours.

```php
<?php // api/routes/pms.php
return static function (string $route, array $parts, string $method): bool {
    if (($parts[0] ?? '') !== 'pms') {
        return false;
    }
    // authenticate (e.g. per-hotel API key) → Tenant::set($hotelId) → Tenant::requireActive()
    Api::ok(['…']);
};
```

Example in the code base: `api/routes/license.php` (`POST /api/license/check`). Device endpoints
use `DeviceManager::authenticate()` which also sets the tenant.

### 2.5 Periodic jobs — `core/Tasks/*.php` (`Task`)

`Scheduler::tick()` (cron every minute, or lazily from TV polls) runs every `Task` whose `interval()`
has elapsed (last run in the platform setting `task_last_<Class>`). Tasks start **without** a hotel
context — loop with `Tenant::each()`.

```php
<?php // core/Tasks/GuestRetentionTask.php
final class GuestRetentionTask implements Task
{
    public function interval(): int { return 86400; }
    public function run(): array
    {
        $n = 0;
        Tenant::each(function (int $hid) use (&$n) { /* delete stays older than Settings::int('guest_retention_days', 30) */ });
        return ['deleted' => $n];
    }
}
```

Existing: `BillingTask` (monthly invoices, overdue reminders, auto-suspend), `LicenseTask` (daily
license check on self-hosted installs).

### 2.6 Admin AJAX — `admin/ajax.d/<prefix>.php`

`admin/ajax.php?action=<prefix>_<name>` actions that are not built in are delegated to
`admin/ajax.d/<prefix>.php`. Available: `$action`, `$in` (JSON body or GET), `$method`, `$user`,
`$needPost()`, `$parseTarget()`. Check permissions with `require_can()`, respond with `ajax_ok()` /
`ajax_error()`; just return for actions that are not yours. Session auth, CSRF (`X-CSRF-Token`) and the
read-only rule are already applied. Example: `admin/ajax.d/platform.php` (`platform_stats`).

### 2.7 Dashboard widgets — `admin/partials/dashboard.d/*.php`

Included by `admin/index.php` in name order inside a Bootstrap `.row`; print one column
(`<div class="col-md-6 col-xl-4">…</div>`) and check your permission first. Example:
`50_plan_usage.php`.

### 2.8 Boot hooks — `core/boot.d/*.php`

Included by `core/bootstrap.php` on every request (before any handler). Use them to register tenant
tables and permissions:

```php
<?php // core/boot.d/guests.php
Tenant::registerTable('guests');
Tenant::registerTable('stays');
Auth::registerPermission('guests.history', 'manager');
```

### 2.9 Migrations — `migrations/NNN_name.sql|.php`

Numbered files run once, in natural order, by the installer and the auto-updater (tracked in
`schema_migrations`). Never edit a released migration.

* `.sql`: plain statements; `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE` — "already exists" errors
  (1050, 1060, 1061, 1068, 1091, 1826) are ignored.
* `.php`: `return static function (PDO $pdo, callable $log): void { … };` for conditional / data
  migrations. Must be **idempotent and resumable**: check `information_schema` before each `ALTER`
  (helpers: `Migrator::hasColumn/hasIndex/hasForeignKey/indexColumns`). Use only PDO inside, because the
  auto-updater may run it from a process that still has older classes loaded.

Parallel modules: pick a distinct name (`003_guests.sql`, `004_services.sql` …); new tables reference
`hotels(id)`.

### 2.10 Other hooks

* `Auth::homePage()` sends `reception` users to `guests.php` once that page exists.
* `Hotels::MODULES` lists the plan modules shown on the plan form (`Tenant::feature()` checks them).
* `Notifier::send()` (hotel alerts, per-hotel settings) and `Notifier::sendToContact()` (platform →
  hotel contact, email / WhatsApp gateway with `{phone}` / `{message}`).
* `Branding::get()` / `Branding::forTv()` — resolved white-label branding of the current hotel.
* `admin/partials/billing.d/*.php` — included by `admin/billing.php` (POST-able while a hotel is suspended):
  handle your own `op`, return a callable that prints a card (example: free-trial upgrade).
* `admin/partials/tv_simulator.php` — the TV simulator page (`$obj`, `$label`, `$simRefreshUrl`, …), used by
  `admin/preview.php` and the public `demo_tv.php`.
* `Demo::sampleContent([floor => rooms])` — sample rooms / content / playlist for the current hotel (installer,
  sign-up, demo hotels). Demo hotels (`hotels.demo_kind`) are write-protected by `Demo::guard()`; see
  `docs/modules/signup_demo.md`.
* **Display apps (2.3)** — `core/Apps/<Name>App.php` (extends `DisplayApp`, auto-discovered like
  `core/Tasks`): server-rendered TV screens (content type `app`, signed `/display/` page, themes, live
  data). Guide: `docs/modules/display_apps.md`.

---------------------------------------------------------------------------------------------------
## 3. Platform model (reference)

| table | purpose |
|---|---|
| `hotels` | tenants: status `active/suspended/expired`, `suspend_reason`, plan, reseller, `max_tvs`, `expires_at`, unique `registration_key`, contact / billing details, branding override |
| `plans` | price per TV / month, `max_tvs`, `features` (JSON list of modules, NULL = all) |
| `resellers` | commission %, hotel allowance, branding, status; users with role `reseller` have `users.reseller_id` |
| `invoices` (+ `invoice_sequences`) | number `PREFIX-YYYY-0001`, period, TVs × unit price, tax, total, status `unpaid/paid/cancelled`, payment ref |
| `licenses` | self-hosted keys, domain binding, last check |
| `users.hotel_id` | NULL for platform-only users (platform admins / resellers) |
| `system_settings` | `(hotel_id, setting_key)`; hotel 0 = platform |

`config.php`: `mode` (`saas` default | `standalone`), `license_key`, `license_server`, `brand_name`
(installer). See `core/License.php` (client: daily check, 14-day grace, unlicensed = 2 TVs) and
`core/LicenseServer.php`.

---------------------------------------------------------------------------------------------------
## 4. Tests

```bash
cd hotelcast
php phpunit.phar -c phpunit.xml          # needs the test DB (see README); the DB is wiped
```

`tests/AdminSession.php` gives a logged-in HTTP admin session (`get`, `post`, `ajax`);
`TestEnv::startServer()` runs the sandbox app with PHP's built-in server; `TestEnv::phpErrors()`
returns PHP warnings logged by the app (tests assert it is empty). Add every new integration test file
to `phpunit.xml`. Isolation tests are mandatory for every module that stores hotel data.
