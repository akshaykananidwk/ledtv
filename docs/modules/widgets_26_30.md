# Display apps #26–#30 (2.4) — air quality, panchang, festivals, celebrations, Google reviews

Five display apps built on the display-apps framework (`docs/modules/display_apps.md`) and, where external
data is needed, the data-feeds layer (`docs/modules/data_feeds.md`). All five are in the **Widgets** group
of Admin → Apps, render in English / Gujarati / Hindi and refresh themselves on the TV.

| # | App (key) | Data | Management |
|---|---|---|---|
| 26 | Air quality & weather alerts (`air_quality`) | Open-Meteo Air Quality + Forecast (no key) | App form only |
| 27 | Panchang & Choghadiya (`panchang`) | Computed offline (`core/Panchang.php`) | App form (tithi override per day) |
| 28 | Festival calendar (`festivals`) | Tenant table `festivals` | `admin/festivals.php` (`festivals.manage`, staff+) |
| 29 | Birthday & anniversary wall (`celebrations`) | Tenant table `celebrations` | `admin/celebrations.php` (`celebrations.manage`, manager+) |
| 30 | Google reviews (`reviews`) | Typed reviews, or Google Places API (key) | App form; key on the Data feeds page / Platform settings |

## Files

| File | What |
|---|---|
| `core/WidgetApp.php` | Base class of the five apps (extends `DataFeedApp`: header + server-rendered `#dfBody`, live refresh swaps it); location, date / time helpers |
| `core/Apps/AirQualityApp.php`, `PanchangApp.php`, `FestivalsApp.php`, `CelebrationsApp.php`, `ReviewsApp.php` | The apps |
| `core/AirQuality.php` | CPCB / US AQI maths, Open-Meteo parsers, weather / ferry warnings |
| `core/Panchang.php` | Sun / Moon longitudes, tithi, nakshatra, yoga, month, Samvat, sunrise / sunset, choghadiya |
| `core/Festivals.php` | Festival model, starter list 2026–2027, import |
| `core/Celebrations.php` | Celebration model, date / type parsing, CSV import, board (today + upcoming) |
| `core/GoogleReviews.php` | Places parser, manual review parser, filters, stars |
| `core/DataFeeds.php` | (shared) providers `open_meteo_aq`, `open_meteo_wx`, `google_places` |
| `admin/festivals.php`, `admin/celebrations.php` | Management pages (phone friendly cards) |
| `admin/partials/nav.d/18_widgets_26_30.php`, `core/boot.d/widgets_26_30.php` | Sidebar, tenant tables, permissions |
| `migrations/021_widgets.sql` | Tables `festivals`, `celebrations` |
| `assets/display/apps/{air_quality,panchang,festivals,celebrations,reviews}.{css,js}` | TV styles and ES5 scripts |
| `lang/gu_apps_widgets.php`, `lang/hi_apps_widgets.php` | Gujarati / Hindi |
| `tests/Integration/Apps/WidgetsTest.php` | Tests (Http mocks only) |

## #26 Air quality + weather alerts

* **Location**: the app's own latitude / longitude, or (empty) the hotel's weather location
  (Settings → Weather, `weather_lat` / `weather_lon`, the same values `core/Weather.php` uses).
  Coordinates are rounded to 0.01° (≈ 1 km) so nearby screens share one feed.
* **Providers** (DataFeeds, fixed hosts, no key): `open_meteo_aq` →
  `https://air-quality-api.open-meteo.com/v1/air-quality` (current PM2.5, PM10, US AQI, European AQI + the
  last 24 hourly PM values), TTL 1 h; `open_meteo_wx` → `https://api.open-meteo.com/v1/forecast` (current
  wind, daily max temperature, rain sum, max wind / gusts, weather code for today and tomorrow), TTL 30 min.
  Weather.php's `current_weather` call is separate (overlay); the warnings need the daily forecast.
