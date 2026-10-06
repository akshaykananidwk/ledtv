<?php
declare(strict_types=1);

/**
 * Manual billing (#20): monthly invoices (active TVs × plan price + tax), payment recording,
 * overdue reminders, auto-suspend / auto-reactivate, reseller commissions.
 */
final class Billing
{
    /** [from, to] (Y-m-d) of a month "YYYY-MM"; default = previous calendar month. */
    public static function monthRange(?string $ym = null): array
    {
        if ($ym === null || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            $ym = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        }
        $from = $ym . '-01';
        return [$from, date('Y-m-t', (int) strtotime($from))];
    }

    /** Next invoice number for the year: PREFIX-YYYY-0001. Must run inside a transaction. */
    public static function nextNumber(?int $year = null): string
    {
        $year ??= (int) date('Y');
        DB::query('INSERT IGNORE INTO invoice_sequences (seq_year, last_number) VALUES (:y, 0)', ['y' => $year]);
        $last = (int) DB::value('SELECT last_number FROM invoice_sequences WHERE seq_year = :y FOR UPDATE', ['y' => $year]);
        $next = $last + 1;
        DB::query('UPDATE invoice_sequences SET last_number = :n WHERE seq_year = :y', ['n' => $next, 'y' => $year]);
        $prefix = preg_replace('/[^A-Za-z0-9-]/', '', (string) Settings::platform('invoice_prefix', 'HC')) ?: 'HC';
        return sprintf('%s-%d-%04d', strtoupper($prefix), $year, $next);
    }

    /** Amount breakdown: [amount, tax, total] rounded to paise. */
    public static function calc(int $tvCount, float $unitPrice, float $taxPercent): array
    {
        $amount = round($tvCount * $unitPrice, 2);
        $tax = round($amount * $taxPercent / 100, 2);
        return [$amount, $tax, round($amount + $tax, 2)];
    }

    /**
     * Create one invoice for a hotel. $tvCount / $unitPrice default to the hotel's active TVs and
     * plan price. Returns the invoice id.
     */
    public static function createInvoice(int $hotelId, string $from, string $to, ?int $userId = null, ?int $tvCount = null, ?float $unitPrice = null, string $notes = ''): int
    {
        $hotel = Hotels::find($hotelId);
        if (!$hotel) {
            throw new InvalidArgumentException('Hotel not found');
        }
        $tvCount ??= Tenant::tvCount($hotelId);
        $unitPrice ??= (float) ($hotel['price_per_tv_month'] ?? 0);
        $taxPct = max(0.0, (float) Settings::platform('billing_tax_percent', '0'));
        [$amount, $tax, $total] = self::calc($tvCount, $unitPrice, $taxPct);
        $dueDays = max(0, (int) Settings::platform('invoice_due_days', '15'));
        return DB::transaction(function () use ($hotel, $from, $to, $userId, $tvCount, $unitPrice, $taxPct, $amount, $tax, $total, $dueDays, $notes) {
            return DB::insert('invoices', [
                'hotel_id' => (int) $hotel['id'],
                'number' => self::nextNumber((int) date('Y')),
                'period_from' => $from,
                'period_to' => $to,
                'plan_name' => $hotel['plan_name'],
                'tv_count' => $tvCount,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'tax_percent' => $taxPct,
                'tax' => $tax,
                'total' => $total,
                'currency' => substr((string) Settings::platform('billing_currency', 'INR'), 0, 3),
                'status' => 'unpaid',
                'issued_at' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+' . $dueDays . ' days')),
                'notes' => $notes !== '' ? mb_substr($notes, 0, 1000) : null,
                'bill_to' => json_out(self::billTo($hotel)),
                'created_by' => $userId,
                'created_at' => now(),
            ]);
        });
    }

    /** Snapshot of the customer's billing details at invoice time. */
    public static function billTo(array $hotel): array
    {
        return [
            'name' => $hotel['name'], 'contact' => $hotel['contact_name'], 'email' => $hotel['contact_email'],
            'phone' => $hotel['contact_phone'], 'address' => $hotel['address'], 'city' => $hotel['city'], 'gstin' => $hotel['gstin'],
        ];
    }

