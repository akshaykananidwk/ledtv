<?php
declare(strict_types=1);

/**
 * Hook into the TV Content object. Every class in core/Extensions/*.php implementing this
 * interface (class name = file name) is called by ContentResolver after the content of a room
 * has been decided, before the hash is computed. Runs with the room's hotel as Tenant.
 *
 * Example (core/Extensions/GuestWelcomeExtension.php):
 *   final class GuestWelcomeExtension implements ContentExtension {
 *       public function apply(array &$content, array $room): void { $content['guest'] = …; }
 *   }
 * Keep it fast (it runs on TV polls when the cache is cold) and deterministic (same input →
 * same output), otherwise TVs re-download content on every poll.
 */
interface ContentExtension
{
    public function apply(array &$content, array $room): void;
}
