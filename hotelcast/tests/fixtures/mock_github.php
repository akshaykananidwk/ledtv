<?php
/**
 * Minimal GitHub REST API mock for updater tests (run with `php -S`).
 * State lives in the directory given by env MOCK_GH_DIR:
 *   state.json  {"sha": "...", "version": "1.1.0", "message": "..."}
 *   package.zip zipball returned for /zipball/{sha}
 */
$dir = getenv('MOCK_GH_DIR');
$state = json_decode((string) @file_get_contents($dir . '/state.json'), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

if (preg_match('#^/repos/([^/]+)/([^/]+)/commits/([^/]+)$#', $path, $m)) {
    echo json_encode([
        'sha' => $state['sha'],
        'html_url' => 'https://github.com/' . $m[1] . '/' . $m[2] . '/commit/' . $state['sha'],
        'commit' => ['message' => $state['message'], 'author' => ['name' => 'Test Bot', 'date' => '2026-10-05T10:00:00Z'], 'committer' => ['date' => '2026-10-05T10:00:00Z']],
    ]);
    return;
}
if (preg_match('#^/repos/[^/]+/[^/]+/contents/(.+)$#', $path, $m)) {
    if (!str_ends_with($m[1], 'version.json')) {
        http_response_code(404);
        echo '{"message":"Not Found"}';
        return;
    }
    echo json_encode(['content' => base64_encode(json_encode(['version' => $state['version']]))]);
    return;
}
if (preg_match('#^/repos/[^/]+/[^/]+/compare/#', $path)) {
    echo json_encode(['commits' => [['sha' => $state['sha'], 'commit' => ['message' => $state['message'], 'author' => ['name' => 'Test Bot', 'date' => '2026-10-05T10:00:00Z']]]],
        'files' => [['filename' => 'hotelcast/version.json', 'status' => 'modified'], ['filename' => 'android/x.kt', 'status' => 'modified']]]);
    return;
}
if (preg_match('#^/repos/[^/]+/[^/]+/zipball/#', $path)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-token') {
        http_response_code(401);
        echo '{"message":"Bad credentials"}';
        return;
    }
    header('Content-Type: application/zip');
    readfile($dir . '/package.zip');
    return;
}
http_response_code(404);
echo '{"message":"Not Found"}';