    /**
     * Generate invoices for every active hotel with a plan for the month (default: previous month).
     * Idempotent: hotels that already have a (non-cancelled) invoice for the period are skipped.
     * Returns ['period' => [from,to], 'created' => [ids], 'skipped' => [hotelId => reason]].
     */
    public static function generateMonthly(?string $ym = null, ?int $userId = null): array
    {
        [$from, $to] = self::monthRange($ym);
        $created = [];
        $skipped = [];
        $hotels = DB::all("SELECT h.id, h.plan_id, p.price_per_tv_month FROM hotels h LEFT JOIN plans p ON p.id = h.plan_id WHERE h.status = 'active' ORDER BY h.id");
        foreach ($hotels as $h) {
            $hid = (int) $h['id'];
            if (!$h['plan_id']) {
                $skipped[$hid] = 'no plan';
                continue;
            }
            if (DB::value("SELECT id FROM invoices WHERE hotel_id = :h AND period_from = :f AND status <> 'cancelled'", ['h' => $hid, 'f' => $from])) {
                $skipped[$hid] = 'already invoiced';
                continue;
            }
            $tvs = Tenant::tvCount($hid);
            if ($tvs === 0 || (float) $h['price_per_tv_month'] <= 0) {
                $skipped[$hid] = 'nothing to bill';
                continue;
            }
            $created[] = self::createInvoice($hid, $from, $to, $userId, $tvs);
        }
        Logger::write('billing', 'info', 'Monthly invoices generated', ['period' => $from, 'created' => count($created), 'skipped' => count($skipped)]);
        return ['period' => [$from, $to], 'created' => $created, 'skipped' => $skipped];
    }

    public static function find(int $id): ?array
    {
        return DB::one(
            'SELECT i.*, h.name AS hotel_name, h.reseller_id, h.contact_email, h.contact_phone, h.status AS hotel_status
             FROM invoices i JOIN hotels h ON h.id = i.hotel_id WHERE i.id = :id',
            ['id' => $id]
        );
    }

