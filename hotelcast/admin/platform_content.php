<?php
/**
 * Super Admin console → Content overview (2.6, docs/SPEC_SAAS.md §34): a snapshot of every customer's content —
 * items, playlists, storage, screens with own content, last change — with links to the Customer 360 content tab
 * and "Open customer workspace" for deep editing. Platform admins only.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/panel_ui.php';

$user = Auth::require('platform.manage');
Csrf::check();

$q = req_str('q', $_GET, 60);
$rows = DB::all(
    'SELECT h.id, h.name, h.city, h.status, h.archived_at,
            (SELECT COUNT(*) FROM content_items c WHERE c.hotel_id = h.id) AS items,
            (SELECT COUNT(*) FROM content_items c WHERE c.hotel_id = h.id AND c.is_active = 1) AS active_items,
            (SELECT COUNT(*) FROM content_playlists p WHERE p.hotel_id = h.id) AS playlists,
            (SELECT COUNT(*) FROM rooms r WHERE r.hotel_id = h.id) AS screens,
            (SELECT COUNT(*) FROM rooms r WHERE r.hotel_id = h.id AND (r.content_id IS NOT NULL OR r.playlist_id IS NOT NULL)) AS assigned,
            (SELECT COALESCE(SUM(c.file_size), 0) FROM content_items c WHERE c.hotel_id = h.id) AS bytes,
            (SELECT MAX(c.updated_at) FROM content_items c WHERE c.hotel_id = h.id) AS last_change
     FROM hotels h WHERE h.archived_at IS NULL' . ($q !== '' ? ' AND (h.name LIKE :q1 OR h.city LIKE :q2)' : '') . ' ORDER BY h.name',
    $q !== '' ? ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%'] : []
);
$totals = ['items' => array_sum(array_column($rows, 'items')), 'playlists' => array_sum(array_column($rows, 'playlists')), 'bytes' => array_sum(array_column($rows, 'bytes')), 'assigned' => array_sum(array_column($rows, 'assigned'))];
$types = DB::all('SELECT type, COUNT(*) AS n FROM content_items GROUP BY type ORDER BY n DESC LIMIT 10');
$pageTitle = __('Content overview');
$activeNav = 'platform_content';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div><h1><i class="bi bi-collection-play"></i> <?= e(__('Content overview')) ?></h1><p class="lead-sm"><?= e(__('What every customer has uploaded and assigned. Editing happens in the customer workspace.')) ?></p></div>
  <form method="get" class="d-flex gap-2"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search customer, city…')) ?>" aria-label="<?= e(__('Search')) ?>"><button class="btn btn-outline-primary"><i class="bi bi-search"></i></button></form>
</div>
<div class="kpi-grid mb-3">
  <?= panel_kpi(__('Content items'), $totals['items'], 'bi-images') ?>
  <?= panel_kpi(__('Playlists'), $totals['playlists'], 'bi-collection-play') ?>
  <?= panel_kpi(__('Screens with own content'), $totals['assigned'], 'bi-pin-angle') ?>
  <?= panel_kpi(__('Storage used'), human_bytes($totals['bytes']), 'bi-hdd') ?>
</div>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card">
      <?php if (!$rows): ?><?= panel_empty('bi-buildings', __('No customers found')) ?>
      <?php else: ?>
      <div class="table-responsive"><table class="table table-hc table-hover align-middle mb-0" data-content-overview>
        <thead><tr><th><?= e(__('Customer')) ?></th><th class="text-end"><?= e(__('Content items')) ?></th><th class="text-end"><?= e(__('Playlists')) ?></th><th class="text-end d-none d-md-table-cell"><?= e(__('Screens with own content')) ?></th><th class="text-end d-none d-md-table-cell"><?= e(__('Storage')) ?></th><th class="d-none d-lg-table-cell"><?= e(__('Last change')) ?></th><th class="text-end"><?= e(__('Actions')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $r['id'], 'tab' => 'content'])) ?>"><?= e($r['name']) ?></a><div class="small text-muted"><?= e((string) $r['city']) ?></div></td>
            <td class="text-end"><?= (int) $r['items'] ?><?= (int) $r['items'] !== (int) $r['active_items'] ? ' <span class="small text-muted">(' . (int) $r['active_items'] . ' ' . e(__('active')) . ')</span>' : '' ?></td>
            <td class="text-end"><?= (int) $r['playlists'] ?></td>
            <td class="text-end d-none d-md-table-cell"><?= (int) $r['assigned'] ?> / <?= (int) $r['screens'] ?></td>
            <td class="text-end d-none d-md-table-cell"><?= e(human_bytes((int) $r['bytes'])) ?></td>
            <td class="small d-none d-lg-table-cell"><?= e($r['last_change'] ? time_ago((string) $r['last_change']) : __('never')) ?></td>
            <td class="text-end text-nowrap"><a class="btn btn-sm btn-light border" href="<?= e(admin_url('platform_customer.php', ['id' => $r['id'], 'tab' => 'content'])) ?>"><i class="bi bi-card-list"></i></a> <?= panel_open_workspace((int) $r['id'], 'platform_customer.php', 'sm', 'content.php') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card"><div class="card-header"><?= e(__('Content by type')) ?></div><div class="card-body">
      <?= panel_bars(array_map(static fn ($t) => [(string) $t['type'], (int) $t['n']], $types), null, 'content-types') ?>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
