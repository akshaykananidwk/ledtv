# HotelCast — Admin User Guide

Open `https://your-domain/hotelcast/admin/` and log in. Switch the language (English / ગુજરાતી)
from the top bar. Everything works on a phone, tablet or PC.

## Roles

| Role | Scope | Can do |
|------|-------|--------|
| **Platform Admin** | whole platform | Hotels, plans, invoices, resellers, licenses, platform settings & branding, auto-update / backups; can *enter* any hotel and works there as its Super Admin |
| **Reseller** | own hotels | Create hotels (within an allowance), enter and manage them, see their invoices and the commission report |
| **Super Admin** | one hotel | Everything inside the hotel: users, settings, billing (read-only invoices) and all of the below |
| **Manager** | one hotel | Rooms, groups, content, playlists, broadcasts, schedules, device commands (reboot…), APK manager, logs |
| **Staff** | one hotel | View dashboard/rooms/content, push existing content and playlists, send emergency messages |
| **Reception** | one hotel | Front desk: view dashboard and rooms; guests check-in/out and service orders (modules added in 2.x) |

A hotel's users only ever see **their own hotel** — rooms, TVs, content, logs and users of other
hotels on the same platform are invisible and cannot be opened even with a guessed link.

When a 1.x installation is upgraded to 2.0, the first Super Admin automatically becomes **Platform
Admin** (and still manages the hotel as before); other Super Admins stay Super Admins.

Five wrong passwords lock an account for 15 minutes (a Super Admin can unlock it in **Users**).

## 1. Dashboard

Live numbers (rooms, TVs online/offline, rooms without a TV), a room grid that refreshes every
10 seconds (green = online, red = offline, grey = no TV yet) showing what each TV is playing, recent
activity, and quick buttons: **Broadcast**, **Emergency message**, **Refresh all TVs**.

## 2. Setting up rooms and TVs

* TVs register themselves: on the TV enter the server address, room number and the **registration key**
  (Admin → Settings → Devices). If *Auto-create rooms* is on, the room appears automatically
  (room `305` → floor `3`).
* Or add rooms first in **Rooms → Add room**, or **Bulk add** (e.g. `101-120`, floor `1`).
* Each room shows its TV status, last seen, IP, app version, Android version and Wi-Fi.
* Per room you can assign content/playlist, switch the TV **off/on**, set a room-specific settings PIN,
  **reboot**, **clear cache**, **refresh**, or **revoke** a TV (it must register again).

## 3. Groups

**Groups** collect rooms by floor, zone (e.g. "Suites") or anything else. *Auto-create floor groups*
builds one group per floor. A group can have its own content — rooms without their own content show it.

## 4. Content library

| Type | Use it for |
|------|-----------|
| Image | Photos, posters, menus (resized to 1920 px automatically) |
| Video | MP4/WEBM/MKV/MOV (compressed automatically if the server has ffmpeg) — loop/mute options |
| Live Stream | Dwarkadhish live darshan — paste the HLS (`.m3u8`) or RTSP address |
| Temple Timetable | Type the times and darshan names; the current darshan is highlighted on the TV |
| Announcement | Full-screen message or scrolling marquee, colours and font size |
| Custom HTML | Hotel menu, local guide, offers designed in HTML |
| Web URL / YouTube | Any web page or YouTube video/live |
| Clock | Big digital or analog clock |

Use **Preview** to see exactly how it will look on a TV before publishing.

## 5. Playlists (slideshows)

**Playlists** play several items in order — drag to reorder, set a duration per item, choose the
transition (fade / slide / none). A playlist of images is a slideshow.

## 6. Broadcast

1. Choose **what**: a content item or a playlist.
2. Choose **where**: all rooms, selected rooms, groups or floors.
3. **Push now** — TVs switch within one poll interval (default 8 s).
   Or **Schedule**:
   * *Once at a time* — e.g. switch every TV to the darshan stream tomorrow at 06:25.
   * *Time window* — e.g. show the Aarti stream **every day 19:15–20:00**, or a breakfast offer on
     **Sat/Sun 07:00–10:30**. Outside the window rooms go back to their normal content automatically.

