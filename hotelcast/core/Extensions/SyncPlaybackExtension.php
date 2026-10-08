<?php
declare(strict_types=1);

/**
 * Synchronized playback (2.4, #37): content that plays a playlist marked "Sync playback" gets the
 * deterministic schedule `sync` {epoch_ms, cycle_ms, item_offsets_ms}. Runs after the sponsor ads
 * (PRIORITY 150 > 100) so the schedule covers exactly the items the TV plays. See core/SyncPlayback.php.
 */
final class SyncPlaybackExtension implements ContentExtension
{
    public const PRIORITY = 150;

    public function apply(array &$content, array $room): void
    {
        SyncPlayback::applyToContent($content);
    }
}
