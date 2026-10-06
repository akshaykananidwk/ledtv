# Module: online sign-up + free trial (#17), demo mode (#21)

SaaS mode only (`config.php` `mode = saas`); self-hosted installs hide every public page (404) and menu entry.

Migration: `migrations/007_signup_demo.sql` — columns `hotels.is_trial`, `hotels.demo_kind`
(`NULL | public | client`), `hotels.demo_purged_at`; table `signups` (platform level, **not** a tenant
table). Boot hook: `core/boot.d/signup_demo.php` (permissions + demo write guard).

| permission | rule | used for |
|---|---|---|
| `signup.manage` | platform_admin | Platform → Sign-ups & trials (list, approve / reject, extend, settings) |
| `demo.client` | platform_admin, reseller | Platform → Demo / reseller → Client demos |

Classes: `core/Signup.php`, `core/Demo.php`; tasks `core/Tasks/TrialTask.php` (hourly),
`core/Tasks/DemoResetTask.php` (every 10 min).

---------------------------------------------------------------------------------------------------
## 1. Sign-up (`signup.php`)

Fields: hotel name, city, owner name, mobile (10–15 digits), email, rooms/TVs estimate, password
(`Auth::passwordError` + strength meter), language EN/GU, accept terms (text = platform setting).

Anti-abuse, in this order:

1. CSRF (session token), 30 POSTs / IP / hour on the whole page.
2. Honeypot field `website` (hidden off-screen) → answers like a normal "we will contact you" page, nothing stored.
3. Time-to-submit: hidden `ts` = `<unix>.<hmac(APP_KEY)>` (`Signup::formToken()`); refused when younger than
   `signup_min_seconds` (default 3) or older than 2 h / forged.
4. Optional math captcha (`signup_captcha`), answer kept in the session.
5. Validation; disposable e-mail domains (`Signup::DISPOSABLE_DOMAINS` + `signup_blocked_domains`).
6. Rate limits on valid submissions: 3 / IP / hour, 3 / e-mail / day, 3 / mobile (last 10 digits) / day.

Modes (`signup_mode`):

| mode | flow |
|---|---|
| `otp` (default) | 6-digit code by e-mail (`Notifier::email`, platform sender). Stored as **bcrypt hash**, valid 15 min, 5 attempts (then the request expires), max 3 codes, 1 per minute. The request id lives in the session only. |
| `auto` | hotel created immediately (no mail needed) |
| `manual` | request waits in Platform → Sign-ups & trials (approve → hotel + mail to the owner, reject) |

No enumeration: an e-mail / mobile that already belongs to an account (users, open requests, hotel
contacts) gets **the same screen** — OTP mode sends an "account already exists" mail instead of a code
(verification always fails), auto mode queues the request for manual review (`signups.duplicate = 1`).

On success `Signup::provision()` → `Hotels::create()` with `plan_id = trial_plan_id`, `expires_at = now + trial_days`,
`max_tvs = trial_max_tvs`, `is_trial = 1`, registration key, super admin (username from the e-mail's
local part, login also with the e-mail), demo content rooms 101..N (`Demo::sampleContent([1 => min(N, 20)])`),
`default_language`. The owner is logged in → `admin/getting_started.php?welcome=1`.

## 2. Trial lifecycle

* `admin/getting_started.php` — checklist with progress (`Signup::checklist()`): rooms, logo, first TV
  (link `admin/claim.php`, QR setup), own content / push, staff. Menu entry + dashboard widget for trial hotels;
  AJAX `signup_checklist`.
* Banner on every admin page of a trial hotel (`admin/partials/footer.d/70_signup_demo.php`): days left /
  ended + **Upgrade** → `billing.php#upgrade`.
* `TrialTask` → `Signup::processTrials()`: reminders 3 days and 1 day before the end (e-mail + WhatsApp via
  `Notifier::sendToContact`, sent flags in hotel setting `trial_reminders`), at the end `Hotels::setStatus(expired,
  'trial')` → TVs show "service paused", admin read-only except billing (existing suspended behaviour).
  Unverified requests older than a day → `expired`.
* Upgrade (`admin/partials/billing.d/50_trial_upgrade.php`, POST `op=trial_upgrade` on `billing.php`, allowed
  while expired): plan + TV count → `Signup::requestUpgrade()` → `Billing::createInvoice()` for one month
  (earlier unpaid upgrade invoice cancelled), hotel setting `trial_upgrade`, platform notified. When the platform
  marks the invoice paid, `Billing::reactivateIfPaid()` calls `Signup::activatePaidUpgrade()`: `is_trial = 0`,
  chosen plan, no expiry, plan TV limit, status active, `signups.converted_at`.
