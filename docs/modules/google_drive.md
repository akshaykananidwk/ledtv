# Google Drive slideshow (2.9.7)

Display app **Google Drive slideshow** (`gdrive_slideshow`, module "Content apps"): the customer pastes the link
of a Google Drive folder and every photo and video in it plays on the TV as a slideshow.

* **Folder sharing:** Google Drive → right-click the folder → Share → General access **"Anyone with the link"** →
  Copy link. Links `…/drive/folders/ID`, `…/drive/u/0/folders/ID`, `open?id=ID`, `embeddedfolderview?id=ID`
  and a bare folder id work; `resourcekey` of older links is passed on.
* **Listing** (`core/GoogleDrive.php`, cached 3 min, last good list kept 7 days while Google is unreachable):
  with a Google API key (Super Admin → Platform settings → Data feeds → *Google Drive slideshow*) the Drive API v3;
  without a key Google's public embedded folder page. Photos: jpg, jpeg, png, webp, gif, bmp, heic; videos: mp4,
  m4v, mov, webm, mkv, 3gp, avi. Sub-folders and documents are skipped. Sorted by file name.
* **On the TV** (`assets/display/apps/gdrive_slideshow.js`): photos cross-fade (whole photo or fill, optional slow
  zoom, file name), videos play full screen to the end or up to *Play videos for at most*, with or without sound;
  a file that cannot load is skipped. New files appear within about 4 minutes (data every 60 s + cache).
* **Media links:** photos from `lh3.googleusercontent.com` sized 1920×1080; videos via the Drive API with a key,
  else Google's direct download link (very large videos may need the API key).

Tests: `tests/Integration/Apps/GoogleDriveTest.php` (Google mocked). Strings: `lang/gu_google_drive.php`,
`lang/hi_google_drive.php`.
