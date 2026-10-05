<?php
declare(strict_types=1);

/** Daily license check for self-hosted (standalone) installations. */
final class LicenseTask implements Task
{
    public function interval(): int
    {
        return 3600; // License::check() itself only calls the server once per 24 h
    }

    public function run(): array
    {
        if (License::mode() !== 'standalone') {
            return ['skipped' => 'saas'];
        }
        $before = License::state()['status'];
        $s = License::check();
        if ($before !== $s['status']) {
            // State changed (e.g. license expired): TVs must re-fetch content.
            Tenant::each(static function (): void {
                Settings::bumpContentVersion();
            });
        }
        return ['status' => $s['status']];
    }
}