* Platform → Sign-ups & trials: list (filters verify / pending / approved / rejected / expired / active trials /
  converted), stats (pending, active trials, conversion %), approve / reject / +7 days, settings. AJAX `signup_stats`.

Platform settings (hotel 0, `Settings::platform()`): `signup_enabled` (default 0), `signup_mode`, `trial_days` (14),
`trial_plan_id`, `trial_max_tvs` (5), `signup_terms`, `signup_notify_email` (fallback `platform_notify_email`),
`signup_captcha`, `signup_min_seconds`, `signup_blocked_domains`.

---------------------------------------------------------------------------------------------------
## 3. Demo mode

**Public demo** (Platform → Demo, `demo_public_enabled`): one hotel with `demo_kind = 'public'`
(`demo_hotel_id`), created / reset by `Demo::resetPublic()` — button, and nightly by `DemoResetTask` (once a day
after 03:00, or when the last reset is older than 26 h). Data: 20 rooms on 2 floors, floor + VIP groups,
timetable, announcements, 2 playlists, a daily schedule, 3 simulated TVs (`device_uid demo-<hotel>-n`, kept
online by `Demo::keepAlive()`; `max_tvs = 3` so no real TV can register), 7 days of play logs / status logs /
usage samples and — when the module tables exist — 4 checked-in guests, room-service menu, orders, a request,
feedback, a sponsor + ad campaign with stats.

* `demo.php` — landing page: "Open the demo admin panel" (POST, CSRF, 30 / IP / hour) logs in the demo user
  (role manager, random unknown password), "See the TV" room buttons, "Start free trial".
* `demo_tv.php?room=<id>` — public TV simulator (`admin/partials/tv_simulator.php`, same as `admin/preview.php`),
  `&json=1` for the live refresh. Only rooms of the public demo hotel: any other id is a plain 404. 900 req / IP / hour.
* **Write guard** `Demo::guard()` (boot hook): for hotel users of a read-only demo hotel every non-GET request to
  an `admin/` script is refused before the page runs — AJAX: `403 {"code":"DEMO_READONLY"}`, pages: flash
  "Demo mode — changes are disabled." + `303` back. Allowed: `login.php`, `logout.php`, `ajax.php?action=set_language`.
  Platform admins / resellers inside the demo hotel are not restricted.
* Demo banner on every page of a demo hotel (footer hook) and on `demo.php`; "Demo" badge in the TV simulator.

**Demo for client** (platform admins + resellers, max 10 active per reseller): `Demo::createClientDemo()` — hotel
"<prospect> (Demo)", `demo_kind = 'client'`, same rich data, own login (super admin, or manager + read-only when
"Read-only" is ticked — hotel setting `demo_readonly`), valid N days (default 7; login shown once). Resellers
see / enter / delete only their own. On expiry `DemoResetTask` → `Demo::expireClientDemos()`: status `expired` +
`Demo::purge()` (every row with that `hotel_id` in every table, FK order resolved by retries, users, settings,
upload folders; the hotel row and invoices stay, `demo_purged_at` set). `purge()` refuses non-demo hotels.

---------------------------------------------------------------------------------------------------
## 4. Shared changes (outside the module files)

| file | change |
|---|---|
| `install/Installer.php` | `demoData()` delegates to `Demo::sampleContent()` (same data, also usable after `/install` is deleted) |
| `core/Billing.php` | `reactivateIfPaid()` first calls `Signup::activatePaidUpgrade()` |
| `admin/billing.php` | hook `admin/partials/billing.d/*.php` (handle own POST, return a card renderer) |
| `admin/preview.php` | simulator HTML/JS moved to `admin/partials/tv_simulator.php` (refresh URL / close link as variables) |
| `admin/login.php` | "Start free trial" / "Try the demo" buttons when enabled |
| `phpunit.xml` | `tests/Integration/SignupDemoTest.php` |

Known limitations: guest-facing token pages (`g/…`, QR on the demo TVs) of the public demo hotel accept orders
/ requests like any hotel (reset nightly, normal guest rate limits). OTP mails need a working `mail()` — choose
`auto` or `manual` otherwise. Payments stay manual (invoice marked paid by the platform).
