<?php
/** Sidebar item: Photo albums (#19, display app "photo_album") right after the notice board. */
declare(strict_types=1);

return [
    ['albums', 'album_upload.php', 'albums.manage', 'bi-images', __('Photo albums'), 'hotel', ['after' => 'notices']],
];
