# Module: Slide designer (#11) and PDF / PowerPoint → slides (#12) — 2.3

Two browser-side tools that turn into **ordinary `image` content items**. The TV app needs nothing new,
and the server does no rendering, because shared hosting cannot run headless browsers, ImageMagick or
LibreOffice:

* **Slide designer** (`admin/designer.php`) is a simple drag & drop editor for 1920×1080 slides, built
  on fabric.js. The browser exports a PNG and uploads it together with the editable design JSON.
* **PDF import** (`admin/pdf_import.php`) renders each page of a PDF with pdf.js in the browser and
  uploads the pages one at a time. It can also create a playlist of all pages.

Both are opened from the Content Library ("Design a slide" and "Import PDF / slides" buttons). The
designer also has its own menu entry right after the Content Library.

## Files

| file | purpose |
|---|---|
| `migrations/020_designer.sql` | tables `designs`, `pdf_imports` and `pdf_import_pages` (all tenant tables with `hotel_id`) |
| `core/Designer.php` | validation (PNG/JPEG ≤ 8 MB, dimensions ≤ 4096 px, JSON ≤ 1 MB), save/update of designs, hotel templates, idempotent PDF pages and playlist, starter templates, font discovery |
| `core/boot.d/designer.php` | registers the three tenant tables |
| `admin/designer.php` | the editor page; the config is embedded as `<script type="application/json" id="dzConfig">` |
| `admin/pdf_import.php` | the PDF import page (`<script id="pdfConfig">`) |
| `admin/ajax.d/designer.php` | AJAX actions `designer_*` (see below) |
| `admin/partials/nav.d/14_designer.php` | menu entry "Design a slide" (`content.manage`, `['after' => 'content']`) |
| `assets/js/designer.js`, `assets/js/pdf_import.js`, `assets/css/designer.css` | front end; no build step |
| `assets/vendor/fabric/` | fabric.js 6.9.1 `dist/index.min.js` saved as `fabric.min.js` + LICENSE (MIT) |
| `assets/vendor/pdfjs/` | pdfjs-dist 4.10.38 **legacy** build `pdf.min.mjs` / `pdf.worker.min.mjs`, renamed to `.js` so shared hosts serve them with a JavaScript MIME type (ES modules need one) + LICENSE (Apache-2.0) |
| `templates/designer/*.json` | 14 starter templates (fabric JSON, see below); the folder is web-denied by `templates/.htaccess` |
| `lang/gu_designer.php`, `lang/hi_designer.php` | Gujarati and Hindi strings |
| `tests/Integration/Apps/DesignerTest.php` | tests |

### Changes to shared files

1. `admin/content.php` gets "Design a slide" and "Import PDF / slides" buttons next to "Add content" (for
   `content.manage`). Editing an image item that has a design redirects to `designer.php?id=…`;
   `?raw=1` keeps the plain image form, in the same way as template items.
2. `THIRD_PARTY_NOTICES.md` lists fabric.js and pdf.js.

Fonts: the designer uses the woff2 files under `assets/fonts/` added by the display-apps module
(@fontsource Noto Sans, Noto Sans Gujarati and Noto Sans Devanagari, 400/700). `Designer::fontFaces()`
scans that folder, so more fonts can be added there by dropping files in. It understands @fontsource
names (`hind-vadodara-gujarati-400-normal.woff2`) and Google names (`Mukta-Bold.woff2`). It writes
`@font-face` rules with the right `unicode-range`. Every font choice ends in an Indic fallback stack,
so Gujarati and Hindi text render whatever font is picked.

## Access

The pages and actions use the same permissions as `content.php`. `content.view` is needed to log in to
the library. The designer, PDF import and every `designer_*` action need **`content.manage`** (manager
and above), so staff get 403. `designer_pdfplaylist` also needs `playlists.manage`.

## Data

* `designs`: `kind = design` rows belong to one `image` content item (`content_id` UNIQUE, with
  `ON DELETE CASCADE`, so deleting the item deletes its design). `data` is the fabric JSON, cleaned to the
  keys `version, objects, background, backgroundImage, hc`, where `hc` holds the editor's background
  controls. `kind = template` rows are the hotel's own templates and have no content item. Saving a
  template with an existing name replaces it. A hotel can keep at most 100 templates.
