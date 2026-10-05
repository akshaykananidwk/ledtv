<?php
declare(strict_types=1);

/**
 * DPDP privacy: anonymise guest PII (name, phone, notes, Wi-Fi password, booking ref) N days after
 * check-out (hotel setting guest_retention_days, default 30). Runs every 6 hours for every hotel.
 */
final class GuestRetentionTask implements Task
{
    public function interval(): int
    {
        return 21600;
    }

    public function run(): array
    {
        $total = 0;
        Tenant::each(static function (int $hid) use (&$total): void {
            $days = max(1, (int) Guests::setting('guest_retention_days') ?: 30);
            $total += Guests::purgePii($days);
        });
        return ['anonymised' => $total];
    }
}
