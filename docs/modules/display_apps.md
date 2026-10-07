# Display apps (2.3) — developer guide

A **display app** is a ready-made TV screen that the server renders as a web page: notice board,
countdown, QR code, menu board, token display, offers … The admin picks an app in **Admin → Apps**,
fills in a few fields, chooses a theme, and saves. The result is a normal content item
(`content_items.type = 'app'`) that goes into rooms, groups, playlists, broadcasts and schedules like
any other content. The TV shows it in its WebView as a `url` item, so **every TV app since 2.0 can
show display apps without an update**.

This page is the contract for everyone who adds an app. Read it once, then copy one of the three
reference apps (`core/Apps/QrApp.php` is the simplest, `NoticeBoardApp.php` has live data and its own
management page, `CountdownApp.php` has client-side ticking).

## 1. How it fits together

```
Admin → Apps (admin/apps.php)                 TV (Android WebView) / admin preview / TV simulator
  gallery of DisplayApps::all()                 GET /display/?c=<content id>&s=<signature>&v=<rev>
  form: title, duration, theme, font,              display/index.php → DisplayApps::renderPage()
        accent, language + $app->form()              = app.css + theme CSS variables + $app->render()
  save → content_items (type 'app')                  + app.js (live refresh) + apps/<key>.css|js
                                                  every refreshSec: GET /display/data.php?c=&s=
TV poll (api/device/command)                         → $app->data() → window.HCApp.update(data)
  ContentManager::toTvItem() →
  {"type":"url","url":"<signed display URL>","app":"qr","refresh_sec":0, "duration": …}
```

* **Settings JSON** of an app item:
  `{"app": "qr", "config": {…app fields…}, "theme": "diwali", "font": "auto", "accent": "#FF8F00"|null, "lang": "auto"|"en"|"gu"|"hi"}`
  (`DisplayApps::normalize()` fills / repairs every key; `lang: auto` = the hotel's default language).
* **Signed URL**: `s` = first 32 hex characters of `HMAC-SHA256("display:<hotel_id>:<content_id>", APP_KEY)`
  (`DisplayApps::signature()`). It is not guessable, read-only and needs no login. Wrong signature,
  a deleted item, a non-app item or another hotel's id → **404**; a suspended / expired hotel → **403**
  with a neutral "Service paused" page. `v` is the item's revision (`DisplayApps::rev()`, hash of
  title + settings + duration): after an edit the URL — and therefore the TV content hash — changes, so
  TVs reload the page. `&preview=1` marks admin previews (`$ctx['preview']`).
* **Language**: while `render()` / `data()` run, `I18n` is switched to the item's language, so `__()`
  returns TV texts in English, Gujarati or Hindi. The admin language is restored afterwards.
* **Tenancy**: `display/index.php` looks the item up by id, verifies the signature against the row's
  `hotel_id` and then calls `Tenant::set()`. Inside an app everything works as in the admin panel
  (`Tenant::id()`, `Settings::get()`, `DB` auto-scoping, `ContentManager::findOwn()`).
* **Permissions**: the Apps page and app items use the content permissions (`content.manage` to
  create / edit / delete, like the Content Library forms). An app's own management page uses its own
  permission (the notice board registers `notices.manage` = staff in `core/boot.d/display_apps.php`).

## 2. File layout

| File | What |
|---|---|
| `core/DisplayApp.php` | Abstract base class (the API below) + form / validation helpers |
| `core/DisplayApps.php` | Registry, settings, signing, themes, page rendering (`renderPage`, `payload`) |
| `core/Apps/<Name>App.php` | **One class per app** (class name = file name, must end in `App`) — auto-discovered |
| `assets/display/app.css` | Base styles, bundled fonts, building blocks (`.hc-card`, `.hc-badge`, `.hc-title` …) |
| `assets/display/app.js` | Runtime: scaling, live refresh with retry, clock / rotate / fit helpers |
| `assets/display/apps/<key>.css` / `<key>.js` | Optional app assets, included automatically when present |
| `assets/fonts/` | Noto Sans Gujarati / Devanagari / Latin woff2 (OFL, `OFL.txt`) |
| `display/index.php`, `display/data.php` | Public signed TV page and live-data JSON |
| `admin/apps.php` | Gallery, create / edit / delete, live preview |
| `lang/gu_apps.php`, `lang/hi_apps.php` | Translations of the framework and the first apps |
| `tests/Integration/Apps/` | Tests (`DisplayAppsTest.php`) + `DisplayAppsTestKit.php` helper |

