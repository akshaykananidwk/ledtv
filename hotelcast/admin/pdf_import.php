<?php
declare(strict_types=1);
/**
 * PDF / PowerPoint → slides (#12). Shared hosting cannot convert PDFs, so the browser renders every page
 * with pdf.js (assets/vendor/pdfjs, legacy build) to a 1920 px wide image and uploads the pages one by one
 * to ajax.php?action=designer_pdfpage (idempotent per batch + page, core/Designer.php). Optionally a
 * playlist of all pages is created at the end (designer_pdfplaylist). PowerPoint files only get a hint:
 * "File → Save as PDF" first. Access: like the content library (content.manage).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('content.view');
require_can('content.manage');

$canPlaylist = Auth::can('playlists.manage');
$config = [
    'pdfjs' => asset('vendor/pdfjs/pdf.min.js'),
    'worker' => asset('vendor/pdfjs/pdf.worker.min.js'),
    'maxPages' => Designer::MAX_PDF_PAGES,
    'maxImage' => Designer::MAX_IMAGE_BYTES,
    'width' => Designer::WIDTH,
    'height' => Designer::HEIGHT,
    'contentUrl' => admin_url('content.php'),
    'i18n' => [
        'reading' => __('Reading the PDF…'),
        'page_of' => __('Page :n of :t', ['n' => '{n}', 't' => '{t}']),
        'too_many' => __('This PDF has :n pages. The limit is :m pages — split it into smaller files.', ['n' => '{n}', 'm' => Designer::MAX_PDF_PAGES]),
        'not_pdf' => __('Please choose a PDF file.'),
        'pptx' => __('PowerPoint files cannot be converted in the browser. In PowerPoint use File → Save as (or Export) → PDF, then upload that PDF here.'),
        'bad_pdf' => __('This PDF could not be opened. It may be damaged or password protected.'),
        'done' => __('Done: :n slides added to your content library.', ['n' => '{n}']),
        'failed' => __(':n pages could not be uploaded.', ['n' => '{n}']),
        'playlist' => __('Creating the playlist…'),
        'playlist_done' => __('Playlist created.'),
        'open_playlist' => __('Open playlist'),
        'open_library' => __('Open content library'),
        'uploaded' => __('uploaded'),
        'already' => __('already uploaded'),
        'error' => __('error'),
        'leave' => __('The import is still running.'),
        'no_browser' => __('Your browser is too old for PDF import. Please use a current Chrome, Edge or Firefox.'),
    ],
];
$pageTitle = __('Import PDF / slides');
$activeNav = 'content';
$extraScripts = ['js/pdf_import.js'];
$extraStyles = ['css/designer.css'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-file-earmark-pdf"></i> <?= e($pageTitle) ?></h1>
    <p class="lead-sm"><?= e(__('Every page of a PDF becomes an image slide in your content library — conversion happens in your browser, nothing to install.')) ?></p>
  </div>
  <a href="<?= e(admin_url('content.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <form class="card" id="pdfForm">
      <div class="card-body d-flex flex-column gap-3">
        <div>
          <label class="form-label fw-semibold" for="pdfFile"><?= e(__('PDF file')) ?> *</label>
          <input class="form-control" type="file" id="pdfFile" accept="application/pdf,.pdf,.ppt,.pptx,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation" required>
          <div class="form-text"><?= e(__('Up to :n pages.', ['n' => Designer::MAX_PDF_PAGES])) ?></div>
        </div>
        <div class="alert alert-info small mb-0" id="pptHint">
          <i class="bi bi-filetype-pptx"></i> <strong><?= e(__('PowerPoint?')) ?></strong>
          <?= e(__('PowerPoint → File → Save as PDF, then upload the PDF here. (.ppt / .pptx files cannot be converted reliably in the browser.)')) ?>
        </div>
        <fieldset>
          <legend class="form-label fw-semibold fs-6"><?= e(__('Page shape')) ?></legend>
          <div class="form-check"><input class="form-check-input" type="radio" name="fit" id="fitBox" value="letterbox" checked><label class="form-check-label" for="fitBox"><?= e(__('Fit into a 16:9 TV screen (bars around the page)')) ?></label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="fit" id="fitOrig" value="original"><label class="form-check-label" for="fitOrig"><?= e(__('Keep the original page shape (1920 px wide)')) ?></label></div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <input type="color" class="form-control form-control-color" id="pdfBg" value="#ffffff" aria-label="<?= e(__('Background colour')) ?>">
            <label class="small" for="pdfBg"><?= e(__('Background colour')) ?></label>
          </div>
        </fieldset>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label fw-semibold" for="pdfDuration"><?= e(__('Seconds per slide')) ?></label>
            <input class="form-control" type="number" id="pdfDuration" min="1" max="86400" value="10">
          </div>
        </div>
        <?php if ($canPlaylist): ?>
        <div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pdfPlaylist" checked><label class="form-check-label" for="pdfPlaylist"><?= e(__('Also create a playlist with all pages')) ?></label></div>
          <div class="row g-2 mt-1" id="pdfPlaylistOpts">
            <div class="col-8"><input class="form-control" id="pdfPlaylistName" maxlength="190" placeholder="<?= e(__('Playlist name (default: file name)')) ?>" aria-label="<?= e(__('Playlist name')) ?>"></div>
            <div class="col-4">
              <select class="form-select" id="pdfTransition" aria-label="<?= e(__('Transition')) ?>">
                <option value="fade"><?= e(__('Fade')) ?></option>
                <option value="slide"><?= e(__('Slide')) ?></option>
                <option value="none"><?= e(__('None')) ?></option>
              </select>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-primary btn-lg" type="submit" id="pdfStart"><i class="bi bi-cloud-arrow-up"></i> <?= e(__('Convert & upload')) ?></button>
          <button class="btn btn-outline-warning btn-lg" type="button" id="pdfRetry" hidden><i class="bi bi-arrow-repeat"></i> <?= e(__('Retry failed pages')) ?></button>
        </div>
      </div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <div id="pdfStatus" class="mb-2 text-muted"><?= e(__('Choose a PDF to start.')) ?></div>
      <div class="progress mb-3" style="height:22px" id="pdfBarWrap" hidden><div class="progress-bar progress-bar-striped progress-bar-animated" id="pdfBar" style="width:0%">0%</div></div>
      <div id="pdfResult" class="mb-3"></div>
      <div class="pdf-pages" id="pdfPages"></div>
    </div></div>
  </div>
</div>
<script type="application/json" id="pdfConfig"><?= json_embed($config) ?></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
