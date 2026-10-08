# Krishna Cloud TV Management — Admin User Guide

Open `https://your-domain/hotelcast/admin/` and log in. Switch the language (English / ગુજરાતી / हिन्दी)
from the top bar. Everything works on a phone, tablet or PC.

## Roles

Krishna Cloud TV Management is a digital-signage SaaS platform for any business (temples, shops, restaurants, hospitals, schools, offices, factories, hotels). From top to bottom: the **Super Admin (Platform)** owns the platform,
each customer (a business) has one or more **Admins**, and the Admin adds the people who work with the TVs.

| Role (shown as) | Scope | Can do |
|------|-------|--------|
| **Super Admin (Platform)** | whole platform | Customers, plans, invoices, resellers, licenses, platform settings & branding, features (e.g. chains), auto-update / backups; can *enter* any customer and works there as its Admin |
| **Reseller** | own customers | Create customers (within an allowance), enter and manage them, see their invoices and the commission report |
| **Admin** | one customer | Everything of the customer: users, settings, billing (read-only invoices) and all of the below. Always controls all TVs |
| **Manager** | one customer | Screens, groups, content, playlists, broadcasts, schedules, device commands (reboot…), APK manager, logs |
| **Staff** | one customer | View dashboard/screens/content, push existing content and playlists, send emergency messages |
| **Reception** | one customer | Front desk: view dashboard and screens; guests check-in/out and service orders (Hospitality module) |

Older screens and documents call the Admin "Super Admin" and the Super Admin (Platform) "Platform
Admin". Only the names changed; the accounts and their rights are the same.

**Different people for different TVs.** When the Admin adds or edits a Manager, Staff or Reception
user (**Users**), they choose **All TVs** (default) or **Only these TVs** and tick groups and/or screens.
Such a user then sees and controls only those TVs: screens list, dashboard numbers, broadcasts, commands,
power, schedules, emergency messages, guests and orders of those screens. They cannot send to "All
screens", cannot add or delete screens or groups, cannot change TV settings for all screens (volume rules, guest
menu, power-off mode) and can stop only emergency messages that show on their TVs. The content library
and playlists stay shared. A group gives access to every screen in the group, also screens added later.
Details: `docs/modules/user_access.md`.

A customer's users only ever see **their own customer** — screens, TVs, content, logs and users of other
customers on the same platform are invisible and cannot be opened even with a guessed link.

When a 1.x installation is upgraded to 2.0, the first Super Admin automatically becomes **Platform
Admin** (now shown as Super Admin (Platform)) and still manages the customer as before; other Super Admins
stay customer Admins.

**Chains** (one owner, several customers / locations) are switched off by default. The Super Admin (Platform)
can switch them on in **Platform settings → Features**; while off, the chain menus and pages are hidden
and existing chain data is kept.

Five wrong passwords lock an account for 15 minutes (an Admin can unlock it in **Users**).

## 1. Dashboard

Live numbers (screens, TVs online/offline, screens without a TV), a screen grid that refreshes every
10 seconds (green = online, red = offline, grey = no TV yet) showing what each TV is playing, recent
activity, and quick buttons: **Broadcast**, **Emergency message**, **Refresh all TVs**.

## 2. Setting up screens and TVs

* TVs register themselves: on the TV enter the server address, a screen name / ID and the **registration key**
  (Admin → Settings → Devices). If *Create screens automatically* is on, the screen appears automatically
  (screen `305` → area / floor `3`). Each screen has a **screen name / ID** (what the TV types or the QR
  setup picks) and an optional **location** (area / floor, groups).
* Or add screens first in **Screens & TVs → Add screen**, or **Bulk add screens** (e.g. `101-120`, floor `1`).
* Each screen shows its TV status, last seen, IP, app version, Android version and Wi-Fi.
* Per screen you can assign content/playlist, switch the TV **off/on**, set a screen-specific settings PIN,
  **reboot**, **clear cache**, **refresh**, or **revoke** a TV (it must register again).

## 3. Groups

**Groups** collect screens by floor, zone (e.g. "Display walls") or anything else. *Auto-create floor groups*
builds one group per floor. A group can have its own content — screens without their own content show it.

## 4. Content library

| Type | Use it for |
|------|-----------|
| Image | Photos, posters, menus (resized to 1920 px automatically) |
| Video | MP4/WEBM/MKV/MOV (compressed automatically if the server has ffmpeg) — loop/mute options |
| Live Stream | Dwarkadhish live darshan — paste the HLS (`.m3u8`) or RTSP address |
| Temple Timetable | Type the times and darshan names; the current darshan is highlighted on the TV |
| Announcement | Full-screen message or scrolling marquee, colours and font size |
| Custom HTML | Restaurant menu, local guide, offers designed in HTML |
| Web URL / YouTube | Any web page or YouTube video/live |
| Clock | Big digital or analog clock |

Use **Preview** to see exactly how it will look on a TV before publishing.

## 5. Playlists (slideshows)

**Playlists** play several items in order — drag to reorder, set a duration per item, choose the
transition (fade / slide / none). A playlist of images is a slideshow.