* **Index**: the **Indian AQI (CPCB National AQI) is estimated** from the 24-hour average PM2.5 / PM10
  (sub-index by linear interpolation in the CPCB breakpoints; AQI = the higher sub-index). The official NAQI
  needs measured values of at least three pollutants — the TV says "Indian AQI (estimated from PM2.5 / PM10)"
  and "Model estimate". With fewer than 16 hourly values the **US AQI** from Open-Meteo is shown instead,
  labelled "US AQI (EPA scale)" with the US categories. Colour band + advice text (en / gu / hi) per category.
* **Warnings** (banner, optional): heavy rain (default ≥ 64 mm / day), heat (≥ 40 °C), high wind (≥ 40 km/h),
  thunderstorm (weather code 95–99), for today or tomorrow. **Sea / ferry line** for coastal towns
  (switch "Coastal town"): when today's max wind ≥ the threshold (default 35 km/h) — standard text with the
  wind speed, or the hotel's own text (e.g. "Bet Dwarka boats suspended, ask at Okha jetty").
  These are forecast-based hints, **not** official IMD warnings.
* Errors keep the last good value ("Last known value" tag), like every data feed.
* **Terms**: Open-Meteo is free for **non-commercial** use; commercial use (a hotel showing it to guests)
  needs an Open-Meteo API subscription — see `THIRD_PARTY_NOTICES.md` and https://open-meteo.com/en/pricing.
  Air-quality data comes from CAMS (Copernicus); the TV shows "Source: Open-Meteo".

## #27 Panchang + Choghadiya (offline)

* **Sunrise / sunset**: `date_sun_info()` for the location and the hotel time zone. The Vedic day runs
  sunrise → next sunrise; before sunrise the page shows the previous day (its night choghadiya).
* **Choghadiya**: day and night each split into 8 equal parts. Cycle Udveg, Char, Labh, Amrit, Kaal,
  Shubh, Rog; the day starts with the weekday lord (Sun Udveg, Mon Amrit, Tue Rog, Wed Labh, Thu Shubh,
  Fri Char, Sat Kaal) and steps +1; the night starts with the lord of the fifth weekday and steps −2.
  Good: Amrit, Shubh, Labh; neutral: Char; avoid: Rog, Kaal, Udveg. `Panchang::choghadiya()` is a pure function.
* **Tithi / nakshatra / yoga**: apparent Sun (Meeus ch. 25) and Moon (Meeus ch. 47, the 59 main longitude
  terms + additive terms) longitudes, ΔT = 69 s. Tithi = (Moon − Sun) / 12°; nakshatra = sidereal Moon /
  13°20′; yoga = (sidereal Sun + sidereal Moon) / 13°20′; sidereal = tropical − **Lahiri ayanamsa** (linear:
  23.857° at J2000 + 50.29″ / year). The day's values are those at sunrise (udaya), with the end times.
* **Month**: Gujarati **Amanta** month (new moon → new moon), named from the Sun's sidereal sign at the new
  moon that starts it; two new moons in one sign = **Adhik** month (e.g. Adhik Jyeshtha 2026).
  **Vikram Samvat** changes on Kartak sud 1 (the day after Diwali): VS 2082 → 2083 on 10 Nov 2026.
* **Display**: Gujarati first — the tithi line is always shown in Gujarati ("આસો વદ તેરસ"), plus the
  screen language below when it is not Gujarati. Card: date, Vikram Samvat, tithi (until …), nakshatra
  (until …), yoga, sunrise, sunset, "Approximate" note. Table: day / night choghadiya, the current one
  highlighted; `panchang.js` re-highlights every 15 s and fetches the server body at every segment boundary
  and after the next sunrise.
* **Manual override**: app field "Tithi of the day typed by you" — lines `YYYY-MM-DD | text`. On that
  (Vedic) day the text replaces the computed tithi and is marked "(set by the hotel)".
