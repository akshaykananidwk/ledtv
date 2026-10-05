<?php
/**
 * Optional cron entry point. HotelCast works without cron (maintenance runs lazily on
 * TV polls), but a cron job makes schedules and offline alerts exact:
 *
 *   * * * * * php /path/to/hotelcast/cron.php >/dev/null 2>&1
 *
 * Web access is blocked by .htaccess; this script refuses to run outside the CLI.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/core/bootstrap.php';

$result = Scheduler::tick(true);
if (in_array('--verbose', $argv, true)) {
    echo json_out($result), PHP_EOL;
}
