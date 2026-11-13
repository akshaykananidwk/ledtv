# CCTV cameras on the TVs (2.10)

Customer page **CCTV cameras** (`admin/cctv.php`, permission `content.manage`, module "Content library").

* **Add recorder:** name, brand (Dahua, CP Plus, Hikvision, Prama, Uniview, Other with own RTSP path using
  `{ch}`), IP address or DDNS name, RTSP port (554), username, password, camera numbers (`1,2,3,4`, `1-8`),
  picture quality (automatic: sub stream in grids, main stream alone; small; full HD), "All cameras" grid.
* **Result:** one `stream` content item per camera (`rtsp://user:pass@host/…` per brand, login URL-encoded) and,
  when the layouts module is in the plan, "All cameras" split-screen layouts of up to 6 cameras each
  (1×2, 2×2, 2×3; zones fill, no sound) with the decoder hint `max_decoders` = number of cameras. Items carry
  `settings.cctv = {group, nvr, brand, channel, sub}`; the page lists recorders (password never shown) and
  deletes all items of a recorder.
* **TV app 2.6.3** honours `layout.max_decoders` (`DecoderPlanner.effectiveLimit`): up to the hint, capped by
  what the TV proved with hinted layouts (`Prefs.hintDecoderCeiling`, lowered after a decoder failure). Older apps
  keep their device limit (2): a 2×2 grid shows 2 cameras live and 2 placeholders.
* **Reaching the recorder:** same local network (local IP), or RTSP port forwarded on the recorder's router
  (public IP / DDNS). Serial-number-only login (Dahua / CP Plus P2P, Hik-Connect) is a closed vendor cloud
  without a public interface and is not supported.

Code: `core/Cctv.php`, `core/Layouts.php` (`max_decoders`), Android `LayoutSpec.kt`, `LayoutPlayer.kt`, `Prefs.kt`.
Tests: `tests/Integration/Apps/CctvTest.php`, Android `CctvGridTest.kt`. Strings: `lang/gu_cctv.php`, `lang/hi_cctv.php`.
