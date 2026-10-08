<?php
declare(strict_types=1);

/**
 * 2.6 panels (docs/modules/panels.md) — small UI pieces shared by the Super Admin console and the
 * Reseller panel: KPI tiles, one-click switches (admin/ajax_platform.php), alert lists, empty states,
 * the "Open customer workspace" button. Only renders escaped HTML; permission checks stay in the pages.
 */

require_once __DIR__ . '/common.php';

if (defined('HC_PANEL_UI')) {
    return;
}
define('HC_PANEL_UI', true);

/** One KPI tile. $tone: '' | 'success' | 'warning' | 'danger' (danger/warning only when $value > 0 makes sense). */
function panel_kpi(string $label, int|string $value, string $icon, string $href = '', string $sub = '', string $tone = '', string $key = ''): string
{
    $tag = $href !== '' ? 'a' : 'div';
    return '<' . $tag . ($href !== '' ? ' href="' . e($href) . '"' : '') . ' class="kpi' . ($tone !== '' ? ' kpi-' . e($tone) : '') . '"' . ($key !== '' ? ' data-kpi="' . e($key) . '"' : '') . '>'
        . '<span class="kpi-label"><i class="bi ' . e($icon) . '"></i> ' . e($label) . '</span>'
        . '<span class="kpi-value">' . e((string) $value) . '</span>'
        . ($sub !== '' ? '<span class="kpi-sub">' . e($sub) . '</span>' : '')
        . '</' . $tag . '>';
}

/**
 * One-click switch bound to ajax.php?action=platform_toggle. $kind + $target identify the thing
 * (see admin/ajax_platform.php); $on = current state. Optional confirm text when switching OFF.
 */
function panel_switch(string $kind, int|string $target, bool $on, string $label = '', string $confirmOff = '', string $confirmOn = '', array $extra = [], bool $disabled = false): string
{
    $id = 'sw_' . preg_replace('/[^a-z0-9]+/i', '_', $kind . '_' . $target);
    $attrs = '';
    foreach ($extra as $k => $v) {
        $attrs .= ' data-' . e((string) $k) . '="' . e((string) $v) . '"';
    }
    return '<label class="hc-switch form-check form-switch m-0" data-platform-switch data-kind="' . e($kind) . '" data-target="' . e((string) $target) . '"'
        . ($confirmOff !== '' ? ' data-confirm-off="' . e($confirmOff) . '"' : '') . ($confirmOn !== '' ? ' data-confirm-on="' . e($confirmOn) . '"' : '') . $attrs . '>'
        . '<input class="form-check-input" type="checkbox" role="switch" id="' . e($id) . '"' . ($on ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>'
        . ($label !== '' ? '<span class="hc-switch-label" data-switch-label data-on="' . e(__('On')) . '" data-off="' . e(__('Off')) . '">' . e($label) . '</span>' : '')
        . '</label>';
}

/** Alerts list (overview). $alerts: [['tone' => 'danger', 'icon' => 'bi-…', 'text' => '', 'href' => '', 'action' => '']]. */
function panel_alerts(array $alerts, string $allGood): string
{
    if (!$alerts) {
        return '<div class="hc-empty py-4" data-alerts-empty><i class="bi bi-check2-circle text-success"></i><p class="mb-0"><strong>' . e($allGood) . '</strong></p></div>';
    }
    $h = '<ul class="list-group list-group-flush hc-alerts" data-alerts>';
    foreach ($alerts as $a) {
        $tone = $a['tone'] ?? 'warning';
        $h .= '<li class="list-group-item"><i class="bi ' . e($a['icon'] ?? 'bi-exclamation-circle') . ' text-' . e($tone) . '"></i>'
            . '<div class="flex-grow-1 min-w-0"><div>' . e($a['text']) . '</div>' . (!empty($a['sub']) ? '<div class="small text-muted">' . e($a['sub']) . '</div>' : '') . '</div>'
            . (!empty($a['href']) ? '<a class="btn btn-sm btn-light border text-nowrap" href="' . e($a['href']) . '">' . e($a['action'] ?? __('Open')) . '</a>' : '')
            . '</li>';
    }
    return $h . '</ul>';
}

/** Empty state block. */
function panel_empty(string $icon, string $title, string $text = '', string $href = '', string $action = ''): string
{
    return '<div class="hc-empty"><i class="bi ' . e($icon) . '"></i><p class="mb-1"><strong>' . e($title) . '</strong></p>'
        . ($text !== '' ? '<p class="mb-2 small">' . e($text) . '</p>' : '')
        . ($href !== '' ? '<a class="btn btn-sm btn-primary" href="' . e($href) . '">' . e($action) . '</a>' : '') . '</div>';
}

/** "Open customer workspace" (impersonation) button: POST op=enter to $page. */
function panel_open_workspace(int $hotelId, string $page = 'platform_customer.php', string $size = '', string $next = ''): string
{
    return '<form method="post" action="' . e(admin_url($page)) . '" class="d-inline m-0">' . Csrf::field()
        . '<input type="hidden" name="op" value="enter"><input type="hidden" name="id" value="' . $hotelId . '">'
        . ($next !== '' ? '<input type="hidden" name="next" value="' . e($next) . '">' : '')
        . '<button class="btn btn-outline-primary' . ($size !== '' ? ' btn-' . e($size) : '') . '" title="' . e(__('Work inside this customer\'s panel (shown with a banner, logged).')) . '">'
        . '<i class="bi bi-box-arrow-in-right"></i> <span class="d-none d-sm-inline">' . e(__('Open customer workspace')) . '</span></button></form>';
}

/** Recent activity rows across customers (platform / reseller scope). */
function panel_recent_activity(?array $scope, int $limit = 15): array
{
    $w = '';
    $p = [];
    if ($scope !== null) {
        if (!$scope) {
            return [];
        }
        $in = [];
        foreach (array_values($scope) as $i => $hid) {
            $in[] = ':s' . $i;
            $p['s' . $i] = (int) $hid;
        }
        $w = ' WHERE a.hotel_id IN (' . implode(',', $in) . ')';
    }
    return DB::all('SELECT a.*, h.name AS hotel_name FROM activity_logs a LEFT JOIN hotels h ON h.id = a.hotel_id' . $w . ' ORDER BY a.id DESC LIMIT ' . max(1, $limit), $p);
}
