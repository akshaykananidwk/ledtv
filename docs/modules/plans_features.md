# Plans & feature entitlements (2.5)

Every customer (tenant, table `hotels`) is on a **plan**. The plan decides which **features** (modules) the
customer gets and its **limits** (screens, users, storage). The Super Admin can add or remove single
features and change limits for one customer.

Entitlements are **separate from roles**: a request must pass both.

| Question | Answered by |
|---|---|
| Is this **customer** entitled to X? (plan) | `Features` (`core/Features.php`) |
| May this **user** do X? (role) | `Auth::can()` — which also asks `Features::permissionEnabled()` |

Code never checks plan names. Adding a plan is data (Platform → Plans), not code.

## Effective features of a customer

```
plan.features (JSON array of keys; NULL / [] = everything)
  + hotels.feature_overrides {"add": [...], "remove": [...]}
  → depends (a feature is on only when every feature it depends on is on)
  + core features (always on)
```

* **NULL = everything** — every plan that existed before 2.5 (Basic, Standard, Premium) and every customer
  without a plan keep all features. Nothing changes for existing customers.
* **Old 2.0 lists** (`["guests"]`, `["services","ads"]` … only old module names or unknown names) keep
  their meaning: everything except the old modules that are left out
  (`guests → guests, pms`, `services → room_service, feedback`, `ads → ads, marketplace`,
  `analytics`, `templates → templates, guide`, `pwa → pwa_push`, `support → support, live_view`).
* Lists written by the plan editor always contain the core keys (format marker), so a plan with only
  `["ads"]` is never mistaken for an old list.