* **Accuracy (honest limits)**: Sun ≈ 0.01°, Moon ≈ 0.05° → tithi / nakshatra boundaries within a few
  minutes of a good ephemeris; the 2026 eclipse new moons come out within ~2 minutes. Near a boundary
  (around sunrise) the "day's tithi" can differ by **one** from a printed panchang. Not modelled: kshaya /
  vriddhi tithi rules, festival-specific rules (madhyahna / pradosh / nishita vyapti — e.g. Ganesh Chaturthi
  2026 shows Tritiya at sunrise), local sunrise conventions (upper limb vs centre), karana, Rahu kaal.
  Tests check well-known dates (Janmashtami, Diwali, Holi, Raksha Bandhan, Kartik Purnima 2025–2026)
  with a ±1 tithi / nakshatra tolerance and the starter festival list against the engine.

## #28 Festival calendar

* Table `festivals` (hotel_id, `name_en` / `name_gu` / `name_hi`, `starts_on`, optional `ends_on`,
  `description`, `theme` (a display theme preset), image / thumb, `is_starter`, `is_active`).
* **Import starter list** (one click, `Festivals::importStarter()`): ~50 major Hindu / Indian festivals of
  2026–2027 (from today on, no duplicates). The dates were cross-checked with the panchang engine (±1 tithi),
  but local custom differs — every page says **"please verify with your temple / panchang"** and imported
  rows show "Please verify the date" until edited.
* TV: today's festival as a hero (picture or 🪔, "Warm wishes on Diwali!", description), the next N
  festivals with the days left ("10 days", "Tomorrow"). Option **"Use the festival's colours on the festival
  day"**: the page swaps its CSS variables to the festival's theme (e.g. Diwali) for that day.
* Admin: list (today / upcoming / past / off badges, past hidden by default), create / edit (picture upload,
  remove), show / hide, delete. Another hotel's ids → 404 (`Tenant::find`).

## #29 Birthday / anniversary wall

* Table `celebrations` (hotel_id, `name`, `type` birthday | anniversary | work_anniversary, `month`, `day`,
  optional `year`, `group_label`, photo / thumb, **`consent`**, `is_active`).
* **Privacy**: only rows with consent = 1 and active are ever shown on TVs (`Celebrations::board()`
  filters in SQL). Age / years are hidden unless the screen enables "Show age / number of years".
  Management needs `celebrations.manage` (manager+) because it is personal data.
* TV: today's people as a rotating hero (photo or initials, greeting, group, optional "Turns 36 today"),
  CSS-only confetti, then the next days ("Coming up", default 7 days, wraps over New Year). 29 February is
  celebrated on 28 February in non-leap years. Filters: types, one group.
* **CSV import**: `name,date,type,group` (header optional, UTF-8, ≤ 1 MB / 1000 rows, comma or semicolon).
  Dates: `DD/MM/YYYY`, `DD-MM-YYYY`, `DD.MM.YYYY`, `DD/MM`, `YYYY-MM-DD`, `--MM-DD` (day first, Indian
  style). Types: birthday (default), anniversary / wedding, work / work_anniversary / joining. Bad lines are
  skipped with a message. Imported rows have **no consent** unless the importer ticks "Everyone in this file
  agreed to be shown".

## #30 Google reviews

* **Manual**: one review per line `Author | rating 1-5 | date | text` (the text may contain `|`), optional
  overall rating and total count (else the average of the typed reviews). "My typed reviews are from
  Google" shows the Google attribution.
* **Auto**: Google Places API (legacy) **Place Details** with `fields=name,rating,user_ratings_total,reviews`,
  `reviews_sort=newest`, `language` = the screen language, via DataFeeds provider `google_places` (fixed host
  `maps.googleapis.com`, key required). The key is the hotel's own (Data feeds page) or the platform's
  (Platform settings → Data feeds), stored **encrypted**; it is only put into the request URL server-side,
  scrubbed from provider errors, and never appears in page HTML, data JSON, feed rows or admin pages.
  Default TTL 12 h, minimum 6 h (Platform settings), default daily cap 100 requests per key owner. Google
  returns at most 5 reviews. Errors (`REQUEST_DENIED`, `OVER_QUERY_LIMIT` = rate limit, `NOT_FOUND`) keep
  the last good value. Without a key the screen falls back to the typed reviews.
