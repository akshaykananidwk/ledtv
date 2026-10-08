<?php
declare(strict_types=1);

/**
 * 2.4 TV health (#44) and live view (#41) housekeeping, every 10 minutes: debounced health alerts
 * per active hotel (DeviceHealth::checkAlerts → Notifier email / WhatsApp + staff push), history older
 * than 7 days deleted, frames of finished live views deleted.
 */
final class DeviceHealthTask implements Task
{
    public function interval(): int
    {
        return DeviceHealth::HISTORY_EVERY;
    }

    public function run(): array
    {
        $alerted = array_sum(array_filter(Tenant::each(static fn () => DeviceHealth::checkAlerts(), true), 'is_int'));
        return ['alerted' => $alerted, 'pruned' => DeviceHealth::pruneHistory(), 'live_cleaned' => LiveView::cleanup()];
    }
}
