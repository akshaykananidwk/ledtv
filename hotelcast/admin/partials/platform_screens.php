<?php
declare(strict_types=1);

/**
 * Shared UI + POST handler of Platform → All screens (admin/platform_screens.php), the Screens tab
 * of the customer detail page (admin/platform_customer.php) and (2.5.1) the "All customers" view and the
 * "Transfer to another customer" dialog of Screens & TVs (admin/rooms.php). docs/modules/platform_screens.md.
 * Permission checks: the pages require platform.screens; pool actions here require platform.pool.
 * Every device id is re-checked against the user's customers by core/PlatformScreens.php.
 */

require_once __DIR__ . '/common.php';

ActivityLog::$platformScope = true; // logs without an explicit customer are platform-level

/** Handle a POST of the screens UI, flash the result and redirect back to $back. */
function ps_handle_post(string $back, bool $allowTransfer = true): never
{
    $op = req_str('op', $_POST, 30);
    // Owner rule (2.6.1): TVs are transferred only from the Super Admin console (Devices & screens,
    // Customer 360), never from a customer's Screens page — not even by the Super Admin.
    if (!$allowTransfer && in_array($op === 'bulk' ? req_str('bulk_action', $_POST, 30) : $op, ['move', 'transfer', 'pool_assign'], true)) {
        if (Auth::isAjax()) {
            ajax_error(__('Transfer TVs from the Super Admin console: Devices & screens.'), 403, 'FORBIDDEN');
        }
        http_response_code(403);
        $forbiddenMessage = __('Transfer TVs from the Super Admin console: Devices & screens.');
        require __DIR__ . '/forbidden.php';
        exit;
    }
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

/** Move options from POST (target screen mode, room, new screen name, 2.5.1 "Transfer everything" boxes). */
function ps_move_options(): array
{
    $mode = req_str('screen_mode', $_POST, 10);
    return [
        'mode' => in_array($mode, ['existing', 'new', 'same'], true) ? $mode : 'existing',
        'room_id' => req_int('target_room', $_POST),
        'name' => req_str('new_name', $_POST, 100),
        'copy' => ['details' => !empty($_POST['copy_details']), 'content' => !empty($_POST['copy_content'])],
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
            require_can('platform.move'); // only platform admins move TVs (resellers: commands / revoke only)
            $r = PlatformScreens::transfer($ids, req_int('target_customer', $_POST), ps_move_options());
            flash('success', __(':n TV(s) moved. They show the new customer\'s content on their next poll.', ['n' => $r['moved']]));
            if ($r['summary'] !== '') {
                flash($r['report']['skipped'] ? 'warning' : 'info', $r['summary']);
            }
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
function ps_table(array $rows, bool $showCustomer = true, bool $allowTransfer = true): string
{
    $canPool = Auth::can('platform.pool');
    $canMove = $allowTransfer && Auth::can('platform.move');
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
              <?php if ($canMove && !$revoked): ?><button type="button" class="btn btn-sm btn-outline-primary js-ps-transfer" data-ids="<?= $id ?>" data-label="<?= e(dot_trim(($d['room_number'] ?? '') . ' · ' . $d['device_uid'])) ?>" title="<?= e(__('Transfer to another customer')) ?>" aria-label="<?= e(__('Transfer to another customer')) ?>"><i class="bi bi-arrow-left-right"></i></button><?php endif; ?>
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
                  <?php if ($canMove): ?><li><button class="dropdown-item js-ps-transfer" type="button" data-ids="<?= $id ?>" data-label="<?= e($d['device_uid']) ?>"><i class="bi bi-arrow-left-right"></i> <?= e(__('Transfer to another customer…')) ?></button></li><?php endif; ?>
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

/**
 * Target customer + screen fields (move / pool assign). $prefix keeps element ids unique.
 * $transfer (2.5.1): add the "Transfer everything" boxes (screen details, content), checked by default.
 */
function ps_move_fields(array $customers, string $prefix, int $preselect = 0, bool $transfer = false): string
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
      <?php if ($transfer): ?>
      <div class="col-12 ps-transfer-opts">
        <div class="border rounded p-2 bg-body-tertiary">
          <div class="fw-semibold small mb-1"><i class="bi bi-box-seam"></i> <?= e(__('Transfer everything')) ?></div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="copy_details" value="1" id="<?= e($prefix) ?>CopyDetails" checked>
            <label class="form-check-label small" for="<?= e($prefix) ?>CopyDetails"><strong><?= e(__('Screen details')) ?></strong> — <?= e(__('name, area / floor, on / off, settings PIN, notes, USB and HDMI-CEC mode, power-off times and device schedules (volume, input, restart) of this screen')) ?></label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="copy_content" value="1" id="<?= e($prefix) ?>CopyContent" checked>
            <label class="form-check-label small" for="<?= e($prefix) ?>CopyContent"><strong><?= e(__('Content')) ?></strong> — <?= e(__('the playlist or item this screen plays with its media files, split screen layouts, tickers and scheduled content of this screen')) ?></label>
          </div>
          <div class="form-text"><?= e(__('Copied into the new customer and assigned to the target screen; the old customer keeps its originals. The TV keeps its token: no setup on the TV. Display apps are not copied.')) ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Script binding every .ps-move block (customer → screens of that customer, screen mode). Emitted once
 * per page. $roomsUrl: JSON list of a customer's screens (platform_screens.php?action=rooms).
 */
function ps_move_script(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    ob_start();
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const roomsUrl = <?= json_embed(admin_url('platform_screens.php', ['action' => 'rooms'])) ?>;
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

/**
 * 2.5.1 "Transfer to another customer" dialog (platform.move only): target customer, target screen and
 * the "Transfer everything" boxes; posts op=bulk / bulk_action=move to the current page. Opened by any
 * .js-ps-transfer element with data-ids="1,2" (TV ids) and data-label, or by ps_transfer_open(ids, label).
 */
function ps_transfer_modal(array $customers, int $preselect = 0): string
{
    static $done = false;
    if ($done || !Auth::can('platform.move')) {
        return '';
    }
    $done = true;
    ob_start();
    ?>
    <div class="modal fade" id="psTransferModal" tabindex="-1" aria-labelledby="psTransferTitle" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="post" class="modal-content" id="psTransferForm">
          <?= Csrf::field() ?><input type="hidden" name="op" value="bulk"><input type="hidden" name="bulk_action" value="move"><input type="hidden" name="ps_form" value="1">
          <div id="psTransferIds"></div>
          <div class="modal-header">
            <h2 class="modal-title fs-5" id="psTransferTitle"><i class="bi bi-arrow-left-right"></i> <?= e(__('Transfer to another customer')) ?></h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
          </div>
          <div class="modal-body">
            <p class="small mb-2"><?= e(__('TV')) ?>: <strong id="psTransferWhat">-</strong></p>
            <?= ps_move_fields($customers, 'psTr', $preselect, true) ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button>
            <button class="btn btn-primary" id="psTransferGo"><i class="bi bi-arrow-left-right"></i> <?= e(__('Transfer now')) ?></button>
          </div>
        </form>
      </div>
    </div>
    <script>
    function ps_transfer_open(ids, label) {
      ids = (ids || []).map(String).filter((v) => /^\d+$/.test(v));
      if (!ids.length) { HC.toast(<?= json_embed(__('Select screens with a TV first.')) ?>, 'warning'); return; }
      const box = document.getElementById('psTransferIds');
      box.innerHTML = '';
      ids.forEach((id) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; box.appendChild(i); });
      document.getElementById('psTransferWhat').textContent = label || (ids.length + ' TV');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('psTransferModal')).show();
    }
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.js-ps-transfer').forEach((b) => b.addEventListener('click', (ev) => {
        ev.preventDefault();
        ps_transfer_open(String(b.dataset.ids || '').split(','), b.dataset.label || '');
      }));
      document.getElementById('psTransferForm').addEventListener('submit', (ev) => {
        if (!document.getElementById('psTransferForm').querySelector('.js-ps-customer').value) {
          ev.preventDefault(); ev.stopImmediatePropagation();
          HC.toast(<?= json_embed(__('Choose the target customer.')) ?>, 'warning');
        }
      }, true);
    });
    </script>
    <?= ps_move_script() ?>
    <?php
    return (string) ob_get_clean();
}