* `pdf_imports`: one row per browser batch (`UNIQUE (hotel_id, batch_id)`). `batch_id` is 32 hex
  characters chosen by the browser. `playlist_id` is set once the playlist exists.
* `pdf_import_pages`: `PRIMARY KEY (import_id, page)` → `content_id`. This makes page uploads
  idempotent: a retried page returns the existing item. If two retries race, the loser deletes its own
  item and file.

Image URLs inside a design are stored as `../uploads/h<id>/media/…`, relative to `admin/`. That keeps
the canvas same-origin (a CDN or a changed host name would taint the export), and the design survives a
domain change. If a picture's file is gone, the editor leaves it out and shows a warning.

## AJAX actions (`admin/ajax.php?action=…`)

All actions are multipart POST (`FormData`) requests with the `X-Requested-With: XMLHttpRequest` and
`X-CSRF-Token` headers. A missing or wrong token returns **419**. A GET returns 405. A body over
`post_max_size` returns 413. Errors are `{ok:false,error:{code,message}}` with 404 (`NOT_FOUND`), 413
(`TOO_LARGE`) or 422 (`VALIDATION_ERROR`).

| action | input | result |
|---|---|---|
| `designer_save` | `id` (0 = new), `title`, `duration`, `design` (JSON as a field or as a file part, ≤ 1 MB), `png` (≤ 8 MB, real PNG) | `{id, created, url, thumb, edit_url}`. Creates or updates the `image` item and its design. Re-saving keeps the item id, playlists and room assignments, replaces the file and deletes the old file. An id from another hotel returns 404 and a non-image item returns 422. |
| `designer_image` | `file` (JPG/PNG/GIF/WEBP) | `{id, title, url, thumb}`. A picture uploaded in the designer is added to the content library through the normal `Uploader`. |
| `designer_template` | `name`, `design` | `{id}` |
| `designer_tpldelete` | `id` | `{}`. Another hotel's template returns 404. |
| `designer_pdfpage` | `batch`, `file_name`, `page` (1–100), `duration`, `image` (PNG/JPEG ≤ 8 MB) | `{id, created, title}`. Creates an item titled `"<file> – page N"`. Idempotent per (batch, page). |
| `designer_pdfplaylist` | `batch`, `name` (default: file name), `duration` (per item), `transition` | `{id, created, items, edit_url}`. Builds a playlist of all uploaded pages in page order. A retry returns the same playlist. |

Images always go through `Designer::checkImage()` (size, finfo MIME, `getimagesize`, dimensions ≤
4096 px) and then `Uploader::handle($file, 'image')`. That re-encodes the image, which strips metadata
and any payload, resizes it to `image_max_width` (1920), writes a thumbnail and stores it under
`uploads/h<hotel>/media/YYYY/MM/`. PNG stays PNG and JPEG becomes JPEG (quality 85).

## Editor features (`assets/js/designer.js`)

* Text (heading, sub-heading, body) as fabric `Textbox`, with: font, size, colour, bold, italic,
  underline, alignment, line spacing, shadow (colour and softness) and outline (colour and width,
  painted behind the fill).
* Pictures: upload (stored in the library), choose from the hotel's library images, and the hotel logo
  (`hotel_logo` setting).
* Shapes: rectangle, rounded box, circle, triangle, line and star, with fill, border, border width,
  corner radius and transparency.
* Background: solid colour, two-colour gradient (→ ↓ ↘), or a picture that covers the slide.
* Layers: a list (top first), bring to front / forward / backward / to back, duplicate, delete, and
  align to the slide edges or centre.
* Undo / redo: 60 JSON snapshots, also on Ctrl+Z / Ctrl+Y / Ctrl+Shift+Z.
* Snap guides to the slide edges and centre and to other objects' edges and centres (can be switched off).
* Keyboard: Delete / Backspace removes, arrow keys move by 1 px (10 px with Shift), Ctrl+D duplicates
  and Ctrl+S saves.
* Templates: 14 ready-made starters, the hotel's own templates (with "Save as template"), and a blank
  slide. Thumbnails are rendered in the browser.
* Save: export at 1920×1080 with `toCanvasElement(1920 / displayWidth)`. The editor checks the PNG
  against 8 MB and the server upload limit before uploading, then shows a progress bar and replaces
  the URL with `designer.php?id=…`.

