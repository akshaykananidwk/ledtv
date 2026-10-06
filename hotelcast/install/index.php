<?php
/**
 * HotelCast one-click installer.
 * Upload the files, open /install in a browser and follow the 7 steps.
 */
declare(strict_types=1);

define('HC_INSTALLER', true);
require __DIR__ . '/../core/bootstrap.php';
require __DIR__ . '/Installer.php';

// ------------------------------------------------------------ lock
if (is_file(HC_ROOT . '/installed.lock')) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Already installed</title><body style="font-family:sans-serif;padding:40px">'
        . '<h1>Krishna Cloud LED TV is already installed</h1><p>The installer is locked. For security, delete the <code>/install</code> folder.</p>'
        . '<p><a href="../admin/">Go to the admin panel →</a></p></body>';
    exit;
}

@set_time_limit(300);
session_name('HCINSTALL');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => is_https()]);
session_start();

$S = &$_SESSION['inst'];
if (!is_array($S)) {
    $S = ['step' => 1, 'csrf' => random_token(16), 'app_key' => random_token(32)];
}
$step = (int) $S['step'];
$errors = [];
$notes = [];

function inst_csrf_ok(): bool
{
    return hash_equals((string) $_SESSION['inst']['csrf'], (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
}

function inst_db_from_post(): array
{
    return [
        'host' => trim((string) ($_POST['db_host'] ?? 'localhost')) ?: 'localhost',
        'port' => (int) ($_POST['db_port'] ?? 3306) ?: 3306,
        'name' => trim((string) ($_POST['db_name'] ?? '')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
    ];
}

/** Connect to the DB saved in session and make it the app connection. */
function inst_use_db(): void
{
    Env::load(HC_ROOT . '/.env');
    Config::load(HC_ROOT . '/config.php');
    [$pdo, $err] = Installer::connect($_SESSION['inst']['db'], false);
    if (!$pdo) {
        throw new RuntimeException('Database connection lost: ' . $err);
    }
    DB::setPdo($pdo);
    DB::syncTimezone();
    // A fresh installation is hotel #1.
    if (Migrator::hasTable($pdo, 'hotels')) {
        Tenant::set(1);
    }
}

// ------------------------------------------------------------ AJAX: test DB
if (($_GET['action'] ?? '') === 'testdb' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!inst_csrf_ok()) {
        echo json_out(['ok' => false, 'message' => 'Session expired — reload the page.']);
        exit;
    }
    $db = inst_db_from_post();
    if (!Installer::validDbName($db['name']) || $db['user'] === '') {
        echo json_out(['ok' => false, 'message' => 'Enter database name and username.']);
        exit;
    }
    [$pdo, $err, $info] = Installer::connect($db, false);
    if ($pdo) {
        [$vok, $ver] = Installer::mysqlVersionOk($pdo);
        echo json_out(['ok' => $vok, 'message' => $vok ? "✔ Connection successful ($ver)" : "Server version $ver is too old (need MySQL 5.7.8+ / MariaDB 10.4+)"]);
    } else {
        $msg = $err;
        if (str_contains((string) $err, 'does not exist') || str_contains((string) $err, 'Unknown database')) {
            $msg = 'Connected to server, but database "' . $db['name'] . '" does not exist yet — it will be created if your user has permission.';
        }
        echo json_out(['ok' => false, 'message' => $msg]);
    }
    exit;
}

// ------------------------------------------------------------ POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!inst_csrf_ok()) {
        $errors[] = 'Security token expired. Please try again.';
    } elseif (isset($_POST['back']) && $step > 1 && $step <= 6) {
        $S['step'] = max(1, $step - 1);
        header('Location: ./');
        exit;
    } else {
        try {
            switch ($step) {
                case 1:
                    if (Installer::requirementsMet()) {
                        $S['step'] = 2;
                    } else {
                        $errors[] = 'Please fix the required items marked in red, then click "Check again".';
                    }
                    break;

                case 2:
                    $db = inst_db_from_post();
                    $S['db_form'] = array_diff_key($db, ['pass' => 1]);
                    if (!Installer::validDbName($db['name'])) {
                        $errors[] = 'Database name may contain only letters, numbers, _ and -.';
                    }
                    if ($db['user'] === '') {
                        $errors[] = 'Database username is required.';
                    }
                    if (!$errors) {
                        [$pdo, $err, $info] = Installer::connect($db, true);
                        if (!$pdo) {
                            $errors[] = $err;
                        } else {
                            [$vok, $ver] = Installer::mysqlVersionOk($pdo);
                            if (!$vok) {
                                $errors[] = "Database server $ver is too old (need MySQL 5.7.8+ / MariaDB 10.4+).";
                            } else {
                                $S['db'] = $db;
                                Installer::writeConfig($db, $S['app_key'], [
                                    'base_url' => Installer::detectBaseUrl(),
                                    'timezone' => 'Asia/Kolkata',
                                    'debug' => false,
                                    'trust_proxy' => false,
                                    'db_persistent' => false,
                                    'update_protected' => [],
                                ]);
                                $S['db_info'] = $info . " ($ver)";
                                $S['step'] = 3;
                            }
                        }
                    }
                    break;

                case 3:
                    inst_use_db();
                    $S['setup_log'] = Installer::setupDatabase(!empty($_POST['demo']));
                    $S['demo'] = !empty($_POST['demo']);
                    $S['step'] = 4;
                    break;

                case 4:
                    inst_use_db();
                    $username = trim((string) ($_POST['username'] ?? ''));
                    $email = trim((string) ($_POST['email'] ?? ''));
                    $name = trim((string) ($_POST['full_name'] ?? ''));
                    $pass = (string) ($_POST['password'] ?? '');
                    $S['admin_form'] = ['username' => $username, 'email' => $email, 'full_name' => $name];
                    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                        $errors[] = 'Username: 3–50 characters (letters, numbers, _ . -).';
                    }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'Enter a valid email address.';
                    }
                    if ($pe = Auth::passwordError($pass)) {
                        $errors[] = $pe;
                    }
                    if ($pass !== (string) ($_POST['password2'] ?? '')) {
                        $errors[] = 'Passwords do not match.';
                    }
                    if (!$errors) {
                        Installer::createAdmin($username, $email, $pass, $name ?: $username);
                        $S['admin_user'] = $username;
                        $S['step'] = 5;
                    }
                    break;

                case 5:
                    inst_use_db();
                    $hotel = trim((string) ($_POST['hotel_name'] ?? ''));
                    $tz = (string) ($_POST['timezone'] ?? 'Asia/Kolkata');
                    $lang = ($_POST['language'] ?? 'en') === 'gu' ? 'gu' : 'en';
                    $baseUrl = trim((string) ($_POST['base_url'] ?? ''));
                    if ($hotel === '' || mb_strlen($hotel) > 120) {
                        $errors[] = 'Hotel name is required.';
                    }
                    if (!in_array($tz, timezone_identifiers_list(), true)) {
                        $errors[] = 'Choose a valid time zone.';
                    }
                    if (!ContentManager::validUrl($baseUrl, ['http', 'https'])) {
                        $errors[] = 'Enter the full website address, e.g. https://hotel.com/hotelcast/';
                    }
                    $brand = mb_substr(trim((string) ($_POST['brand_name'] ?? '')), 0, 120);
                    $S['hotel_form'] = ['hotel_name' => $hotel, 'timezone' => $tz, 'language' => $lang, 'base_url' => $baseUrl, 'brand_name' => $brand];
                    if (!$errors && !empty($_FILES['logo']['name'])) {
                        try {
                            $saved = Uploader::handle($_FILES['logo'], 'logo');
                            Settings::set('hotel_logo', $saved['path']);
                        } catch (RuntimeException $e) {
                            $errors[] = 'Logo: ' . $e->getMessage();
                        }
                    }
                    if (!$errors) {
                        Settings::setMany([
                            'hotel_name' => $hotel,
                            'timezone' => $tz,
                            'default_language' => $lang,
                            'weather_city' => trim((string) ($_POST['weather_city'] ?? 'Dwarka')),
                        ]);
                        if ($brand !== '') {
                            Settings::setPlatform('platform_name', $brand);
                        }
                        $cfg = (array) require HC_ROOT . '/config.php';
                        $cfg['base_url'] = rtrim($baseUrl, '/') . '/';
                        $cfg['timezone'] = $tz;
                        if ($brand !== '') {
                            $cfg['brand_name'] = $brand;
                        }
                        Installer::writeConfig($S['db'], $S['app_key'], $cfg);
                        $S['step'] = 6;
                    }
                    break;

                case 6:
                    inst_use_db();
                    // Installation mode + optional self-hosted license (#19).
                    $mode = ($_POST['mode'] ?? 'saas') === 'standalone' ? 'standalone' : 'saas';
                    $licKey = strtoupper(trim((string) ($_POST['license_key'] ?? '')));
                    $licServer = trim((string) ($_POST['license_server'] ?? ''));
                    if ($mode === 'standalone' && $licKey !== '' && !ContentManager::validUrl($licServer, ['https', 'http'])) {
                        $errors[] = 'Enter the license server address (the provider\'s server URL), e.g. https://tv.provider.com/hotelcast/';
                    }
                    if ($licKey !== '' && !preg_match('/^[A-Z0-9-]{8,64}$/', $licKey)) {
                        $errors[] = 'The license key looks wrong (letters, numbers and dashes).';
                    }
                    if (!$errors) {
                        $cfg = (array) require HC_ROOT . '/config.php';
                        $cfg['mode'] = $mode;
                        if ($mode === 'standalone') {
                            $cfg['license_key'] = $licKey;
                            $cfg['license_server'] = $licServer !== '' ? rtrim($licServer, '/') . '/' : '';
                        } else {
                            unset($cfg['license_key'], $cfg['license_server']);
                        }
                        Installer::writeConfig($S['db'], $S['app_key'], $cfg);
                    }
                    if (!$errors && empty($_POST['skip'])) {
                        $repo = trim((string) ($_POST['github_repo'] ?? ''));
                        if ($repo !== '' && !Updater::parseRepo($repo)) {
                            $errors[] = 'Repository must look like https://github.com/owner/repo or owner/repo.';
                        }
                        if (!$errors && $repo !== '') {
                            Settings::setMany([
                                'github_repo' => $repo,
                                'github_branch' => trim((string) ($_POST['github_branch'] ?? 'main')) ?: 'main',
                                'github_subdir' => trim((string) ($_POST['github_subdir'] ?? 'hotelcast'), '/ '),
                            ]);
                            Settings::setSecret('github_token', trim((string) ($_POST['github_token'] ?? '')));
                            $notes[] = 'GitHub auto-update configured.';
                        }
                    }
                    if (!$errors) {
                        $S['summary'] = [
                            'admin' => $S['admin_user'] ?? '',
                            'db' => $S['db']['name'] ?? '',
                            'demo' => !empty($S['demo']),
                            'key' => (string) Settings::get('registration_key'),
                            'url' => (string) Config::get('base_url', ''),
                            'github' => (string) Settings::get('github_repo', ''),
                        ];
                        Version::write(['installed_at' => date('c')]);
                        ActivityLog::add('installed', null, null, 'Krishna Cloud LED TV ' . Version::current()['version'] . ' installed');
                        $S['finish_msg'] = Installer::finish();
                        $S['step'] = 7;
                    }
                    break;
            }
        } catch (Throwable $e) {
            Logger::error('Installer step ' . $step . ': ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }
        if (!$errors) {
            header('Location: ./');
            exit;
        }
    }
    $step = (int) $S['step'];
}

