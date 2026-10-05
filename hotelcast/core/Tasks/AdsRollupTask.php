<?php
declare(strict_types=1);

/**
 * Ads (#11): hourly roll-up of yesterday's and older ad impressions (last 3 days, idempotent) into
 * ad_stats_daily, so sponsor reports survive the broadcast_logs retention.
 */
final class AdsRollupTask implements Task
{
    public function interval(): int
    {
        return 3600;
    }

    public function run(): array
    {
        $rows = 0;
        Tenant::each(static function () use (&$rows): void {
            if ((int) DB::value('SELECT COUNT(*) FROM ad_campaigns WHERE hotel_id = :h', ['h' => Tenant::id()]) > 0) {
                $rows += Ads::rollup(date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('-1 day')));
            }
        });
        return ['rows' => $rows];
    }
}
