# Panels — Super Admin console, Reseller panel, Customer workspace (2.6)

Release 2.6 splits the admin UI into three clearly separate panels. Before, a platform admin logged in,
landed on the customer list and then *entered* a customer, after which the sidebar was the customer's own
menu (content, playlists, screens …) with a small "Back to platform" button — the console looked exactly
like a client's panel. The owner's rule for 2.6: **the Super Admin controls everything for everyone from
inside their own console, and that console must not look like a client.**

## What we looked at (research, October 2026)

| Product / pattern | What we took from it |
|---|---|
| **Yodeck Partner Administrative Console** (help.yodeck.com, yodeck.com/docs) | Partners (resellers) get a *separate* console with its own login context; the partner's own signage account is opened through a link from the console, never mixed into it. The console home shows quick links, invoices, recent actions and support links. → our Reseller panel: own theme, overview with KPIs, "Open customer workspace" as an explicit action. |
| **Xibo community guidance** | Tenants that must never mix are kept strictly apart (they even recommend one CMS per group). → nothing in the customer workspace may link to platform pages, and platform pages never render inside the customer shell. |
| **Impersonation patterns** (WorkOS AuthKit docs, GitLab super-sidebar issue #396689, Atlassian CONFCLOUD-61719, SaaSykit) | A *persistent, always-visible banner* with a one-click exit is the consistent pattern (transient popups were reported as a usability bug); the exit control is a distinct, recognisable button; the real actor is visible in the header; every action is audited. → the "You are managing customer … as Super Admin — Exit" banner, the sticky console badge, and `hotel_enter` + per-action audit rows. |
| **Customer 360 consoles** (BMC Helix Customer 360) | One page per customer with an identity header (status, plan, contact) and tabbed sections that aggregate data from every subsystem, so an operator never has to "become" the customer for routine work. → `platform_customer.php` with Summary, Plan & features, Screens, Users, Content, Billing, Activity, Settings. |
| **Filament global search** (laravel-news.com) | A single search box in the header that searches every resource by its title attributes and lists results grouped by resource. → `platform_search.php`: customers, screens / TVs (name, device id, IP), users (email / username), resellers; scoped to what the user may see. |
| **Microsoft 365 admin center home** (Microsoft docs via managedsolution / trustedinstitute) | Home = service health + "issues for your organisation to act on" (hidden when empty) + usage KPIs + recommended actions; settings grouped in sections. → the Overview page: KPI tiles, an *Alerts* list that disappears when all is well, recent activity across customers, quick actions. |
| **Stripe / Vercel / Clerk dashboards** (general knowledge, no fresh source found) | Dark, neutral chrome for the operator console, light product UI for the end user; status as pills; toggles instead of edit forms for on/off decisions. → distinct colour theme per panel, one-click switches with CSRF + audit. |

## Design chosen

### Three panels, one `Panel` concept (`core/Panel.php`)

| Panel | Who | Theme | Home | Sidebar |
|---|---|---|---|---|
| `platform` — **Super Admin console** | `platform_admin` while **not** inside a customer | dark indigo sidebar + indigo top bar, "Super Admin" badge | `platform_overview.php` | Overview · Customers · All screens · Resellers · Chains ‖ Plans & features · Invoices · Licenses · Sign-ups & trials · Demo · Ad marketplace ‖ Support & logs · Platform settings · Auto-Update · Add TV (QR) · Notifications |
| `reseller` — **Reseller panel** | `reseller` while not inside a customer | teal sidebar, "Reseller" badge | `reseller_overview.php` | Overview · My customers · Screens · Plans (read-only) · Invoices · Support · Client demos · Chains · Add TV (QR) · Notifications |
| `customer` — **Customer workspace** | every customer role; **and** a platform admin / reseller / chain admin who opened a customer | the customer's own branding (logo, colour) | `index.php` | the customer modules only (`hotel` nav section, plus `chain` for chain-enabled customer admins) |
| `chain` | `chain_admin` outside a customer | slate | `chain.php` | chain section |

`Panel::current()` is derived from the role and `Auth::inEnteredHotel()`; nothing is stored. The header
sets `<body data-panel="…">` and `assets/css/admin.css` themes by that attribute (CSS variables only, no
second stylesheet). Nav items keep their `nav.d` section; `Panel::navSections()` picks the sections of the
current panel, orders them (`Panel::ORDER`) and groups the platform / reseller items under small headings.

### Impersonation is visible, never implicit

"Open customer workspace" (old: "Enter customer") keeps `Auth::enterHotel()` and its session semantics.
Inside, the page is the customer's workspace (customer theme and sidebar) with a prominent banner
*"You are managing customer **X** as Super Admin — Exit workspace"* above the top bar, and the user menu
still offers "Super Admin console". The customer's own staff never see platform or reseller items.

### Super Admin does the routine work from the console

* **Overview**: KPI tiles (customers / active / suspended / trials, TVs online / offline / outdated /
  health warnings, open sign-ups, unpaid invoices, pool TVs), an alerts list (expiring soon, suspended,
  overdue invoices, customers with offline TVs, pending sign-ups), recent activity across customers, quick
  actions.
* **Global search** in the header (`platform_search.php`): customers, screens / TVs, users, resellers.
  Resellers only find their own customers' data; customer users get 403.
* **Customer 360** (`platform_customer.php`): Summary · Plan & features (switch every feature on / off for
  this customer, limits inline) · Screens (commands, transfer, update, revoke) · Users (create, role,
  reset password, enable / disable) · Content (what is assigned, link into the workspace) · Billing ·
  Activity · Settings (status switch, branding, registration key, chain, delete).
* **Switches** (`admin/ajax_platform.php`, action `platform_toggle`): customer active / suspended, feature
  per customer, user active, plan active, sign-up open / closed, platform registration (pool), screen
  enabled. One click, CSRF header, permission + scope check, audit log row, JSON back.

### Reseller panel

Same shell as the console in teal; overview with their customers' KPIs, My customers (create / edit within
the allowance), Screens (scope-limited `platform_screens.php`), Plans they may sell (read-only), Invoices +
commission, Support (offline TVs / health of their customers). No platform-only item is ever listed, and
`Auth::can()` still guards every page server-side.

## Files

* `core/Panel.php` — panel detection, theme, nav sections / order / groups, labels.
* `admin/partials/header.php` — panel-aware shell (brand block, badge, global search, impersonation banner).
* `admin/partials/panel_ui.php` — shared pieces: KPI tile, switch, empty state, alert list.
* `admin/platform_overview.php`, `admin/platform_search.php`, `admin/platform_customer.php` (extended),
  `admin/ajax_platform.php`, `admin/reseller_overview.php`, `admin/reseller_plans.php`,
  `admin/reseller_invoices.php`, `admin/reseller_support.php`.
* `assets/css/admin.css` (`[data-panel]` themes), `assets/js/admin.js` (switches).
* `lang/gu_panels.php`, `lang/hi_panels.php`.
* Tests: `tests/Integration/Apps/PanelsTest.php`.
