<?php
declare(strict_types=1);

/**
 * Gold & silver rates of a hotel / jeweller (#21), table `metal_rates` (migrations/019_data_feeds.sql).
 *
 * Manual mode (default): the admin types today's rates on admin/rates.php (mobile friendly). Every save
 * is a new row; the newest row is the current rate, the rows before it are the change history (the
 * TV shows ▲ / ▼ against the previous row).
 *
 * Auto mode (hotel setting rates_mode = auto): indicative rates from the GoldAPI.io feed (USD per gram,
 * DataFeeds 'goldapi') converted to INR with the currency feed, plus the hotel's duty % and markup %
 * (rates_duty_pct, rates_markup_pct) so the price approximates local rates. 22K = 22/24, 18K = 18/24 of
 * 24K. Falls back to the manual rates while no auto value is available.
 */
final class MetalRates
{
    public const FIELDS = ['gold_24k', 'gold_22k', 'gold_18k', 'silver_kg'];
    public const MAX_RATE = 100000000;
    public const HISTORY = 20;
    private const OZ = 31.1034768;

    /** Field labels (translated). */
    public static function labels(): array
    {
        return [
            'gold_24k' => __('Gold 24K (10 g)'),
            'gold_22k' => __('Gold 22K (10 g)'),
            'gold_18k' => __('Gold 18K (10 g)'),
            'silver_kg' => __('Silver (1 kg)'),
        ];
    }

    /** @return array{0: array, 1: string[]} */
    public static function validate(array $in): array
    {
        $errors = [];
        $data = [];
        $labels = self::labels();
        foreach (self::FIELDS as $f) {
            $raw = is_scalar($in[$f] ?? null) ? trim((string) $in[$f]) : '';
            if ($raw === '') {
                $data[$f] = null;
                continue;
            }
            $v = DataFeeds::number($raw);
            if ($v === null || $v < 0 || $v > self::MAX_RATE) {
                $errors[] = __(':f: enter a valid amount.', ['f' => $labels[$f]]);
                $data[$f] = null;
                continue;
            }
            $data[$f] = round($v, 2);
        }
        if (!$errors && count(array_filter($data, static fn ($v) => $v !== null)) === 0) {
            $errors[] = __('Enter at least one rate.');
        }
        $data['note'] = is_scalar($in['note'] ?? null) ? mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $in['note']) ?? ''), 0, 255) : '';
        return [$data, $errors];
    }

    public static function save(array $data): int
    {
        $row = array_intersect_key($data, array_flip([...self::FIELDS, 'note'])) + ['created_by' => Auth::id(), 'created_at' => now()];
        if (($row['note'] ?? '') === '') {
            $row['note'] = null;
        }
        $id = DB::insert('metal_rates', $row);
        DataFeeds::flush();
        Settings::bumpContentVersion(); // ticker placeholders {gold_24k} …
        return $id;
    }

    /** A history row of the current hotel (another hotel's id → null). */
    public static function find(int $id): ?array
    {
        return $id > 0 ? Tenant::find('metal_rates', $id) : null;
    }

    public static function delete(int $id): void
    {
        DB::delete('metal_rates', 'id = :id', ['id' => $id]);
        DataFeeds::flush();
        Settings::bumpContentVersion();
    }

    /** Newest rows first. */
    public static function history(int $limit = self::HISTORY): array
    {
        try {
            return DB::all('SELECT r.*, u.username FROM metal_rates r LEFT JOIN users u ON u.id = r.created_by WHERE r.hotel_id = :h ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limit)), ['h' => Tenant::id()]);
        } catch (PDOException $e) {
            Logger::error('MetalRates: ' . $e->getMessage());
            return [];
        }
    }

    public static function mode(): string
    {
        return Settings::get('rates_mode', 'manual') === 'auto' ? 'auto' : 'manual';
    }

    public static function dutyPct(): float
    {
        return max(0.0, min(100.0, (float) Settings::get('rates_duty_pct', '15')));
    }

    public static function markupPct(): float
    {
        return max(0.0, min(100.0, (float) Settings::get('rates_markup_pct', '3')));
    }

    /**
     * Current rates of the hotel:
     * ['mode', 'source' manual|auto, 'values' => [field => ?float], 'prev' => [field => ?float], 'note',
     *  'updated_at' (unix|null), 'indicative' (auto), 'stale', 'status'].
     */
    public static function current(): array
    {
        $out = ['mode' => self::mode(), 'source' => 'manual', 'values' => array_fill_keys(self::FIELDS, null), 'prev' => array_fill_keys(self::FIELDS, null),
            'note' => '', 'updated_at' => null, 'indicative' => false, 'stale' => false, 'status' => 'ok'];
        if ($out['mode'] === 'auto') {
            $auto = self::auto();
            if ($auto !== null) {
                return array_merge($out, $auto, ['note' => (string) Settings::get('rates_auto_note', '')]);
            }
            $out['status'] = DataFeeds::hasKey('goldapi') ? 'pending' : 'no_key';
        }
        $rows = self::history(2);
        if ($rows) {
            foreach (self::FIELDS as $f) {
                $out['values'][$f] = $rows[0][$f] !== null ? (float) $rows[0][$f] : null;
                $out['prev'][$f] = isset($rows[1]) && $rows[1][$f] !== null ? (float) $rows[1][$f] : null;
            }
            $out['note'] = (string) ($rows[0]['note'] ?? '');
            $out['updated_at'] = strtotime((string) $rows[0]['created_at']) ?: null;
        } elseif ($out['mode'] === 'manual') {
            $out['status'] = 'empty';
        }
        return $out;
    }

    /** Auto (indicative) rates from the metals + currency feeds, or null when not available. */
    public static function auto(): ?array
    {
        if (!DataFeeds::hasKey('goldapi')) {
            return null;
        }
        $metals = DataFeeds::get('goldapi');
        $cur = DataFeeds::currency();
        $usd = $cur['data']['rates']['USD'] ?? null;
        $d = $metals['data'];
        if (!is_array($d) || !is_numeric($usd) || !is_numeric($d['gold_usd_g'] ?? null)) {
            return null;
        }
        $factor = (float) $usd * (1 + self::dutyPct() / 100) * (1 + self::markupPct() / 100);
        $calc = static function (?float $goldG, ?float $silverG) use ($factor): array {
            return [
                'gold_24k' => $goldG !== null ? round($goldG * 10 * $factor) : null,
                'gold_22k' => $goldG !== null ? round($goldG * 10 * $factor * 22 / 24) : null,
                'gold_18k' => $goldG !== null ? round($goldG * 10 * $factor * 18 / 24) : null,
                'silver_kg' => $silverG !== null ? round($silverG * 1000 * $factor) : null,
            ];
        };
        $num = static fn ($v) => is_numeric($v) ? (float) $v : null;
        return [
            'source' => 'auto',
            'values' => $calc($num($d['gold_usd_g'] ?? null), $num($d['silver_usd_g'] ?? null)),
            'prev' => $calc($num($d['gold_prev_usd_g'] ?? null), $num($d['silver_prev_usd_g'] ?? null)),
            'updated_at' => $metals['as_of'],
            'indicative' => true,
            'stale' => $metals['stale'] || $cur['stale'],
            'status' => $metals['stale'] || $cur['stale'] ? 'stale' : 'ok',
        ];
    }

    /** 'up' | 'down' | '' comparing a value with its previous one. */
    public static function trend(?float $now, ?float $prev): string
    {
        if ($now === null || $prev === null || abs($now - $prev) < 0.005) {
            return '';
        }
        return $now > $prev ? 'up' : 'down';
    }
}
