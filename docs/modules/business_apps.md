# Business display apps — offers, class schedule, departures board, KPI dashboard

Four ready-made TV screens built on the display-apps framework (`docs/modules/display_apps.md`).
Each one is a gallery app in **Admin → Apps** (category *Business*) with its own management page,
so staff keep the data up to date from a phone and every TV showing the app follows within seconds.

| # | App (key) | Management page | Permission (min. role) | Live refresh |
|---|---|---|---|---|
| 4 | Shop offers (`offers`) | Admin → Offers (`admin/offers.php`) | `offers.manage` (staff) | 15 s |
| 6 | Class schedule (`class_schedule`) | Admin → Class schedule (`admin/class_schedule.php`) | `class_schedule.manage` (staff) | 30 s |
| 7 | Departures board (`departures`) | Admin → Departures board (`admin/departures.php`) | `departures.manage` (reception) | 15 s |
| 8 | KPI dashboard (`kpi_dashboard`) | Admin → KPI dashboard (`admin/kpi.php`) | `kpi.manage` (staff) | 10 s |

Creating the TV screen itself (Admin → Apps → pick the app → theme, language, options) needs
`content.manage` (manager), like every app item. Users limited to some TVs (Access) can still manage
these hotel-wide lists. When there is no data yet, the admin preview shows sample content and the TV
shows a short hint such as "Add offers on the Offers page."

## User guide

### Shop offers (#4)
1. **Admin → Offers → New offer**: title, description, optional photo, offer price and old price (MRP)
   in ₹. The discount % is calculated from the two prices; type a % to override it. Add a badge
   ("New", "Bestseller"…), *valid from* / *valid until* and a sort order (smaller first).
2. **Admin → Apps → Shop offers**: layout *one big offer at a time*, *grid (4 per page)* or
   *list (5 per page)*, change interval, currency symbol, footer ("T&C apply"), countdown on/off.
3. The TV shows a big round discount sticker, the struck-through old price and, for offers with an
   end, a live countdown "Ends in 2d 04:13:22". When an offer ends it disappears by itself (the page
   asks the server for a fresh list the second a countdown reaches zero; `valid_to` is exclusive).

### Class schedule (#6)
1. **Admin → Class schedule → New class**: name, days (tap *Mon–Fri*, *Mon–Sat* or *Every day*),
   start / end time, trainer (+ photo), studio / room, level, colour.
2. **Admin → Apps → Class schedule**: view *Today (NOW / NEXT)* or *Whole week*, rename the labels
   ("Trainer" → "Doctor" / "Teacher", "Studio" → "Room" / "Cabin"), rows per page, photos on/off,
   hide classes that are over, 24-hour time.
3. Today view: one row per class with times, trainer photo (or initials), a pulsing green **NOW** and
   an amber **NEXT** badge (hotel time zone). Week view: seven columns, today highlighted.
   Works for gyms, yoga studios, schools (periods), coaching classes and OPD timings.

### Departures board (#7)
1. **Admin → Departures board → New entry**: departure / arrival, time, number / route, destination in
   English (+ Gujarati and Hindi), platform / gate, *Runs every day* or one date, status, delay, remark.
2. On the same page, **On the board now** lists the next 24 hours with one-tap buttons:
   **On time**, **Delayed +15** (adds 15 minutes each tap), **Boarding**, **Departed** / **Arrived**,
   **Cancelled**. A status on a daily entry applies only to that day — the next day starts "On time".
3. **Admin → Apps → Departures board**: departures, arrivals or both, column name (Platform, Gate,
   Stand, Bay, Counter), rows per page, page interval, *hide past entries after N minutes*, look-ahead
   hours, English line below Gujarati / Hindi names, 24-hour clock, footer.
4. The TV shows a split-flap style board with a seconds clock; *Boarding* blinks, *Cancelled* is red
   and struck through, *Delayed* shows the new expected time; rows whose status changed "flip".
   (Live train / flight data from public APIs is a separate widget.)

### KPI dashboard (#8)
1. **Admin → KPI dashboard → New tile**: name, type — *Counter* (number vs target), *Percent*,
   *Text* ("Running") or *Days since* (e.g. "Days without accident", counted from a date) — target,
   unit, colour thresholds: green from / red at (if *green from* is smaller than *red at*, lower is
   better, e.g. rejects).
2. The tile list is made for phones: big **−1**, **+1** and **Set** buttons (no page reload), a text
   box for text tiles and **Reset to today** for days-since tiles.
3. **Admin → Apps → KPI dashboard**: shifts (one per line `Name | 06:00 | 14:00`; a night shift may
   pass midnight), safety messages that scroll at the bottom, scroll speed, page interval (more than
   12 tiles), clock.
4. The TV shows big tiles with progress bars against the target, green / amber / red by threshold,
   the current shift and the clock.

#### Machine push API (optional)
On a tile's edit page, **Turn on machine push** creates a secret token (shown **once**; only its
SHA-256 is stored; *New token* replaces it, *Turn off* revokes it). A PLC, counter box or script can
then update that one tile:

```
POST /api/kpi/push
Authorization: Bearer kpi<48 hex characters>      (or "token" in the JSON / form body)
Content-Type: application/json

{"value": 830}               set a counter / percent (text tile: any text)
{"add": 1}                   add (negative to subtract)
{"reset": true}              days-since tile: count again from today (counter: back to 0)
{"since": "2026-01-15"}      days-since tile: count from this date

→ 200 {"ok":true,"data":{"id":12,"label":"Production","type":"counter","value":"830","num":830,"unit":"pcs","level":"warn"}}
```

