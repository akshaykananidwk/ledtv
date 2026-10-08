# Third-party components

HotelCast includes the following open-source components. All licenses allow commercial use; keep this
file (and the license texts linked below) with every copy you distribute.

## Server / admin panel (`hotelcast/assets/vendor`)

| Component | Version | License |
|-----------|---------|---------|
| Bootstrap | 5.3.3 | MIT — https://github.com/twbs/bootstrap/blob/main/LICENSE |
| Bootstrap Icons | 1.11.3 | MIT — https://github.com/twbs/icons/blob/main/LICENSE |
| SortableJS | 1.15.2 | MIT — https://github.com/SortableJS/Sortable/blob/master/LICENSE |
| FullCalendar | 6.1.15 | MIT — https://github.com/fullcalendar/fullcalendar/blob/main/LICENSE.md |
| hls.js | 1.5.15 | Apache-2.0 — https://github.com/video-dev/hls.js/blob/master/LICENSE |
| fabric.js (`vendor/fabric`, slide designer) | 6.9.1 | MIT — `assets/vendor/fabric/LICENSE` |
| PDF.js / pdfjs-dist legacy build (`vendor/pdfjs`, PDF import; `pdf.min.mjs` / `pdf.worker.min.mjs` renamed to `.js`) | 4.10.38 | Apache-2.0 — `assets/vendor/pdfjs/LICENSE` |
| Noto Sans, Noto Sans Gujarati, Noto Sans Devanagari (woff2 via @fontsource, `assets/fonts`, display apps + designer) | 400/700 | SIL Open Font License 1.1 — `assets/fonts/OFL.txt` |

## Android TV app (Gradle dependencies)

| Component | License |
|-----------|---------|
| ExoPlayer 2.x (Google) | Apache-2.0 |
| AndroidX libraries (AppCompat, Core, Lifecycle, WorkManager, ConstraintLayout, Leanback) | Apache-2.0 |
| Kotlin standard library & kotlinx.coroutines (JetBrains) | Apache-2.0 |
| Retrofit, OkHttp (Square) | Apache-2.0 |
| Gson (Google) | Apache-2.0 |
| Glide (Bumptech) | BSD-2-Clause / Apache-2.0 / MIT parts — https://github.com/bumptech/glide/blob/master/LICENSE |
| ZXing core (QR codes) | Apache-2.0 |

## External services (not bundled)

* Open-Meteo weather API — free for non-commercial use; **commercial use requires an Open-Meteo API
  subscription** (https://open-meteo.com/en/pricing) or switching the weather overlay off. The same applies
  to the Air quality app (#26), which uses the Open-Meteo Air Quality and Forecast APIs (data: CAMS /
  Copernicus, attribution "Open-Meteo" shown on the TV).
* Google Places API (Reviews app #30, optional) — needs the hotel's / platform's own Google Maps Platform
  API key and billing account; subject to the Google Maps Platform Terms (attribution "Reviews from
  Google" is shown; review content is cached only for the refresh interval).
* YouTube embeds — subject to YouTube Terms of Service (embed only; no downloading).
* Live darshan / temple streams — obtain permission from the stream owner (temple trust) before showing
  them commercially.
