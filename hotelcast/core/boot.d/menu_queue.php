<?php
/**
 * 2.3: Menu board (display app "menu_board", core/MenuBoard.php, admin/menu_board.php) and token /
 * queue system (display app "queue_display", core/Queue.php, admin/queue.php, admin/queue_issue.php,
 * display/queue.php). Tables from migrations/016_menu_queue.sql. The menu tables themselves
 * (guest_menu_*) are registered by guests_services.php.
 */
declare(strict_types=1);

foreach (['queue_services', 'queue_counters', 'queue_tokens'] as $__t) {
    Tenant::registerTable($__t);
}
unset($__t);

// Menu board: dishes, prices, sold-out switch, today's special (staff+, kitchen / restaurant staff).
Auth::registerPermission('menu_board.manage', 'staff');
// Queue: call / issue tokens at a counter (reception+); services, counters, reset (manager+).
Auth::registerPermission('queue.operate', 'reception');
Auth::registerPermission('queue.manage', 'manager');