    /** Record a payment; reactivates a hotel that was auto-suspended for non-payment. */
    public static function markPaid(int $id, string $ref, ?string $paidAt = null, string $method = ''): void
    {
        $inv = self::find($id);
        if (!$inv || $inv['status'] === 'cancelled') {
            throw new InvalidArgumentException(__('Invoice not found.'));
        }
        $ts = $paidAt ? strtotime($paidAt) : time();
        DB::update('invoices', [
            'status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s', $ts ?: time()),
            'payment_ref' => mb_substr(trim($ref), 0, 120) ?: null,
            'payment_method' => mb_substr(trim($method), 0, 40) ?: null,
        ], 'id = :id', ['id' => $id]);
        self::reactivateIfPaid((int) $inv['hotel_id']);
    }

    public static function cancel(int $id): void
    {
        $inv = self::find($id);
        if (!$inv || $inv['status'] === 'paid') {
            throw new InvalidArgumentException(__('Only unpaid invoices can be cancelled.'));
        }
        DB::update('invoices', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
        self::reactivateIfPaid((int) $inv['hotel_id']);
    }

    /** Days an unpaid invoice is past its due date (0 when not overdue). */
    public static function overdueDays(array $inv, ?int $now = null): int
    {
        if ($inv['status'] !== 'unpaid') {
            return 0;
        }
        $now ??= time();
        $due = strtotime($inv['due_date'] . ' 23:59:59');
        return $due < $now ? (int) floor(($now - $due) / 86400) + 1 : 0;
    }

    /** Unpaid invoices of a hotel overdue by more than the auto-suspend threshold. */
    private static function seriouslyOverdue(int $hotelId): int
    {
        $days = (int) Settings::platform('auto_suspend_days', '15');
        if ($days <= 0) {
            return 0;
        }
        return (int) DB::value(
            "SELECT COUNT(*) FROM invoices WHERE hotel_id = :h AND status = 'unpaid' AND due_date < :d",
            ['h' => $hotelId, 'd' => date('Y-m-d', strtotime('-' . $days . ' days'))]
        );
    }

    /** Auto-reactivate a hotel suspended for non-payment once nothing is seriously overdue. */
    public static function reactivateIfPaid(int $hotelId): bool
    {
        // Free trial (#17): a paid upgrade invoice converts the trial hotel to its paid plan.
        if (class_exists('Signup') && Signup::activatePaidUpgrade($hotelId)) {
            return true;
        }
        $h = DB::one('SELECT status, suspend_reason FROM hotels WHERE id = :id', ['id' => $hotelId]);
        if ($h && $h['status'] === 'suspended' && $h['suspend_reason'] === 'billing' && self::seriouslyOverdue($hotelId) === 0) {
            Hotels::setStatus($hotelId, 'active');
            Logger::write('billing', 'info', 'Hotel reactivated after payment', ['hotel' => $hotelId]);
            return true;
        }
        return false;
    }

    /**
     * Daily: send overdue reminders (every reminder_every_days) and auto-suspend hotels whose
     * invoices are more than auto_suspend_days overdue. Returns counters.
     */
    public static function processOverdue(?int $now = null): array
    {
        $now ??= time();
        $out = ['reminded' => 0, 'suspended' => 0];
        $every = max(1, (int) Settings::platform('reminder_every_days', '3'));
        $channels = array_values(array_filter([
            Settings::platform('reminder_email', '1') === '1' ? 'email' : null,
            Settings::platform('reminder_whatsapp', '0') === '1' ? 'whatsapp' : null,
        ]));
        $rows = DB::all(
            "SELECT i.*, h.name AS hotel_name, h.contact_email, h.contact_phone, h.status AS hotel_status
             FROM invoices i JOIN hotels h ON h.id = i.hotel_id
             WHERE i.status = 'unpaid' AND i.due_date < :today ORDER BY i.id",
            ['today' => date('Y-m-d', $now)]
        );
        $brand = Branding::get(0)['product'];
        foreach ($rows as $inv) {
            $days = self::overdueDays($inv, $now);
            if ($channels && (!$inv['last_reminder_at'] || strtotime((string) $inv['last_reminder_at']) <= $now - $every * 86400 + 3600)) {
                $msg = sprintf(
                    "Dear %s,\n\nInvoice %s for %s – %s (%s) was due on %s and is now %d day(s) overdue. "
                    . "Please pay to avoid interruption of the TV service.\n\nThank you,\n%s",
                    $inv['hotel_name'], $inv['number'], date('d M Y', (int) strtotime($inv['period_from'])), date('d M Y', (int) strtotime($inv['period_to'])),
                    money($inv['total'], $inv['currency']), date('d M Y', (int) strtotime($inv['due_date'])), $days, $brand
                );
                Notifier::sendToContact((string) $inv['contact_email'], (string) $inv['contact_phone'], $brand . ': payment reminder ' . $inv['number'], $msg, $channels);
                DB::query('UPDATE invoices SET reminders_sent = reminders_sent + 1, last_reminder_at = :n WHERE id = :id', ['n' => date('Y-m-d H:i:s', $now), 'id' => $inv['id']]);
                $out['reminded']++;
            }
        }
        $limit = (int) Settings::platform('auto_suspend_days', '15');
        if ($limit > 0) {
            $cut = date('Y-m-d', $now - $limit * 86400);
            $hotels = DB::column(
                "SELECT DISTINCT i.hotel_id FROM invoices i JOIN hotels h ON h.id = i.hotel_id
                 WHERE i.status = 'unpaid' AND i.due_date < :cut AND h.status = 'active'",
                ['cut' => $cut]
            );
            foreach ($hotels as $hid) {
                Hotels::setStatus((int) $hid, 'suspended', 'billing');
                $h = Hotels::find((int) $hid);
                if ($h && $channels) {
                    Notifier::sendToContact((string) $h['contact_email'], (string) $h['contact_phone'], $brand . ': service suspended',
                        sprintf("Dear %s,\n\nYour TV service has been paused because of unpaid invoices. It is reactivated automatically as soon as the payment is recorded.\n\n%s", $h['name'], $brand), $channels);
                }
                $out['suspended']++;
            }
            $admin = trim((string) Settings::platform('platform_notify_email', ''));
            if ($out['suspended'] && $admin !== '') {
                Notifier::email($admin, $brand . ': ' . $out['suspended'] . ' hotel(s) auto-suspended', 'Hotels auto-suspended for overdue invoices: ' . implode(', ', $hotels), (string) Settings::platform('platform_from_email', ''), $brand);
            }
        }
        return $out;
    }

    /**
     * Reseller commission report: paid invoices of the reseller's hotels × commission %.
     * Returns ['percent', 'paid_total', 'commission', 'rows' => per-hotel totals].
     */
    public static function commission(int $resellerId, ?string $from = null, ?string $to = null): array
    {
        $pct = (float) DB::value('SELECT commission_percent FROM resellers WHERE id = :id', ['id' => $resellerId]);
        $where = "h.reseller_id = :r AND i.status = 'paid'";
        $p = ['r' => $resellerId];
        if ($from) {
            $where .= ' AND i.paid_at >= :f';
            $p['f'] = $from . ' 00:00:00';
        }
        if ($to) {
            $where .= ' AND i.paid_at <= :t';
            $p['t'] = $to . ' 23:59:59';
        }
        $rows = DB::all(
            "SELECT h.id, h.name, COUNT(i.id) AS invoices, COALESCE(SUM(i.amount), 0) AS amount, COALESCE(SUM(i.total), 0) AS total
             FROM invoices i JOIN hotels h ON h.id = i.hotel_id WHERE $where GROUP BY h.id, h.name ORDER BY h.name",
            $p
        );
        $paid = 0.0;
        foreach ($rows as &$r) {
            // Commission on the net amount (without tax).
            $r['commission'] = round((float) $r['amount'] * $pct / 100, 2);
            $paid += (float) $r['amount'];
        }
        unset($r);
        return ['percent' => $pct, 'paid_total' => round($paid, 2), 'commission' => round($paid * $pct / 100, 2), 'rows' => $rows];
    }

    public static function statusBadge(array $inv): string
    {
        if ($inv['status'] === 'unpaid' && self::overdueDays($inv) > 0) {
            return '<span class="badge text-bg-danger">' . e(__('Overdue')) . '</span>';
        }
        return match ($inv['status']) {
            'paid' => '<span class="badge text-bg-success">' . e(__('Paid')) . '</span>',
            'cancelled' => '<span class="badge text-bg-secondary">' . e(__('Cancelled')) . '</span>',
            default => '<span class="badge text-bg-warning">' . e(__('Unpaid')) . '</span>',
        };
    }
}