* Cached per request; `Tenant::forget()` / `Features::forget()` clear it.
* The **platform admin always has everything**: inside an entered customer the pages stay open (a grey
  banner lists the modules that are not in the customer's plan), while the menu shows the customer's view.

## Feature keys

Core (always on, cannot be removed): `dashboard`, `screens`, `groups`, `broadcast` (push content now),
`users`, `profile`, `settings` (incl. billing, *Your plan*), `logs`, `update`, `platform`.

| Group | Keys |
|---|---|
| Content | `content`, `playlists`, `designer`, `pdf_import`, `layouts`, `tickers`, `templates` |
| Display apps | `apps` (gallery, countdown, QR), `app_menu_board`, `app_queue`, `app_notice_board`, `business_apps`, `content_apps`, `data_feeds`, `widgets_26_30` |
| Scheduling | `schedule` (schedules + calendar), `approvals`, `holidays`, `broadcast_emergency`, `emergency_alarm`, `power_schedules` |
| Devices | `device_schedules`, `presence`, `video_walls`, `sync_playback`, `live_view`, `tv_health`, `usb_mode`, `cec`, `web_player`, `tv_controls`, `support`, `apk_updates` |
| Reports & marketing | `play_report`, `analytics`, `ads`, `marketplace`, `pwa_push` |
| Hospitality | `guests`, `room_service`, `feedback`, `pms`, `guide` |
| Advanced | `user_access`, `custom_roles`, `api_access` |

Each registry entry lists `permissions`, `pages` (admin/*.php), `ajax` (actions, `prefix_*`), `api` (REST
route prefixes, gated centrally), `api_self` (routes whose handler checks itself, e.g. the guest app link
answers 404), `apps` (display-app keys), `extensions` (TV content extensions), `widgets` (dashboard.d files)
and `depends`.

Dependencies: `playlists`, `designer`, `pdf_import`, `layouts`, `templates`, `apps`, `approvals`, `guide` → `content`;
every app family → `apps`; `emergency_alarm` → `broadcast_emergency`; `sync_playback` → `playlists`;
`presence` → `device_schedules` + `api_access`; `marketplace` → `ads`; `feedback` → `room_service`;
`pms` → `guests` + `api_access`.

## Ready-made plans (migration 028)

Created only when no plan of that name exists (the Basic plan from 2.0 already exists and stays NULL =
everything; the presets are also offered as "Start from" buttons in the plan editor):

| Plan | Features | Users |
|---|---|---|
| Basic | content, playlists, ticker, schedules, TV power, emergency (+ alarm) | 1 |
| Business | Basic + display apps, split screen, designer, PDF, templates, device schedules, proof of play, analytics | 5 |
| Pro | everything except hospitality and the ad marketplace | unlimited |
| Hospitality | everything | unlimited |

## Limits

| Limit | Plan column | Customer override | Enforced |
|---|---|---|---|
| `max_screens` | `plans.max_tvs` | `hotels.max_tvs` | TV registration (`LICENSE_LIMIT`, also the license); screens = connected TVs, screen records without a TV are free |
| `max_users` | `plans.max_users` | `hotels.max_users` | creating a user (admin/users.php) |
| `storage_mb` | `plans.storage_mb` | `hotels.storage_mb` | every upload (`Uploader::handle`, size of `uploads/h{id}`, cached 5 min) |

NULL = unlimited. Each refusal has a clear message ("Your plan allows at most 5 users …").

## Enforcement (server side)

* **Admin pages and AJAX** — `admin/partials/common.php` calls `Features::guardAdminRequest()`: the
  script's basename (or `ajax.php?action=`) is looked up; a feature outside the plan answers **403 "Not
  included in your plan"** (page with an upgrade hint, or JSON `{"code":"FEATURE_DISABLED","feature":…}`)
  before the page runs.
* **REST API** — `api/index.php` calls `Features::guardApiRoute()`; the check runs when the route selects
  its customer (`Tenant::set`) → `403 FEATURE_DISABLED`. The device API (`device/*`, `content/*`,
  `provision/*`, `license/*`) is never gated.
* **TV content** — `ContentResolver` skips content extensions of disabled features (ticker, ads, guests,
  guide, video wall, sync, TV controls) and `Features::filterContent()` strips `usb_mode`, `cec_mode`
  and the emergency alarm sound (the emergency message itself always shows). `ContentRules::playable()`
  skips display-app items of a disabled family and split-screen layouts, like inactive items.
* **Display pages** — `display/` of a disabled app family shows the neutral "This app is not available."
  page (403); `display/data.php` answers 403; `display/queue.php` and `album/` answer "not found".
* **Web player** — `device/register` with `platform: web` → 403 when `web_player` is off.
* **RBAC** — `Auth::can()` (custom roles module) asks `Features::permissionEnabled()`.

## UI

* Navigation: `hc_nav_sections()` hides every hotel item whose page belongs to a disabled feature; the
  dashboard hides widgets of disabled features; Content hides the designer / PDF buttons and the split
  screen type; Apps shows only the app families of the plan; Users hides per-user screen access.
* Platform → Plans: grouped checkboxes with descriptions, "Select all / none" per group, presets,
  limits (screens, users, storage), price, **Copy plan** (copies start inactive), delete when unused.
* Platform → Hotels → edit: plan, per-feature override (*Plan* / *Add for this customer* / *Remove for
  this customer*), max users, storage, and a summary of the effective features.
* Settings → **Your plan** (`admin/plan.php`): included and not included features, limits with usage and
  the provider's contact for upgrades.

## API for other modules

```php
Features::all();                                  // key => definition
Features::enabled('designer', ?int $hotelId);     // plan + overrides + depends (core = true, no customer = true)
Features::allows('designer');                     // enabled() or the platform admin
Features::require('designer');                    // 403 "Not included in your plan" (page / JSON / API)
Features::permissionEnabled('ads.manage');        // for Auth::can(): false when every owning feature is off
Features::forPage('designer.php');                // 'designer' | null (core / platform page)
Features::forAjax('designer_pdfpage');            // 'pdf_import' | null
Features::forApi('pms/checkin');                  // 'pms' | null
Features::forApp('menu_board');                   // 'app_menu_board' (unknown apps → 'apps')
Features::limits(?int $hotelId);                  // ['max_screens' => ?int, 'max_users' => ?int, 'storage_mb' => ?int]
Features::register('my_module', [...]);           // module hook (core/boot.d)
Tenant::feature('services');                      // old wrapper, maps the 2.0 module names
```

**A new module must register its pages, ajax actions, API routes and permissions** in the registry (or
with `Features::register()` in its boot.d file). `tests/Integration/Apps/FeaturesTest.php` crawls every
admin page, ajax action, API route, permission, display app, content extension and dashboard widget and
fails for anything that belongs to no feature. Platform pages (`platform_*.php`, `chain*.php`,
`reseller.php`) are never gated.

Migration: `migrations/028_plans_features.php` (columns `plans.max_users`, `plans.storage_mb`,
`hotels.feature_overrides`, `hotels.max_users`, `hotels.storage_mb`; ready-made plans).

---

## ગુજરાતી સારાંશ

* દરેક ગ્રાહક (હોટેલ / દુકાન / ઓફિસ) એક **પ્લાન** પર હોય છે. પ્લાન નક્કી કરે છે કે ગ્રાહકને કયા **ફીચર**
  (મોડ્યુલ) મળે અને તેની **મર્યાદા** શું છે: વધુમાં વધુ સ્ક્રીન, યુઝર અને સ્ટોરેજ (MB).
* પ્લાન (ગ્રાહક શું ખરીદ્યું છે) અને રોલ (યુઝર શું કરી શકે) અલગ છે — બંને ચેક પાસ થવા જોઈએ.
* જે પ્લાનમાં ફીચરની યાદી નથી (NULL) તેમાં **બધું ચાલુ** રહે છે — હાલના બધા ગ્રાહકો માટે કંઈ બદલાતું નથી.
* સુપર એડમિન Platform → Plans માં પ્લાન બનાવે / બદલે / **કૉપી** કરે છે: ગ્રુપ મુજબ ફીચર ચેકબોક્સ,
  "બધા / કંઈ નહીં", મર્યાદા અને કિંમત. તૈયાર પ્લાન: Basic, Business, Pro, Hospitality.
* Platform → Hotels → Edit માં એક ગ્રાહક માટે ફીચર **ઉમેરી કે કાઢી** શકાય છે અને મર્યાદા બદલી શકાય છે.
* પ્લાનમાં ન હોય તેવું પેજ, AJAX કે API ખોલતાં **403 "તમારા પ્લાનમાં સામેલ નથી"** આવે છે; મેનુમાં તે છુપાયેલું
  રહે છે અને TV પર પણ દેખાતું નથી (ટિકર, જાહેરાત, ડિસ્પ્લે એપ, લેઆઉટ વગેરે).
* પ્લેટફોર્મ એડમિન પાસે હંમેશાં બધું હોય છે; ગ્રાહકમાં પ્રવેશે ત્યારે મેનુ ગ્રાહક જેવું દેખાય છે અને ઉપર
  પટ્ટીમાં પ્લાનમાં ન હોય તેવા મોડ્યુલ દેખાય છે.
* ગ્રાહક Settings → **તમારો પ્લાન** માં જોઈ શકે છે કે શું સામેલ છે અને શું નથી, અને અપગ્રેડ માટે પ્રોવાઇડરનો
  સંપર્ક.
* નવું મોડ્યુલ બનાવો ત્યારે તેના પેજ, AJAX, API અને પરવાનગી `core/Features.php` માં નોંધવા જરૂરી છે —
  નહીંતર `FeaturesTest` ફેલ થાય છે.
