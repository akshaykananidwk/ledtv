<?php
/**
 * AJAX actions with the prefix "signup_" (admin/ajax.php?action=signup_…), see admin/ajax.d/platform.php.
 *   signup_stats      GET  platform counters (sign-ups, trials, conversion)       — signup.manage
 *   signup_checklist  GET  getting-started checklist of the current hotel         — dashboard.view
 */
declare(strict_types=1);

if ($action === 'signup_stats') {
    require_can('signup.manage');
    ajax_ok(Signup::stats());
}

if ($action === 'signup_checklist') {
    require_can('dashboard.view');
    $steps = Signup::checklist(Tenant::id());
    $out = [];
    foreach ($steps as $k => [$done, $label, $href]) {
        $out[] = ['key' => $k, 'done' => $done, 'label' => $label, 'url' => admin_url($href)];
    }
    $done = count(array_filter($out, fn ($s) => $s['done']));
    ajax_ok(['steps' => $out, 'done' => $done, 'total' => count($out), 'percent' => (int) round($done * 100 / max(1, count($out))), 'days_left' => Signup::daysLeft(Tenant::hotel())]);
}
