<?php
/**
 * 2.4: video walls (#36) and synchronized playback (#37) — core/VideoWalls.php, core/SyncPlayback.php,
 * admin/video_walls.php, migrations/024_video_walls.sql, docs/modules/video_wall_sync.md.
 */
declare(strict_types=1);

Tenant::registerTable('video_walls');
Tenant::registerTable('video_wall_tiles');

// Create / edit walls, assign TVs, identify (manager+, like groups and playlists). Users limited to some
// TVs (Access) only see and change walls made of their own TVs.
Auth::registerPermission('video_walls.manage', 'manager');
