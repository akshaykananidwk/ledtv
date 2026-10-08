# Token / queue system (display app `queue_display`)

Numbered tokens for clinics, hospitals, banks and offices: the reception (or the visitor's own phone)
issues a token, a counter calls it, and the TV shows **"A-025 → Counter 3"** with a blink, a chime and
an optional spoken announcement.

## Setup (manager — permission `queue.manage`)

**Token queue → Setup**:

* **Services** — e.g. "Dr. Patel – OPD", "Cash counter": name, **prefix** (up to 5 letters / digits,
  e.g. `A` → tokens A-001, A-002 …), **first number**, order, active, and
  **"Visitors may take a token on their phone (QR)"** (self-service).
* **Counters** — "Counter 3", "Cabin 2": which service it calls (or *All services*), optional room /
  place text shown on the TV and the visitor's phone.
* **Reset numbers** — starts today's numbering of a service again (today's tokens of that service are
  removed). Numbers also restart **automatically every day** (hotel time zone).

## Issuing tokens (reception — permission `queue.operate`)

**Issue tokens** (`admin/queue_issue.php`, good on a tablet at the entrance): tap the service → the
next number appears big. **Print ticket** prints a small ticket for 58 mm or 80 mm thermal printers
(choose the paper width at the top; "Print automatically" prints every new token). Name and mobile
are optional.

**Self-service**: when switched on for a service, the Token display shows a QR code. The visitor scans
it, taps **Get my token** and keeps a page on the phone that shows the number, how many people are
ahead and — when called — which counter to go to (refreshes by itself). Each phone / IP address can
take 3 tokens per 15 minutes; the link is signed and cannot be guessed for another service.

## Calling (reception / staff — permission `queue.operate`)

**Token queue** (`admin/queue.php`), made for a phone at the counter:

1. Choose **your counter** once (remembered on this phone; "Change counter" to switch).
2. **NEXT** — finishes the current token and calls the next waiting one. Two counters pressing NEXT at
   the same moment never get the same token.
3. **RECALL** — calls the same token again (blink + chime + voice again).
4. **ARRIVED** — the visitor is at the counter (status "being served").
5. **DONE**, **SKIP** (put aside, can be called again by number), **NO-SHOW**.
6. **Call a specific number** — `A-25`, `a25` or `25`.
7. **Transfer** — sends the current token to the end of another service's line (keeps its number),
   e.g. from OPD to the pharmacy.

The waiting count and the next numbers update every 5 seconds.

## The TV screen

**Admin → Apps → Token display**: heading, which services (none ticked = all), show last calls, show
waiting counts, QR code for self-service, chime on a new call, spoken announcement. The TV checks for
new calls every 2 seconds. A new call or recall blinks the counter for 5 seconds, shows a large banner
and plays a short chime (`assets/display/apps/queue_display_chime.wav`, generated for this project).
Where the TV's WebView has speech (`speechSynthesis`), it says "Token A 25, Counter 3" in the item
language (English / Gujarati / Hindi); TVs without it simply stay silent.

## Technical notes

* Tables (migration `016_menu_queue.sql`, tenant tables registered in `core/boot.d/menu_queue.php`):
  `queue_services` (per-day counter `last_date` / `last_number`), `queue_counters`, `queue_tokens`
  (`origin_service_id` + `token_date` + `number` unique; `queued_at` / `called_at` with microseconds).
* Issuing locks the service row (`SELECT … FOR UPDATE`). NEXT is one
  `UPDATE … WHERE status = 'waiting' ORDER BY queued_at, id LIMIT 1` in READ COMMITTED with a retry
  on deadlock — tested with real parallel requests.
* Code: `core/Queue.php`, `core/Apps/QueueDisplayApp.php`, `admin/queue.php`, `admin/queue_issue.php`,
  `display/queue.php` (public, signed: `s` = HMAC of `queue:<hotel>:<service>`, status `k` = HMAC of
  `queue-token:<hotel>:<token>`), `assets/display/apps/queue_display.{css,js}` (ES5),
  `assets/js/queue-admin.js`, `assets/js/queue-issue.js`, `lang/{gu,hi}_apps_queue_display.php`.
* The display runtime polls at least every 3 s; the queue script asks for the next refresh after 2 s.
* Tests: `tests/Integration/Apps/QueueTest.php`.

## ગુજરાતી સારાંશ

**ટોકન કતાર** ક્લિનિક, હૉસ્પિટલ, બેંક અને ઑફિસ માટે છે. મેનેજર **સેટઅપ** માં સેવાઓ (દા.ત. "ડૉ. પટેલ –
ઓપીડી", પ્રિફિક્સ `A`) અને કાઉન્ટર ઉમેરે છે; નંબર દરરોજ આપમેળે 1 થી શરૂ થાય છે. રિસેપ્શન **ટોકન આપો**
પેજ પર સેવા પર ટૅપ કરીને ટોકન આપે છે અને 58 / 80 mm થર્મલ પ્રિન્ટર પર નાની ટિકિટ છાપી શકે છે.
સ્વ-સેવા ચાલુ હોય તો મુલાકાતી ટીવી પરનો QR કોડ સ્કેન કરીને ફોન પર જ ટોકન લઈ શકે છે. કાઉન્ટર પરનો
સ્ટાફ ફોન પર **આગળ**, **ફરી બોલાવો**, **આવી ગયા**, **પૂરું**, **છોડો**, **આવ્યા નહીં** બટન દબાવે છે,
ચોક્કસ નંબર બોલાવી શકે છે કે બીજી સેવામાં મોકલી શકે છે. ટીવી પર **ટોકન ડિસ્પ્લે** દરેક કાઉન્ટરનો
હાલનો નંબર મોટા અક્ષરે, છેલ્લા 5 નંબર અને રાહ જોનારની સંખ્યા બતાવે છે; નવો નંબર 5 સેકન્ડ ઝબકે છે,
ચાઇમ વાગે છે અને ટીવી સપોર્ટ કરે તો ગુજરાતી / હિન્દી / અંગ્રેજીમાં "ટોકન A 25, કાઉન્ટર 3" બોલે છે.
