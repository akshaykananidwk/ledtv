#!/usr/bin/env php
<?php
/**
 * Recovery tool: create (or repair) a Super Admin (platform_admin) login from the command line.
 * Use it when the last super admin user was deleted or locked out and the panel can no longer be reached.
 *
 *   php bin/make_super_admin.php --email=you@example.com --password='NewStrongPass123' [--name="Your Name"]
 *
 * Without --password the password is asked interactively (not stored in the shell history).
 * An existing user with that email is converted to platform_admin, activated and unlocked; the password
 * is replaced. Runs only from the CLI on an installed copy (config.php present).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../core/bootstrap.php';

$opts = getopt('', ['email:', 'password::', 'name::', 'username::']);
$email = strtolower(trim((string) ($opts['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/make_super_admin.php --email=you@example.com [--password=...] [--name=\"Full Name\"]\n");
    exit(1);
}
$password = (string) ($opts['password'] ?? '');
if ($password === '') {
    fwrite(STDOUT, "New password (min 8 characters): ");
    $password = trim((string) fgets(STDIN));
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
$name = trim((string) ($opts['name'] ?? '')) ?: 'Super Admin';
$username = preg_replace('/[^a-z0-9_.-]/', '', strtolower(trim((string) ($opts['username'] ?? '')) ?: strstr($email, '@', true) ?: 'superadmin'));
$username = substr($username ?: 'superadmin', 0, 50);

$cols = array_column(DB::all('SHOW COLUMNS FROM users'), 'Field');
$has = static fn (string $c): bool => in_array($c, $cols, true);

$row = [
    'full_name' => $name,
    'password_hash' => Auth::hash($password),
    'role' => 'platform_admin',
    'is_active' => 1,
    'failed_attempts' => 0,
    'locked_until' => null,
];
foreach (['hotel_id', 'reseller_id', 'chain_id', 'role_id'] as $c) {
    if ($has($c)) {
        $row[$c] = null; // a platform admin belongs to no customer, reseller or chain
    }
}
if ($has('must_change_password')) {
    $row['must_change_password'] = 0;
}

$existing = DB::one('SELECT id, username FROM users WHERE email = :e', ['e' => $email]);
if ($existing) {
    DB::update('users', $row, 'id = :id', ['id' => (int) $existing['id']]);
    $id = (int) $existing['id'];
    $username = (string) $existing['username'];
    $what = 'updated';
} else {
    // keep the username unique
    $base = $username;
    for ($i = 2; DB::value('SELECT id FROM users WHERE username = :u', ['u' => $username]); $i++) {
        $username = substr($base, 0, 46) . $i;
    }
    $row += ['username' => $username, 'email' => $email, 'language' => 'en', 'created_at' => date('Y-m-d H:i:s')];
    $id = DB::insert('users', $row);
    $what = 'created';
}
// drop old sessions of this user so a stale (locked/limited) session cannot linger
try {
    DB::query('DELETE FROM user_sessions WHERE user_id = :id', ['id' => $id]);
} catch (Throwable $e) {
}
if (class_exists('Cache')) {
    Cache::clear();
}

fwrite(STDOUT, "Super admin $what: #$id  username=$username  email=$email  role=platform_admin\n");
fwrite(STDOUT, "Log in at /admin/login.php with the e-mail (or username) and the new password.\n");
