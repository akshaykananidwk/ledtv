<?php
/**
 * Auto-update / backup AJAX actions (super admin only).
 * Reached directly or via admin/ajax.php for actions update_* and rollback.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

$user = Auth::require('update.manage');
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

/** JSON response helper (local to this file). */
$respond = static function (array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_out($data);
    exit;
};

// Download is a GET with token in query (opened in a new tab).
if ($action === 'update_backup_download') {
    if (!Csrf::valid((string) ($_GET['token'] ?? ''))) {
        http_response_code(419);
        exit('Security token expired — reload the page.');
    }
    try {
        $path = Backup::path((string) ($_GET['name'] ?? ''));
    } catch (Throwable $e) {
        http_response_code(404);
        exit('Backup not found');
    }
    ActivityLog::add('backup_download', 'backup', null, basename($path));
    session_write_close();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && $action !== 'update_status') {
    $respond(['ok' => false, 'error' => ['code' => 'METHOD', 'message' => 'POST required']], 405);
}
if ($action !== 'update_status') {
    Csrf::check();
}
$in = request_json();

try {
    switch ($action) {
        case 'update_check':
            session_write_close();
            $respond(['ok' => true, 'data' => Updater::check()]);

        case 'update_run':
            // Release the session lock so update_status polling works while this runs.
            session_write_close();
            $u = new Updater();
            $result = $u->run((int) $user['id']);
            $respond(['ok' => $result['ok'], 'data' => $result]);

        case 'update_status':
            session_write_close();
            $row = DB::one('SELECT id, status, to_version, log, started_at FROM update_history ORDER BY id DESC LIMIT 1');
            $running = is_file(HC_ROOT . '/storage/update.lock');
            $respond(['ok' => true, 'data' => ['running' => $running, 'row' => $row]]);

        case 'update_health':
            session_write_close();
            $respond(['ok' => true, 'data' => HealthCheck::full(true) + ['report' => HealthCheck::report()]]);

        case 'rollback':
            session_write_close();
            $name = '';
            if (!empty($in['history_id'])) {
                $name = (string) DB::value('SELECT backup_file FROM update_history WHERE id = :id', ['id' => (int) $in['history_id']]);
            } elseif (!empty($in['backup'])) {
                $name = (string) $in['backup'];
            }
            if ($name === '') {
                $respond(['ok' => false, 'error' => ['code' => 'NO_BACKUP', 'message' => 'No backup file is linked to this entry.']], 400);
            }
            Backup::path($name); // validates
            $result = Updater::rollback($name, (int) $user['id'], !isset($in['restore_db']) || (bool) $in['restore_db']);
            $respond(['ok' => $result['ok'], 'data' => $result]);

        case 'update_backup_create':
            session_write_close();
            @set_time_limit(0);
            $name = Backup::create('manual', !empty($in['include_uploads']));
            ActivityLog::add('backup_create', 'backup', null, $name);
            Backup::prune(max(2, Settings::int('backup_keep', 10)) + 5);
            $respond(['ok' => true, 'data' => ['name' => $name]]);

        case 'update_backup_delete':
            $name = (string) ($in['name'] ?? '');
            Backup::delete($name);
            ActivityLog::add('backup_delete', 'backup', null, basename($name));
            $respond(['ok' => true, 'data' => ['deleted' => basename($name)]]);

        case 'update_backup_upload':
            if (empty($_FILES['backup']) || ($_FILES['backup']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new RuntimeException(__('Upload failed. Check the file size limit (:s).', ['s' => ini_get('upload_max_filesize')]));
            }
            $tmp = $_FILES['backup']['tmp_name'];
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true || $zip->locateName('database.sql') === false) {
                throw new RuntimeException(__('This is not a Krishna Cloud TV Management backup file (database.sql missing).'));
            }
            $zip->close();
            $name = 'backup_uploaded_' . date('Y-m-d_H-i-s') . '.zip';
            if (!move_uploaded_file($tmp, Backup::dir() . '/' . $name)) {
                throw new RuntimeException(__('Could not save the uploaded file. Check folder permissions.'));
            }
            ActivityLog::add('backup_upload', 'backup', null, $name);
            $respond(['ok' => true, 'data' => ['name' => $name]]);
    }
    $respond(['ok' => false, 'error' => ['code' => 'UNKNOWN_ACTION', 'message' => 'Unknown action']], 404);
} catch (Throwable $e) {
    Logger::write('update', 'error', $action . ': ' . $e->getMessage());
    $respond(['ok' => false, 'error' => ['code' => 'ERROR', 'message' => $e->getMessage()]], 500);
}
