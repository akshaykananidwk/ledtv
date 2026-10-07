<?php
declare(strict_types=1);

/**
 * Base class of the data feed widgets (#21–#25: GoldRatesApp, MarketApp, CricketApp, CurrencyApp,
 * TravelStatusApp). The page = header (heading, sub-heading, clock) + a body (#dfBody) that body()
 * renders on the server; live refresh sends the same HTML as data.html and assets/display/feeds.js
 * swaps it in (no client-side templating, everything escaped here with e()).
 * Values come from DataFeeds (stored feed values; the first use may fetch inline once) — never from the
 * TV, and no API key is ever part of the page or the data JSON.
 */
abstract class DataFeedApp extends DisplayApp
{
    public function category(): string
    {
        return 'widget';
    }

    /** HTML inside #dfBody (also the live data). */
    abstract protected function body(array $config, array $ctx): string;

    public function render(array $config, array $ctx): string
    {
        $sub = (string) ($config['subtitle'] ?? '');
        return '<link rel="stylesheet" href="' . e(asset('display/feeds.css')) . '">'
            . '<div class="hc-header"><div class="hc-title"><h1>' . e((string) ($config['heading'] ?? '')) . '</h1>'
            . ($sub !== '' ? '<div class="hc-subtitle">' . e($sub) . '</div>' : '') . '</div>'
            . '<div><div class="hc-clock" data-hc-clock="12"></div><div class="hc-date" data-hc-date></div></div></div>'
            . '<div class="hc-body df-body" id="dfBody">' . $this->body($config, $ctx) . '</div>'
            . '<script src="' . e(asset('display/feeds.js')) . '"></script>';
    }

    public function data(array $config, array $ctx): ?array
    {
        return ['html' => $this->body($config, $ctx)];
    }

    /** Footer line: "Updated 7 Oct, 6:05 PM · Indicative · Last known value". */
    protected static function foot(?int $asOf, bool $stale, string $label = '', string $source = ''): string
    {
        $parts = [];
        if ($asOf) {
            $parts[] = '<span>' . e(__('Updated :t', ['t' => DataFeeds::asOf($asOf)])) . '</span>';
        }
        if ($label !== '') {
            $parts[] = '<span class="df-tag">' . e($label) . '</span>';
        }
        if ($stale) {
            $parts[] = '<span class="df-tag df-stale">' . e(__('Last known value')) . '</span>';
        }
        if ($source !== '') {
            $parts[] = '<span>' . e(__('Source: :s', ['s' => $source])) . '</span>';
        }
        return $parts ? '<div class="df-foot">' . implode('<span class="df-sep">·</span>', $parts) . '</div>' : '';
    }

    protected static function empty(string $text): string
    {
        return '<div class="hc-center"><div class="hc-message">' . e($text) . '</div></div>';
    }

    /** ▲ / ▼ arrow span for a trend 'up' | 'down' | ''. */
    protected static function arrow(string $trend): string
    {
        return match ($trend) {
            'up' => '<span class="df-up">▲</span>',
            'down' => '<span class="df-down">▼</span>',
            default => '<span class="df-flat">•</span>',
        };
    }

    /** Float from form input within [min, max] (default when empty / invalid). */
    protected static function float(array $in, string $key, float $min, float $max, float $default): float
    {
        $v = DataFeeds::number(is_scalar($in[$key] ?? null) ? (string) $in[$key] : '');
        return $v === null ? $default : max($min, min($max, $v));
    }
}
