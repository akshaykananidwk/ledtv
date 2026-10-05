<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

// POST with CSRF field, or GET with ?token=<csrf> (for plain links).
if (is_post()) {
    Csrf::check();
} elseif (!Csrf::valid(is_string($_GET['token'] ?? null) ? $_GET['token'] : null)) {
    redirect(admin_url('index.php'));
}

Auth::logout();
Auth::startSession();
flash('success', __('You have been logged out.'));
redirect(admin_url('login.php'));
