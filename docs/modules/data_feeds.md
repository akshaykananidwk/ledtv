# Data feeds (2.3) — gold, market, cricket, currency, travel widgets (#21–#25)

External live data for five display apps and for ticker placeholders. Everything is fetched **on the
server** by a background task, cached with a per-provider TTL, and shown on TVs from the stored value.
**Every widget also works without any API key** — the hotel types the values itself (for gold that is
the main mode: jewellers set their own daily rates).

## 1. Overview

```
Platform settings → Data feeds (keys, TTL, caps)      admin/rates.php (gold / silver / market values)
admin/data_feeds.php (hotel's own key, status)          │
          │                                             ▼
          ▼                                  metal_rates (tenant table, history) + hotel settings
  DataFeeds::get(provider, params)  ── reads ──▶ data_feeds (platform table: last good value, as of,
          │   (first use: one inline fetch)               error, retry_at, demand_at)
          │                                             ▲
  core/Apps/*App.php (render / data) ──────────┐        │ refreshDue(): feeds used in the last 24 h whose
  TickerExtension → DataFeeds::applyToTicker() ┘        │ TTL is over, within the request budget
                                                 core/Tasks/DataFeedsTask.php (every minute)
```

| File | What |
|---|---|
| `core/DataFeeds.php` | Providers, fixed hosts, parsers, feed state, budget, background refresh, keys, placeholders, formatting |
| `core/MetalRates.php` | Gold / silver rates of a hotel: manual history, auto (indicative) mode |
| `core/DataFeedApp.php` | Base class of the five widgets (server-rendered body, live refresh via `data.html`) |
| `core/Apps/GoldRatesApp.php` … `TravelStatusApp.php` | The widgets (`gold_rates`, `market`, `cricket`, `currency`, `travel_status`) |
| `core/Tasks/DataFeedsTask.php` | Background refresh (interval 60 s) |
| `admin/rates.php` | Phone-friendly quick edit of gold / silver rates (+ history) and manual market values (`rates.manage`, staff+) |
| `admin/data_feeds.php` | Hotel's own API keys + feed status / "Refresh now" (`settings.manage`, super admin) |
| `admin/partials/data_feeds_platform.php` | Platform settings → **Data feeds** tab (platform admin) |
| `assets/display/feeds.css`, `feeds.js` | Shared widget styles and the (ES5) live-refresh hook |
| `migrations/019_data_feeds.sql` | Tables `data_feeds` (platform) and `metal_rates` (tenant) |
| `lang/gu_apps_data_feeds.php`, `lang/hi_apps_data_feeds.php` | Gujarati / Hindi |
| `tests/Integration/Apps/DataFeedsTest.php` | Tests (Http mocks only) |

## 2. Providers

| Provider (id) | Feed | Key | Fixed host | Where to get a key | Defaults (TTL / daily cap) |
|---|---|---|---|---|---|
| open.er-api.com (`open_er_api`) — ExchangeRate-API open access | currency | **no** | `open.er-api.com` | — (attribution requested by the provider) | 1 h / — |
| Frankfurter (`frankfurter`) — ECB reference rates | currency | **no** | `api.frankfurter.dev` | — | 1 h / — (ECB has no AED / SAR) |
| GoldAPI.io (`goldapi`) | metals (XAU, XAG in USD) | yes | `www.goldapi.io` | https://www.goldapi.io | 12 h / 6 requests (2 per refresh) |
| Twelve Data (`twelvedata`) | market quotes | yes | `api.twelvedata.com` | https://twelvedata.com/pricing | 15 min / 700 |
| CricketData.org / CricAPI (`cricapi`) | cricket `currentMatches` | yes | `api.cricapi.com` | https://cricketdata.org | 60 s / 100 → effectively ~14 min |
| aviationstack (`aviationstack`) | flight status | yes | `api.aviationstack.com` | https://aviationstack.com | 1 h / 3 → effectively 8 h |
| Indian trains | — | — | — | **manual rows only** (see below) | — |

**Free tiers change often — check the provider's current terms** before relying on any number here.
The defaults are chosen to stay inside typical free plans (e.g. ~100 requests / month for GoldAPI and
aviationstack, ~100 hits / day for CricAPI, ~800 credits / day for Twelve Data at the time of writing).
Super Admin can change the TTL and the daily cap per provider; the effective TTL is stretched so that a
single feed never exceeds the daily cap (`86400 × requests-per-refresh / cap`).

