# HotelCast — Admin User Guide

Open `https://your-domain/hotelcast/admin/` and log in. Switch the language (English / ગુજરાતી)
from the top bar. Everything works on a phone, tablet or PC.

## Roles

| Role | Can do |
|------|--------|
| **Super Admin** | Everything, including users, settings, auto-update, backups |
| **Manager** | Rooms, groups, content, playlists, broadcasts, schedules, device commands (reboot…), APK manager, logs |
| **Staff** | View dashboard/rooms/content, push existing content and playlists, send emergency messages |

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

* **General** — hotel name, logo, time zone, default language.
* **TV & Display** — poll interval (3–60 s), offline threshold, default content, clock/logo/weather
  overlays, bottom ticker text and colours.
* **Devices** — registration key (regenerate if leaked), auto-create rooms, TV settings PIN.
* **Media** — CDN base URL, upload limits, image size.
* **Notifications** — email and/or WhatsApp (e.g. CallMeBot URL with `{message}`) when TVs go offline;
  *Send test notification*.
* **Maintenance** — log retention, clear cache.

## 11. Auto-Update & backups (Super Admin)

See the README section *Auto-update*. Buttons: **Check for Update**, **Update Now** (live progress),
update history with **Rollback** per row, **Backups** (create, download, upload, restore, delete) and
**System health**.

## 12. Users

Add users with a role and language, reset passwords, disable accounts, unlock locked accounts, see each
user's activity and active sessions (and log them out remotely).
