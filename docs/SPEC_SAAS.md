# TV Management SaaS — Owner's A-to-Z Product & Access-Control Specification

Status: authoritative product requirements supplied by the owner (2026-10-09). Development must follow
this document; where the existing code base differs, the code base is brought in line. Internal names
stay as documented in docs/modules/terminology.md (`hotels` = Customers/Organizations, `rooms` = Screens,
`devices` = TVs); the user-visible wording follows this specification.

---

## 1. Objective

Multi-tenant TV Management SaaS platform to centrally manage TVs / Android TV devices installed in
hotels, temples, hospitals, hostels, resorts, schools, corporate buildings and similar venues.

Two primary access levels:

### A. Super Admin (platform owner/operator)

Can: see all clients; manage clients; manage each client's subscription/plan; create/modify plans;
enable/disable modules; set client limits; see all registered TVs/devices with online/offline status
and errors; manage content; remote configuration; suspend/activate client accounts; see/manage a
client's users; system-wide settings; reports and analytics; audit logs.

### B. Client / Organization Admin (customer)

Can: see only its own organization's data; manage its own TVs/devices, rooms/locations, content and
settings; create users in its organization and give them permissions; manage its own profile/settings.

A client can never see or modify another client's organization, TVs, devices, rooms, users, content,
settings, reports or data. This isolation is enforced at backend / database / API level — hiding menus
in the frontend is not enough.

## 2. Multi-tenant architecture

Each client is a separate Organization / Tenant with a unique `organization_id` (in this code base:
`hotel_id`). Every tenant-related table carries it. Tenant scope is applied automatically to queries
(`Tenant::*`).

## 3. Access control chain (checked on every request)

Authentication → User → Role → Organization → Plan → Module → Permission → Resource.

1. Is the user authenticated?  2. Which organization?  3. Required permission?  4. Required module in
the plan?  5. Does the requested resource belong to that organization?  6. Is a plan limit exceeded?

## 4. User types

System roles: `SUPER_ADMIN` and `CLIENT_ADMIN`. Inside an organization the Client Admin creates
additional users (Manager, Reception, IT Staff, Operator …) with custom permissions.

## 5. Super Admin dashboard

KPIs: total/active/suspended clients; total/online/offline/error TVs; active plans; subscription
revenue; expiring subscriptions; recent registrations; recent device issues; recent activities.
Charts: client growth, device growth, plan distribution, device status, monthly revenue, active vs
inactive clients.

## 6. Client management (Super Admin)

List columns: organization name, client name, contact, email, mobile, plan, number of TVs, status,
registration date, subscription expiry, actions. Actions: view, edit, activate, suspend,
delete/archive, change plan, view devices, view users, view activity, view billing, login as client
(with strict audit logging).

## 7. Client self-registration flow

Register → organization details → admin details → select plan → payment (if applicable) →
organization created → client admin created → dashboard active → TV app installation → device
pairing. The client receives organization id, admin account, credentials, pairing and installation
instructions.

## 8–9. Plans and the plan → module system

Unlimited plans, created in the Super Admin UI; never hard-coded. A plan = modules (TV management,
device management, content, playlist, room management, remote control, scheduling, analytics,
reports, notifications, user management …) + limits (max TVs, users, rooms, storage, playlists …).
Modules are switched ON/OFF per plan in the UI. Clients see only enabled modules.

## 10. Module security

A module that is OFF in the plan is hidden in the menu AND denied on page access, API access, direct
URL access and backend services: HTTP 403 with "This feature is not available in your current plan."

## 11. Plan limits

Configured per plan (max TVs, users, rooms, locations, playlists, storage, content items, scheduled
events). Enforced in frontend and backend: "TV limit reached. Please upgrade your plan."

## 12. Client dashboard

Total/online/offline/error TVs, rooms, active users, content count, scheduled content, recent alerts.
Never any other client's data.

## 13–17. TV / device management, monitoring, pairing, TV app, heartbeat

Client: add/remove/rename TVs, assign room and location, see status, last seen, app version, device
info. Super Admin: all TVs platform-wide with filters (client, location, status, app version, device
type, last seen, error type). Pairing by device code shown on the TV; a device binds securely to one
organization. TV app: device auth, heartbeat, online/offline detection, content/playlist/config/
schedule sync, error reporting, app version and device info. Missing heartbeat ⇒ OFFLINE.

## 18–21. Content, playlists, scheduling, rooms/locations

Content types: images, videos, text, web URLs, announcements, promotions, venue information, emergency
messages. Playlists: create → add content → order → duration → assign TVs → publish. Scheduling (if
in plan): dates, times, days, TV/room, playlist. Rooms/locations: building, floor, room, department,
area.

## 22–23. Client user management and settings

Users with name, mobile, email, password/invite, role, permissions, status. Permission matrix per
module (view/edit/delete). Client settings: organization name, logo, address, contact, timezone,
notification preferences, branding, password, profile. Clients can never change plan, plan price,
plan modules, global system settings, other clients, system users, global API or billing settings.

