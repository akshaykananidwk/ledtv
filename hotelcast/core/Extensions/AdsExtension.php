<?php
declare(strict_types=1);

/**
 * Sponsor ads (#11): inserts the ad items of eligible campaigns into the room's playlist, or turns a
 * single item into a rotation [main item for M minutes, ad]. All rules live in Ads::apply()
 * (modes, date range, daily window, targeting, daily cap per TV, frequency, priority).
 */
final class AdsExtension implements ContentExtension
{
    public function apply(array &$content, array $room): void
    {
        Ads::apply($content, $room);
    }
}