**Emergency message** overrides everything on the selected TVs immediately (red full-screen) until you
press **Stop** (the red banner at the top of every admin page also has a Stop button).

Managers also get **device commands**: reboot, clear cache, reload app, screen on/off, ping.

Priority on a TV: *room off* → *emergency* → *active time window* → *room content* → *group content*
→ *hotel default content* → *welcome screen*.

## 7. Schedule

Calendar of all scheduled broadcasts (month/week/day), with a list to edit, cancel or delete them.

## 7a. TV Power (automatic off / on)

**TV Power** (Manager+):

* **Turn TVs off / on now** — all, selected rooms, groups or floors. A room switched off stays off,
  even after a TV restart, until you turn it on.
* **Daily schedules** — e.g. *OFF 23:00 → ON 06:00, every day, all rooms* or *OFF 01:00 → ON 05:30 on
  floor 2*. Overnight times are supported. Pause/resume or delete any schedule.
* A guest can always switch on the TV with the remote; an emergency message wakes all TVs.
* Real standby / wake needs the TV app as *device owner* and the TV's *Quick start / Network standby*
  setting on — see `android/README.md` → *TV power*. Without it the TV only shows a black screen.

## 8. APK Manager (TV app updates)

Upload a new signed APK with its version name and version code, then **Push App Update** to all or
selected rooms. TVs download it, verify the SHA-256, install it (silently when the app is device owner)
and restart. The device table shows each TV's current app version and update status.

## 9. Logs & reports

TV online/offline history, content play history per room, broadcast delivery, user activity, system
errors and update logs — filter by room/user/date and export to CSV.

## 10. Settings (Super Admin)

All settings below are **per hotel**.

* **General** — hotel name, logo, time zone, default language.
* **TV & Display** — poll interval (3–60 s), offline threshold, default content, clock/logo/weather
  overlays, bottom ticker text and colours.
* **Devices** — registration key (regenerate if leaked; it also tells the platform which hotel a TV
  belongs to), auto-create rooms, TV settings PIN.
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
user's activity and active sessions (and log them out remotely).

## 13. Billing (hotel Super Admin)

**Billing** shows the hotel's plan, the amount due and every invoice (read-only) with a printable /
PDF view. If the hotel is suspended for non-payment, the admin panel shows a red banner, TVs show a
polite *"Service paused — please contact reception"* screen and all changes are disabled — only
Billing, the profile and logout keep working. Payment recorded by the platform reactivates the hotel
automatically.

---------------------------------------------------------------------------------------------------
# Platform administration (multi-hotel / SaaS)

The **Platform** section of the menu is visible to Platform Admins. The hotel menu above it always
belongs to the hotel you are currently in (your own hotel, or the one you *entered*).

## 14. Hotels

* List of all hotels with plan, TVs (used / limit, online), reseller, unpaid invoices and status;
  search and filter by status.
* **New hotel**: name, plan, reseller (or direct customer), max TVs (empty = plan limit), valid-until
  date, status, contact person, phone / WhatsApp, billing email, GSTIN, address, optional
  **branding override** (product name, colour, logo) and the hotel's **first super admin** login.
  A unique registration key is generated automatically.
* **Hotel details**: TVs, rooms, expiry, registration key (*New registration key*), the login link
  `…/admin/login.php?b=<slug>` that shows the hotel's branding, users, invoices, *Add a super admin*.
* **Suspend / Activate**: a suspended (or expired) hotel's TVs show the *service paused* screen
  (emergency messages still work), new TVs cannot register (`HOTEL_SUSPENDED`), and its admin panel
  is read-only except Billing.
* **Enter hotel**: switches your session into that hotel — every hotel page (rooms, content, users,
  settings…) now works on it. A coloured banner *"You are managing hotel X"* is shown with
  **Back to platform**.

## 15. Plans & TV limits

**Plans** hold the price per TV per month, an optional TV limit and the modules included (guests,
room service, advertising, analytics, templates, mobile app, support). A hotel's TV limit is its own
*Max TVs* or else its plan's limit; when it is reached, a new TV is refused with `LICENSE_LIMIT`
(remove / revoke an old TV in Rooms & TVs to free a slot). The dashboard widget *Plan & TV limit*
shows the usage to the hotel's Super Admin.

