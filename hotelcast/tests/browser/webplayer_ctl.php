<?php
/**
 * Web player browser test (2.4, #45) — changes data in a running webplayer_server.php sandbox.
 *
 *   php tests/browser/webplayer_ctl.php <sandbox root> emergency_on|emergency_off
 *   php tests/browser/webplayer_ctl.php <sandbox root> command <device uid> <COMMAND> '<payload json>'  → prints the command id
 *   php tests/browser/webplayer_ctl.php <sandbox root> status <command id>                            → prints {status, message}
 *   php tests/browser/webplayer_ctl.php <sandbox root> device <device uid>                            → prints the device row
 */
declare(strict_types=1);

define('HC_TESTING', true);
$root = (string) ($argv[1] ?? '');
if (!is_file($root . '/core/bootstrap.php') || !str_contains($root, 'hotelcast_sandbox_webplayer_')) {
    fwrite(STDERR, "usage: webplayer_ctl.php <sandbox root> <action> …\n");
    exit(2);
}
require $root . '/core/bootstrap.php';
Tenant::set(1);
$action = (string) ($argv[2] ?? '');
switch ($action) {
    case 'emergency_on':
        echo Broadcaster::emergencyStart('Fire drill', 'Please use the stairs. Do not use the lift. / કૃપા કરીને સીડીનો ઉપયોગ કરો.', 'all', []), "\n";
        break;
    case 'emergency_off':
        echo Broadcaster::emergencyStop(), "\n";
        break;
    case 'command':
        $dev = DB::one('SELECT id FROM devices WHERE hotel_id = 1 AND device_uid = :u', ['u' => (string) ($argv[3] ?? '')]);
        if (!$dev) {
            fwrite(STDERR, "device not found\n");
            exit(1);
        }
        echo DB::insert('device_commands', ['device_id' => $dev['id'], 'command' => strtoupper((string) ($argv[4] ?? 'PING')),
            'payload' => (string) ($argv[5] ?? '{}'), 'status' => 'pending', 'created_at' => now()]), "\n";
        break;
    case 'status':
        echo json_encode(DB::one('SELECT status, message FROM device_commands WHERE id = :id', ['id' => (int) ($argv[3] ?? 0)]), JSON_UNESCAPED_UNICODE), "\n";
        break;
    case 'device':
        echo json_encode(DB::one('SELECT device_uid, platform, app_version, app_version_code, android_version, model, user_agent, screen_on, current_hash FROM devices WHERE hotel_id = 1 AND device_uid = :u', ['u' => (string) ($argv[3] ?? '')]), JSON_UNESCAPED_UNICODE), "\n";
        break;
    default:
        fwrite(STDERR, "unknown action\n");
        exit(2);
}
