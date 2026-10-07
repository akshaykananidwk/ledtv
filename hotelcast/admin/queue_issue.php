<?php
declare(strict_types=1);
/**
 * Token issue / kiosk page (permission queue.operate, reception+): tap a service → the next token
 * number is issued (core/Queue.php::issue, numbers restart daily), shown big and printed on a small
 * ticket (window.print, CSS for 58 / 80 mm thermal printers; optional automatic printing).
 * AJAX (JSON) with a normal form post fallback (?t=<token id> shows the ticket).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('queue.operate');
Csrf::check();
$ajax = Auth::isAjax();

/** Ticket data for a token. */
$ticket = static function (array $t): array {
    $s = Queue::findService((int) $t['origin_service_id']);
    return [
        'id' => (int) $t['id'],
        'label' => Queue::label($t),
        'service' => (string) ($s['name'] ?? ''),
        'ahead' => Queue::ahead($t),
        'name' => (string) ($t['customer_name'] ?? ''),
        'time' => date('d-m-Y h:i A', (int) strtotime((string) $t['created_at'])),
        'hotel' => (string) Settings::get('hotel_name', ''),
    ];
};

if (is_post()) {
    if (req_str('op', $_POST, 20) !== 'issue') {
        $ajax ? ajax_error(__('Unknown action.'), 422) : redirect(admin_url('queue_issue.php'));
    }
    $svc = Queue::findService(req_int('service_id', $_POST)); // another hotel's id → 404
    if (!$svc || !(int) $svc['is_active']) {
        $ajax ? ajax_error(__('This service is closed.'), 422, 'SERVICE_CLOSED') : redirect(admin_url('queue_issue.php'));
    }
    $t = Queue::issue((int) $svc['id'], ['name' => req_str('name', $_POST, 80), 'phone' => req_str('phone', $_POST, 20), 'source' => 'desk', 'user' => Auth::id()]);
    if ($ajax) {
        ajax_ok($ticket($t));
    }
    redirect(admin_url('queue_issue.php', ['t' => $t['id']]));
}

$shown = req_int('t', $_GET) ? Queue::findToken(req_int('t', $_GET)) : null; // another hotel's id → 404
$shownTicket = $shown ? $ticket($shown) : null;
$services = Queue::services(true);
$waiting = [];
foreach (DB::all("SELECT service_id, COUNT(*) n FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND status = 'waiting' GROUP BY service_id", ['h' => Tenant::id(), 'd' => Queue::today()]) as $r) {
    $waiting[(int) $r['service_id']] = (int) $r['n'];
}
$activeNav = 'queue_issue';
$pageTitle = __('Issue tokens');
$extraScripts = ['js/queue-issue.js'];
require __DIR__ . '/partials/header.php';
?>
<style>
.qi-svc{min-height:6rem;font-size:1.4rem;font-weight:700}
.qi-big{font-size:clamp(4rem,20vw,8rem);font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
#qTicket{display:none;font-family:Arial,Helvetica,sans-serif;color:#000;background:#fff;text-align:center}
#qTicket .t-hotel{font-size:13pt;font-weight:700}
#qTicket .t-svc{font-size:11pt;margin-top:2mm}
#qTicket .t-num{font-size:40pt;font-weight:800;line-height:1.1;margin:3mm 0}
#qTicket .t-meta{font-size:9pt}
@media print {
  @page{margin:0}
  body *{visibility:hidden}
  #qTicket,#qTicket *{visibility:visible}
  #qTicket{display:block;position:absolute;left:0;top:0;width:72mm;padding:4mm 3mm}
  .paper-58 #qTicket{width:48mm;padding:3mm 2mm}
  .paper-58 #qTicket .t-num{font-size:30pt}
}
</style>
<div class="page-head">
  <div><h1><i class="bi bi-ticket-perforated"></i> <?= e(__('Issue tokens')) ?></h1><p class="lead-sm"><?= e(__('Tap a service to give the visitor the next token number.')) ?></p></div>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <select class="form-select w-auto" id="qiPaper" aria-label="<?= e(__('Ticket paper')) ?>"><option value="80">80 mm</option><option value="58">58 mm</option></select>
    <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="qiAuto"><label class="form-check-label" for="qiAuto"><?= e(__('Print automatically')) ?></label></div>
    <a class="btn btn-light border" href="<?= e(admin_url('queue.php')) ?>"><i class="bi bi-people"></i> <?= e(__('Token queue')) ?></a>
  </div>
</div>
<?php if (!$services): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-ticket-perforated"></i><p class="text-muted"><?= e(__('No active services yet.')) ?></p></div></div>
<?php else: ?>
<div class="row g-3" id="qiApp" data-url="<?= e(admin_url('queue_issue.php')) ?>">
  <div class="col-lg-7">
    <details class="mb-3"><summary class="text-muted"><?= e(__('Visitor name / mobile (optional)')) ?></summary>
      <div class="row g-2 mt-1"><div class="col-sm-6"><input class="form-control form-control-lg" id="qiName" maxlength="80" placeholder="<?= e(__('Name')) ?>" autocomplete="off"></div>
        <div class="col-sm-6"><input class="form-control form-control-lg" id="qiPhone" maxlength="20" inputmode="tel" placeholder="<?= e(__('Mobile')) ?>" autocomplete="off"></div></div>
    </details>
    <div class="row g-2">
      <?php foreach ($services as $s): ?>
        <div class="col-sm-6"><form method="post" data-qi-issue><?= Csrf::field() ?><input type="hidden" name="op" value="issue"><input type="hidden" name="service_id" value="<?= (int) $s['id'] ?>">
          <input type="hidden" name="name" value=""><input type="hidden" name="phone" value="">
          <button class="btn btn-primary w-100 qi-svc"><?= e($s['name']) ?><br><small class="fw-normal"><?= e(__('Waiting')) ?>: <span data-qi-wait="<?= (int) $s['id'] ?>"><?= (int) ($waiting[(int) $s['id']] ?? 0) ?></span></small></button></form></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card text-center"><div class="card-body">
      <div class="text-muted small text-uppercase"><?= e(__('Your token')) ?></div>
      <div class="qi-big my-2" id="qiLabel"><?= e($shownTicket['label'] ?? '—') ?></div>
      <div class="fw-semibold" id="qiService"><?= e($shownTicket['service'] ?? '') ?></div>
      <div class="text-muted" id="qiAhead"><?= $shownTicket ? e(__(':n people before you', ['n' => $shownTicket['ahead']])) : '' ?></div>
      <button class="btn btn-outline-dark btn-lg mt-3" id="qiPrint" type="button"<?= $shownTicket ? '' : ' disabled' ?>><i class="bi bi-printer"></i> <?= e(__('Print ticket')) ?></button>
    </div></div>
  </div>
</div>
<?php endif; ?>
<div id="qTicket" data-ahead-text="<?= e(__(':n people before you')) ?>">
  <div class="t-hotel" id="tHotel"><?= e($shownTicket['hotel'] ?? (string) Settings::get('hotel_name', '')) ?></div>
  <div class="t-svc" id="tSvc"><?= e($shownTicket['service'] ?? '') ?></div>
  <div class="t-num" id="tNum"><?= e($shownTicket['label'] ?? '') ?></div>
  <div class="t-meta" id="tAhead"><?= $shownTicket ? e(__(':n people before you', ['n' => $shownTicket['ahead']])) : '' ?></div>
  <div class="t-meta" id="tTime"><?= e($shownTicket['time'] ?? '') ?></div>
  <div class="t-meta"><?= e(__('Please wait for your number on the screen.')) ?></div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
