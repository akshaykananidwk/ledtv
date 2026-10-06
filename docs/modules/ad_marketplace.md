# Module: Ad marketplace (#19)

Local businesses ("advertisers") book TV ad space in hotels that opt in; hotels earn a revenue share
(platform setting, default 70 % hotel / 30 % platform). Payment is manual (bank transfer / UPI), the platform
admin marks orders paid. Approved + paid bookings become **normal campaigns of the Ads module** inside each
hotel, so `AdsExtension`, impression logging (`POST /api/device/played`) and `ad_stats_daily` work unchanged.

## Files

| file | purpose |
|---|---|
| `migrations/008_ad_marketplace.sql` | `mkt_advertisers`, `mkt_advertiser_sessions`, `mkt_hotel_settings`*, `mkt_creatives`, `mkt_bookings`, `mkt_booking_hotels`*, `mkt_booking_events`, `mkt_payouts`* (* tenant tables) |
| `core/boot.d/ad_marketplace.php` | registers the 3 tenant tables, permissions `marketplace.manage` (manager+), `marketplace.settings` (super admin) |
| `core/Marketplace.php` | accounts (sign-up, OTP, login lockout), hotel opt-in, public listing, quote, creatives, booking workflow, campaign creation, reports, payouts, housekeeping |
| `core/MarketplacePortal.php` | advertiser session (cookie `HCADVSESSID`, path `/advertise/`), guards, layout, TV-simulator frame |
| `core/Tasks/MarketplaceTask.php` | every 15 min: expire unpaid orders, reject lines not approved in time, stop delivered CPM lines, scheduled → running → completed |
| `advertise/*.php` | portal: `index` (landing / dashboard), `signup`, `verify`, `login`, `logout`, `hotels` (public), `creatives`, `book`, `booking`, `report`, `ajax` (quote) |
| `assets/advertise/advertise.css`, `advertise.js` | mobile-first styles, previews, live quote (no inline scripts, CSP `script-src 'self'`) |
| `admin/marketplace.php` | hotel: requests (approve / reject), bookings, earnings + payout statements, settings ("Sell ad space") |
| `admin/platform_marketplace.php` | platform: overview, orders (review, mark paid, reject / cancel + refund note, reject creative), advertisers, payouts, settings |
| `admin/partials/nav.d/35_marketplace.php` | menu items (SaaS only; hotel item needs the `ads` plan module) |
| `lang/gu_marketplace.php` | Gujarati (advertiser UI: English / Gujarati) |
| `tests/Integration/MarketplaceTest.php` | tests |

### Shared edits
* `phpunit.xml`: `tests/Integration/MarketplaceTest.php` added before `UpdaterTest.php`. Nothing else.

## Flow

`draft → submitted (only when "Review every order before payment" is on) → awaiting_payment → paid →
(per hotel line: pending → approved | rejected) → scheduled → running → completed`; `rejected` / `cancelled`
with reason, refund amount and refund note.

* **Quote** (server-side only, integer paise): per day = days × active TVs × price per TV per day; CPM =
  impressions ÷ 1000 × price per 1000; tax `platform_mkt_tax_percent` on the subtotal. Re-computed on submit.
* **Capacity**: a hotel accepts at most `max_ads_per_loop` marketplace bookings overlapping the same dates
  (open bookings count from submission); checked at quote, submit and approval.
* **Paid**: revenue share fixed per line (`hotel_share_pct`, `hotel_share`, `platform_share`). Auto-approve hotels
  get the campaign at once, others are notified (`Notifier::send`).
* **Approval** creates in the hotel: sponsor (= advertiser, reused), content item (creative file **copied** into
  `uploads/h{hotel}/media/…`; text → `announcement`), campaign (all rooms, after every 3 items / 10 min, dates and
  daily window of the booking). `mkt_booking_hotels.campaign_id` ↔ `ad_campaigns.id`.
* **Rejection**: hotel rejects a line (refund for that line incl. tax) or stops an approved one (campaign paused);
  platform rejects / cancels whole orders, optionally rejects the creative (all its open orders).
* **Payouts**: "Create statements" for a month collects every approved line of orders paid until the month's end
  that is not on a statement yet; paid statements never change.
* **Reports**: advertiser proof of play per hotel per day from `Ads::report()` (roll-up + today's logs), CSV, print.

## Security
* Advertiser session: own cookie name + path, opaque token in `mkt_advertiser_sessions`; the session never holds
  `hc_token`, so it can not authenticate `admin/` (tested, also with the id sent as `HCSESSID`).
* Advertiser queries always filter `advertiser_id`; other ids → 404. Public listing: name, city, TVs, rooms,
  prices, categories, approval mode, description, estimated plays — no contacts / keys.
* Hotel pages use `Tenant::find()` (foreign line / statement → 404). CSRF on every POST (portal + JSON quote).
* Rate limits: sign-up 5/h/IP, login 20/15 min/IP + 5 failures → 15 min lock, OTP 5 tries/code, resend 3/h,
  uploads 30/h, quotes 240/h.
* Creatives: images validated (extension + MIME + `getimagesize`, min 320×180, re-encoded by `Uploader::storeImage`),
  videos MP4/WEBM only with size limit, text without links / HTML (`Marketplace::hasUrl`).

## Platform settings (hotel 0)
`platform_mkt_enabled, _hotel_share, _tax_percent, _bank_text, _upi_id, _upi_name, _expire_days, _signup_otp,
_signup_approval, _review, _rules, _categories, _max_image_mb, _max_video_mb, _max_days`.

## Tests
```
cd hotelcast
HC_TEST_DB_NAME=hotelcast_test_m php phpunit.phar -c phpunit.xml tests/Integration/MarketplaceTest.php
```

## Limitations
* No payment gateway; no password reset for advertisers yet (the platform helps manually).
* Video length is not checked (no ffprobe on shared hosting) — only type and size.
* Estimated daily impressions = played items of the last 7 days ÷ 4 (rough).
* CPM delivery is checked every 15 minutes, so a few extra impressions may be shown.
* Refunds are recorded (amount + note), not executed.
* Advertiser UI in English / Gujarati only (no Hindi).