## 6. Broadcast

1. Choose **what**: a content item or a playlist.
2. Choose **where**: all screens, selected screens, groups or floors.
3. **Push now** — TVs switch within one poll interval (default 8 s).
   Or **Schedule**:
   * *Once at a time* — e.g. switch every TV to the darshan stream tomorrow at 06:25.
   * *Time window* — e.g. show the Aarti stream **every day 19:15–20:00**, or a breakfast offer on
     **Sat/Sun 07:00–10:30**. Outside the window screens go back to their normal content automatically.

**Emergency message** overrides everything on the selected TVs immediately (red full-screen) until you
press **Stop** (the red banner at the top of every admin page also has a Stop button).

Managers also get **device commands**: reboot, clear cache, reload app, screen on/off, ping.

Priority on a TV: *screen off* → *emergency* → *active time window* → *screen content* → *group content*
→ *default content* → *welcome screen*.

## 7. Schedule

Calendar of all scheduled broadcasts (month/week/day), with a list to edit, cancel or delete them.

## 7a. TV Power (automatic off / on)

**TV Power** (Manager+):

* **Turn TVs off / on now** — all, selected screens, groups or floors. A screen switched off stays off,
  even after a TV restart, until you turn it on.
* **Daily schedules** — e.g. *OFF 23:00 → ON 06:00, every day, all screens* or *OFF 01:00 → ON 05:30 on
  floor 2*. Overnight times are supported. Pause/resume or delete any schedule.
* Anyone can always switch on the TV with the remote; an emergency message wakes all TVs.
* Real standby / wake needs the TV app as *device owner* and the TV's *Quick start / Network standby*
  setting on — see `android/README.md` → *TV power*. Without it the TV only shows a black screen.

## 8. APK Manager (TV app updates)

Upload a new signed APK with its version name and version code, then **Push App Update** to all or
selected screens. TVs download it, verify the SHA-256, install it (silently when the app is device owner)
and restart. The device table shows each TV's current app version and update status.

## 9. Logs & reports

TV online/offline history, content play history per screen, broadcast delivery, user activity, system
errors and update logs — filter by screen/user/date and export to CSV.

## 10. Settings (Super Admin)

All settings below are **per customer**.

* **General** — business name, logo, time zone, default language.
* **TV & Display** — poll interval (3–60 s), offline threshold, default content, clock/logo/weather
  overlays, bottom ticker text and colours.
* **Devices** — registration key (regenerate if leaked; it also tells the platform which customer a TV
  belongs to), auto-create screens, TV settings PIN.
* **Media** — CDN base URL, upload limits, image size.
* **Notifications** — email and/or WhatsApp (e.g. CallMeBot URL with `{message}`) when TVs go offline;
  *Send test notification*.
* **Maintenance** — log retention, clear cache.

## 11. Auto-Update & backups (Platform Admin)

See the README section *Auto-update*. Buttons: **Check for Update**, **Update Now** (live progress),
update history with **Rollback** per row, **Backups** (create, download, upload, restore, delete) and
**System health**.

## 12. Users

Add users with a role and language, reset passwords, disable accounts, unlock locked accounts, see each
user's activity and active sessions (and log them out remotely). For Managers, Staff and Reception choose
**All TVs** or **Only these TVs** (groups / screens, searchable); the **TVs** column shows each user's scope
and a "Who can do what" box explains the roles.

## 13. Billing (customer Admin)

**Billing** shows the customer's plan, the amount due and every invoice (read-only) with a printable /
PDF view. If the customer is suspended for non-payment, the admin panel shows a red banner, TVs show a
polite *"Service paused — please contact the administrator"* screen and all changes are disabled — only
Billing, the profile and logout keep working. Payment recorded by the platform reactivates the customer
automatically.

---------------------------------------------------------------------------------------------------
# Platform administration (multi-customer / SaaS)

The **Platform** section of the menu is visible to Platform Admins. The customer menu above it always
belongs to the customer you are currently in (your own customer, or the one you *entered*).

## 14. Customers

* List of all customers with plan, TVs (used / limit, online), reseller, unpaid invoices and status;
  search and filter by status.
* **New customer**: name, plan, reseller (or direct customer), max TVs (empty = plan limit), valid-until
  date, status, contact person, phone / WhatsApp, billing email, GSTIN, address, optional
  **branding override** (product name, colour, logo) and the customer's **first super admin** login.
  A unique registration key is generated automatically.
* **Customer details**: TVs, screens, expiry, registration key (*New registration key*), the login link
  `…/admin/login.php?b=<slug>` that shows the customer's branding, users, invoices, *Add a super admin*.
* **Suspend / Activate**: a suspended (or expired) customer's TVs show the *service paused* screen
  (emergency messages still work), new TVs cannot register (`HOTEL_SUSPENDED`), and its admin panel
  is read-only except Billing.
* **Enter customer**: switches your session into that customer — every customer page (screens, content, users,
  settings…) now works on it. A coloured banner *"You are managing customer X"* is shown with
  **Back to platform**.

