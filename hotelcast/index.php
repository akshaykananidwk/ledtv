<?php
declare(strict_types=1);
// Root of the HotelCast install: send visitors to the admin panel.
require __DIR__ . '/core/bootstrap.php';
redirect(admin_url(''));
