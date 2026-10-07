<?php
/**
 * Sidebar items: Rates (gold / silver / market values, #21 / #22, staff+) after the Notice board, and
 * Data feeds (hotel's own API keys + feed status, super admin) after Settings.
 */
declare(strict_types=1);

return [
    ['rates', 'rates.php', 'rates.manage', 'bi-gem', __('Rates'), 'hotel', ['after' => 'notices']],
    ['data_feeds', 'data_feeds.php', 'settings.manage', 'bi-broadcast', __('Data feeds'), 'hotel', ['after' => 'settings']],
];
