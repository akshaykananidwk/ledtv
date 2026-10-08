<?php
declare(strict_types=1);

/**
 * "Content expires soon" (2.4, #33): once per item and expiry date, managers get a web push (and email /
 * WhatsApp when the hotel enabled the alert type "Content expires soon" for that channel) N days before
 * an item's valid_to (hotel setting content_expiry_warn_days, default 3; 0 = off), plus an activity log
 * entry. Runs hourly for every active hotel.
 */
final class ContentExpiryTask implements Task
{
    public function interval(): int
    {
        return 3600;
    }

    public function run(): array
    {
        $total = 0;
        Tenant::each(static function (int $hid) use (&$total): void {
            $total += self::forHotel();
        }, true);
        return ['warned' => $total];
    }

    /** Warn about the current hotel's items expiring soon. Returns the number of items warned about. */
    public static function forHotel(?int $now = null): int
    {
        $days = (int) Settings::get('content_expiry_warn_days', '3');
        if ($days <= 0) {
            return 0;
        }
        $now ??= ContentRules::now();
        $rows = DB::all(
            "SELECT id, title, valid_to FROM content_items
             WHERE hotel_id = :h AND is_active = 1 AND approval_status = 'approved' AND valid_to IS NOT NULL
               AND valid_to > :now AND valid_to <= :until AND (expiry_warned_for IS NULL OR expiry_warned_for <> valid_to)
             ORDER BY valid_to LIMIT 50",
            ['h' => Tenant::id(), 'now' => date('Y-m-d H:i:s', $now), 'until' => date('Y-m-d H:i:s', $now + $days * 86400)]
        );
        if (!$rows) {
            return 0;
        }
        $lines = [];
        foreach ($rows as $r) {
            DB::query('UPDATE content_items SET expiry_warned_for = valid_to WHERE id = :id AND hotel_id = :h', ['id' => $r['id'], 'h' => Tenant::id()]);
            $lines[] = $r['title'] . ' — ' . date('d M Y H:i', (int) strtotime((string) $r['valid_to']));
            ActivityLog::add('content_expiry_warning', 'content', (int) $r['id'], mb_substr($r['title'] . ' → ' . $r['valid_to'], 0, 200), Tenant::id());
        }
        StaffAlerts::send('content.manage', __('Content expires soon'), implode("\n", $lines), 'content.php?state=live', 'content_expiry');
        return count($rows);
    }
}
