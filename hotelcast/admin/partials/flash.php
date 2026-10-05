<?php
declare(strict_types=1);

/**
 * Session flash messages (Post/Redirect/Get).
 *   flash('success', __('Saved.'));  → stored in the session
 *   echo flash_show();               → renders & clears them (called by header.php)
 */

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        Auth::startSession();
        $type = in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info';
        $_SESSION['hc_flash'][] = ['type' => $type, 'msg' => $message];
    }

    /** Flash a list of validation errors as one message. */
    function flash_errors(array $errors): void
    {
        if ($errors) {
            flash('danger', implode("\n", array_map('strval', $errors)));
        }
    }

    function flash_show(): string
    {
        Auth::startSession();
        $items = $_SESSION['hc_flash'] ?? [];
        unset($_SESSION['hc_flash']);
        $html = '';
        $icons = ['success' => 'bi-check-circle-fill', 'danger' => 'bi-exclamation-octagon-fill', 'warning' => 'bi-exclamation-triangle-fill', 'info' => 'bi-info-circle-fill'];
        foreach ($items as $f) {
            $html .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show d-flex gap-2 align-items-start" role="alert">'
                . '<i class="bi ' . e($icons[$f['type']] ?? 'bi-info-circle') . ' flex-shrink-0 mt-1"></i>'
                . '<div>' . nl2br(e($f['msg'])) . '</div>'
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . e(__('Close')) . '"></button></div>';
        }
        return $html;
    }
}
