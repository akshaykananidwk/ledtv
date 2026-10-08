<?php
/** Sidebar items of the festival calendar (#28) and the birthday / anniversary wall (#29), after the notice board. */
declare(strict_types=1);

return [
    ['festivals', 'festivals.php', 'festivals.manage', 'bi-stars', __('Festivals'), 'hotel', ['after' => 'notices']],
    ['celebrations', 'celebrations.php', 'celebrations.manage', 'bi-balloon-heart', __('Birthdays & anniversaries'), 'hotel', ['after' => 'festivals']],
];
