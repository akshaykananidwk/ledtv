<?php
declare(strict_types=1);
/**
 * TV simulator: renders a Content object the way the Android app does.
 *   preview.php?content_id=N | ?playlist_id=N | ?room_id=N   (&embed=1 hides the toolbar)
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('content.view');
$contentId = req_int('content_id', $_GET);
$playlistId = req_int('playlist_id', $_GET);
$roomId = req_int('room_id', $_GET);
if ($roomId) {
    require_can('rooms.view');
}
$obj = hc_preview_object($contentId, $playlistId, $roomId);
if (!$obj) {
    http_response_code(404);
    flash('warning', __('Nothing to preview.'));
    redirect(admin_url('index.php'));
}
$embed = !empty($_GET['embed']);
if ($roomId) {
    $label = __('Screen') . ' ' . $obj['room']['number'];
} elseif ($playlistId) {
    $label = __('Playlist') . ': ' . ($obj['playlist']['name'] ?? '');
} else {
    $label = $obj['items'][0]['title'] ?? __('Preview');
}
$refreshParams = array_filter(['content_id' => $contentId, 'playlist_id' => $playlistId, 'room_id' => $roomId]);
header('Cache-Control: no-store');
// Room previews follow the live content (like the TV's polling).
$simRefreshUrl = $roomId ? admin_url('ajax.php', ['action' => 'preview_content'] + $refreshParams) : null;
$simCloseUrl = admin_url('index.php');
require __DIR__ . '/partials/tv_simulator.php';