* TV: big average, stars, "1,234 reviews", rotating review cards with stars, author initials, date.
  Option: all ratings / 4–5 stars / 5 stars only. "Reviews from Google" attribution.
* Google Maps Platform terms apply (billing account, attribution, limited caching of Places content);
  check the current terms before enabling auto mode.

## Tests

`tests/Integration/Apps/WidgetsTest.php` — choghadiya order / timing for Dwarka on Thu 8 Oct 2026 and every
weekday; tithi / nakshatra / month / Samvat for known dates (±1), eclipse new / full moons, Makar Sankranti
ayanamsa, starter list vs engine; Open-Meteo parse / errors / rate limit / stale + back-off, CPCB
sub-indices, US fallback, warnings and ferry line; Places parse, errors scrubbed, TTL floor, key never in
HTML / JSON / rows / admin; manual reviews; festivals and celebrations pages (CRUD, 422, CSRF 419,
permissions 403, tenancy 404, XSS, uploads, starter import once, CSV text + file, consent rule, year wrap);
every app over HTTP in en / gu / hi + previews without PHP warnings; translations complete.

## ગુજરાતી સારાંશ

* **હવાની ગુણવત્તા (#26)**: હોટલના હવામાન સ્થાન (અથવા સ્ક્રીનના અક્ષાંશ-રેખાંશ) માટે Open-Meteo પરથી
  PM2.5 / PM10; ભારતીય AQI અંદાજિત (CPCB શ્રેણી, રંગ પટ્ટી, ગુજરાતી / હિન્દી / અંગ્રેજી સલાહ). ભારે વરસાદ,
  ગરમી, પવનની ચેતવણી અને દરિયાકાંઠાનાં ગામ માટે "દરિયાઈ / ફેરી ચેતવણી" (દા.ત. બેટ દ્વારકાની હોડી).
  Open-Meteo વ્યાપારી ઉપયોગ માટે સબ્સ્ક્રિપ્શન માગે છે.
* **પંચાંગ અને ચોઘડિયાં (#27)**: ઇન્ટરનેટ વગર ગણતરી — સૂર્યોદય / સૂર્યાસ્ત, દિવસ-રાતનાં 8+8 ચોઘડિયાં (ચાલુ
  ચોઘડિયું પ્રકાશિત), તિથિ, પક્ષ (સુદ / વદ), નક્ષત્ર, યોગ, ગુજરાતી અમાંત માસ અને વિક્રમ સંવત. પરિણામ
  **અંદાજિત** છે (સીમા પાસે એક તિથિનો ફરક શક્ય); કોઈ દિવસની તિથિ તમે જાતે લખી શકો, તે પ્રાથમિક રહેશે.
* **તહેવાર કેલેન્ડર (#28)**: "તહેવારો" પેજ પર તહેવાર ઉમેરો અથવા 2026–2027ની શરૂઆતની યાદી એક ક્લિકમાં
  ઉમેરો — **તારીખો મંદિર / પંચાંગ સાથે ચકાસો**. TV પર આજનો તહેવાર ફોટો સાથે અને આવનારા તહેવારોના બાકી દિવસ.
* **જન્મદિવસ / વર્ષગાંઠ દીવાલ (#29)**: નામ, ફોટો, તારીખ (વર્ષ વૈકલ્પિક), પ્રકાર, જૂથ; CSV થી ઉમેરો
  (name,date,type,group). **માત્ર સંમતિવાળી વ્યક્તિ જ TV પર દેખાય છે**; ઉંમર સેટિંગ ચાલુ કરો ત્યારે જ દેખાય.
* **Google રિવ્યૂ (#30)**: રિવ્યૂ જાતે લખો અથવા Google Places API કી અને Place ID થી આપમેળે (6–24 કલાકે
  તાજા). સરેરાશ સ્ટાર, કુલ સંખ્યા અને બદલાતાં રિવ્યૂ કાર્ડ; "Reviews from Google" દેખાય છે. API કી
  એન્ક્રિપ્ટ થઈને સચવાય છે અને TV પેજમાં ક્યારેય આવતી નથી.