The canvas is shown scaled (fabric zoom). All coordinates in the JSON are 1920×1080 slide pixels.

### Starter templates (`templates/designer/NN_id.json`)

```json
{ "id": "diwali", "name": "Festival greeting – Diwali", "category": "festival",
  "design": { "version": "6.9.1", "background": "#… or a linear gradient", "objects": [ fabric objects ] } }
```

`name` is an English string translated with `__()`. `{hotel}` in a text is replaced with the hotel
name when the template is opened. The ids are: sale_offer, menu_special, welcome_guest, diwali,
navratri, janmashtami, notice, event, birthday, real_estate, doctor_timing, gym_class, temple_aarti
and coming_soon. Image placeholders are dashed rectangles. Use plain `Textbox`, `Rect`, `Circle`,
`Triangle`, `Line` and `Polygon` objects, because the tests check this.

## PDF import (`assets/js/pdf_import.js`)

1. The page loads pdf.js with a dynamic `import()` of the legacy build and opens the file with
   `isEvalSupported: false` (the hardening against CVE-2024-4367). It stops with a message if the
   PDF has more than 100 pages.
2. Each page is rendered at 1920 px width on a white page background. There are two shapes:
   *letterbox* (1920×1080, page centred, bars in a chosen colour, white by default) or *original*
   (1920 px wide, height as in the page, at most 4096 px). The result is JPEG 0.92, or 0.75 if it is
   still over 8 MB.
3. Pages upload one after the other, with up to 3 tries on network or 5xx errors. Each tile shows
   "uploaded", "already uploaded" or "error". "Retry failed pages" resends only the failures with the
   same batch id, which is safe because the server is idempotent.
4. Optionally the page then calls `designer_pdfplaylist` with the chosen duration and transition.

PowerPoint files (`.ppt`, `.pptx`, `.pps`, `.odp`, `.key`) are accepted by the file picker only to show
the hint: *PowerPoint → File → Save as PDF, then upload the PDF here.* They are never uploaded.

## Limits and known issues

* **Conversion happens only in the browser.** A very old browser without ES module support cannot
  import PDFs, and the page says so. Large PDFs use the admin's
  device memory and CPU, which is why there is a 100-page limit.
* pdf.js `cmaps` and `standard_fonts` are not vendored, to keep the package small (about 2.5 MB). PDFs
  that do not embed their fonts (rare for PowerPoint or Word exports) fall back to system fonts, and
  CJK text without embedded fonts may render incorrectly.
* An exported 1920×1080 PNG with photos can be 2–5 MB. Many shared hosts default to
  `upload_max_filesize = 2M`. The editor warns before uploading and the server answers 413. Raise
  `upload_max_filesize` and `post_max_size` to 10M for comfortable use. Designs made of text and shapes
  only are usually well under 1 MB.
* The PNG is a picture. Text in a designer slide is not re-rendered on the TV, so editing always goes
  through the designer, which keeps the JSON.
* Hotel templates store JSON only and get no server-side thumbnail. The browser renders the previews
  without pictures.

## Tests

`tests/Integration/Apps/DesignerTest.php` (9 tests) covers:

* starter templates: ≥ 12, the required ids, validation, and gu/hi names
* save creates an image item; re-save updates the same item and file, and the old file is deleted
* `content.php` redirects to the designer, and deleting an item cascades to its design
* the design JSON round-trips through the DB into the editor config, embedded safely
* validation: JPEG or a text file sent as the PNG → 422; over the server limit → 413; the 8 MB and
  dimension rules; bad, list-shaped and over-1 MB JSON → 422/413; missing title → 422; foreign or
  unknown id → 404; non-image item → 422; missing CSRF → 419; GET → 405
* PDF pages create items (JPEG, and a PNG with the original shape), are idempotent on retry, and build
  the playlist in page order (also idempotent); page 101, a bad batch id, a non-image file and an
  unknown batch are rejected; staff get 403
* hotel templates (replace by name, delete, cross-hotel delete → 404) and picture upload into the library
* tenancy: another hotel cannot open or overwrite a design or use another hotel's batch; the same batch
  id is separate per hotel; library lists are per hotel; files go under `h<id>/`
* pages render without PHP warnings for super_admin and manager, staff get 403, and the Gujarati UI works
* XSS in slide titles, template names and PDF file names (content list and grid, designer, playlists)