## 24–26. Super Admin vs Client, data isolation, database security

Super Admin: ALL organizations/users/TVs/devices/content/plans/modules/settings/reports/logs.
Client: OWN only. Cross-tenant id access ⇒ 403 Forbidden with no data. Proper indexes and foreign
keys; tenant scope applied automatically.

## 27. Audit log

User, action, organization, IP, timestamp, resource, old value, new value for login/logout,
create/update/delete, device pairing/removal, plan change, user creation, permission change, content
publishing, client suspension. Super Admin sees all; a client sees its own if the module is in its plan.

## 28. Client suspend

Suspend ⇒ dashboard blocked, TVs restricted/gracefully locked, API denied; data kept; re-activation
restores everything.

## 29. Subscription management

Plan, start, expiry, status (TRIAL, ACTIVE, EXPIRING, EXPIRED, SUSPENDED, CANCELLED), billing cycle;
expiry notifications at 15/7/3/1 days and on expiry.

## 30. Super Admin impersonation ("Login as Client")

Audit-logged (performed by, acting as, timestamp, IP, actions); the super admin's own session stays
safe and distinct.

## 31–33. Notifications, security, API

Notifications for registrations, device offline/error, subscription expiring/expired, limit reached,
new user, security alerts (email/WhatsApp/SMS/push ready). Security: hashing, session security, HTTPS,
rate limiting, validation, RBAC, tenant isolation, SQLi/XSS/CSRF protection, upload validation, secrets
management, audit logging, login attempt protection, session expiry, device token security. Versioned
API (`/api/v1/...`) with separate device endpoints.

## 34. Frontend structure

Role-based dashboards. Super Admin: Dashboard, Clients, Plans, Modules, Subscriptions, Devices,
Content Overview, Reports, Notifications, Audit Logs, System Settings. Client: Dashboard, My TVs,
Rooms/Locations, Content, Playlists, Schedules, Users, Reports, My Settings, Subscription. Menus are
dynamic: disabled modules are not shown.

## 35–38. Zero manual setup, plan change, feature flags, white-label

Self-service registration end to end. Plan change updates modules and limits dynamically without
deleting data; on downgrade, existing devices are kept but new ones are blocked with a warning.
Dynamic feature/module flags; white-label-ready (logo, brand name, colours, domain, TV app branding).

## 39–53. Reporting, search/filter/export, error monitoring, system settings, backup, performance,
caching, indexing, UX, empty states, confirmations, deletion policy, global search, data export, API docs

As listed in the owner's specification: reports for super admin and clients; search/sort/pagination/
filters/export in every large module; device error monitoring with severity; system settings only for
the super admin; automated backups; scalable architecture with background jobs; caching without stale
security data; proper indexes; modern responsive UI, visually distinct for super admin and client;
empty states; confirmations before dangerous actions; soft delete / archive for business data;
super-admin global search; client data export; API documentation.

## 54–55. Testing requirements

Authentication, authorization, tenant isolation (Client A cannot access Client B even by changing ids —
mandatory), plan tests (module on/off, limits, upgrade/downgrade, expiry), device tests (pairing,
unpairing, heartbeat, offline, sync, invalid token).

## 56–71. Future AI readiness, business control, access matrix, core entities, development principles,
deployment, environment variables, logging, acceptance criteria, final product flow, deliverables

| Feature | Super Admin | Client Admin | Client User |
|---|---|---|---|
| All clients | YES | NO | NO |
| Own organization | YES | YES | YES |
| All TVs | YES | NO | NO |
| Own TVs | YES | YES | Permission |
| Plans / Modules | YES | NO | NO |
| Subscription | YES | View own | NO |
| Own users | YES | YES | Permission |
| Global settings | YES | NO | NO |
| Own settings | YES | YES | Permission |
| Content / Playlists / Scheduling | ALL | OWN | Permission |
| Device monitoring / Audit logs / Reports | ALL | OWN | Permission |
| Client suspension / System configuration | YES | NO | NO |

Principles: the frontend decides what the user sees, the backend decides what the user may do, the
database enforces tenant isolation. Never hard-code plans or client limits. Secrets only in environment
/ config, never in code. Acceptance: every item in the owner's §68 list.

## Mapping to the current code base (2.6)

| Spec term | Code base |
|---|---|
| Organization / Client | `hotels` table, "Customer" in the UI (Panel: customer) |
| Super Admin | role `platform_admin`, Super Admin console (Panel: platform) |
| Client Admin | role `super_admin` inside a customer |
| Client users / permissions | built-in roles + custom roles (`core/Roles.php`) |
| Plan / modules / limits | `plans`, `core/Features.php` (feature keys, limits, overrides) |
| Device pairing code | QR / setup code (`admin/claim.php`, device API) |
| Heartbeat | device poll (`api/device/*`, `DeviceManager::isOnline`) |
| Audit log | `activity_logs` (+ platform logs) |
| Impersonation | `Auth::enterHotel` with the impersonation banner |
| Reseller | optional partner level between Super Admin and clients (Panel: reseller) |
