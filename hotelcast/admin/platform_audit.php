<?php
/**
 * Super Admin console → Audit logs (2.6, docs/SPEC_SAAS.md §27): every activity_logs row across all customers
 * and the platform, with filters (customer, user, action, date range, text) and pagination. Platform admins
 * only; customers keep their own Logs page (admin/logs.php) limited to their data.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.manage');
Csrf::check();

$f = [
    'customer' => req_str('customer', $_GET, 10),   // id, or 'platform'
    'user' => req_str('user', $_GET, 60),
    'action' => req_str('action', $_GET, 60),
    'q' => req_str('q', $_GET, 100),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '',
];
$page = max(1, req_int('page', $_GET));
$per = 50;
$w = [];
$p = [];
if ($f['customer'] === 'platform') {
    $w[] = 'a.hotel_id IS NULL';
} elseif (ctype_digit($f['customer']) && (int) $f['customer'] > 0) {
    $w[] = 'a.hotel_id = :c';
    $p['c'] = (int) $f['customer'];
}
if ($f['user'] !== '') {
    $w[] = 'a.username LIKE :u';
    $p['u'] = '%' . addcslashes($f['user'], '%_\\') . '%';
}
if ($f['action'] !== '') {
    $w[] = 'a.action LIKE :a';
    $p['a'] = addcslashes($f['action'], '%_\\') . '%';
}
if ($f['q'] !== '') {
    $w[] = '(a.details LIKE :q1 OR a.entity_type LIKE :q2)';
    $p['q1'] = $p['q2'] = '%' . addcslashes($f['q'], '%_\\') . '%';
}
if ($f['from'] !== '') {
    $w[] = 'a.created_at >= :f';
    $p['f'] = $f['from'] . ' 00:00:00';
}
if ($f['to'] !== '') {
    $w[] = 'a.created_at <= :t';
    $p['t'] = $f['to'] . ' 23:59:59';
}
$where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
$total = (int) DB::value('SELECT COUNT(*) FROM activity_logs a' . $where, $p);
$rows = DB::all('SELECT a.*, h.name AS hotel_name FROM activity_logs a LEFT JOIN hotels h ON h.id = a.hotel_id' . $where
    . ' ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
$customers = DB::all('SELECT id, name FROM hotels ORDER BY name');
$actions = DB::column('SELECT DISTINCT action FROM activity_logs ORDER BY action LIMIT 300');

if (req_str('export', $_GET, 5) === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['time', 'customer', 'user', 'action', 'entity', 'entity_id', 'details', 'ip']);
    foreach (DB::all('SELECT a.*, h.name AS hotel_name FROM activity_logs a LEFT JOIN hotels h ON h.id = a.hotel_id' . $where . ' ORDER BY a.id DESC LIMIT 5000', $p) as $r) {
        fputcsv($out, [$r['created_at'], $r['hotel_name'] ?? ($r['hotel_id'] ? '#' . $r['hotel_id'] : 'platform'), $r['username'], $r['action'], $r['entity_type'], $r['entity_id'], $r['details'], $r['ip_address']]);
    }
    exit;
}

$pageTitle = __('Audit logs');
$activeNav = 'platform_audit';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-journal-check"></i> <?= e(__('Audit logs')) ?></h1><p class="lead-sm"><?= e(__('Who did what, where and when — across every customer and the platform itself.')) ?></p></div>
  <a class="btn btn-light border" href="<?= e(self_url(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> <?= e(__('Export CSV')) ?></a>
</div>
<form method="get" class="card card-body mb-3" data-audit-filters>
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small mb-0" for="af_c"><?= e(__('Customer')) ?></label>
      <select class="form-select form-select-sm" id="af_c" name="customer"><option value=""><?= e(__('All')) ?></option><option value="platform"<?= $f['customer'] === 'platform' ? ' selected' : '' ?>><?= e(__('Platform only')) ?></option>
        <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $f['customer'] === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="af_u"><?= e(__('User')) ?></label><input class="form-control form-control-sm" id="af_u" name="user" value="<?= e($f['user']) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="af_a"><?= e(__('Action')) ?></label>
      <input class="form-control form-control-sm" id="af_a" name="action" value="<?= e($f['action']) ?>" list="af_actions"><datalist id="af_actions"><?php foreach ($actions as $a): ?><option value="<?= e((string) $a) ?>"><?php endforeach; ?></datalist></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="af_f"><?= e(__('From')) ?></label><input class="form-control form-control-sm" type="date" id="af_f" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="af_t"><?= e(__('To')) ?></label><input class="form-control form-control-sm" type="date" id="af_t" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="col-md-1 d-grid"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button></div>
    <div class="col-12"><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="<?= e(__('Search in details…')) ?>" aria-label="<?= e(__('Search')) ?>"></div>
  </div>
</form>
<div class="card">
  <div class="card-header d-flex align-items-center"><span><?= e(__(':n entries', ['n' => $total])) ?></span></div>
  <?php if (!$rows): ?><?= panel_empty('bi-journal-check', __('No entries match your filter.')) ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hc mb-0" data-audit-table>
    <thead><tr><th><?= e(__('Time')) ?></th><th><?= e(__('Customer')) ?></th><th><?= e(__('User')) ?></th><th><?= e(__('Action')) ?></th><th><?= e(__('Details')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('IP')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="small text-nowrap"><?= e(substr((string) $r['created_at'], 0, 16)) ?></td>
        <td class="small"><?php if ($r['hotel_id']): ?><a class="text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $r['hotel_id'], 'tab' => 'activity'])) ?>"><?= e($r['hotel_name'] ?? ('#' . $r['hotel_id'])) ?></a><?php else: ?><span class="badge text-bg-light border"><?= e(__('Platform')) ?></span><?php endif; ?></td>
        <td class="small"><?= e($r['username'] ?? '-') ?></td>
        <td class="small"><a class="mono text-decoration-none" href="<?= e(self_url(['action' => $r['action'], 'page' => null])) ?>"><?= e($r['action']) ?></a></td>
        <td class="small text-break"><?= e((string) ($r['details'] ?? '')) ?><?php if ($r['entity_type']): ?> <span class="text-muted">(<?= e(match ((string) $r['entity_type']) { 'hotel' => __('customer'), 'room' => __('screen'), 'device' => __('TV'), default => (string) $r['entity_type'] }) ?> #<?= (int) $r['entity_id'] ?>)</span><?php endif; ?></td>
        <td class="small d-none d-lg-table-cell"><?= e((string) ($r['ip_address'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php if ($total > $per): ?><div class="card-footer"><?= paginate($total, $page, $per) ?></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