## 15. Plans & TV limits

**Plans** hold the price per TV per month, an optional TV limit and the features included (see [modules/plans_features.md](modules/plans_features.md); e.g. guests,
room service (Hospitality), advertising, analytics, templates, mobile app, support). A customer's TV limit is its own
*Max TVs* or else its plan's limit; when it is reached, a new TV is refused with `LICENSE_LIMIT`
(remove / revoke an old TV in Screens & TVs to free a slot). The dashboard widget *Plan & TV limit*
shows the usage to the customer's Super Admin.

## 16. Invoices (manual billing)

* **Generate monthly invoices** (pick the month, default last month): one invoice per active customer
  with a plan and at least one TV — *active TVs × plan price*, plus tax (Platform settings → Billing).
  Customers already invoiced for that month are skipped, so it is safe to click twice. With *Generate
  automatically* switched on this runs by itself on the 1st of every month.
* **Single invoice**: any customer, month, optional TV count / price override and notes.
* Numbers are sequential per year: `<prefix>-<year>-0001`.
* **Mark paid**: payment date, method (UPI, bank transfer, cheque, cash) and reference (UTR / cheque
  no.). **Cancel** an unpaid invoice. **Send reminder** by email / WhatsApp. **Print** opens a clean
  invoice page (browser *Print → Save as PDF*).
* **Overdue**: every day the platform reminds overdue customers (every *N* days, email and/or WhatsApp)
  and **automatically suspends** a customer whose invoice is more than *auto-suspend days* overdue. As
  soon as the payment is recorded the customer is **reactivated automatically** (a manual suspension is
  never lifted automatically). *Run overdue check* runs the job immediately.

## 17. Resellers

* **Resellers**: company, contact, commission %, customer allowance (max number of customers), status, and
  branding for their customers (product name, colour, logo, support phone / email — used on their
  customers' TVs, login link and admin panel).
* **Reseller logins**: add one or more logins (role *Reseller*); disable / enable them. Suspending a
  reseller logs its users out and blocks their login.
* The **reseller panel** (*My customers*) shows only the reseller's own customers with TV status, lets them
  **Add customer** (with its first super admin, within the allowance), **Enter** a customer to manage it,
  see the invoices of their customers and the **commission report** for a date range:
  *commission = paid invoices of their customers, without tax × commission %*. Resellers cannot change
  plans' prices, TV limits, expiry dates or status — that stays with the platform.

## 18. Licenses (self-hosted customers)

For customers that run the server on their own hosting (*standalone* mode, see INSTALL.md):
**New license** generates a key `HC-XXXXX-XXXXX-XXXXX-XXXXX` with customer name, max TVs, valid-until
date and an optional billing customer (suspending that customer also invalidates the license). The
installation checks the key once a day; the key is **bound to the domain of its first check**
(*Reset domain* when the customer moves servers). **Revoke** stops the installation after its next
check (its TVs show the *service paused* screen). The list shows the last check, version and TV count.

## 19. Platform settings

* **Branding** (white-label): product name (replaces "Krishna Cloud TV Management"), logo, primary colour, support phone
  and email, footer text — used on the login page, admin panel, installer, invoices and every TV.
  Resellers and customers can override it.
* **Billing**: currency (INR default), tax % and label (GST), invoice prefix, payment due days,
  auto-suspend after *N* days overdue (0 = never), reminders by email / WhatsApp and how often,
  WhatsApp gateway URL (`{phone}` and `{message}` placeholders), your company details printed on
  invoices (address, GSTIN, bank / UPI).
* **Notifications**: platform admin email(s) for alerts (e.g. auto-suspended customers) and the sender
  address for invoices / reminders.
* **Platform admins**: add more platform admins, disable them.
* **License**: on a self-hosted installation the license status, last check and *Check license now*.

**Support** (menu placeholder) lists customers with offline TVs; the full support dashboard follows in a
later release.



## 2.0 modules

Step-by-step guides for the new pages are in:

* Front desk, check-in mode, PMS, room service, requests, feedback (Hospitality module): [modules/guests_services.md](modules/guests_services.md)
* Ads & sponsor reports, analytics, templates & local guide: [modules/ads_analytics_templates.md](modules/ads_analytics_templates.md)
* TV controls (volume, inputs, messages), support tools, phone app & notifications, setup file: [modules/pwa_support_devices.md](modules/pwa_support_devices.md)
* Bulk TV setup on Windows: [../tools/windows/README.md](../tools/windows/README.md)

* Add TVs with a QR code (no typing on the TV): [modules/qr_setup.md](modules/qr_setup.md)
* Online sign-up, free trial and demo: [modules/signup_demo.md](modules/signup_demo.md)
* Ad marketplace (local businesses book ads, revenue share): [modules/ad_marketplace.md](modules/ad_marketplace.md)
* Chain dashboard: [modules/hotel_chains.md](modules/hotel_chains.md)
* Terminology (UI term ↔ database / API name): [modules/terminology.md](modules/terminology.md)
