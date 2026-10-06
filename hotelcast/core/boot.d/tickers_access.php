<?php
/**
 * 2.2: ticker bars per TV / group (core/Tickers.php) and per-user TV access (core/Access.php).
 */
declare(strict_types=1);

Tenant::registerTable('tickers');
Tenant::registerTable('user_access');

Auth::registerPermission('tickers.manage', 'staff');
