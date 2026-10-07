<?php
declare(strict_types=1);

/**
 * Gold & silver rates (#21): jeweller-style board with 24K / 22K / 18K per 10 g and silver per kg,
 * ▲ / ▼ against the previous rate, the making-charge note and the "updated at" time.
 * Rates come from MetalRates: typed on admin/rates.php (manual, default) or indicative auto rates
 * (GoldAPI.io + currency feed + duty / markup %), labelled "Indicative".
 */
final class GoldRatesApp extends DataFeedApp
{
    public function key(): string
    {
        return 'gold_rates';
    }

    public function label(): string
    {
        return __('Gold & silver rates');
    }

    public function description(): string
    {
        return __('Today\'s gold (24K, 22K, 18K) and silver rates on a big jeweller board with up / down arrows. Update them from your phone.');
    }

    public function icon(): string
    {
        return 'bi-gem';
    }

    public function category(): string
    {
        return 'business';
    }

    public function adminPage(): ?string
    {
        return admin_url('rates.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Today\'s gold & silver rates'),
            'subtitle' => '',
            'show' => MetalRates::FIELDS,
            'show_note' => true,
            'show_updated' => true,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $show = self::multi($in, 'show', MetalRates::FIELDS);
        if (!$show) {
            $errors[] = __('Choose at least one rate to show.');
            $show = MetalRates::FIELDS;
        }
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'show' => $show,
            'show_note' => self::bool($in, 'show_note'),
            'show_updated' => self::bool($in, 'show_updated'),
        ], $errors];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190], __('e.g. your shop name or "Rates incl. GST"'))
            . self::checkboxes('show', __('Rates on the board'), MetalRates::labels(), (array) $config['show'])
            . self::checkbox('show_note', __('Show the note (e.g. making charges)'), (bool) $config['show_note'])
            . self::checkbox('show_updated', __('Show when the rates were updated'), (bool) $config['show_updated'])
            . '<div class="col-12"><a class="btn btn-sm btn-outline-primary" href="' . e(admin_url('rates.php')) . '"><i class="bi bi-pencil-square"></i> ' . e(__('Enter today\'s rates')) . '</a></div>';
    }

    public function refreshSec(array $config): int
    {
        return 60;
    }

    /** Board labels: field => [name, unit]. */
    private static function names(): array
    {
        return [
            'gold_24k' => [__('Gold 24K'), __('per 10 g')],
            'gold_22k' => [__('Gold 22K'), __('per 10 g')],
            'gold_18k' => [__('Gold 18K'), __('per 10 g')],
            'silver_kg' => [__('Silver'), __('per kg')],
        ];
    }

    protected function body(array $config, array $ctx): string
    {
        $r = MetalRates::current();
        $names = self::names();
        $rows = '';
        foreach ((array) $config['show'] as $f) {
            if (!isset($names[$f])) {
                continue;
            }
            $v = $r['values'][$f];
            $trend = MetalRates::trend($v, $r['prev'][$f]);
            $rows .= '<div class="gr-row hc-card' . (str_starts_with($f, 'silver') ? ' gr-silver' : ' gr-gold') . '">'
                . '<div class="gr-name"><b>' . e($names[$f][0]) . '</b><span>' . e($names[$f][1]) . '</span></div>'
                . '<div class="gr-price">' . ($v === null ? '—' : '₹ ' . e(DataFeeds::inr($v, 0))) . ' ' . self::arrow($trend) . '</div></div>';
        }
        $hasAny = count(array_filter(array_intersect_key($r['values'], array_flip((array) $config['show'])), static fn ($v) => $v !== null)) > 0;
        if (!$hasAny) {
            return self::empty(__('Rates will appear here as soon as they are entered.'));
        }
        $note = $config['show_note'] && $r['note'] !== '' ? '<div class="gr-note">' . e($r['note']) . '</div>' : '';
        return '<div class="gr-board">' . $rows . '</div>' . $note
            . self::foot($config['show_updated'] ? $r['updated_at'] : null, (bool) $r['stale'], $r['indicative'] ? __('Indicative rates') : '');
    }
}