/** Bulk form (bar) + hidden row form + move dialog script. */
function ps_bulk_bar(array $customers, int $preselect = 0, bool $allowTransfer = true): string
{
    $canPool = Auth::can('platform.pool');
    $canMove = $allowTransfer && Auth::can('platform.move');
    ob_start();
    ?>
    <form method="post" id="psRowForm" class="d-none"><?= Csrf::field() ?><input type="hidden" name="op" value="row"><input type="hidden" name="ps_form" value="1"></form>
    <form method="post" id="psBulkForm" class="bulk-bar card card-body mt-3 shadow">
      <?= Csrf::field() ?><input type="hidden" name="op" value="bulk"><input type="hidden" name="ps_form" value="1">
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
              <?php if ($canMove): ?><option value="move"><?= e(__('Transfer to another customer / screen')) ?></option><?php endif; ?>
              <?php if ($canPool): ?><option value="unassign"><?= e(__('Move to unassigned pool')) ?></option><?php endif; ?>
              <option value="revoke"><?= e(__('Revoke')) ?></option>
            </optgroup>
          </select>
        </div>
        <div class="col-12 col-md-auto">
          <button class="btn btn-primary w-100" data-confirm="<?= e(__('Apply this action to the selected screens?')) ?>" data-confirm-safe="1"><i class="bi bi-lightning-charge"></i> <?= e(__('Apply')) ?></button>
        </div>
        <?php if ($canMove): ?>
        <div class="col-12 col-md-auto ms-md-auto">
          <button type="button" class="btn btn-outline-primary w-100" id="psTransferSel"><i class="bi bi-arrow-left-right"></i> <?= e(__('Transfer selected to another customer')) ?></button>
        </div>
        <?php endif; ?>
      </div>
      <div id="psMoveBox" class="mt-2" hidden><?= ps_move_fields($customers, 'psMv', $preselect, true) ?></div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const sel = document.getElementById('psAction');
      const box = document.getElementById('psMoveBox');
      const count = () => { document.getElementById('psSelCount').textContent = document.querySelectorAll('.ps-cb:checked').length; };
      sel.addEventListener('change', () => { box.hidden = sel.value !== 'move'; });
      document.querySelectorAll('.ps-cb').forEach((c) => c.addEventListener('change', count));
      document.addEventListener('hc:selection', count);
      document.getElementById('psBulkForm').addEventListener('submit', (ev) => {
        if (!document.querySelectorAll('.ps-cb:checked').length || !sel.value) {
          ev.preventDefault(); ev.stopImmediatePropagation();
          HC.toast(<?= json_embed(__('Select screens and an action first.')) ?>, 'warning');
        }
      }, true);
      const tr = document.getElementById('psTransferSel');
      if (tr) tr.addEventListener('click', () => {
        const ids = [...document.querySelectorAll('.ps-cb:checked')].map((c) => c.value);
        ps_transfer_open(ids, ids.length + ' ' + <?= json_embed(__('TV(s) selected')) ?>);
      });
    });
    </script>
    <?= ps_move_script() ?>
    <?= $canMove ? ps_transfer_modal($customers) : '' ?>
    <?php
    return (string) ob_get_clean();
}
