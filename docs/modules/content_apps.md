# Content display apps — showcase, wedding welcome, photo album, Google Sheet, social wall

Five ready-made TV screens built on the display-apps framework (`docs/modules/display_apps.md`).
Create them in **Admin → Apps**, pick a theme / font / language, save, and assign the screen to rooms,
groups or playlists like any other content. TVs need no update.

| App (key) | Gallery group | What it shows | Live data |
|---|---|---|---|
| Showcase (`showcase`, #9) | Business | Real-estate project / product: Ken Burns slideshow + info panel (name, tagline, key features, price, phone, QR) | only when an album is used (60 s) |
| Wedding / event welcome (`event_welcome`, #10) | Business | Title (શુભ વિવાહ), names, family line, date, venue, programme with *Now* / *Next*, photos, festive frame, welcome text rotating in gu / hi / en | 60 s |
| Photo album (`photo_album`, #19) | Content | Cross-fade slideshow of an album filled from phones (staff + optional guest QR link) | 30 s |
| Google Sheet table (`sheet_table`, #14) | Content | A published Google Sheet as a big paged table (price lists, rates, aarti / class timetables) | 60 s (sheet reloaded every *N* minutes) |
| Social wall (`social_wall`, #16) | Content | Facebook Page timeline and / or rotating public Instagram / Facebook posts via official embeds | none (page reloads every 30 min) |

## User guide

### Showcase (#9)
1. Upload the project photos to the **Content Library** (or to a **Photo album** from your phone).
2. Apps → *Showcase*: project name, tagline, tick the library photos and / or choose an album.
3. *Key features*: one per line (`2 & 3 BHK`, `Ready possession`, `RERA no. PR/GJ/…`), a price line
   (`Starting ₹ 45 lakh*`) and the contact phone.
4. QR code: *Link* (brochure PDF, 3D tour, website — any `http(s)` address) or *WhatsApp chat* with the
   contact phone (opens WhatsApp with "Hello, I am interested in <project>."). Text under the QR is optional.
5. Info panel on the right, left or bottom; photo interval; slow zoom (Ken Burns) on / off.

### Wedding / event welcome (#10)
* Title (default "Shubh Vivah" in the admin language), names (`Riya & Aarav`), family / host line,
  date, venue.
* **Programme**, one line per item: `time | name | place` — e.g. `10:00 | Haldi | Garden`,
  `6:30 pm Sangeet`, `18:00 | Baraat`. For another day put the date first: `2026-12-11 19:30 | Reception`
  or `11/12 08:00 | Pheras`. Lines without a time are shown but never highlighted.
  Times are in the **hotel time zone**. The item that started last is marked **Now** (for at most 3 hours,
  until the next one starts), the next one **Next**, past items are dimmed. The TV updates every minute.
* Photos: choose a **Photo album** (guests can add photos with the album's QR code) or one library image.
* **Frame**: *Automatic* follows the theme — flowers for Wedding / Holi, lights (diyas) for Diwali /
  Christmas, mandala for Navratri, Janmashtami, Eid, Temple saffron; a simple border otherwise. Or pick
  flowers / mandala / lights / simple / none.
* Welcome message rotating in Gujarati, Hindi and English (each text editable, empty = skipped).

### Photo album (#19)
* **Admin → Photo albums** (`admin/album_upload.php`, permission `albums.manage` = staff and above; works
  well on a phone): create an album, open it, tap **Choose photos** (gallery, many at once) or **Take a
  photo** (camera). Photos upload **one by one with a progress bar**; big photos are made smaller on the phone
  first (max 1920 px JPEG) and re-encoded on the server (Uploader: JPG / PNG / GIF / WEBP, max 25 MB,
  `image_max_width`, EXIF rotation applied, metadata stripped).
* **HEIC** (iPhone default) is refused with a clear message: *iPhone → Settings → Camera → Formats →
  Most Compatible*, or share as JPG. (Most iPhones convert automatically when uploading from Safari.)
* Optional caption for a batch; edit captions, move photos earlier / later, delete.
* App settings: album, heading, interval, whole photo (blurred edges) or fill screen, captions, upload
  date, shuffle, slow zoom, maximum photos, *show the guest upload QR code*. New photos appear on the TVs
  **within 30 seconds** and are shown next.
* **Guest upload link** (good for weddings): in the album settings turn on *Guests may upload photos*.
  The page shows a link and QR code (`<site>/album/?a=<id>&s=<signature>`) — print it or show it on the TV
  with the photo-album app. Guests need no login; they can add their name and a caption.
  * *Moderation* (on by default): guest photos stay **pending** until a staff member taps **Approve**
    (or *Approve all*); *Reject* deletes them.
  * *Maximum guest photos* per album (default 100); after that the link answers "maximum reached".
  * Rate limit: 20 uploads per 10 minutes per phone (IP) and album.
  * **New link** makes old links and printed QR codes stop working; switching the option off closes the page.

### Google Sheet table (#14)
1. In Google Sheets: **File → Share → Publish to web** → choose the sheet → *Comma-separated values
   (.csv)* → **Publish** → copy the link.
2. Apps → *Google Sheet table* → paste it. Accepted forms (normalised automatically):
   `https://docs.google.com/spreadsheets/d/e/<id>/pub?output=csv` (`&gid=…&single=true`), `…/pubhtml`,
   `https://docs.google.com/spreadsheets/d/<id>/export?format=csv&gid=…` and `…/d/<id>/edit#gid=…`
   (the last two only work when the sheet is shared "anyone with the link"). **Only `docs.google.com`
   is allowed.**
3. Options: header row on / off, columns (`A, C, D`, `1, 3` or header names; empty = all), text size,
   rows per page and page interval (automatic paging), reload every *N* minutes (min 1), highlight the
   row containing today's date (`2026-10-07`, `07/10/2026`, `7 Oct`, …) or weekday (`Wednesday`,
   `બુધવાર`, `बुधवार`), show "Updated 10:32 AM".
* Cell texts are shown exactly as in the sheet (number formats untouched); numeric columns are right-aligned.

### Social wall (#16)
* **Facebook page** (optional): `https://www.facebook.com/yourpage` → official Page Plugin (timeline).
* **Post links**, one per line (max 30): public Instagram posts / reels (`instagram.com/p/<code>/`,
  `/reel/<code>/`, `/<user>/p/<code>/`), Facebook posts (`/page/posts/<id>`, `permalink.php?story_fbid=…&id=…`,
  `photo/?fbid=…`) and videos (`/page/videos/<id>`, `/watch/?v=<id>`, `/reel/<id>`). They rotate, 1–3 side
  by side (max 2 next to the page timeline).

## Limits (read before selling it)

* **Social wall**: works **without an API token**, so: the TV needs **internet**; posts must be **public**;
  embeds are rendered by Facebook / Instagram (their layout, their cookie prompts, may be slow on old TVs);
  **videos may not autoplay** (WebView autoplay policy); **no automatic "latest posts"** for Instagram —
  you paste the posts you want (the Facebook Page Plugin does show the page's latest posts). Facebook may
  block embedding for some pages (age / country restricted, unpublished). **X / Twitter is not supported.**
  Instagram Basic Display / Graph API tokens (for automatic latest posts) are **not implemented**: Basic
  Display was shut down by Meta (Dec 2024) and the Graph API needs a Business account, an app review and
  60-day token refresh — out of scope for now.
* **Google Sheet**: the sheet must be published (or shared "anyone with the link" for export links);
  max **500 rows × 20 columns**, 300 characters per cell, 2 MB; the server reloads it at most every
  *refresh* minutes (per hotel cache), so edits appear after up to *refresh* + 1 minutes. When Google is
  unreachable or answers with an error / an HTML login page, the TV keeps showing the **last good copy**
  with the error in the footer. Formulas are not evaluated (the CSV has the calculated values).
* **Photo album**: images only (no videos); HEIC not supported; max 1000 photos per album; photos use the
  hotel's upload storage.
* Showcase / welcome slideshows show library images and album photos; videos are not supported there.

## Developer notes

| File | What |
|---|---|
| `core/Apps/ShowcaseApp.php`, `EventWelcomeApp.php`, `PhotoAlbumApp.php`, `SheetTableApp.php`, `SocialWallApp.php` | The apps |
| `core/ContentApps.php` | Shared helpers: slideshow assets, library / album slides, album id validation, QR, WhatsApp link, lines |
| `core/Albums.php` | Albums + photos (tenant tables), upload (HEIC check, EXIF rotation, Uploader), guest link signing |
| `core/SheetFeed.php` | Google Sheets URL normalisation (SSRF guard), fetch + cache, CSV parser |
| `admin/album_upload.php` | Album management (mobile-first), permission `albums.manage` (staff) — `core/boot.d/content_apps.php` |
| `album/index.php` | Public guest upload page + upload endpoint (signed, rate-limited, CSP) |
| `assets/js/album_upload.js` | Phone uploader (resize, one by one, progress) for both pages |
| `assets/display/apps/lib-slides.js` / `.css` | Shared cross-fade / Ken Burns slideshow (`HCSlides.create(el, opts).set(list)`), included by `ContentApps::slidesAssets()` |
| `assets/display/apps/<key>.js` / `.css` | Per-app TV scripts (ES5) and styles |
| `migrations/018_content_apps.sql` | Tables `albums`, `album_photos` |
| `admin/partials/nav.d/16_content_apps.php` | Sidebar item "Photo albums" (after Notice board) |
| `lang/{gu,hi}_apps_{showcase,event_welcome,photo_album,sheet_table,social_wall}.php` | Translations |
| `tests/Integration/Apps/ContentAppsTest.php` | Tests (rendering en / gu / hi, validation, XSS, album + guest flow, Http mocks) |

* **Tables**: `albums` (hotel_id, name, guest_upload, guest_moderation, guest_max, guest_key) and
  `album_photos` (hotel_id, album_id, image_path, thumb_path, caption, status `approved|pending`,
  source `staff|guest`, guest_name, uploader_ip, sort_order). Both registered with `Tenant::registerTable()`;
  every lookup goes through `Tenant::find()` (another hotel's id → 404).
* **Guest link signature**: `s` = first 32 hex of `HMAC-SHA256("album:<hotel_id>:<album_id>:<guest_key>", APP_KEY)`.
  The album row is looked up by id, the signature binds it to its hotel, then `Tenant::set()`.
  Upload: `POST album/?a=&s=` with `op=upload`, `photo` (multipart), optional `caption`, `guest_name` →
  `{"ok":true,"data":{"id","status","message"}}`; errors `404 NOT_FOUND`, `403 SUSPENDED|CLOSED|LIMIT`,
  `413 TOO_LARGE`, `422 UPLOAD`, `429 RATE_LIMITED` (+ `Retry-After`).
* **Staff upload endpoint**: `POST admin/album_upload.php` `op=upload&album_id=…` with
  `X-Requested-With: XMLHttpRequest` + `X-CSRF-Token` → same JSON. Other ops: `album_save`, `album_delete`,
  `guest_rotate`, `photo_caption`, `photo_approve`, `approve_all`, `photo_delete`, `move` (dir up/down),
  `reorder` (`ids[]`).
* **SSRF**: `SheetFeed::normalizeUrl()` accepts only `https://docs.google.com/spreadsheets/…` (no user /
  password / port) and **rebuilds** the URL from the sheet id and gid, so nothing else from the admin's
  input reaches the request. Google may redirect the download to `*.googleusercontent.com` (cURL follows
  Google's redirect). `SheetFeed::get()` refuses any URL that is not already canonical, so tampered stored
  settings cannot make the server fetch another host; the social
  wall never fetches anything server-side (only iframe `src` built from validated facebook.com /
  instagram.com parts).
* **Sheet cache**: `Cache` namespace `sheet_h<hotel>`, key = canonical URL, value
  `{rows, ok_at, checked_at, error: [code, params]}`; `checked_at` is written before fetching so parallel TV
  polls do not all fetch. Errors are stored as codes and translated in the TV language (`SheetFeed::ERRORS`).
* **Tests with HTTP mocks**: in-process `Http::$mock`; over HTTP `TestEnv::writeConfig(HC_ROOT, $url,
  ['http_mock_file' => …])` (see `core/Http.php`).

## ગુજરાતી સારાંશ

* **શોકેસ**: રિયલ એસ્ટેટ પ્રોજેક્ટ / પ્રોડક્ટ માટે ફોટા (કન્ટેન્ટ લાઇબ્રેરી કે આલ્બમ) નો ધીમા ઝૂમ સાથે સ્લાઇડ શો,
  સાથે નામ, ટૅગલાઇન, મુખ્ય વિશેષતાઓ (2 અને 3 BHK, RERA નં.), કિંમત, ફોન અને બ્રોશર / 3D ટૂર / WhatsApp માટે QR કોડ.
  પેનલ જમણે, ડાબે કે નીચે.
* **લગ્ન / પ્રસંગ સ્વાગત**: "શુભ વિવાહ", વર-કન્યાનાં નામ, પરિવાર, તારીખ, સ્થળ અને કાર્યક્રમ (`10:00 | હલ્દી`,
  `18:00 | જાન`). હોટલના સમય પ્રમાણે ચાલુ કાર્યક્રમ "હમણાં" અને આગળનો "પછી" તરીકે પ્રકાશિત થાય છે. આલ્બમના ફોટા,
  થીમ મુજબ ફ્રેમ (ફૂલો / દીવા / મંડલા) અને ગુજરાતી-હિન્દી-અંગ્રેજીમાં બદલાતો સ્વાગત સંદેશ.
* **ફોટો આલ્બમ**: Admin → ફોટો આલ્બમ પરથી ફોનમાં ઘણા ફોટા પસંદ કરો કે કૅમેરાથી પાડો; એક પછી એક પ્રગતિ સાથે અપલોડ
  થાય છે અને 30 સેકન્ડમાં ટીવી પર આવે છે. HEIC (iPhone) ફોટા ચાલતા નથી — iPhone માં Settings → Camera →
  Formats → Most Compatible કરો. લગ્ન માટે **મહેમાન લિંક / QR કોડ** ચાલુ કરો: મહેમાનો લૉગિન વગર ફોટા મોકલે,
  મંજૂરી (moderation) ચાલુ હોય તો તમે "મંજૂર કરો" દબાવો ત્યારે જ ટીવી પર દેખાય. મહત્તમ ફોટા અને દર ફોનની મર્યાદા છે;
  "નવી લિંક" થી જૂનો QR બંધ થાય છે.
* **Google શીટ ટેબલ**: Google Sheets માં File → Share → Publish to web → CSV કરીને લિંક પેસ્ટ કરો (ફક્ત
  docs.google.com). ભાવ યાદી / રૂમ ભાડાં / આરતીનો સમય મોટા ટેબલમાં, આપોઆપ પાનાં બદલાય, આજની તારીખ કે વારવાળી
  પંક્તિ પ્રકાશિત. શીટ દર N મિનિટે ફરી લોડ થાય; ભૂલ થાય તો છેલ્લી સારી નકલ દેખાતી રહે. મર્યાદા 500 પંક્તિ × 20 કૉલમ.
* **સોશિયલ વૉલ**: Facebook પેજની ટાઇમલાઇન અને પસંદ કરેલી જાહેર Instagram / Facebook પોસ્ટ. ટોકન વગર ચાલે છે,
  એટલે ટીવીમાં ઇન્ટરનેટ જોઈએ, પોસ્ટ જાહેર હોવી જોઈએ, વીડિયો આપોઆપ ન ચાલે એવું બને, અને Instagram ની નવી પોસ્ટ
  આપોઆપ ઉમેરાતી નથી — લિંક જાતે પેસ્ટ કરો. X / Twitter ચાલતું નથી.
