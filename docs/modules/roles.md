# Module: Custom roles (RBAC) — 2.5

What a **user** may do inside their customer account. It is separate from the plan (core/Features.php):

> **Effective permission = the user's role allows it AND the customer's plan includes it.**
> Then the per-user screen access (core/Access.php, docs/modules/user_access.md) limits **which** screens.

Files: `core/Roles.php`, `core/Auth.php` (`can`, `userCan`, `planAllows`, `customRoleId`, `isAdmin`, `roleName`),
`admin/roles.php`, `admin/users.php`, `admin/partials/nav.d/19_roles.php`, `core/boot.d/roles.php`,
`migrations/029_roles.sql`, `lang/gu_roles.php`, `lang/hi_roles.php`, `tests/Integration/Apps/RolesTest.php`.

## Data

| | |
|---|---|
| `roles` (tenant table) | `id, hotel_id, name, description, base_level, is_system, permissions (JSON list), created_by, created_at, updated_at`. Unique name per customer. |
| `users.role_id` | `NULL` = built-in role (`users.role` exactly as before). Otherwise the custom role (same customer). |
| `users.role` for a custom role | the role's `base_level` (`manager` / `staff` / `reception`, never `super_admin`), only for code that still compares levels. It is computed: the lowest built-in role whose default permissions cover the role's list. |

**Built-in roles** (Admin = `super_admin`, Manager, Staff, Reception) are **not stored**. `Roles::builtIn()` computes
them from the permission registry (`Auth::PERMISSIONS` + `Auth::registerPermission()` of every module), so a new
module's permission is automatically part of the built-in roles and shows up in the matrix. Their behaviour is
unchanged.

## Permission check (`Auth::can`)

1. Role-list permissions (platform / reseller / chain / `push.self`): the real role must be listed (unchanged).
2. Hotel permissions:
   * built-in role: level comparison as before (Admin has every permission);
   * custom role: the permission must be in the role's list, and only inside the user's own customer;
   * **and** `Auth::planAllows($perm)` → `Features::permissionEnabled($perm)` when `core/Features.php` exists
     (guarded with `class_exists` / `method_exists`; falls back to "enabled"). So the Admin has exactly the
     permissions of the customer's plan.
3. Cached per request (`Auth::forgetPermissions()` clears it). Nothing is cached in the session, so a role change
   applies on the user's **next request**; their sessions stay.

`Auth::userCan($userRow, $perm)` is the session-free variant (custom-role aware; used for staff push alerts).
`Auth::roleCan($role, $perm)` stays for built-in roles only.

## Pages and navigation

Pages call `Auth::require('<permission>')`, AJAX actions `require_can()`, and every `admin/partials/nav.d/*.php`
item declares a permission that `hc_nav_sections()` checks with `Auth::can()`. A custom role therefore hides menu
items and denies pages / AJAX actions automatically (403). `index.php` (Home) sends a custom role without
`dashboard.view` to its first allowed page (`Auth::HOME_PAGES`), also right after login.

## admin/roles.php (`roles.manage`, Admin only by default)

