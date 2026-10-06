<?php
declare(strict_types=1);

/**
 * Free-trial lifecycle (#17), SaaS only, hourly: reminders 3 days and 1 day before a trial ends,
 * expired trials → hotel status 'expired' (TVs "service paused", admin read-only except billing),
 * unverified sign-ups older than a day → expired. See Signup::processTrials().
 */
final class TrialTask implements Task
{
    public function interval(): int
    {
        return 3600;
    }

    public function run(): array
    {
        if (License::mode() !== 'saas') {
            return ['skipped' => 'standalone'];
        }
        return Signup::processTrials();
    }
}