$csrf = $S['csrf'];
$steps = [1 => 'Requirements', 2 => 'Database', 3 => 'Tables', 4 => 'Admin', 5 => 'Hotel', 6 => 'Update & License', 7 => 'Done'];
$brandName = Branding::installerName();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($brandName) ?> Installer — <?= e($steps[$step]) ?></title>
<style>
:root{--p:#7b1fa2;--p2:#4a148c;--ok:#2e7d32;--bad:#c62828;--warn:#ef6c00;--bg:#f4f1f8;--card:#fff;--txt:#212121;--mut:#666}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--txt);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Gujarati",sans-serif}
.wrap{max-width:780px;margin:0 auto;padding:24px 16px 60px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:18px}.logo{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--p),#ff8f00);display:grid;place-items:center;color:#fff;font-size:24px}
.brand h1{font-size:22px;margin:0}.brand small{color:var(--mut)}
.steps{display:flex;gap:4px;margin:0 0 18px;padding:0;list-style:none;flex-wrap:wrap}
.steps li{flex:1;min-width:90px;text-align:center;font-size:12px;padding:8px 4px;border-radius:8px;background:#e8e2ef;color:#6a5a7a}
.steps li.on{background:var(--p);color:#fff;font-weight:600}.steps li.done{background:#d6c4e6;color:var(--p2)}
.card{background:var(--card);border-radius:14px;box-shadow:0 2px 14px rgba(60,20,90,.08);padding:26px}
h2{margin:0 0 6px;font-size:21px}p.lead{color:var(--mut);margin:0 0 18px}
label{display:block;font-weight:600;margin:14px 0 4px;font-size:14px}
input[type=text],input[type=password],input[type=email],input[type=number],input[type=url],select{width:100%;padding:11px 12px;border:1px solid #cfc6da;border-radius:9px;font:inherit;background:#fff}
input:focus,select:focus{outline:2px solid #ce93d8;border-color:var(--p)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}@media(max-width:600px){.row{grid-template-columns:1fr}}
.hint{font-size:13px;color:var(--mut);margin-top:3px}
.btns{display:flex;justify-content:space-between;gap:10px;margin-top:24px;flex-wrap:wrap}
button,.btn{appearance:none;border:0;border-radius:9px;padding:12px 22px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
.primary{background:var(--p);color:#fff}.primary:hover{background:var(--p2)}.ghost{background:#eee;color:#333}.outline{background:#fff;border:1px solid var(--p);color:var(--p)}
.alert{padding:12px 14px;border-radius:9px;margin-bottom:16px}.alert.err{background:#ffebee;color:var(--bad)}.alert.ok{background:#e8f5e9;color:var(--ok)}.alert.warn{background:#fff3e0;color:var(--warn)}
ul.checks{list-style:none;padding:0;margin:0}ul.checks li{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f0ecf4;font-size:15px}
.i{font-weight:700;width:22px;text-align:center}.i.ok{color:var(--ok)}.i.bad{color:var(--bad)}.i.warn{color:var(--warn)}
.chk{display:flex;gap:10px;align-items:flex-start;font-weight:400;padding:12px;border:1px solid #e3dbea;border-radius:9px;margin-top:14px}
.chk input{margin-top:4px;width:18px;height:18px}
pre.log{background:#1e1e2e;color:#c8f7c5;padding:12px;border-radius:9px;font-size:13px;white-space:pre-wrap;max-height:260px;overflow:auto}
.meter{height:6px;border-radius:3px;background:#eee;margin-top:6px;overflow:hidden}.meter i{display:block;height:100%;width:0;transition:.3s}
.kv{display:grid;grid-template-columns:200px 1fr;gap:6px 12px;font-size:15px}.kv b{color:var(--mut);font-weight:600}@media(max-width:600px){.kv{grid-template-columns:1fr}}
code{background:#f3e5f5;padding:2px 6px;border-radius:5px;font-size:.92em}
.big{font-size:22px;letter-spacing:.12em;font-weight:700;color:var(--p2)}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="logo">📺</div><div><h1><?= e($brandName) ?> Installer</h1><small>Hotel TV Remote Management · v<?= e(Version::current()['version']) ?></small></div></div>
  <ol class="steps">
    <?php foreach ($steps as $n => $label): ?>
      <li class="<?= $n === $step ? 'on' : ($n < $step ? 'done' : '') ?>"><?= $n ?>. <?= e($label) ?></li>
    <?php endforeach; ?>
  </ol>
  <div class="card">
    <?php foreach ($errors as $err): ?><div class="alert err">✘ <?= e($err) ?></div><?php endforeach; ?>
    <?php if (!is_https() && $step < 7): ?><div class="alert warn">⚠ You are not using HTTPS. It is strongly recommended to install an SSL certificate (free with Let's Encrypt on most hosts) before using Krishna Cloud LED TV.</div><?php endif; ?>

<?php if ($step === 1): $checks = Installer::requirements(); $met = Installer::requirementsMet(); ?>
    <h2>Welcome! Let's check your server</h2>
    <p class="lead">Krishna Cloud LED TV needs PHP 8.1+, MySQL/MariaDB and a few standard PHP extensions. Everything else is automatic.</p>
    <ul class="checks">
      <?php foreach ($checks as $c): $cls = $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn'); ?>
        <li><span class="i <?= $cls ?>"><?= $c['ok'] ? '✔' : ($c['required'] ? '✘' : '!') ?></span><span><?= e($c['label']) ?><?= !$c['ok'] && !$c['required'] ? ' <em style="color:#999">(recommended)</em>' : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="btns"><a class="btn ghost" href="./">↻ Check again</a><button class="primary" <?= $met ? '' : 'disabled style="opacity:.5"' ?>>Continue →</button></div>
    </form>

<?php elseif ($step === 2): $f = ($S['db_form'] ?? []) + ['host' => 'localhost', 'port' => 3306, 'name' => 'hotelcast', 'user' => '']; ?>
    <h2>Database connection</h2>
    <p class="lead">Create a MySQL database in your hosting control panel (cPanel → MySQL Databases), then enter its details here.</p>
    <form method="post" id="dbform"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="row">
        <div><label>Database host</label><input type="text" name="db_host" value="<?= e($f['host']) ?>" required><div class="hint">Usually <code>localhost</code></div></div>
        <div><label>Port</label><input type="number" name="db_port" value="<?= e($f['port']) ?>" required></div>
      </div>
      <label>Database name</label><input type="text" name="db_name" value="<?= e($f['name']) ?>" required>
      <div class="row">
        <div><label>Database username</label><input type="text" name="db_user" value="<?= e($f['user']) ?>" autocomplete="off" required></div>
        <div><label>Database password</label><input type="password" name="db_pass" autocomplete="new-password"></div>
      </div>
      <div id="dbresult" style="margin-top:14px"></div>
      <div class="btns"><button class="ghost" name="back" value="1" formnovalidate>← Back</button>
        <span><button type="button" class="outline" id="testdb">Test connection</button> <button class="primary">Save &amp; continue →</button></span></div>
    </form>
    <script>
    document.getElementById('testdb').onclick=function(){var b=this,r=document.getElementById('dbresult');b.disabled=true;r.innerHTML='<div class="alert warn">Testing…</div>';
      fetch('?action=testdb',{method:'POST',body:new FormData(document.getElementById('dbform'))}).then(function(x){return x.json()}).then(function(j){
        r.innerHTML='<div class="alert '+(j.ok?'ok':'err')+'"></div>';r.firstChild.textContent=j.message;}).catch(function(){r.innerHTML='<div class="alert err">Request failed</div>'}).finally(function(){b.disabled=false})};
    </script>

<?php elseif ($step === 3): ?>
    <h2>Create database tables</h2>
    <p class="lead"><?= e($S['db_info'] ?? '') ?>. Click below to create all tables automatically — no phpMyAdmin needed.</p>
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="chk"><input type="checkbox" name="demo" value="1" checked>
        <span><b>Load demo data</b><br><span class="hint">20 sample rooms (101–110, 201–210), floor groups, Dwarkadhish darshan timetable, welcome message, offer, clock, and a default playlist. You can delete or edit everything later.</span></span></label>
      <div class="btns"><button class="ghost" name="back" value="1">← Back</button><button class="primary">Create tables →</button></div>
    </form>

<?php elseif ($step === 4): $f = ($S['admin_form'] ?? []) + ['username' => 'admin', 'email' => '', 'full_name' => '']; ?>
    <?php if (!empty($S['setup_log'])): ?><pre class="log"><?= e(implode("\n", $S['setup_log'])) ?></pre><?php endif; ?>
    <h2>Administrator account</h2>
    <p class="lead">This account has full access: it is the platform admin (auto-update, hotels, billing) and the super admin of this hotel. You can add Manager, Staff and Reception users later.</p>
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="row">
        <div><label>Username</label><input type="text" name="username" value="<?= e($f['username']) ?>" required pattern="[A-Za-z0-9_.\-]{3,50}"></div>
        <div><label>Full name</label><input type="text" name="full_name" value="<?= e($f['full_name']) ?>"></div>
      </div>
      <label>Email</label><input type="email" name="email" value="<?= e($f['email']) ?>" required>
      <div class="row">
        <div><label>Password</label><input type="password" name="password" id="pw" required minlength="8" autocomplete="new-password"><div class="meter"><i id="pwm"></i></div><div class="hint" id="pwt">Min 8 characters with letters and numbers</div></div>
        <div><label>Confirm password</label><input type="password" name="password2" required autocomplete="new-password"></div>
      </div>
      <div class="btns"><span></span><button class="primary">Create account →</button></div>
    </form>
    <script>
    document.getElementById('pw').addEventListener('input',function(){var v=this.value,s=0;if(v.length>=8)s++;if(v.length>=12)s++;if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++;if(/\d/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
      var c=['#c62828','#c62828','#ef6c00','#f9a825','#7cb342','#2e7d32'][s],t=['Very weak','Weak','Fair','Good','Strong','Very strong'][s];var m=document.getElementById('pwm');m.style.width=(s*20)+'%';m.style.background=c;document.getElementById('pwt').textContent=t;});
    </script>

<?php elseif ($step === 5): $f = ($S['hotel_form'] ?? []) + ['hotel_name' => '', 'timezone' => 'Asia/Kolkata', 'language' => 'en', 'base_url' => Installer::detectBaseUrl()]; ?>
    <h2>Hotel details</h2>
    <p class="lead">Shown on every TV screen and in the admin panel.</p>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>Hotel name</label><input type="text" name="hotel_name" value="<?= e($f['hotel_name']) ?>" required placeholder="e.g. Hotel Dwarka Palace">
      <label>Hotel logo (optional, PNG/JPG)</label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
      <div class="row">
        <div><label>Time zone</label><select name="timezone">
          <?php foreach (timezone_identifiers_list() as $tz): ?><option <?= $tz === $f['timezone'] ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
        </select></div>
        <div><label>Admin panel language</label><select name="language"><option value="en">English</option><option value="gu" <?= $f['language'] === 'gu' ? 'selected' : '' ?>>ગુજરાતી (Gujarati)</option></select></div>
      </div>
      <label>Weather city (for TV weather widget)</label><input type="text" name="weather_city" value="Dwarka">
      <label>Product name <span style="font-weight:400;color:#888">(optional, white-label)</span></label><input type="text" name="brand_name" value="<?= e($f['brand_name'] ?? '') ?>" placeholder="Krishna Cloud LED TV" maxlength="120">
      <div class="hint">Replaces "Krishna Cloud LED TV" on the login page, admin panel and TVs. Logo and colour: Admin → Platform settings.</div>
      <label>Website address of this installation</label><input type="url" name="base_url" value="<?= e($f['base_url']) ?>" required>
      <div class="hint">TVs use this address to connect. Detected automatically — change only if you use a different domain.</div>
      <div class="btns"><button class="ghost" name="back" value="1" formnovalidate>← Back</button><button class="primary">Save →</button></div>
    </form>

<?php elseif ($step === 6): ?>
    <h2>Installation type &amp; license</h2>
    <p class="lead">Choose <b>Platform (SaaS)</b> if this server hosts one or many hotels and is managed by you. Choose <b>Self-hosted</b> if this is a single hotel installation licensed from a Krishna Cloud LED TV provider.</p>
    <form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="row">
        <div><label>Installation type</label><select name="mode" id="instMode"><option value="saas">Platform (SaaS, no license needed)</option><option value="standalone">Self-hosted single hotel (license key)</option></select></div>
        <div><label>License key <span style="font-weight:400;color:#888">(self-hosted)</span></label><input type="text" name="license_key" placeholder="HC-XXXXX-XXXXX-XXXXX-XXXXX" autocomplete="off"></div>
      </div>
      <label>License server address <span style="font-weight:400;color:#888">(self-hosted)</span></label><input type="url" name="license_server" placeholder="https://tv.your-provider.com/hotelcast/">
      <div class="hint">Without a key a self-hosted installation runs in demo mode (max <?= License::UNLICENSED_MAX_TVS ?> TVs). You can add the key later in <code>config.php</code>.</div>
    <h2 style="margin-top:26px">GitHub auto-update <span style="font-weight:400;color:#888;font-size:15px">(optional)</span></h2>
    <p class="lead">Connect your GitHub repository once — afterwards updates are installed with one click (backup, migrations and automatic rollback included). You can also set this later in Admin → Auto-Update.</p>
      <label>Repository URL</label><input type="text" name="github_repo" placeholder="https://github.com/your-name/hotelcast">
      <div class="row">
        <div><label>Branch</label><input type="text" name="github_branch" value="main"></div>
        <div><label>App folder inside the repository</label><input type="text" name="github_subdir" value="hotelcast"><div class="hint">Folder that contains <code>version.json</code>. Empty = repository root.</div></div>
      </div>
      <label>Personal Access Token</label><input type="password" name="github_token" autocomplete="off" placeholder="github_pat_…">
      <div class="hint">Needed for private repositories. Create a fine-grained token with <b>Contents: Read-only</b> access. Stored encrypted.</div>
      <div class="btns"><button class="ghost" name="back" value="1">← Back</button>
        <span><button class="outline" name="skip" value="1">Skip for now</button> <button class="primary">Save &amp; finish →</button></span></div>
    </form>

<?php else: $sum = $S['summary'] ?? []; ?>
    <h2>🎉 Installation complete!</h2>
    <p class="lead">Krishna Cloud LED TV is ready. <?= e($S['finish_msg'] ?? '') ?></p>
    <div class="kv">
      <b>Admin panel</b><span><a href="<?= e(($sum['url'] ?? '../') . 'admin/') ?>"><?= e(($sum['url'] ?? '') . 'admin/') ?></a></span>
      <b>Admin username</b><span><?= e($sum['admin'] ?? '') ?></span>
      <b>Database</b><span><?= e($sum['db'] ?? '') ?></span>
      <b>Demo data</b><span><?= !empty($sum['demo']) ? 'Loaded' : 'No' ?></span>
      <b>GitHub auto-update</b><span><?= !empty($sum['github']) ? e($sum['github']) : 'Not configured (Admin → Auto-Update)' ?></span>
      <b>TV server address</b><span><code><?= e($sum['url'] ?? '') ?></code></span>
      <b>TV registration key</b><span class="big"><?= e($sum['key'] ?? '') ?></span>
    </div>
    <div class="alert ok" style="margin-top:18px">Next: install the Krishna Cloud LED TV app (APK) on each TV, open it, enter the server address, room number and the registration key above. The TV appears in Admin → Rooms within seconds.</div>
    <div class="btns"><span></span><a class="btn primary" href="<?= e(($sum['url'] ?? '../') . 'admin/login.php') ?>">Open admin panel →</a></div>
    <?php $_SESSION = []; session_destroy(); ?>
<?php endif; ?>
  </div>
  <p style="text-align:center;color:#999;font-size:13px;margin-top:18px"><?= e($brandName) ?> · English + ગુજરાતી</p>
</div>
</body>
</html>