The first apps: `NoticeBoardApp` (#3, with `core/Notices.php`, `admin/notices.php`, table `notices`
from `migrations/014_display_apps.sql`), `CountdownApp` (#17), `QrApp` (#18).

## 3. The API (`abstract class DisplayApp`)

| Method | Required | Returns |
|---|---|---|
| `key(): string` | yes | Unique id `[a-z0-9_]{2,40}`, stored in settings; also the asset file name |
| `label(): string` / `description(): string` | yes | Gallery card texts, wrap in `__()` |
| `icon(): string` | no (`bi-app`) | Bootstrap icon class, e.g. `bi-qr-code` |
| `category(): string` | no (`content`) | `business` \| `content` \| `widget` (gallery group) |
| `defaults(): array` | yes | The **complete** config with default values |
| `validate(array $in): array` | yes | `[$config, $errors]` — `$in` is the raw `cfg[...]` form input; return a full, normalised config and a list of translated error strings |
| `form(array $config): string` | yes | HTML of the app's own fields (Bootstrap 5 `col-*` blocks, inputs named `cfg[...]`) |
| `render(array $config, array $ctx): string` | yes | HTML **body** of the TV page (inside `#hc-stage`). Escape every value with `e()` |
| `data(array $config, array $ctx): ?array` | no (`null`) | JSON-able live data; `null` = static page |
| `refreshSec(array $config): int` | no (`0`) | Seconds between `data()` refreshes; `0` = no live data |
| `adminPage(): ?string` | no (`null`) | URL of the app's own management page (`admin_url('tokens.php')`) — shown as "Open management page" |

`$ctx` = `['hotel' => ['id','name','logo_url'], 'branding' => Branding::get(), 'lang' => 'en'|'gu'|'hi',
'preview' => bool, 'item' => ['id','title'], 'theme' => resolved theme (bg, fg, accent, font …), 'now' => unix time]`.

Rules:

* The stored config is merged over `defaults()` with `$app->config($stored)` before `render()`,
  `data()`, `form()` and `refreshSec()` — keys you add later automatically get their default, keys you
  remove are dropped. Never read `$config[...]` keys that are not in `defaults()`.
* `defaults()` must render without warnings (the test renders every app with its defaults in en / gu / hi).
* `validate([])` must not throw; unknown / out-of-range values fall back to defaults.
* `render()` and `data()` must not change data and must be fast (TVs poll them).
* Never trust ids from the form: use `ContentManager::find()` / `Tenant::find()` (another hotel's id
  → 404) and check the type, like `NoticeBoardApp::validate()` does for the timetable.
* An exception in `render()` is logged and the TV shows "This app is not available" — never a stack trace.
* Images from the library: `self::imagePicker()` in the form, `self::imageUrl($id)` when rendering (own
  hotel, type image, else null).
* Own tables: tenant tables with `hotel_id`, registered in `core/boot.d/<module>.php` with
  `Tenant::registerTable()`, created in a numbered migration.

### Helpers in `DisplayApp`

Validation (all `protected static`): `str($in, $key, $max, $default)`, `text($in, $key, $max)`,
`int($in, $key, $min, $max, $default)`, `bool($in, $key)`, `choice($in, $key, $allowed, $default)`,
`multi($in, $key, $allowed)`, `color($in, $key, $default)`, `url($in, $key, &$errors, $label)`,
`datetime($in, $key)` (→ `Y-m-d H:i:s` or null).

Form fields (Bootstrap markup, names `cfg[key]`): `input($key, $label, $value, $type, $attrs, $help, $col)`,
`textarea()`, `select($key, $label, $options, $value, $help, $col, $attrs)`, `checkbox()` (posts 0/1),
`checkboxes()` (posts `cfg[key][]`), `colorInput()`, `imagePicker()`.

## 4. Add an app in 5 steps

1. **Class** — create `core/Apps/MenuBoardApp.php`:

   ```php
   <?php
   declare(strict_types=1);

   final class MenuBoardApp extends DisplayApp
   {
       public function key(): string { return 'menu_board'; }
       public function label(): string { return __('Menu board'); }
       public function description(): string { return __('Dishes and prices in columns.'); }
       public function icon(): string { return 'bi-egg-fried'; }
       public function category(): string { return 'business'; }

       public function defaults(): array
       {
           return ['heading' => __('Today\'s menu'), 'items' => "Masala dosa | 120\nIdli | 80", 'currency' => '₹'];
       }

       public function validate(array $in): array
       {
           $c = ['heading' => self::str($in, 'heading', 120), 'items' => self::text($in, 'items', 4000), 'currency' => self::str($in, 'currency', 5, '₹')];
           return [$c, $c['items'] === '' ? [__('Add at least one dish.')] : []];
       }

       public function form(array $config): string
       {
           return self::input('heading', __('Heading'), $config['heading'])
               . self::textarea('items', __('Dishes (one per line: name | price)'), $config['items'], 8);
       }

       public function render(array $config, array $ctx): string
       {
           $rows = '';
           foreach (array_filter(explode("\n", $config['items'])) as $line) {
               [$name, $price] = array_map('trim', explode('|', $line, 2) + [1 => '']);
               $rows .= '<div class="mb-row hc-card"><span>' . e($name) . '</span><b>' . e($config['currency'] . $price) . '</b></div>';
           }
           return '<div class="hc-header"><div class="hc-title"><h1>' . e($config['heading']) . '</h1></div>'
               . '<div class="hc-clock" data-hc-clock="12"></div></div><div class="hc-body mb-grid">' . $rows . '</div>';
       }
   }
   ```

2. **Styles / script** (optional) — `assets/display/apps/menu_board.css` and `menu_board.js`
   (ES5 only, see §6). Use rem units and the theme variables (§5).
3. **Live data** (optional) — implement `data()` + `refreshSec()` and `window.HCApp.update(data)` in
   the JS file. Data comes from your tables; the page re-renders without reloading.
4. **Translations** — every `__()` string in Gujarati and Hindi: `lang/gu_apps_menu_board.php` and
   `lang/hi_apps_menu_board.php` (any `lang/<code>_*.php` file is merged automatically). TV texts
   (render / data) are shown in the item's language, admin texts in the admin's language.
5. **Test** — `tests/Integration/Apps/MenuBoardTest.php` (the directory is part of the integration
   suite; see §8). `DisplayAppsTest::testEveryRegisteredAppRendersInEveryLanguage` already renders your
   app with its defaults in en / gu / hi and checks `data.php`.

Nothing else is needed: the gallery, the content library, playlists, the TV contract, signing,
themes, fonts, preview and the TV simulator work automatically. Only touch shared files if your app
needs a management page (`admin/<page>.php` + an entry in `admin/partials/nav.d/`), a table
(`migrations/0NN_*.sql` + `core/boot.d/`) or a permission (`Auth::registerPermission()` in boot.d).

## 5. Themes (#20) and fonts

Every app item picks a theme preset, a font and optionally its own accent colour (generic form,
`DisplayApps::THEMES`): `classic_dark` (default), `light`, `temple_saffron`, `diwali`, `navratri`,
`janmashtami`, `holi`, `christmas`, `eid`, `wedding`, `corporate_blue`, `restaurant_warm`.

They become CSS variables on `:root` — **use them instead of fixed colours**:

| Variable | Use |
|---|---|
| `--hc-bg`, `--hc-bg2` | Page background (gradient from bg to bg2; already set on `body`) |
| `--hc-fg`, `--hc-muted` | Text, secondary text |
| `--hc-accent`, `--hc-accent-fg` | Highlights / headings, text on the accent colour (auto black/white) |
| `--hc-card`, `--hc-card-fg`, `--hc-border` | Cards / panels |
| `--hc-font`, `--hc-heading-font` | Body font stack, heading font (serif for "wedding") |

`<body>` carries `hc-app-<key> hc-theme-<preset> hc-font-<font>` (and `hc-preview` in previews),
`<html>` carries `hc-lang-<lang>`, if an app needs per-theme tweaks.

Fonts: `auto` (Gujarati / Hindi / Latin by the item language), `gujarati`, `hindi`, `latin`, `serif`.
The bundled Noto Sans Gujarati, Noto Sans Devanagari and Noto Sans (latin) woff2 files are split by
`unicode-range`, so mixed text (e.g. "Room 101 · રૂમ") always renders, and only the needed files load.

## 6. Page runtime (`assets/display/app.js`)

**Sizing**: the stage is 16:9; `1rem` = 1/64 of the largest 16:9 box in the window (30 px on 1080p,
20 px on 720p, 60 px on 4K). Size everything in `rem`; the page looks identical on every TV and in the
small admin preview. No scrollbars (`overflow: hidden`). Safe padding: `#hc-stage` has 2.2rem × 2.8rem.

**Building blocks** (app.css): `.hc-header` (row: `.hc-logo`, `.hc-title` with `h1` + `.hc-subtitle`,
`.hc-clock`, `.hc-date`), `.hc-body` (flex: 1), `.hc-card`, `.hc-badge`, `.hc-muted`, `.hc-accent`,
`.hc-center` (centred full-size box), `.hc-empty`, `.hc-footer`, `.hc-slides` / `.hc-slide` (rotation).

**JS hook** — in `assets/display/apps/<key>.js`:

```js
window.HCApp = {
  init: function (cfg) { /* optional, once; cfg = window.HC_DISPLAY */ },
  update: function (data, initial) {
    // initial = true: called once at start with the data embedded in the page (same as render()).
    // initial = false: new data from data.php every refresh_sec seconds.
  }
};
```

`window.HC_DISPLAY` = `{app, item, rev, lang, preview, refresh_sec, data_url, server_now, tz_offset_min, data, i18n}`.

Helpers on `window.HC`:

| Helper | |
|---|---|
| `HC.now()` | Server-corrected epoch ms (TV clocks are often wrong) |
| `HC.local(ms?)` | Date whose `getUTC*()` fields are the **hotel's** local time |
| `HC.formatTime(d, h24, seconds)`, `HC.formatDate(d)` | "6:05 PM", "Wednesday, 7 October 2026" (translated) |
| `HC.clock(el, {h24, seconds, date})` | Live clock; or just put `data-hc-clock="12|24"` / `data-hc-date` on an element |
| `HC.rotate(container, sec, onShow)` | Cycle `.hc-slide` children (cross-fade) |
| `HC.fitText(el, minRem)` | Shrink the font until the content fits the box |
| `HC.esc(s)`, `HC.pad(n)` | HTML-escape, two digits |
| `HC.refresh()` | Fetch data now |

**Live refresh**: `data.php` is polled every `refresh_sec` (min 3 s) with a cache-buster. On failure it
retries with back-off (up to 5 min) and keeps showing the last data; after 2 failures a small
"Reconnecting…" pill appears; it disappears on the next success. If the item was edited (`rev`
changed) the page reloads itself (not in previews). Apps without live data still check `rev` every
10 minutes. JSON: `{"ok":true,"rev":"…","server_now":<ms>,"refresh_sec":N,"data":{…}|null}`.

**Old WebViews**: shipped JS must be ES5 (Chrome 50-era TV WebViews): no `=>`, `let`/`const`, template
literals, `?.`, `fetch`, `Promise`, `classList.toggle(x, force)`, CSS `gap` in flexbox, `min()` /
`clamp()`, `display: contents`. Use XHR, `var`, `-webkit-` prefixed flexbox / transforms. No build step.
Check with `node acorn/bin/acorn --ecma5 --silent file.js` (acorn from `npm pack acorn`).

## 7. TV and admin behaviour

* TV item: `{"id", "type":"url", "title", "duration", "url": <signed>, "app": key, "refresh_sec": 0}` —
  `refresh_sec: 0` because the page refreshes its own data (the Android `ContentPlayer.buildWeb` would
  otherwise reload the whole page). Network errors on the TV are retried after 30 s by the app.
* `duration` works as for any item (playlists); a single assigned item stays on screen.
* Admin preview: the edit form shows the saved page in an iframe (`&preview=1`); while typing, the form
  is POSTed with `op=preview` and the draft page is shown (`srcdoc`, no polling, not saved).
* The TV simulator (`admin/preview.php`) shows app items like any `url` item.
* The Content Library lists app items (type "Display app"); "Edit" and "Add content → Display app"
  lead to `admin/apps.php`.
* In previews (`$ctx['preview']`) an app may show sample data when it has none (the notice board does).

## 8. Tests

`tests/Integration/Apps/*Test.php` run in the integration suite (`phpunit.xml`). Use the kit:

```php
require_once __DIR__ . '/DisplayAppsTestKit.php';

$item = DisplayAppsTestKit::createItem('menu_board', ['heading' => 'Lunch'], ['lang' => 'gu', 'theme' => 'diwali']);
$html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);   // 200, no PHP warning, hc-app-menu_board
[$code, $json] = DisplayAppsTestKit::data(self::$url, $item);          // display/data.php
[$code, $html] = DisplayAppsTestKit::page(self::$url, $item, ['preview' => 1]);
$doc = DisplayAppsTestKit::renderInProcess($item);                     // no HTTP
```

Set up the HTTP server like the other integration tests (`TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2)`
then `TestEnv::writeConfig(HC_ROOT, $url)`), and check `TestEnv::phpErrors() === ''` at the end.
Cover: validation (good and bad input), XSS in every text field (`e()` everywhere; JSON for JS is
escaped by the runtime), tenancy for your tables (another hotel's ids → 404), the management page
(CRUD + CSRF 419 + permissions).
Run: `HC_TEST_DB_NAME=… php phpunit.phar -c phpunit.xml tests/Integration/Apps/MenuBoardTest.php`.

## 9. Reference: notice board (#3)

* Table `notices` (hotel_id, title, body, category `exam|holiday|result|event|general`, starts_on,
  ends_on, priority, image_path / thumb_path, is_active). Current = active and today within the dates.
  Order: priority desc, newest first.
* `admin/notices.php` (permission `notices.manage`, staff+): list with state badges, create / edit with
  image upload (`Uploader`), remove image, show / hide, delete.
* App config: heading, sub-heading, categories (none = all), layout `rotate` (one at a time) or `list`
  (pages through when long), change interval, maximum, dates, clock, optional timetable content item
  shown beside the notices (rendered by `ContentManager::renderTimetable()` in an `iframe srcdoc`).
  Live data every 60 s.
