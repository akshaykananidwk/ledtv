# QR setup of TVs — nothing to type on the TV

A fresh TV (app installed, not registered) shows a big **QR code** and a **6-character code**
(e.g. `K7P2QX`). Hotel staff scan it with any phone camera app, which opens the admin panel's
**Add TV with QR** page (`admin/claim.php?code=K7P2QX`). They pick the room and press **Assign**;
the TV registers itself within a few seconds.

| Part | File |
|---|---|
| Logic | `hotelcast/core/Provisioning.php` |
| TV API | `hotelcast/api/routes/provision.php` |
| Admin page | `hotelcast/admin/claim.php` (+ live status `admin/ajax.d/claim.php`) |
| Menu | `hotelcast/admin/partials/nav.d/15_qr_setup.php` ("Add TV (QR)"), button on Rooms & TVs |
| Table | `migrations/006_provisioning.sql` → `device_provisioning` (UTC timestamps) |
| Cleanup | `core/Tasks/ProvisioningCleanupTask.php` (daily, deletes rows older than 7 days) |
| Gujarati | `lang/gu_provision.php` |
| Tests | `tests/Integration/ProvisioningTest.php` |

---------------------------------------------------------------------------------------------------
## 1. API (TV side)

No device authentication — the TV is not registered yet. Responses use the usual envelope
`{ "ok": true, "data": {…} }` / `{ "ok": false, "error": { "code", "message" } }`.

### `POST /api/provision/start`

```json
{ "device_id": "3f1c9a52-…", "model": "Sony BRAVIA KD-43X80J", "app_version": "2.1.0" }
```

`device_id`: 8–64 characters `[A-Za-z0-9-]` (the same id later sent to `/api/device/register`).

```json
{ "ok": true, "data": {
  "code": "K7P2QX",
  "secret": "<64 hex characters>",
  "claim_url": "https://ledtv.akdwk.in/admin/claim.php?code=K7P2QX",
  "expires_in": 900,
  "poll_interval": 3
} }
```

* `code`: 6 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no 0/O/1/I/L), unique among the
  codes currently in use. Show it big next to the QR code of `claim_url`.
* `secret`: keep it in memory; only its SHA-256 is stored on the server. Never show it.
* Calling `start` again for the same `device_id` invalidates its previous **pending** code
  (e.g. when the code expired after 15 minutes, request a new one).
* Errors: `400 VALIDATION_ERROR` (bad `device_id`), `429 RATE_LIMITED` with `Retry-After`
  (10 starts / 10 min per IP — configurable with `provision_start_limit_ip` in `config.php` — and
  5 starts / 10 min per `device_id`).

### `GET /api/provision/status?code=K7P2QX&secret=<hex>`

Poll every `poll_interval` seconds.

| `data.status` | meaning | TV action |
|---|---|---|
| `pending` (+ `expires_in`) | waiting for staff | keep showing the QR |
| `claimed` | assigned to a room | register with the values below |
| `used` | this device registered in the claimed hotel | done (normally never seen) |
| `expired` | code timed out / replaced / room deleted | call `start` again, show the new code |

```json
{ "ok": true, "data": {
  "status": "claimed",
  "server_url": "https://ledtv.akdwk.in/",
  "room_number": "101",
  "registration_key": "<hotel registration key>",
  "hotel_name": "Hotel Dwarka Palace"
} }
```

Then call `POST {server_url}api/device/register` with `device_id`, `room_number`,
`registration_key` exactly like a manual setup. The claimed answer stays available for **30 minutes**
after the claim, or until the device has registered in that hotel (then `used`). Registration errors
(`HOTEL_SUSPENDED`, `LICENSE_LIMIT`, …) are the normal register errors.

* Wrong secret / unknown code → `404 NOT_FOUND`. 60 requests per minute per code → `429 RATE_LIMITED`.
* "used" is detected by the status endpoint (a device row with this `device_id`, registered after
  the claim in the claimed hotel) — `DeviceManager::register()` is unchanged.

### Default server and self-hosted hotels

The APK has a built-in default server URL (`https://ledtv.akdwk.in/`) and starts the QR setup
against it, so SaaS hotels never type anything. A **self-hosted** hotel types its own server address
once in the TV's setup screen (manual entry); the app then calls `/api/provision/start` on that server
and the QR flow works the same way. (`server_url` in the claimed answer is always the server that
issued the code.)

---------------------------------------------------------------------------------------------------
## 2. Admin page `admin/claim.php`

