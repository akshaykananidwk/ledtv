<?php
/**
 * Sidebar items: Menu board (restaurant menu for TVs, sold-out switch) and the token queue pages
 * (calling page, token issue / kiosk), right after the Apps / Notice board entries.
 */
declare(strict_types=1);

return [
    ['menu_board', 'menu_board.php', 'menu_board.manage', 'bi-egg-fried', __('Menu board'), 'hotel', ['after' => 'notices']],
    ['queue', 'queue.php', 'queue.operate', 'bi-people', __('Token queue'), 'hotel', ['after' => 'menu_board']],
    ['queue_issue', 'queue_issue.php', 'queue.operate', 'bi-ticket-perforated', __('Issue tokens'), 'hotel', ['after' => 'queue']],
];