## 16. Invoices (manual billing)

* **Generate monthly invoices** (pick the month, default last month): one invoice per active hotel
  with a plan and at least one TV — *active TVs × plan price*, plus tax (Platform settings → Billing).
  Hotels already invoiced for that month are skipped, so it is safe to click twice. With *Generate
  automatically* switched on this runs by itself on the 1st of every month.
* **Single invoice**: any hotel, month, optional TV count / price override and notes.
* Numbers are sequential per year: `<prefix>-<year>-0001`.
* **Mark paid**: payment date, method (UPI, bank transfer, cheque, cash) and reference (UTR / cheque
  no.). **Cancel** an unpaid invoice. **Send reminder** by email / WhatsApp. **Print** opens a clean
  invoice page (browser *Print → Save as PDF*).
* **Overdue**: every day the platform reminds overdue hotels (every *N* days, email and/or WhatsApp)
  and **automatically suspends** a hotel whose invoice is more than *auto-suspend days* overdue. As
  soon as the payment is recorded the hotel is **reactivated automatically** (a manual suspension is
  never lifted automatically). *Run overdue check* runs the job immediately.

## 17. Resellers

* **Resellers**: company, contact, commission %, hotel allowance (max number of hotels), status, and
  branding for their hotels (product name, colour, logo, support phone / email — used on their
  hotels' TVs, login link and admin panel).
* **Reseller logins**: add one or more logins (role *Reseller*); disable / enable them. Suspending a
  reseller logs its users out and blocks their login.
* The **reseller panel** (*My hotels*) shows only the reseller's own hotels with TV status, lets them
  **Add hotel** (with its first super admin, within the allowance), **Enter** a hotel to manage it,
  see the invoices of their hotels and the **commission report** for a date range:
  *commission = paid invoices of their hotels, without tax × commission %*. Resellers cannot change
  plans' prices, TV limits, expiry dates or status — that stays with the platform.

## 18. Licenses (self-hosted customers)

For hotels that run HotelCast on their own server (*standalone* mode, see INSTALL.md):
**New license** generates a key `HC-XXXXX-XXXXX-XXXXX-XXXXX` with customer name, max TVs, valid-until
date and an optional billing hotel (suspending that hotel also invalidates the license). The
installation checks the key once a day; the key is **bound to the domain of its first check**
(*Reset domain* when the customer moves servers). **Revoke** stops the installation after its next
check (its TVs show the *service paused* screen). The list shows the last check, version and TV count.

## 19. Platform settings

* **Branding** (white-label): product name (replaces "HotelCast"), logo, primary colour, support phone
  and email, footer text — used on the login page, admin panel, installer, invoices and every TV.
  Resellers and hotels can override it.
* **Billing**: currency (INR default), tax % and label (GST), invoice prefix, payment due days,
  auto-suspend after *N* days overdue (0 = never), reminders by email / WhatsApp and how often,
  WhatsApp gateway URL (`{phone}` and `{message}` placeholders), your company details printed on
  invoices (address, GSTIN, bank / UPI).
* **Notifications**: platform admin email(s) for alerts (e.g. auto-suspended hotels) and the sender
  address for invoices / reminders.
* **Platform admins**: add more platform admins, disable them.
* **License**: on a self-hosted installation the license status, last check and *Check license now*.

**Support** (menu placeholder) lists hotels with offline TVs; the full support dashboard follows in a
later release.



## 2.0 modules

Step-by-step guides for the new pages are in:

* Front desk, check-in mode, PMS, room service, requests, feedback: [modules/guests_services.md](modules/guests_services.md)
* Ads & sponsor reports, analytics, templates & local guide: [modules/ads_analytics_templates.md](modules/ads_analytics_templates.md)
* TV controls (volume, inputs, messages), support tools, phone app & notifications, setup file: [modules/pwa_support_devices.md](modules/pwa_support_devices.md)
* Bulk TV setup on Windows: [../tools/windows/README.md](../tools/windows/README.md)