* **Built-in roles**: read-only view of their permissions, user count, **Copy**.
* **Custom roles**: create / edit / copy / delete, user count, "This role can …" summary.
* **Permission matrix**: grouped by plan feature (`Features::all()`, each feature's `permissions`), with fallback
  groups (Overview, Screens, Content, …) for permissions no feature lists. Each permission has a friendly label,
  a description and a **View / Manage / Action** badge. Per group a **Select all** switch; the side panel
  previews "This role can …" live.
* Permissions of features **not in the customer's plan are hidden** (not just disabled). A hidden permission that is
  stored in a role keeps its stored state when the role is saved (it works again after a plan upgrade) but is always
  denied while the plan lacks it. Inside an entered customer the platform admin sees the customer's plan
  (`Roles::planIncludes`, no platform bypass).
* **Delete**: only when no user has the role, or after choosing the role its users get instead.

## admin/users.php

The role dropdown lists the built-in roles plus the customer's custom roles (`role:<id>`) the editor may give;
the users list shows the role (custom roles with a green badge) and a **Roles** card with the user count per role.
The screen access UI (all screens / only these screens) is unchanged and works for custom roles too
(their `users.role` is always a limitable level). Custom roles can be given only while the plan includes the
"Custom roles" feature (users who already have one keep it).

## Safety rules

| Rule | Where |
|---|---|
| Nobody changes their own role or disables themselves; a custom-role user cannot edit or delete the role they have. | users.php, roles.php |
| Only an Admin can give the Admin role (also when reassigning users of a deleted role). | `Roles::canAssign` |
| A non-Admin (custom role with `users.manage`) can give only roles whose permissions they hold themselves, and cannot edit / delete / unlock / log out users whose role has more rights (e.g. the Admin). | `Roles::canAssign`, `can_manage_user()` |
| `roles.manage` can be put into a custom role only by an Admin; a non-Admin role editor can only add or remove permissions they hold; the others keep their stored state (logged in `logs/security`). | `Roles::sanitize` |
| At least one active Admin per customer remains (unchanged rule, also when an Admin is given a custom role). | users.php |
| Another customer's role id → 404 (`Tenant::find`, logged), for pages, POSTs and `role:<id>` in users.php. | `Roles::find` |
| CSRF on every POST (`Csrf::check`). | roles.php, users.php |
| Activity log: `role_create`, `role_update` (with "+perm, -perm"), `role_delete`, `user_role` ("Manager → Content editor"). | roles.php, users.php |

## Converted role checks

The panel already used permissions almost everywhere. Direct role comparisons that decided authorization:

* `core/StaffAlerts.php` (push recipients): `Auth::roleCan($role)` → `Auth::userCan($user)` (custom roles + plan).
* `admin/users.php`: role assignment / self-change / last-Admin checks now use role specs, `Roles::canAssign()`
  and `can_manage_user()`; before, `users.manage` was Admin-only so no extra rule was needed.
* `Auth::homePage()`: custom roles land on their first allowed page (built-in roles unchanged).
* Kept on purpose (safe with custom roles): `Access::isUnlimitedRole()` (custom roles are never `super_admin`, so
  they can always be limited), the chain super-admin grant in `core/Chains.php` (built-in Admin only),
  platform / reseller / chain role checks.

## Tests

`tests/Integration/Apps/RolesTest.php`: built-in roles = level comparison for every registered permission;
custom role grants / denies exact pages, AJAX actions and nav items; role changes apply on the next request;
plan-disabled permissions hidden and denied even when stored (via `Auth::$planOverride` and a sandbox-only
`core/Features.php` stub); escalation attempts (users.php and roles.php); last-Admin rule; tenancy (404);
CSRF; copy / create / delete with reassignment; screen access with a custom role; crawl of every admin page as a
"Content editor" (content + playlists only, no PHP warnings); gu / hi translations.

---

## ગુજરાતી સારાંશ

* **રોલ (RBAC)** નક્કી કરે છે કે ગ્રાહકના એકાઉન્ટમાં **યુઝર** શું કરી શકે. પ્લાન (Features) નક્કી કરે છે કે ગ્રાહક પાસે કયા
  ફીચર્સ છે. યુઝર કોઈ કામ ત્યારે જ કરી શકે જ્યારે **રોલ મંજૂરી આપે અને પ્લાનમાં ફીચર હોય**. પછી યુઝરનો સ્ક્રીન ઍક્સેસ
  (બધી સ્ક્રીન કે અમુક જ) નક્કી કરે છે કે કઈ સ્ક્રીન.
* **બિલ્ટ-ઇન રોલ** (એડમિન, મેનેજર, સ્ટાફ, રિસેપ્શન) બદલાતા નથી અને પહેલાં જેવા જ કામ કરે છે. નવા મોડ્યુલની પરવાનગીઓ
  તેમાં આપોઆપ ઉમેરાય છે.
* **કસ્ટમ રોલ**: એડમિન **યુઝર્સ → રોલ મેનેજ કરો** (admin/roles.php) પર નવો રોલ બનાવી શકે, બિલ્ટ-ઇન રોલની **કૉપી** કરી શકે,
  બદલી શકે અને કાઢી શકે. પરવાનગીઓ ફીચર મુજબ જૂથમાં છે ("જુઓ" / "મેનેજ"), દરેક જૂથમાં "બધું પસંદ કરો" છે અને બાજુમાં
  "આ રોલ આ કરી શકે છે …" સારાંશ દેખાય છે. પ્લાનમાં ન હોય એવી પરવાનગીઓ દેખાતી જ નથી.
* રોલ ફક્ત ત્યારે જ કાઢી શકાય જ્યારે કોઈ યુઝર પાસે તે ન હોય, અથવા તેના યુઝરને બીજો રોલ આપ્યા પછી.
* **સુરક્ષા**: કોઈ પોતાનો રોલ બદલી શકતું નથી; એડમિન રોલ ફક્ત એડમિન આપી શકે; ઓછામાં ઓછો એક સક્રિય એડમિન રહે જ;
  "રોલ મેનેજ કરો" પરવાનગી ફક્ત એડમિન આપી શકે; બીજા ગ્રાહકના રોલ ID પર 404. રોલનો ફેરફાર યુઝરની આગલી ક્લિકથી લાગુ પડે છે
  (લૉગઆઉટ નહીં). બધા ફેરફાર પ્રવૃત્તિ લૉગમાં નોંધાય છે.
