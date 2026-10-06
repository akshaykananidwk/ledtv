<?php
/** Advertiser portal: log out (revokes the advertiser session). */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();
MarketplacePortal::logout();
redirect(MarketplacePortal::url('login.php'));
