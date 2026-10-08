<?php
declare(strict_types=1);

/**
 * Shared UI + POST handler of Platform → All screens (admin/platform_screens.php) and the Screens tab
 * of the customer detail page (admin/platform_customer.php). docs/modules/platform_screens.md.
 * Permission checks: the pages require platform.screens; pool actions here require platform.pool.
 * Every device id is re-checked against the user's customers by core/PlatformScreens.php.
 */

require_once __DIR__ . '/common.php';

ActivityLog::$platformScope = true; // logs without an explicit customer are platform-level

/** Handle a POST of the screens UI, flash the result and redirect back to $back. */
function ps_handle_post(string $back): never
{
    $op = req_str('op', $_POST, 30);
    try {
        switch ($op) {
            case 'row':
                // One button of a row: "cmd:REBOOT:12", "open:device:12", "update:-:12", "revoke:-:12", "unassign:-:12".
                $parts = explode(':', req_str('row', $_POST, 60));
                [$kind, $arg, $id] = [$parts[0] ?? '', $parts[1] ?? '', (int) ($parts[2] ?? 0)];
                if ($kind === 'open') {
                    $d = PlatformScreens::devices([$id])[0];
                    if (!Auth::enterHotel((int) $d['hotel_id'])) {
                        throw new InvalidArgumentException(__('You cannot open this customer.'));
                    }
                    redirect(match ($arg) {
                        'live' => admin_url('live_view.php', ['device' => $id]),
                        'health' => admin_url('tv_health.php'),
                        default => admin_url('rooms.php', ['action' => 'device', 'id' => $id]),
                    });
                }
                ps_apply($kind === 'cmd' ? 'cmd:' . $arg : $kind, [$id]);
                break;

            case 'bulk':
                ps_apply(req_str('bulk_action', $_POST, 30), int_ids($_POST['ids'] ?? []));
                break;

            case 'pool_toggle':
                require_can('platform.pool');
                DevicePool::setEnabled(!empty($_POST['enabled']));
                ActivityLog::add('pool_settings', 'settings', null, 'Unassigned pool ' . (!empty($_POST['enabled']) ? 'enabled' : 'disabled'), null);
                flash('success', __('Saved.'));
                break;

            case 'pool_regen':
                require_can('platform.pool');
                DevicePool::regenerateKey();
                ActivityLog::add('pool_settings', 'settings', null, 'Platform registration key regenerated', null);
                flash('success', __('New platform registration key created. TVs already in the pool keep working.'));
                break;

            case 'pool_assign':
                require_can('platform.pool');
                $n = DevicePool::assign(int_ids($_POST['pool_ids'] ?? []), req_int('target_customer', $_POST), ps_move_options());
                flash('success', __(':n TV(s) assigned. They show the customer\'s content within a few seconds.', ['n' => $n]));
                break;

            case 'pool_delete':
                require_can('platform.pool');
                $n = DevicePool::delete(int_ids($_POST['pool_ids'] ?? []));
                flash('success', __(':n TV(s) removed from the pool.', ['n' => $n]));
                break;

            case 'pool_notes':
                require_can('platform.pool');
                DevicePool::setNotes(req_int('pool_id', $_POST), req_str('notes', $_POST, 255));
                flash('success', __('Saved.'));
                break;

            default:
                flash('warning', __('Unknown action.'));
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('danger', $e->getMessage());
    }
    redirect($back);
}

/** Move options from POST (target screen mode, room, new screen name). */
function ps_move_options(): array
{
    $mode = req_str('screen_mode', $_POST, 10);
    return [
        'mode' => in_array($mode, ['existing', 'new', 'same'], true) ? $mode : 'existing',
        'room_id' => req_int('target_room', $_POST),
        'name' => req_str('new_name', $_POST, 100),
    ];
}

/** Run one action on device ids and flash the result. */
function ps_apply(string $action, array $ids): void
{
    if (!$ids) {
        throw new InvalidArgumentException(__('Select at least one screen.'));
    }
    if (str_starts_with($action, 'cmd:')) {
        $r = PlatformScreens::bulkCommand($ids, substr($action, 4));
        flash('success', __('Command sent to :t TV(s) of :c customer(s).', ['t' => $r['tvs'], 'c' => $r['customers']]));
        return;
    }
    switch ($action) {
        case 'update':
            $r = PlatformScreens::pushUpdate($ids);
            flash('success', __('App update sent to :t TV(s) of :c customer(s).', ['t' => $r['tvs'], 'c' => $r['customers']]));
            if ($r['skipped']) {
                flash('warning', __('No APK uploaded for: :list (APK Manager of the customer).', ['list' => implode(', ', $r['skipped'])]));
            }
            return;
        case 'revoke':
            $n = PlatformScreens::revoke($ids);
            flash('success', __(':n TV(s) revoked. They return to their setup screen.', ['n' => $n]));
            return;
        case 'move':
            $n = PlatformScreens::move($ids, req_int('target_customer', $_POST), ps_move_options());
            flash('success', __(':n TV(s) moved. They show the new customer\'s content on their next poll.', ['n' => $n]));
            return;
        case 'unassign':
            require_can('platform.pool');
            $n = DevicePool::unassign($ids);
            flash('success', __(':n TV(s) moved to the unassigned pool.', ['n' => $n]));
            return;
    }
    throw new InvalidArgumentException(__('Unknown action.'));
}

/** Header counters. */
function ps_counters(array $c, string $base = 'platform_screens.php', array $baseQuery = []): string
{
    $tiles = [
        ['bi-tv', 'bg-soft-primary', __('Screens'), $c['total'], []],
        ['bi-wifi', 'bg-soft-success', __('Online'), $c['online'], ['status' => 'online']],
        ['bi-wifi-off', 'bg-soft-danger', __('Offline'), $c['offline'], ['status' => 'offline']],
        ['bi-android2', 'bg-soft-warning', __('Outdated app'), $c['outdated'], ['update' => 1]],
        ['bi-heart-pulse', 'bg-soft-info', __('Health warnings'), $c['warnings'], ['warn' => 1]],
    ];
    $h = '<div class="row g-3 mb-3">';
    foreach ($tiles as [$icon, $cls, $label, $val, $q]) {
        $h .= '<div class="col-6 col-md-4 col-xl"><a class="card h-100 text-decoration-none text-reset" href="' . e(admin_url($base, $baseQuery + $q)) . '">'
            . '<div class="stat-card"><div class="stat-icon ' . e($cls) . '"><i class="bi ' . e($icon) . '"></i></div><div><div class="stat-value" data-stat>' . (int) $val
            . '</div><div class="stat-label">' . e($label) . '</div></div></div></a></div>';
    }
    return $h . '</div>';
}

/** "used / max" (or just "used" without a limit). */
function ps_usage(int $used, ?int $max): string
{
    return $max === null ? (string) $used : $used . ' / ' . $max;
}

/** The platform (android / web) label of a row. */
function ps_platform_label(array $d): string
{
    return ($d['platform'] ?? 'android') === 'web' ? __('Web player') : __('Android TV app');
}

/**
 * Screens table inside the bulk form. $rows from PlatformScreens::decorate(). $showCustomer = false on
 * the customer page. Row buttons submit the hidden form #psRowForm (no nested forms).
 */
function ps_table(array $rows, bool $showCustomer = true): string
{
    $canPool = Auth::can('platform.pool');
    ob_start();
    ?>
    <div class="table-responsive">
      <table class="table table-hc table-hover align-middle mb-0" id="psTable">
        <thead><tr>
          <th style="width:2rem"><input type="checkbox" class="form-check-input" data-check-all=".ps-cb" aria-label="<?= e(__('Select all')) ?>"></th>
          <?php if ($showCustomer): ?><th><?= e(__('Customer')) ?></th><?php endif; ?>
          <th><?= e(__('Screen')) ?></th>
          <th class="d-none d-lg-table-cell"><?= e(__('Location / group')) ?></th>
          <th class="d-none d-md-table-cell"><?= e(__('Device')) ?></th>
          <th><?= e(__('Status')) ?></th>
          <th class="d-none d-md-table-cell"><?= e(__('Now showing')) ?></th>
          <th class="d-none d-xl-table-cell"><?= e(__('Health')) ?></th>
          <th class="d-none d-xl-table-cell"><?= e(__('IP')) ?></th>
          <th class="d-none d-xxl-table-cell"><?= e(__('Registered')) ?></th>
          <th class="text-end"><?= e(__('Actions')) ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4"><?= e(__('No screens match your filter.')) ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $d): $id = (int) $d['id']; $revoked = (int) $d['is_revoked'] === 1; ?>
          <tr data-device="<?= $id ?>">
            <td><input type="checkbox" class="form-check-input ps-cb" name="ids[]" value="<?= $id ?>" form="psBulkForm" aria-label="<?= e($d['device_uid']) ?>"></td>
            <?php if ($showCustomer): ?>
              <td><a class="fw-semibold text-decoration-none" href="<?= e(admin_url('platform_customer.php', ['id' => $d['hotel_id']])) ?>"><?= e($d['hotel_name']) ?></a>
                <?php if ($d['hotel_status'] !== 'active'): ?><div><?= Hotels::statusBadge((string) $d['hotel_status']) ?></div><?php endif; ?></td>
            <?php endif; ?>
            <td><?php if ($d['room_id']): ?><strong><?= e($d['room_number']) ?></strong><?php if ($d['room_name']): ?><div class="small text-muted"><?= e($d['room_name']) ?></div><?php endif; ?>
                <?php else: ?><span class="badge text-bg-warning"><?= e(__('No screen')) ?></span><?php endif; ?>
              <div class="small text-muted mono text-truncate" style="max-width:12rem" title="<?= e($d['device_uid']) ?>"><?= e($d['device_uid']) ?></div></td>
            <td class="d-none d-lg-table-cell small"><?= e(dot_trim(($d['floor'] !== null && $d['floor'] !== '' ? __('Floor') . ' ' . $d['floor'] : '') . ' · ' . ($d['group_names'] ?? '')) ?: '-') ?></td>
            <td class="d-none d-md-table-cell small">
              <?= e($d['model'] ?? '-') ?>
              <div class="text-muted"><?php if (($d['platform'] ?? 'android') === 'web'): ?><i class="bi bi-browser-chrome"></i><?php else: ?><i class="bi bi-android2"></i><?php endif; ?>
                <?= e(ps_platform_label($d)) ?> · v<?= e($d['app_version'] ?? '?') ?><?php if ($d['outdated']): ?> <span class="badge text-bg-warning"><?= e(__('Needs update')) ?></span><?php endif; ?></div>
            </td>
            <td><?= $revoked ? '<span class="badge text-bg-dark">' . e(__('Revoked')) . '</span>' : status_badge($d['online'] ? 'online' : 'offline') ?>
              <div class="small text-muted" title="<?= e((string) $d['last_ping']) ?>"><?= e($d['last_seen']) ?></div></td>
            <td class="d-none d-md-table-cell small"><?= e($d['showing']) ?><?php if (!in_array($d['mode'], ['', 'revoked', 'unassigned'], true)): ?><div class="text-muted"><?= e(mode_label((string) $d['mode'])) ?></div><?php endif; ?></td>
            <td class="d-none d-xl-table-cell small">
              <?php if (!$d['warnings']): ?><span class="text-muted">–</span><?php endif; ?>
              <?php foreach ($d['warnings'] as $k => $txt): ?><span class="badge text-bg-warning me-1" title="<?= e($txt) ?>"><?= e(DeviceHealth::warningLabel((string) $k)) ?></span><?php endforeach; ?>
            </td>
            <td class="d-none d-xl-table-cell small mono"><?= e($d['ip_address'] ?? '-') ?><?php if (!empty($d['public_ip']) && $d['public_ip'] !== $d['ip_address']): ?><div class="text-muted"><?= e($d['public_ip']) ?></div><?php endif; ?></td>
            <td class="d-none d-xxl-table-cell small text-muted"><?= e(substr((string) $d['registered_at'], 0, 16)) ?></td>
            <td class="text-end text-nowrap">
              <button class="btn btn-sm btn-light border" form="psRowForm" name="row" value="open:device:<?= $id ?>" title="<?= e(__('Open this screen inside the customer')) ?>"><i class="bi bi-box-arrow-in-right"></i></button>
              <?php if (!$revoked && $d['room_id']): ?>
                <button class="btn btn-sm btn-light border" form="psRowForm" name="row" value="open:live:<?= $id ?>" title="<?= e(__('Live view')) ?>"><i class="bi bi-display"></i></button>
                <?php if (!empty($d['health_at'])): ?><button class="btn btn-sm btn-light border" form="psRowForm" name="row" value="open:health:<?= $id ?>" title="<?= e(__('TV health')) ?>"><i class="bi bi-heart-pulse"></i></button><?php endif; ?>
              <?php endif; ?>
              <?php if (!$revoked): ?>
              <div class="dropdown d-inline-block">
                <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e(__('Commands')) ?>"><i class="bi bi-lightning-charge"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php foreach (PlatformScreens::COMMANDS as $cmd): ?>
                    <li><button class="dropdown-item" form="psRowForm" name="row" value="cmd:<?= e($cmd) ?>:<?= $id ?>"<?= $cmd === 'REBOOT' || $cmd === 'SCREEN_OFF' ? ' data-confirm="' . e(__('Send ":c" to this TV?', ['c' => command_label($cmd)])) . '" data-confirm-safe="1"' : '' ?>><?= e(command_label($cmd)) ?></button></li>
                  <?php endforeach; ?>
                  <?php if (($d['platform'] ?? 'android') !== 'web'): ?><li><button class="dropdown-item" form="psRowForm" name="row" value="update:-:<?= $id ?>" data-confirm="<?= e(__('Install the customer\'s newest app on this TV?')) ?>" data-confirm-safe="1"><?= e(__('Update app')) ?></button></li><?php endif; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><button class="dropdown-item js-ps-move" type="button" data-id="<?= $id ?>"><i class="bi bi-arrow-left-right"></i> <?= e(__('Move to another customer…')) ?></button></li>
                  <?php if ($canPool): ?><li><button class="dropdown-item" form="psRowForm" name="row" value="unassign:-:<?= $id ?>" data-confirm="<?= e(__('Move this TV to the unassigned pool? The customer loses it; it shows a "waiting for setup" screen.')) ?>"><i class="bi bi-inbox"></i> <?= e(__('Move to unassigned pool')) ?></button></li><?php endif; ?>
                  <li><button class="dropdown-item text-danger" form="psRowForm" name="row" value="revoke:-:<?= $id ?>" data-confirm="<?= e(__('Revoke this TV? It will stop showing content and go back to its setup screen until registered again.')) ?>"><i class="bi bi-slash-circle"></i> <?= e(__('Revoke')) ?></button></li>
                </ul>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Target customer + screen fields (move / pool assign). $prefix keeps element ids unique. */
function ps_move_fields(array $customers, string $prefix, int $preselect = 0): string
{
    ob_start();
    ?>
    <div class="row g-2 align-items-end ps-move" data-prefix="<?= e($prefix) ?>">
      <div class="col-12 col-md-4">
        <label class="form-label small" for="<?= e($prefix) ?>Cust"><?= e(__('Target customer')) ?></label>
        <select class="form-select js-ps-customer" id="<?= e($prefix) ?>Cust" name="target_customer">
          <option value=""><?= e(__('— Choose customer —')) ?></option>
          <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $preselect ? ' selected' : '' ?>><?= e($c['name']) ?> #<?= (int) $c['id'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small" for="<?= e($prefix) ?>Mode"><?= e(__('Target screen')) ?></label>
        <select class="form-select js-ps-mode" id="<?= e($prefix) ?>Mode" name="screen_mode">
          <option value="same"><?= e(__('Same screen ID (create if missing)')) ?></option>
          <option value="existing"><?= e(__('An existing screen')) ?></option>
          <option value="new"><?= e(__('Create a new screen')) ?></option>
        </select>
      </div>
      <div class="col-12 col-md-3 js-ps-room" hidden>
        <label class="form-label small" for="<?= e($prefix) ?>Room"><?= e(__('Screen')) ?></label>
        <select class="form-select" id="<?= e($prefix) ?>Room" name="target_room"><option value=""><?= e(__('— Choose customer first —')) ?></option></select>
      </div>
      <div class="col-12 col-md-3 js-ps-new" hidden>
        <label class="form-label small" for="<?= e($prefix) ?>New"><?= e(__('New screen name')) ?></label>
        <input class="form-control" id="<?= e($prefix) ?>New" name="new_name" maxlength="100" placeholder="<?= e(__('e.g. Lobby TV')) ?>">
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Bulk form (bar) + hidden row form + move dialog script. */
function ps_bulk_bar(array $customers, int $preselect = 0): string
{
    $canPool = Auth::can('platform.pool');
    ob_start();
    ?>
    <form method="post" id="psRowForm" class="d-none"><?= Csrf::field() ?><input type="hidden" name="op" value="row"></form>
    <form method="post" id="psBulkForm" class="bulk-bar card card-body mt-3 shadow">
      <?= Csrf::field() ?><input type="hidden" name="op" value="bulk">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-auto small text-muted"><i class="bi bi-check2-square"></i> <span id="psSelCount">0</span> <?= e(__('selected')) ?></div>
        <div class="col-12 col-md-3">
          <label class="form-label small" for="psAction"><?= e(__('Action')) ?></label>
          <select class="form-select" name="bulk_action" id="psAction">
            <option value=""><?= e(__('— Choose action —')) ?></option>
            <optgroup label="<?= e(__('Commands')) ?>">
              <?php foreach (PlatformScreens::COMMANDS as $cmd): ?><option value="cmd:<?= e($cmd) ?>"><?= e(command_label($cmd)) ?></option><?php endforeach; ?>
              <option value="update"><?= e(__('Update app (newest APK of each customer)')) ?></option>
            </optgroup>
            <optgroup label="<?= e(__('Manage')) ?>">
              <option value="move"><?= e(__('Move to another customer / screen')) ?></option>
              <?php if ($canPool): ?><option value="unassign"><?= e(__('Move to unassigned pool')) ?></option><?php endif; ?>
              <option value="revoke"><?= e(__('Revoke')) ?></option>
            </optgroup>
          </select>
        </div>
        <div class="col-12 col-md-auto">
          <button class="btn btn-primary w-100" data-confirm="<?= e(__('Apply this action to the selected screens?')) ?>" data-confirm-safe="1"><i class="bi bi-lightning-charge"></i> <?= e(__('Apply')) ?></button>
        </div>
      </div>
      <div id="psMoveBox" class="mt-2" hidden><?= ps_move_fields($customers, 'psMv', $preselect) ?></div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const roomsUrl = <?= json_embed(admin_url('platform_screens.php', ['action' => 'rooms'])) ?>;
      const sel = document.getElementById('psAction');
      const box = document.getElementById('psMoveBox');
      const count = () => { document.getElementById('psSelCount').textContent = document.querySelectorAll('.ps-cb:checked').length; };
      sel.addEventListener('change', () => { box.hidden = sel.value !== 'move'; });
      document.querySelectorAll('.ps-cb').forEach((c) => c.addEventListener('change', count));
      document.addEventListener('hc:selection', count);
      document.querySelectorAll('.js-ps-move').forEach((b) => b.addEventListener('click', () => {
        document.querySelectorAll('.ps-cb').forEach((c) => { c.checked = c.value === b.dataset.id; });
        sel.value = 'move'; box.hidden = false; count();
        document.getElementById('psBulkForm').scrollIntoView({ behavior: 'smooth' });
      }));
      document.getElementById('psBulkForm').addEventListener('submit', (ev) => {
        if (!document.querySelectorAll('.ps-cb:checked').length || !sel.value) {
          ev.preventDefault(); ev.stopImmediatePropagation();
          HC.toast(<?= json_embed(__('Select screens and an action first.')) ?>, 'warning');
        }
      }, true);
      document.querySelectorAll('.ps-move').forEach((wrap) => {
        const cust = wrap.querySelector('.js-ps-customer');
        const mode = wrap.querySelector('.js-ps-mode');
        const roomBox = wrap.querySelector('.js-ps-room');
        const room = roomBox.querySelector('select');
        const newBox = wrap.querySelector('.js-ps-new');
        const load = async () => {
          room.innerHTML = '';
          if (!cust.value) { room.add(new Option(<?= json_embed(__('— Choose customer first —')) ?>, '')); return; }
          try {
            const r = await fetch(roomsUrl + '&customer=' + encodeURIComponent(cust.value), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const j = await r.json();
            (j.data && j.data.screens || []).forEach((s) => room.add(new Option(s.label, s.id)));
            if (!room.options.length) room.add(new Option(<?= json_embed(__('No screens yet — create a new one')) ?>, ''));
          } catch (e) { HC.toast(e.message, 'danger'); }
        };
        const sync = () => { roomBox.hidden = mode.value !== 'existing'; newBox.hidden = mode.value !== 'new'; };
        cust.addEventListener('change', load); mode.addEventListener('change', sync); sync();
        if (cust.value) load();
      });
    });
    </script>
    <?php
    return (string) ob_get_clean();
}
