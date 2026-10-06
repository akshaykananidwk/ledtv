<?php
declare(strict_types=1);

/**
 * Daily housekeeping of the QR setup module: deletes device_provisioning rows older than
 * Provisioning::RETENTION_DAYS (7 days), whatever their status. The table is not hotel data
 * (hotel_id is NULL until a code is claimed), so no Tenant::each() loop is needed.
 */
final class ProvisioningCleanupTask implements Task
{
    public function interval(): int
    {
        return 86400;
    }

    public function run(): array
    {
        return ['deleted' => Provisioning::purge()];
    }
}
