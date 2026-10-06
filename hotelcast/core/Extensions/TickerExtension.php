<?php
declare(strict_types=1);

/**
 * Ticker bar (2.2): sets content.overlay.ticker from Tickers::forRoom() (room / group / all tickers
 * plus the legacy `ticker_text` setting). Runs last (PRIORITY 200) so it sees the final mode after
 * other extensions (e.g. the guests module switching a vacant room off).
 *
 * No ticker (null) when the hotel is suspended, the screen is off or an emergency is shown full
 * screen. Normal, scheduled, default and welcome (empty) screens keep it.
 */
final class TickerExtension implements ContentExtension
{
    public const PRIORITY = 200;
    private const NO_TICKER_MODES = ['suspended', 'off', 'emergency'];

    public function apply(array &$content, array $room): void
    {
        if (!isset($content['overlay']) || !is_array($content['overlay'])) {
            $content['overlay'] = [];
        }
        $mode = (string) ($content['mode'] ?? '');
        if (in_array($mode, self::NO_TICKER_MODES, true) || ($content['screen_on'] ?? true) === false || empty($room['id'])) {
            $content['overlay']['ticker'] = null;
            return;
        }
        $content['overlay']['ticker'] = Tickers::forRoom($room);
    }
}
