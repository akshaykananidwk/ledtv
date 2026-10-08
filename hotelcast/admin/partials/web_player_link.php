<?php
/**
 * "Web player link" button + modal (2.4, #45): the address of the web player (player/) as text and as
 * a QR code, with short setup notes per device. Used on Rooms & TVs and on Add TV (QR).
 * Optional: $wpBtnClass (button classes). Needs the admin layout (Bootstrap 5 modal, [data-copy]).
 * See docs/modules/web_player.md.
 */
declare(strict_types=1);

$wpUrl = WebPlayer::url();
try {
    $wpQr = QrCode::svg($wpUrl);
} catch (Throwable) {
    $wpQr = '';
}
?>
<button type="button" class="btn <?= e($wpBtnClass ?? 'btn-light border') ?>" data-bs-toggle="modal" data-bs-target="#hcWebPlayerModal" data-web-player-link>
  <i class="bi bi-browser-chrome"></i> <?= e(__('Web player link')) ?>
</button>
<div class="modal fade" id="hcWebPlayerModal" tabindex="-1" aria-hidden="true" aria-labelledby="hcWebPlayerTitle">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="hcWebPlayerTitle"><i class="bi bi-browser-chrome"></i> <?= e(__('Web player link')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body">
        <div class="row g-4 align-items-center">
          <?php if ($wpQr !== ''): ?>
          <div class="col-md-5 text-center">
            <div class="border rounded-3 p-2 bg-white d-inline-block" style="width:100%;max-width:240px"><?= $wpQr ?></div>
            <div class="small text-muted mt-1"><?= e(__('Scan to open the web player on a phone or tablet.')) ?></div>
          </div>
          <?php endif; ?>
          <div class="col-md-<?= $wpQr !== '' ? '7' : '12' ?>">
            <p><?= e(__('Open this address in the web browser of a smart TV, a Fire TV stick, a mini PC or a Raspberry Pi. The screen then works like a TV with the app.')) ?></p>
            <div class="input-group mb-3">
              <input type="text" class="form-control font-monospace" value="<?= e($wpUrl) ?>" readonly aria-label="<?= e(__('Web player link')) ?>" data-web-player-url>
              <button type="button" class="btn btn-outline-secondary" data-copy="<?= e($wpUrl) ?>" title="<?= e(__('Copy')) ?>"><i class="bi bi-clipboard"></i></button>
              <a class="btn btn-outline-primary" href="<?= e($wpUrl) ?>" target="_blank" rel="noopener" title="<?= e(__('Open')) ?>"><i class="bi bi-box-arrow-up-right"></i></a>
            </div>
            <ul class="small mb-2 ps-3">
              <li><?= e(__('Samsung / LG smart TV: open the Internet / Web Browser app, type the address and add it to the bookmarks or home screen.')) ?></li>
              <li><?= e(__('Fire TV: open it in the Silk browser, or install the Android app with the Downloader app.')) ?></li>
              <li><?= e(__('Raspberry Pi: run tools/raspberry-pi/setup.sh with this address (full screen, starts by itself).')) ?></li>
              <li><?= e(__('Windows mini PC: start Chrome with --kiosk and this address.')) ?></li>
            </ul>
            <p class="small text-muted mb-0"><?= e(__('The screen shows a QR code: scan it with your phone and choose the room — or type the room number and the registration key on the screen.')) ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
