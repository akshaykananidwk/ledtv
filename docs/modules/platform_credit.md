# Platform credit line on every TV (2.9.4)

One line from the platform owner — who made the system and the mobile number to call — shown at the end of
the ticker bar of every screen of every customer, in Gujarati and English.

* **Where to edit:** Super Admin → Platform settings → **TV credit line** (on / off, Gujarati text, English
  text, mobile number; `:brand` = platform name, `:phone` = the number, empty number = the Branding support
  phone). Saving refreshes every customer's TVs.
* **Cannot be removed by customers:** `PlatformCredit::apply()` runs last in `ContentResolver::finish()`,
  after the customer's tickers, the plan filter (`Features::filterContent`) and every content extension. It
  appends the line to the customer's ticker or creates a ticker that contains only the line, so it also shows
  on screens without a ticker or without the ticker module. The settings are platform keys (`platform_credit_*`)
  that customer settings cannot override; the customer's Ticker page shows the line read-only.
* **Not shown** while a screen is off, the customer is suspended, an emergency is on screen, or on video-wall
  tiles.
* Works with every installed TV app (it is ordinary ticker text; the extra `ticker.credit` field is ignored by
  older apps).

Code: `core/PlatformCredit.php`; strings `lang/gu_platform_credit.php`, `lang/hi_platform_credit.php`;
tests `tests/Integration/Apps/PlatformCreditTest.php`.
