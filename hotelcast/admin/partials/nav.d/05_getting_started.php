<?php
/** Admin menu: "Getting started" checklist, first in the hotel menu of free-trial hotels (#17). See 10_hotel.php. */
declare(strict_types=1);

if (!Tenant::has() || empty(Tenant::hotel()['is_trial'])) {
    return [];
}
return [['getting_started', 'getting_started.php', 'dashboard.view', 'bi-flag', __('Getting started'), 'hotel', ['saas' => true]]];