* **Login**: not logged in → `login.php?next=/admin/claim.php?code=…` → back to the page after login.
* **Permission**: `rooms.manage` (manager, super admin). Staff / reception → 403.
* **Hotel**: hotel users always work in their own hotel. Platform admins and resellers choose the
  hotel from a list of the hotels they may manage (a reseller with one hotel gets it preselected); the
  choice applies to that request only (it does not "enter" the hotel).
* **Room list**: rooms without an active TV first ("No TV yet"), then rooms with a TV ("Has TV" —
  warning: the new TV is added to the room, revoke the old one in Rooms & TVs if it is replaced),
  search box, and **Create new room** (number + floor) inline.
* Problems are shown **before** assigning and the button is disabled: hotel suspended / expired, TV
  limit (`max_tvs`) reached. A missing registration key is created automatically.
* After **Assign** the page shows "TV assigned to room …" and polls
  `ajax.php?action=claim_status&id=…` every 3 s: *Waiting for the TV…* → *Registered ✔*.
* Without a code: a big input to type the code (case-insensitive) for phones that cannot scan.
  Wrong codes are limited to 10 per user and 20 per IP in 15 minutes.
* Platform admins also see **TVs waiting for setup** (unclaimed codes of the last 15 minutes, all
  hotels). Hotel users never see other TVs — they must have the code.
* Every assignment is written to the activity log (`tv_qr_assign`).

Security notes: the code alone never reveals the registration key — only the TV, which holds the
secret, receives it. `device_provisioning` is not a tenant table (hotel_id is NULL until claimed);
every admin query checks `Auth::canAccessHotel()` and room ids are loaded with `Tenant::find()`
(another hotel's room id → 404).

---------------------------------------------------------------------------------------------------
## 3. Step-by-step guide (English)

1. Create the rooms first (Rooms & TVs → Add room / Add many rooms) — or create the room while
   assigning.
2. Install the HotelCast app on the TV and open it. It shows a QR code and a 6-character code.
3. On your phone, open the camera and point it at the QR code. Tap the link that appears.
4. Log in if asked (manager or owner account). Platform / reseller users: choose the hotel.
5. Tap the room for this TV (use the search box), or "Create new room".
6. Press **Assign**. The TV starts within a few seconds; the page shows *Registered ✔*.
7. Cannot scan? Open **Add TV (QR)** in the menu and type the code from the TV.
8. The code is valid for 15 minutes. If it expired, restart the setup on the TV to get a new code.

## 4. પગલું-દર-પગલું માર્ગદર્શિકા (ગુજરાતી)

1. પહેલાં રૂમ બનાવો (રૂમ અને TV → રૂમ ઉમેરો / ઘણા રૂમ ઉમેરો) — અથવા TV સોંપતી વખતે જ નવો રૂમ બનાવો.
2. TV માં HotelCast એપ ઇન્સ્ટોલ કરીને ખોલો. TV પર QR કોડ અને 6 અક્ષરનો કોડ દેખાશે.
3. ફોનમાં કેમેરા ખોલીને QR કોડ સામે રાખો. દેખાતી લિંક પર ટૅપ કરો.
4. જરૂર પડે તો લોગિન કરો (મેનેજર અથવા માલિકનું એકાઉન્ટ). પ્લેટફોર્મ / રિસેલર યુઝર: હોટેલ પસંદ કરો.
5. આ TV માટેનો રૂમ પસંદ કરો (શોધ બોક્સ વાપરો), અથવા "નવો રૂમ બનાવો".
6. **સોંપો** દબાવો. થોડી સેકન્ડમાં TV ચાલુ થઈ જશે; પેજ પર *નોંધણી થઈ ગઈ ✔* દેખાશે.
7. સ્કેન નથી થતું? મેનુમાં **TV ઉમેરો (QR)** ખોલીને TV પરનો કોડ લખો.
8. કોડ 15 મિનિટ માટે માન્ય છે. મુદત પૂરી થાય તો TV પર સેટઅપ ફરી શરૂ કરો — નવો કોડ દેખાશે.

સ્વ-હોસ્ટેડ (પોતાના સર્વર પર) હોટેલ: TV ના સેટઅપમાં એક વાર પોતાના સર્વરનું સરનામું લખો, પછી ઉપર મુજબ QR થી સેટઅપ કરો.

---------------------------------------------------------------------------------------------------
## 5. Limitations

* All TVs of a hotel usually share one public IP: at most 10 codes per 10 minutes per IP by default.
  Raise `provision_start_limit_ip` in `config.php` for large installations.
* A claimed code cannot be re-assigned to another room; if the wrong room was chosen, move or revoke
  the TV in Rooms & TVs after it registered (or let the claim expire and restart the setup on the TV).
* Platform admins see the waiting codes of all hotels on this server; if two TVs wait at the same
  time, compare the code on the TV screen.
