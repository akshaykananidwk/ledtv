# Terminology — UI terms ↔ database / API names

Release 2.5 renamed the product to **Krishna Cloud TV Management** and turned it from hotel TV software into a
general digital-signage SaaS (temples, shops, restaurants, hospitals, schools, offices, factories, hotels).
Only **user-visible text** changed. Database tables and columns, PHP class names, admin page file names,
API endpoint paths and JSON field names keep their original hotel names, because installed TVs, the device
API, the web player and the Android app depend on them.

**Rule for developers:** `hotels` = **customers**, `rooms` = **screens**. Never rename the storage / API
names; use the UI words below in every new string (`__('…')`, lang files, Android `strings.xml`, docs).

## Mapping

| UI term (English) | ગુજરાતી | हिन्दी | Database / code / API name |
|---|---|---|---|
| **Customer** (platform side: the tenant / account) | ગ્રાહક | ग्राहक | table `hotels`, column `hotel_id`, class `Hotels`, `Tenant`, JSON `hotel` / `hotel_id`, `admin/platform_hotels.php` |
| **Business** (customer side: "Business name", "Business logo") | વ્યવસાય | व्यवसाय | setting `hotel_name`, `hotel_logo`; JSON `hotel.name`, `hotel.logo_url` |
| **Users** (staff logins, unchanged) | યુઝર | यूज़र | table `users` |
| **Screen** | સ્ક્રીન | स्क्रीन | table `rooms`, column `room_id`, JSON `room` |
| **Screen name / ID** (what a TV types or the QR setup picks) | સ્ક્રીનનું નામ / ID | स्क्रीन का नाम / ID | column `rooms.room_number`, API field `room_number`, JSON `room.number`, setup CSV column `room` |
| Screen label (optional display name) | સ્ક્રીનનું નામ | स्क्रीन का नाम | column `rooms.name`, JSON `room.name` |
| **Area / floor** (location) | વિસ્તાર / માળ | क्षेत्र / मंज़िल | column `rooms.floor`, JSON `room.floor`, broadcast target `floors` |
| **Screens & TVs** (admin page) | સ્ક્રીન અને TV | स्क्रीन और TV | `admin/rooms.php`, permissions `rooms.view` / `rooms.manage`, nav key `rooms` |
| **Groups** of screens | ગ્રુપ | ग्रुप | tables `room_groups`, `room_group_members` |
| **TV** / player (physical device) | TV | TV | table `devices` (`device_uid`, `room_id`) |
| **All screens** (broadcast target) | બધી સ્ક્રીન | सभी स्क्रीन | target type `all`; selected screens = target type `rooms` |
| **Chains** (one owner, many customers / locations) | ચેઇન | चेन | table `hotel_chains`, column `hotels.chain_id`, `chain_*` tables, files `chain*.php`, nav `55_hotel_chains.php` |
| **Venue** (in the ad marketplace: a customer that sells TV ad space) | સ્થળ | स्थान | `mkt_booking_hotels`, `advertise/hotels.php`, column `hotel_share` |
| Default content (customer-wide) | ડિફોલ્ટ કન્ટેન્ટ | डिफ़ॉल्ट कंटेंट | setting `default_playlist_id` / `default_content_id` |
| Product name | — | — | `Branding::DEFAULT_PRODUCT` = `Krishna Cloud TV Management`, platform setting `platform_name` |

Error codes stay as they are: `HOTEL_SUSPENDED`, `ROOM_NOT_FOUND`, `NO_HOTEL`, `LICENSE_LIMIT` (their
human-readable messages now say customer / screen).

## Where hotel wording is still correct

The optional **Hospitality** feature group (`core/Features.php`, group `hospitality`; plans without it do
not show these modules) keeps hotel words because there they are natural — "property" is preferred where it
reads well:

* Guests / front desk, check-in mode, welcome card, checkout reminder (`admin/guests.php`, `core/Guests.php`)
* Room service & requests, live order board (`admin/orders.php`, `core/GuestServices.php`, guest web app `g/`)
* Guest feedback (`admin/feedback.php`), PMS integration (`api/routes/pms.php`, `core/GuestPms.php`)
* Local guide in the TV menu (`core/Extensions/GuideExtension.php`)
* Occupancy and room-service numbers in Analytics, and the hospitality sample templates (checkout, pool,
  room service)

Inside these modules a screen is still called a **room** ("Room 101", "Check out :g from room :r?").

## Product name history

| Version | Name |
|---|---|
| ≤ 2.1 | HotelCast |
| 2.2 – 2.4 | Krishna Cloud LED TV (migration `011_product_name.php`) |
| 2.5 | **Krishna Cloud TV Management** (migration `031_product_name_tv_management.php`: an unchanged old default `platform_name` and reseller / customer brand overrides equal to an old default are updated; custom white-label names are kept) |

"HotelCast" remains the internal code name: folder `hotelcast/`, Android package `com.hotelcast.tv`,
user agent `HotelCast/<version>`, session cookie `HCSESSID`, `HC_*` constants.

## Translations

* Server: English keys in `__('…')`; Gujarati `lang/gu*.php`, Hindi `lang/hi*.php` (admin strings in
  `lang/hi_admin.php`; Hindi is an admin panel language since 2.5). When an English key changes, every lang
  file that has it must change too.
* Android: `values/`, `values-gu/`, `values-hi/strings.xml` — resource **names** (`room_number`,
  `info_hotel`, `room_label` …) are code identifiers and stay; only the texts changed. The launcher label
  is `app_label` ("Krishna Cloud TV"), the full name `app_name`.