Notes:

* **Currency** (#24) is the free, key-less feed (choose open.er-api or Frankfurter in Platform settings).
  Rates are stored as "INR per 1 unit". The currency widget adds buy / sell margins (%) or fixed values
  per currency typed by the hotel.
* **Gold** (#21) auto mode = GoldAPI `XAU/USD` and `XAG/USD` per gram × USD→INR (currency feed) ×
  (1 + duty %) × (1 + markup %); 22K = 22/24 and 18K = 18/24 of 24K; silver per kg. Labelled
  **"Indicative"** on the TV. Without a value (no key, first fetch pending) the manual rates are shown.
* **Market** (#22): Twelve Data `quote` endpoint, several symbols per request. Index symbols are
  editable (Platform settings): NIFTY 50 = `NSEI`, SENSEX = `BSESN`, BANK NIFTY = `NSEBANK` (from the
  provider's `/indices?country=India` list). Stocks: `RELIANCE:NSE`, `TCS:BSE`. Whether Indian indices are
  included in the free plan depends on the provider — if not, use manual values. Always labelled
  **"Delayed / indicative"**. **No scraping**.
* **Cricket** (#23): one shared `currentMatches` request serves every TV of every hotel using the platform
  key. Match choice: first live match, first live match of a team, a chosen match id, or manual score.
  The required run rate is computed for a T20 / ODI chase. TV pages poll their (cached) data every 30 s.
* **Flights** (#25): one feed per flight number (`flight_iata`). Some aviationstack free plans have no
  HTTPS — Platform settings has a "use plain HTTP" switch (the key is then sent unencrypted; off by default).
* **Trains**: Indian Railways has no official public live-status API; the RapidAPI "IRCTC" wrappers are
  unofficial third-party scrapers with changing formats, so they are **not** integrated. Use manual rows
  (`train | 12902 | Ahmedabad → Mumbai | 21:40 | 21:55 | Delayed | PF 3`).

## 3. Caching, errors, budget

* State per feed in `data_feeds` (provider + params + key owner): `data` (last good value, JSON),
  `fetched_at` ("as of"), `error`, `error_count`, `retry_at`, `demand_at` (last time a TV / page used it).
* Pages and TVs only read; `DataFeeds::get()` registers the demand. The very first use of a new feed
  fetches inline once (so a new widget shows data immediately); afterwards only `DataFeedsTask` fetches.
* On an error the last good value stays; the TV shows it with a **"Last known value"** tag (stale =
  last attempt failed or older than 2 × TTL). Retries back off (2, 4, 8 … min, at most max(TTL, 5 min)); a rate limit
  (HTTP 429 or "limit / quota" in the answer) waits at least max(TTL, 30 min).
* Budget: `platform_feeds_per_min` requests per minute for all providers (default 20) and the daily cap
  per provider and key owner (a hotel's own key has its own cap). Counters live in `rate_limits`.
* Feeds nobody used for 24 h are not refreshed; after 30 days they are deleted.
* Ticker placeholders and TV content: `ContentResolver` caches content per minute, so a new value reaches
  the TVs within about a minute and the content hash changes automatically.

## 4. Security

* **No SSRF**: every provider has one fixed `https://<host>/<path>`; parameters are validated with strict
  patterns (symbols `DataFeeds::SYMBOL_RE`, flight numbers) and URL-encoded; `requests()` re-checks the
  host before anything is sent. There is no field where an admin can type a URL.
* **API keys**: platform keys `platform_feedkey_<provider>` and hotel keys `feedkey_<provider>` are
  stored with `Crypto::encrypt` (APP_KEY). Admin pages only say "Key saved". Keys are never written to
  `data_feeds`, logs, errors (provider messages are scrubbed), page HTML or data JSON.
* All values shown on TVs are escaped (`e()`); live refresh swaps server-escaped HTML.
* Tests / sandboxes never reach the network: with `HC_TESTING`, a test sandbox path or config
  `'data_feeds_offline' => true`, requests are refused unless an Http mock (`Http::$mock` or config
  `http_mock_file`) is active.

## 5. Manual mode

| Widget | Manual values |
|---|---|
| Gold & silver | **Rates** page (`admin/rates.php`): 24K / 22K / 18K per 10 g, silver per kg, note (making charges). Each save = new history row; ▲ / ▼ against the previous row. Delete a wrong entry from the history. |
| Stock market | Rates page → *Market (manual)*: `Label \| value \| change %` per line (also used when no key is set) |
| Cricket | Widget form: team 1 / score 1, team 2 / score 2, status line |
| Currency | No key needed at all; optional fixed buy / sell per currency in the widget form |
| Travel | Widget form: manual rows for trains and flights |

## 6. Ticker placeholders

Write them in any ticker message (Ticker bar page); they are resolved server-side when the TV content is
built (`TickerExtension` → `DataFeeds::applyToTicker()`):

`{gold_24k}` `{gold_22k}` `{gold_18k}` `{silver}` (per kg) · `{usd_inr}` `{eur_inr}` `{gbp_inr}`
`{aed_inr}` `{sar_inr}` · `{nifty}` `{sensex}` `{banknifty}` (value + change %).

* Single pass; unknown placeholders (`{foo}`, `{GOLD_24K}`) stay unchanged; a known one without a value
  shows `—`. Values are plain text (control characters and braces removed, max 60 chars).
* Only stored values are read (never an inline fetch), so a TV poll is never slowed down by a provider.

Example: `આજનો સોનાનો ભાવ 24K {gold_24k} / 10 ગ્રામ ✦ USD {usd_inr}`.

## 7. Tests

`tests/Integration/Apps/DataFeedsTest.php`: parsers for every provider (good JSON, error formats, rate
limits → stale + back-off, key scrubbing), fixed hosts / rejected params, background task (TTL, 24 h
demand, per-minute budget, daily cap), encrypted keys never in HTML / JSON / rows, platform tab and hotel
key page (CSRF, permissions), rates page (CRUD, history, validation 422, tenancy, XSS), auto gold maths,
manual mode without keys (no request at all), every widget in en / gu / hi over HTTP, ticker placeholders
(missing feeds, hash change). The sandbox server answers every provider URL from `http_mock_file`.

## 8. ગુજરાતી સારાંશ

* **ડેટા ફીડ** સોનું-ચાંદી, શેર બજાર, ક્રિકેટ સ્કોર, ચલણ દર અને ફ્લાઇટ સ્થિતિ માટે બાહ્ય ડેટા સર્વર પર
  મેળવે છે; TV માત્ર સાચવેલું મૂલ્ય બતાવે છે. ભૂલ થાય ત્યારે છેલ્લું સાચું મૂલ્ય "છેલ્લું જાણીતું મૂલ્ય" તરીકે દેખાય છે.
* **દરેક વિજેટ કી વગર પણ ચાલે છે**: ઝવેરીઓ "ભાવ" પેજ પર ફોનથી આજના 24K / 22K / 18K અને ચાંદીના ભાવ
  લખે છે (ઇતિહાસ સાથે, TV પર ▲ / ▼). બજારનાં મૂલ્યો, ક્રિકેટ સ્કોર અને ટ્રેન-ફ્લાઇટ લાઇનો પણ હાથે લખી શકાય છે.
* ચલણ દર મફત છે (કી જરૂરી નથી). સોનું (GoldAPI.io), બજાર (Twelve Data), ક્રિકેટ (CricAPI) અને ફ્લાઇટ
  (aviationstack) માટે API કી જોઈએ — સુપર એડમિન → પ્લેટફોર્મ સેટિંગ્સ → ડેટા ફીડ, અથવા હોટલ પોતાની કી
  "ડેટા ફીડ" પેજ પર લખે. કી એન્ક્રિપ્ટ કરીને સાચવાય છે અને TV ક્યારેય જોતા નથી. ફ્રી મર્યાદા માટે પ્રદાતાની વર્તમાન શરતો તપાસો.
* ટ્રેન માટે સત્તાવાર API નથી, તેથી ટ્રેનની સ્થિતિ હાથે લખો.
* ટિકરમાં `{gold_24k}`, `{usd_inr}`, `{nifty}` જેવા પ્લેસહોલ્ડર લખો — TV પર વર્તમાન મૂલ્ય દેખાશે.
