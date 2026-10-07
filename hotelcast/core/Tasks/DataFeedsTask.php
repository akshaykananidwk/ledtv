<?php
declare(strict_types=1);

/**
 * Data feeds (2.3): refreshes external feeds (currency, gold, market, cricket, flights) that TVs used in
 * the last 24 hours, when their TTL is over — never more than the per-minute budget and the providers'
 * daily caps (DataFeeds::refreshDue). Pages and TVs only read the stored values.
 */
final class DataFeedsTask implements Task
{
    public function interval(): int
    {
        return 60;
    }

    public function run(): array
    {
        return DataFeeds::refreshDue();
    }
}
