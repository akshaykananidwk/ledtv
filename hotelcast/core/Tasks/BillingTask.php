<?php
declare(strict_types=1);

/**
 * Daily billing job (SaaS platform only): on/after the 1st of a month generate last month's
 * invoices (if "auto-generate" is on and not done yet), then send overdue reminders and
 * auto-suspend hotels that are too long overdue.
 */
final class BillingTask implements Task
{
    public function interval(): int
    {
        return 6 * 3600; // checked a few times a day; the work itself is idempotent
    }

    public function run(): array
    {
        if (License::mode() !== 'saas') {
            return ['skipped' => 'standalone'];
        }
        $out = [];
        $prev = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        if (Settings::platform('invoice_auto_generate', '1') === '1' && Settings::platform('billing_last_generated', '') !== $prev) {
            $r = Billing::generateMonthly($prev);
            Settings::setPlatform('billing_last_generated', $prev);
            $out['generated'] = count($r['created']);
        }
        return $out + Billing::processOverdue();
    }
}