No login and no CSRF token — the tile token is the credential. Errors: `401 INVALID_TOKEN`,
`400 VALIDATION_ERROR`, `405` (not POST), `403 HOTEL_SUSPENDED`, `429 RATE_LIMITED` with
`Retry-After`. Limits: 30 updates per minute per tile, 600 requests per minute per IP and 20 wrong
tokens per 10 minutes per IP. Example: `curl -X POST https://example.com/api/kpi/push -H "Authorization: Bearer kpi…" -H "Content-Type: application/json" -d '{"add":1}'`.

## Developer notes

| File | What |
|---|---|
| `migrations/017_business_apps.sql` | Tenant tables `offers`, `class_sessions`, `departures`, `kpi_tiles` |
| `core/boot.d/business_apps.php` | `Tenant::registerTable()` + permissions |
| `core/BusinessApps.php` | Shared helpers: form parsing, Indian number format (`12,34,567`), time labels, uploads |
| `core/Offers.php`, `core/ClassSchedule.php`, `core/Departures.php`, `core/Kpi.php` | Data + validation + logic |
| `core/Apps/{Offers,ClassSchedule,Departures,KpiDashboard}App.php` | The display apps |
| `assets/display/apps/{offers,class_schedule,departures,kpi_dashboard}.{css,js}` | TV styles / ES5 scripts |
| `admin/{offers,class_schedule,departures,kpi}.php`, `admin/partials/nav.d/16_business_apps.php` | Management pages + sidebar |
| `api/routes/kpi.php` | Machine push endpoint |
| `lang/{gu,hi}_apps_{offers,class_schedule,departures,kpi_dashboard}.php` | Gujarati / Hindi |
| `tests/Integration/Apps/BusinessAppsTest.php` | Tests |

* Time logic is pure and takes the clock as an argument (hotel time zone = PHP default time zone,
  set by `Tenant::set()`): `ClassSchedule::annotate($sessions, $now)` (NOW / NEXT / DONE / LATER),
  `Departures::board($rows, $now, $opts)` (today + tomorrow occurrences, daily status reset, auto-hide),
  `Kpi::daysSince()`, `Kpi::currentShift()`, `Kpi::level()`, `Offers::state()` / `discount()`.
* Offers send JSON rows and the page script renders the cards (same markup as `OffersApp::card()`);
  class schedule, departures and KPI send server-rendered, escaped HTML in `data.html` and the page only
  swaps it in **when it changed**, so page rotation / tickers keep running between refreshes.
* Departures: `service_date` NULL = daily; `status_date` = the day a status applies to. Quick buttons
  post `op=status&id&date&status[&add]` (date limited to today / tomorrow).
* KPI tokens: `kpi` + 48 hex (`Api::bearer()` accepts only `[A-Za-z0-9]`), stored as `push_token_hash`.

## ગુજરાતી સારાંશ

ચાર તૈયાર ટીવી સ્ક્રીન, દરેક માટે ફોનથી ચાલે તેવું અલગ મેનેજમેન્ટ પેજ:

* **દુકાનની ઓફર** (Admin → ઓફર): શીર્ષક, ફોટો, ઓફર ભાવ અને જૂનો ભાવ (MRP) — ડિસ્કાઉન્ટ % આપમેળે ગણાય
  છે. "ક્યાં સુધી માન્ય" આપો તો ટીવી પર "પૂરી થશે 2 દિ 04:13:22" કાઉન્ટડાઉન દેખાય અને ઓફર પૂરી થતાં જ
  આપમેળે દૂર થાય. લેઆઉટ: એક મોટી ઓફર, 2×2 ગ્રીડ અથવા યાદી.
* **ક્લાસ સમયપત્રક** (Admin → ક્લાસ સમયપત્રક): જિમ, યોગ, શાળાના પિરિયડ, કોચિંગ કે ડૉક્ટરના OPD સમય.
  ચાલુ ક્લાસ પર "હમણાં" અને પછીના પર "આગળ" બેજ; આજનો કે આખા અઠવાડિયાનો દેખાવ; "ટ્રેનર" નામ બદલીને
  "ડૉક્ટર" / "શિક્ષક" કરી શકાય.
* **પ્રસ્થાન બોર્ડ** (Admin → પ્રસ્થાન બોર્ડ): બસ, ટ્રેન કે ફ્લાઇટનો સમય, નંબર, ગંતવ્ય (ગુજરાતી / હિન્દી
  નામ સાથે), પ્લેટફોર્મ. એક ટેપથી "મોડું +15", "બોર્ડિંગ", "ઉપડી ગઈ", "રદ". દરરોજ ચાલતી એન્ટ્રી બીજા દિવસે
  ફરી "સમયસર" થી શરૂ થાય. વીતેલી એન્ટ્રી થોડી મિનિટ પછી આપમેળે છુપાય છે.
* **KPI ડેશબોર્ડ** (Admin → KPI ડેશબોર્ડ): ઉત્પાદન કાઉન્ટર, ટકા, લખાણ અને "અકસ્માત વગરના દિવસ" ટાઇલ;
  ફોન પર મોટા −1 / +1 / સેટ બટન; હાલની શિફ્ટ અને નીચે ચાલતા સલામતી સંદેશા. મશીન કે PLC ગુપ્ત ટોકનથી
  `POST /api/kpi/push` વડે કિંમત મોકલી શકે (ટોકન ફક્ત એક વાર દેખાય છે).

ટીવી સ્ક્રીન બનાવવા: Admin → એપ્સ → એપ પસંદ કરો → થીમ, ભાષા (ગુજરાતી / હિન્દી / અંગ્રેજી) → સાચવો.
