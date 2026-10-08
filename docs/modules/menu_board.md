# Menu board (display app `menu_board`)

A restaurant menu on the TV: dishes with prices, Indian veg / non-veg symbols, photos, badges and
"Today's special". When the kitchen marks a dish **sold out** on a phone, every TV shows it within
about 10 seconds.

## For staff — daily use

1. Open **Menu board** in the admin sidebar (permission `menu_board.manage`: staff, manager, admin).
2. Every dish has a big green **Available** button. Tap it and it turns red **Sold out**; tap again
   when the dish is back. Works well on a phone — no page reload.
3. The **star** marks a dish as *Today's special* (shown big in the "special" layout).
4. The **⋮** menu: edit, move up / down, hide from TV boards, delete.

## For the manager — set up the menu

* **New category** (Breakfast, Main course, Drinks …): names in English / Gujarati / Hindi,
  optional **Shown on TV from / until** (dayparting, e.g. breakfast 07:00–11:00; hotel time zone,
  overnight times like 22:00–02:00 work), "Show on TV menu boards", active switch.
* **New dish**: names and short descriptions in three languages, price, optional *old price*
  (shown struck through), food type (veg / non-veg / egg / not marked), badge (New, Chef's special,
  Bestseller, Spicy, Healthy), photo (JPG / PNG / WebP), room-service hours, switches.
* Order: arrows on categories, "Move up / down" on dishes.

The menu is **shared with room service** (guest app): one list of dishes, one price. A sold-out dish
cannot be ordered from the rooms either. Restaurants without the guest-services plan feature can
still use this page and the TV board.

## The TV screen

**Admin → Apps → Menu board** (or the button on the Menu board page) creates the screen:

| Setting | |
|---|---|
| Layout | *Classic menu board* (1–3 columns with dotted price lines), *Photo cards*, *Today's special + list* (big photo of the special on the left, rotating when there are several) |
| Show these categories | e.g. one TV only Breakfast, another only Drinks (none ticked = all) |
| Sold-out dishes | shown struck through with a red "Sold out" tag, or hidden |
| Currency symbol | default ₹ |
| Change page every | when the menu does not fit, it is split into pages that rotate (a category heading never stays alone at the bottom of a column) |
| Use category hours | dayparting on / off |
| Descriptions, photos, clock | on / off |

Plus the usual theme (try *Restaurant warm*), font, accent colour and language (dish names in that
language, English when a translation is missing).

## Technical notes

* Data: `guest_menu_categories` / `guest_menu_items` (migration 003) plus migration
  `016_menu_queue.sql`: items `is_sold_out`, `show_on_board`, `is_special`, `badge`, `price_old`;
  categories `show_on_board`, `board_from`, `board_to`.
* Code: `core/MenuBoard.php` (save, switches, reorder, `board()` data), `core/Apps/MenuBoardApp.php`,
  `admin/menu_board.php` (+ `assets/js/menu-board-admin.js`), `assets/display/apps/menu_board.{css,js}`
  (ES5), translations `lang/{gu,hi}_apps_menu_board.php`. `GuestServices::availableNow()` returns
  false for sold-out dishes.
* Live data every 10 s (`data()`); the TV only re-lays out the pages when the data changed.
* Tests: `tests/Integration/Apps/MenuBoardTest.php`.

## ગુજરાતી સારાંશ

**મેનુ બોર્ડ** ટીવી પર રેસ્ટોરન્ટનું મેનુ બતાવે છે — કિંમત, વેજ / નોન-વેજ ચિહ્ન, ફોટા અને
"આજની સ્પેશિયલ". એડમિનમાં **મેનુ બોર્ડ** પેજ પર કેટેગરી અને વાનગીઓ ઉમેરો (ત્રણ ભાષામાં નામ, ફોટો,
જૂની કિંમત, બેજ). દરેક વાનગી પાસે મોટું લીલું **ઉપલબ્ધ** બટન છે — ફોન પરથી એક ટૅપમાં તે લાલ
**ખતમ** થાય છે અને લગભગ 10 સેકન્ડમાં બધા ટીવી પર દેખાય છે; રૂમ સર્વિસમાં પણ તે ઓર્ડર થઈ શકતી નથી.
કેટેગરીનો સમય (દા.ત. નાસ્તો 07:00–11:00) રાખવાથી તે ફક્ત એ સમયે જ ટીવી પર દેખાય છે. ટીવી
સ્ક્રીન **એપ્સ → મેનુ બોર્ડ** માંથી બનાવો: લેઆઉટ (કૉલમ, ફોટો કાર્ડ, આજની સ્પેશિયલ), કઈ કેટેગરી
બતાવવી, ખતમ વાનગી છેકેલી બતાવવી કે છુપાવવી, ચલણ ચિહ્ન (₹). મેનુ લાંબું હોય તો પેજ આપમેળે બદલાય છે.
