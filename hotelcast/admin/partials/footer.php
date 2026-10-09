<?php
declare(strict_types=1);
/** Admin layout footer (closes header.php). */
$user = $user ?? Auth::user();
?>
<?php if ($user): ?>
    </main>
    <footer class="hc-footer text-muted small">
      <?php $fb = Branding::get(); ?>
      &copy; <?= date('Y') ?> <?= Tenant::has() && (!class_exists('Panel') || !Panel::consolePage()) ? e((string) Settings::get('hotel_name', $fb['product'])) . ' · ' : '' ?><?= e($fb['product']) ?>
      <?php if ($fb['footer'] !== ''): ?> · <?= e($fb['footer']) ?><?php endif; ?>
      <?php if ($fb['support_phone'] !== '' || $fb['support_email'] !== ''): ?> · <?= e(__('Support')) ?>: <?= e(trim($fb['support_phone'] . ' ' . $fb['support_email'])) ?><?php endif; ?>
    </footer>
  </div>
</div>
<?php else: ?>
</main>
<?php endif; ?>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="hcToasts" style="z-index:1090"></div>

<div class="modal fade" id="hcConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" data-title><?= e(__('Please confirm')) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body" data-body></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button>
        <button type="button" class="btn btn-danger" data-ok><?= e(__('Yes, continue')) ?></button>
      </div>
    </div>
  </div>
</div>
<?php // Module hook: admin/partials/footer.d/*.php print scripts / UI before </body> (name order; $user may be null).
foreach (glob(__DIR__ . '/footer.d/*.php') ?: [] as $__fd) { include $__fd; } unset($__fd); ?>
</body>
</html>
