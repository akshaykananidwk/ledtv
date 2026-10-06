<?php
declare(strict_types=1);

/**
 * Ad marketplace (#19), every 15 minutes: cancels unpaid orders after platform_mkt_expire_days, rejects
 * hotel lines not approved before the end date, stops CPM campaigns that delivered the ordered
 * impressions and moves bookings scheduled → running → completed by date (the ad_campaigns themselves
 * already start / stop by their start_date / end_date in Ads::liveCampaigns()).
 */
final class MarketplaceTask implements Task
{
    public function interval(): int
    {
        return 900;
    }

    public function run(): array
    {
        try {
            if (!(int) DB::value("SELECT COUNT(*) FROM mkt_bookings WHERE status IN ('submitted','awaiting_payment','paid','scheduled','running')")) {
                return ['open' => 0];
            }
        } catch (Throwable) {
            return ['skipped' => 'migration 008 not applied'];
        }
        return Marketplace::housekeeping();
    }
}
