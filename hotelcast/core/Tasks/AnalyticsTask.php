<?php
declare(strict_types=1);

/**
 * Analytics (#17): every 5 minutes, sample each TV (online? screen on?) into tv_usage_daily so the
 * analytics page can show hours ON per TV and the estimated electricity use.
 */
final class AnalyticsTask implements Task
{
    public function interval(): int
    {
        return 300;
    }

    public function run(): array
    {
        $n = 0;
        Tenant::each(static function () use (&$n): void {
            if (Tenant::feature('analytics')) {
                $n += Analytics::sampleUsage();
            }
        }, true);
        return ['sampled' => $n];
    }
}
