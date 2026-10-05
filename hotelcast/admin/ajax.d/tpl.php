<?php
/**
 * AJAX actions "tpl_*" (template library #12):
 *   tpl_render  POST {tpl, fields} → {html}  live preview of a template with the form values (nothing is saved).
 */
declare(strict_types=1);

if ($action === 'tpl_render') {
    $needPost();
    require_can('templates.manage');
    if (!Tenant::feature('templates')) {
        ajax_error(__('The template library is not included in your plan.'), 403, 'FORBIDDEN');
    }
    $tpl = Templates::find(is_string($in['tpl'] ?? null) ? $in['tpl'] : '');
    if (!$tpl) {
        ajax_error(__('Unknown template.'), 404, 'NOT_FOUND');
    }
    $fields = is_array($in['fields'] ?? null) ? $in['fields'] : [];
    ajax_ok(['html' => Templates::render($tpl, $fields)]);
}
