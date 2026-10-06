<?php
declare(strict_types=1);

/**
 * Demo mode (#21), SaaS only, every 10 minutes: keeps the simulated TVs of the public demo hotel
 * online, resets the public demo data once a night (after 03:00), creates it when the public demo
 * was enabled but the hotel is missing, and expires + purges client demos past their end date.
 */
final class DemoResetTask implements Task
{
    public function interval(): int
    {
        return 600;
    }

    public function run(): array
    {
        if (License::mode() !== 'saas') {
            return ['skipped' => 'standalone'];
        }
        $out = ['client_demos_expired' => Demo::expireClientDemos()];
        if (Demo::publicEnabled()) {
            $hid = Demo::publicHotelId();
            if (!$hid || Demo::resetDue()) {
                $out['public_reset'] = Demo::resetPublic();
            } else {
                Demo::keepAlive($hid);
            }
        }
        return $out;
    }
}
