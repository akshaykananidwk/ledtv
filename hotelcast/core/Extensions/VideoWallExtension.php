<?php
declare(strict_types=1);

/**
 * Video walls (2.4, #36): a room that is a tile of an active wall gets the wall's content, the `wall`
 * object (rows, cols, its row / col, bezel compensation, audio) and the synced schedule `sync`.
 * Runs after the sponsor ads (100) and the ticker (200), so it can remove both from the wall picture.
 * Emergency, off, suspended and scheduled broadcasts are never replaced. See core/VideoWalls.php.
 */
final class VideoWallExtension implements ContentExtension
{
    public const PRIORITY = 250;

    public function apply(array &$content, array $room): void
    {
        VideoWalls::applyToContent($content, $room);
    }
}
