<?php
declare(strict_types=1);

/**
 * GitHub-based self-update:
 * check → backup (files + DB) → download → verify → install (protected paths untouched)
 * → migrations → cache clear → health check → auto-rollback on any failure.
 */
final class Updater
{
    private array $log = [];
    private ?int $historyId = null;
    private float $started;

    public function __construct()
    {
        $this->started = microtime(true);
    }

    // ------------------------------------------------------------------ config

    /** Parse "https://github.com/owner/repo(.git)" or "owner/repo". */
    public static function parseRepo(string $repo): ?array
    {
        $repo = trim($repo);
        if (preg_match('~^(?:https?://github\.com/|git@github\.com:)?([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~', $repo, $m)) {
            return ['owner' => $m[1], 'repo' => $m[2]];
        }
        return null;
    }

    public static function configured(): bool
    {
        return self::parseRepo((string) Settings::get('github_repo', '')) !== null;
    }

    private static function repo(): array
    {
        $r = self::parseRepo((string) Settings::get('github_repo', ''));
        if (!$r) {
            throw new RuntimeException('GitHub repository is not configured (Admin → Auto-Update → Settings).');
        }
        return $r;
    }

    private static function branch(): string
    {
        $b = trim((string) Settings::get('github_branch', 'main'));
        return preg_match('~^[A-Za-z0-9._/-]+$~', $b) ? $b : 'main';
    }

    private static function subdir(): string
    {
        return trim(str_replace('..', '', (string) Settings::get('github_subdir', 'hotelcast')), '/');
    }

    /** GitHub API base (overridable in config.php for GitHub Enterprise or testing). */
    private static function apiBase(): string
    {
        return rtrim((string) Config::get('github_api_base', 'https://api.github.com'), '/');
    }

    private static function headers(): array
    {
        $h = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        $token = Settings::secret('github_token');
        if ($token !== '') {
            $h[] = 'Authorization: Bearer ' . $token;
        }
        return $h;
    }

    private static function api(string $path, int $timeout = 30): array
    {
        $res = Http::get(self::apiBase() . $path, self::headers(), $timeout);
        if ($res['error']) {
            throw new RuntimeException('Cannot reach GitHub: ' . $res['error']);
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] === 401) {
            throw new RuntimeException('GitHub rejected the access token (401). Check the Personal Access Token.');
        }
        if ($res['status'] === 404) {
            throw new RuntimeException('GitHub returned 404 for ' . $path . ' — check repository name, branch, sub-folder and token access.');
        }
        if ($res['status'] === 403 && ($res['headers']['x-ratelimit-remaining'] ?? '') === '0') {
            throw new RuntimeException('GitHub API rate limit reached. Add a token or try again later.');
        }
        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($json)) {
            throw new RuntimeException('GitHub API error HTTP ' . $res['status'] . ': ' . ($json['message'] ?? substr($res['body'], 0, 200)));
        }
        return $json;
    }

    // ------------------------------------------------------------------ check

    /**
     * Compare local version with GitHub. Returns:
     * [update_available, current{version,commit,date}, latest{version,commit,short,message,date,author,url},
     *  changed_files, commits[{sha,message,date,author}], files[]]
     */
    public static function check(): array
    {
        $r = self::repo();
        $branch = self::branch();
        $sub = self::subdir();
        $base = '/repos/' . rawurlencode($r['owner']) . '/' . rawurlencode($r['repo']);

        $commit = self::api($base . '/commits/' . rawurlencode($branch));
        $sha = (string) $commit['sha'];

        $remoteVersion = null;
        try {
            $vf = self::api($base . '/contents/' . ($sub !== '' ? $sub . '/' : '') . 'version.json?ref=' . rawurlencode($sha));
            $decoded = json_decode((string) base64_decode((string) ($vf['content'] ?? '')), true);
            $remoteVersion = $decoded['version'] ?? null;
        } catch (RuntimeException) {
            $remoteVersion = null;
        }

        $current = Version::current();
        $commits = [];
        $files = [];
        $changed = null;
        if (!empty($current['commit']) && preg_match('/^[0-9a-f]{7,40}$/', $current['commit']) && !str_starts_with($sha, $current['commit'])) {
            try {
                $cmp = self::api($base . '/compare/' . $current['commit'] . '...' . $sha);
                foreach (array_reverse((array) ($cmp['commits'] ?? [])) as $c) {
                    $commits[] = [
                        'sha' => substr((string) $c['sha'], 0, 7),
                        'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"),
                        'date' => (string) ($c['commit']['author']['date'] ?? ''),
                        'author' => (string) ($c['commit']['author']['name'] ?? ''),
                    ];
                }
                foreach ((array) ($cmp['files'] ?? []) as $f) {
                    $name = (string) $f['filename'];
                    if ($sub === '' || str_starts_with($name, $sub . '/')) {
                        $files[] = ['file' => $sub === '' ? $name : substr($name, strlen($sub) + 1), 'status' => $f['status'] ?? 'modified'];
                    }
                }
                $changed = count($files);
            } catch (RuntimeException) {
                // Local commit may not exist on GitHub (e.g. manual install) — treat as unknown.
            }
        }

        $isNewer = $remoteVersion !== null && version_compare((string) $remoteVersion, (string) $current['version'], '>');
        $commitDiffers = empty($current['commit']) || !str_starts_with($sha, (string) $current['commit']);
        $available = $isNewer || ($commitDiffers && ($changed === null || $changed > 0));

        $result = [
            'update_available' => $available,
            'current' => $current,
            'latest' => [
                'version' => $remoteVersion ?? '?',
                'commit' => $sha,
                'short' => substr($sha, 0, 7),
                'message' => (string) ($commit['commit']['message'] ?? ''),
                'date' => (string) ($commit['commit']['committer']['date'] ?? $commit['commit']['author']['date'] ?? ''),
                'author' => (string) ($commit['commit']['author']['name'] ?? ''),
                'url' => (string) ($commit['html_url'] ?? ''),
            ],
            'changed_files' => $changed,
            'commits' => array_slice($commits, 0, 50),
            'files' => array_slice($files, 0, 300),
            'checked_at' => date('c'),
        ];
        Settings::set('last_update_check', json_out($result));
        return $result;
    }

    // ------------------------------------------------------------------ run

    public function log(string $message, string $level = 'info'): void
    {
        $this->log[] = ['time' => date('H:i:s'), 'level' => $level, 'message' => $message];
        Logger::write('update', $level, $message);
        if ($this->historyId) {
            try {
                DB::update('update_history', ['log' => $this->logText()], 'id = :id', ['id' => $this->historyId]);
            } catch (Throwable) {
                // DB may be mid-restore; log is re-saved at the end.
            }
        }
    }

    public function logText(): string
    {
        return implode("\n", array_map(fn ($l) => '[' . $l['time'] . '] ' . strtoupper($l['level']) . ' ' . $l['message'], $this->log));
    }

    public function entries(): array
    {
        return $this->log;
    }

    /**
     * Perform the full update. Returns ['ok' => bool, 'log' => [...], 'error' => ?string, 'version' => ?string].
     */
    public function run(?int $userId = null): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '512M');

        $lock = HC_ROOT . '/storage/update.lock';
        if (is_file($lock) && filemtime($lock) > time() - 1800) {
            return ['ok' => false, 'log' => [], 'error' => 'Another update is already running (storage/update.lock). Wait or delete the lock file if stuck.'];
        }
        @mkdir(dirname($lock), 0755, true);
        file_put_contents($lock, (string) getmypid());

        $current = Version::current();
        $backup = null;
        $installed = false;
        $tmpDir = HC_ROOT . '/storage/tmp/update_' . date('Ymd_His');
        $zipFile = $tmpDir . '.zip';
        $target = null;

        try {
            $this->historyId = DB::insert('update_history', [
                'action' => 'update',
                'from_version' => $current['version'],
                'status' => 'running',
                'started_by' => $userId,
                'started_at' => now(),
            ]);
            $this->log('Update started (current version ' . $current['version'] . ($current['commit'] ? ' @ ' . substr($current['commit'], 0, 7) : '') . ')');

            // 0. Pre-flight
            foreach (['ZipArchive' => class_exists('ZipArchive'), 'curl' => function_exists('curl_init')] as $req => $ok) {
                if (!$ok) {
                    throw new RuntimeException("PHP extension $req is required for updates");
                }
            }
            if (!is_writable(HC_ROOT) || !is_writable(HC_ROOT . '/core')) {
                throw new RuntimeException('Application folder is not writable by PHP — cannot update.');
            }

            // 1. Target commit
            $this->log('Checking GitHub for the latest version…');
            $info = self::check();
            $target = $info['latest'];
            $this->log(sprintf('Latest on %s: %s (%s) — "%s"', self::branch(), $target['version'], $target['short'], strtok($target['message'], "\n")));
            DB::update('update_history', ['to_version' => $target['version'], 'commit_hash' => $target['commit']], 'id = :id', ['id' => $this->historyId]);

            // 2. Backup
            $this->log('Step 1/7: Creating full backup (files + database)…');
            $backup = Backup::create('pre-update ' . $current['version'] . ' → ' . $target['version'], false, fn ($m) => $this->log($m));
            DB::update('update_history', ['backup_file' => $backup], 'id = :id', ['id' => $this->historyId]);

            // 3. Download
            $this->log('Step 2/7: Downloading ' . $target['short'] . ' from GitHub…');
            @mkdir($tmpDir, 0755, true);
            $r = self::repo();
            $url = self::apiBase() . '/repos/' . rawurlencode($r['owner']) . '/' . rawurlencode($r['repo']) . '/zipball/' . $target['commit'];
            $res = Http::request('GET', $url, self::headers(), null, 600, $zipFile);
            if ($res['error'] || $res['status'] !== 200 || !is_file($zipFile) || filesize($zipFile) < 1000) {
                throw new RuntimeException('Download failed (HTTP ' . $res['status'] . ($res['error'] ? ', ' . $res['error'] : '') . ')');
            }
            $this->log('Downloaded ' . human_bytes(filesize($zipFile)));

            // 4. Extract & verify
            $this->log('Step 3/7: Extracting and verifying package…');
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Downloaded file is not a valid zip');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = (string) $zip->getNameIndex($i);
                if (str_contains($n, '../') || str_starts_with($n, '/')) {
                    throw new RuntimeException('Unsafe path in update package: ' . $n);
                }
            }
            $zip->extractTo($tmpDir);
            $zip->close();
            $top = glob($tmpDir . '/*', GLOB_ONLYDIR) ?: [];
            if (count($top) !== 1) {
                throw new RuntimeException('Unexpected archive layout');
            }
            $src = $top[0] . (self::subdir() !== '' ? '/' . self::subdir() : '');
            if (!is_file($src . '/core/bootstrap.php') || !is_file($src . '/version.json')) {
                throw new RuntimeException('Package does not contain a HotelCast application in "' . self::subdir() . '/" (core/bootstrap.php / version.json missing). Check the sub-folder setting.');
            }
            $errors = self::lintTree($src);
            if ($errors) {
                throw new RuntimeException('Package has PHP syntax errors: ' . implode('; ', array_slice($errors, 0, 5)));
            }
            $remoteVer = json_decode((string) file_get_contents($src . '/version.json'), true);
            if (!empty($remoteVer['min_php']) && version_compare(PHP_VERSION, (string) $remoteVer['min_php'], '<')) {
                throw new RuntimeException('New version requires PHP ' . $remoteVer['min_php'] . ' (server has ' . PHP_VERSION . ')');
            }
            $this->log('Package verified');

            // 5. Install files
            $this->log('Step 4/7: Installing files (protected: .env, config.php, uploads/, storage/, backups/, logs/)…');
            $installed = true;
            [$copied, $removed] = self::installTree($src);
            Version::write([
                'version' => (string) ($remoteVer['version'] ?? $target['version']),
                'commit' => $target['commit'],
                'date' => substr($target['date'], 0, 10) ?: date('Y-m-d'),
                'updated_at' => date('c'),
            ]);
            $this->log("Installed $copied files, removed $removed obsolete files");

            // 6. Migrations
            $this->log('Step 5/7: Running database migrations…');
            $ran = Migrator::migrate(fn ($m) => $this->log($m));
            $this->log($ran ? 'Applied: ' . implode(', ', $ran) : 'No new migrations');

            // 7. Cache
            $this->log('Step 6/7: Clearing caches…');
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            Cache::clear();
            Settings::bumpContentVersion();

            // 8. Health check
            $this->log('Step 7/7: Running health check…');
            $health = HealthCheck::full(true);
            foreach ($health['checks'] as $name => $c) {
                $this->log(sprintf('  %s %s: %s', $c['ok'] ? '✔' : '✘', $name, $c['message']), $c['ok'] ? 'info' : 'error');
            }
            if (!$health['ok']) {
                throw new RuntimeException('Health check failed');
            }

            DB::update('update_history', [
                'status' => 'success',
                'to_version' => Version::current()['version'],
                'finished_at' => now(),
            ], 'id = :id', ['id' => $this->historyId]);
            $this->log(sprintf('✅ Update complete: %s → %s in %.1fs', $current['version'], Version::current()['version'], microtime(true) - $this->started));
            ActivityLog::add('system_update', 'update', $this->historyId, $current['version'] . ' → ' . Version::current()['version']);
            Backup::prune(max(2, Settings::int('backup_keep', 10)));
            return ['ok' => true, 'log' => $this->log, 'error' => null, 'version' => Version::current()['version']];
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $this->log('❌ ' . $error, 'error');
            $status = 'failed';
            if ($backup && $installed) {
                $this->log('Rolling back to backup ' . $backup . '…', 'warning');
                try {
                    $this->historyId = null; // the DB restore replaces update_history
                    Backup::restore($backup, true, true, fn ($m) => $this->log($m));
                    if (function_exists('opcache_reset')) {
                        @opcache_reset();
                    }
                    $status = 'rolled_back';
                    $this->log('Rollback complete — previous version restored.', 'warning');
                } catch (Throwable $re) {
                    $this->log('ROLLBACK FAILED: ' . $re->getMessage() . ' — restore manually from backups/' . $backup, 'error');
                }
            }
            // Re-insert/update the history row (a DB restore removed the in-progress row).
            try {
                $row = [
                    'action' => 'update',
                    'from_version' => $current['version'],
                    'to_version' => $target['version'] ?? null,
                    'commit_hash' => $target['commit'] ?? null,
                    'status' => $status,
                    'backup_file' => $backup,
                    'log' => $this->logText(),
                    'started_by' => $userId,
                    'finished_at' => now(),
                ];
                $exists = $this->historyId && DB::value('SELECT id FROM update_history WHERE id = :id', ['id' => $this->historyId]);
                if ($exists) {
                    DB::update('update_history', $row, 'id = :id', ['id' => $this->historyId]);
                } else {
                    DB::insert('update_history', $row + ['started_at' => date('Y-m-d H:i:s', (int) $this->started)]);
                }
            } catch (Throwable $le) {
                Logger::error('Could not save update history: ' . $le->getMessage());
            }
            return ['ok' => false, 'log' => $this->log, 'error' => $error, 'status' => $status];
        } finally {
            if ($this->historyId) {
                try {
                    DB::update('update_history', ['log' => $this->logText()], 'id = :id', ['id' => $this->historyId]);
                } catch (Throwable) {
                }
            }
            @unlink($zipFile);
            rrmdir($tmpDir);
            @unlink($lock);
        }
    }

    /** Copy new files over the installation; delete app files that no longer exist upstream. */
    public static function installTree(string $src): array
    {
        $copied = 0;
        $new = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($src))), '/');
            if (Backup::isProtected($rel) || $rel === 'version.json') {
                continue;
            }
            $new[$rel] = true;
            $dest = HC_ROOT . '/' . $rel;
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) {
                throw new RuntimeException('Cannot create folder for ' . $rel);
            }
            if (is_file($dest) && md5_file($dest) === md5_file($f->getPathname())) {
                continue;
            }
            // Write to temp then rename: avoids half-written PHP files being executed.
            $tmp = $dest . '.hcnew';
            if (!copy($f->getPathname(), $tmp) || !rename($tmp, $dest)) {
                @unlink($tmp);
                throw new RuntimeException('Cannot write ' . $rel);
            }
            $copied++;
        }
        $removed = 0;
        foreach (['admin', 'api', 'core', 'assets', 'lang', 'migrations'] as $managed) {
            if (!is_dir(HC_ROOT . '/' . $managed) || !is_dir($src . '/' . $managed)) {
                continue;
            }
            $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(HC_ROOT . '/' . $managed, FilesystemIterator::SKIP_DOTS));
            foreach ($it2 as $f) {
                $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen(HC_ROOT))), '/');
                // Never delete migrations (history) — only code/assets.
                if ($f->isFile() && !isset($new[$rel]) && !str_starts_with($rel, 'migrations/') && !Backup::isProtected($rel)) {
                    @unlink($f->getPathname()) && $removed++;
                }
            }
        }
        return [$copied, $removed];
    }

    /** Syntax-check every PHP file in a tree. */
    public static function lintTree(string $dir): array
    {
        $errors = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                try {
                    token_get_all((string) file_get_contents($f->getPathname()), TOKEN_PARSE);
                } catch (ParseError $e) {
                    $errors[] = substr($f->getPathname(), strlen($dir) + 1) . ':' . $e->getLine() . ' ' . $e->getMessage();
                }
            }
        }
        return $errors;
    }

    // ------------------------------------------------------------------ rollback

    /** Manual rollback to a backup (from history row or backup name). */
    public static function rollback(string $backupName, ?int $userId = null, bool $restoreDb = true): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $u = new self();
        $from = Version::current();
        $u->log('Manual rollback to ' . $backupName . ' requested');
        try {
            $safety = Backup::create('pre-rollback ' . $from['version'], false, fn ($m) => $u->log($m));
            $u->log('Safety backup created: ' . $safety);
            Backup::restore($backupName, true, $restoreDb, fn ($m) => $u->log($m));
            $to = Version::current();
            $u->log('Restored version ' . $to['version']);
            $health = HealthCheck::full(PHP_SAPI !== 'cli');
            foreach ($health['checks'] as $name => $c) {
                $u->log(sprintf('  %s %s: %s', $c['ok'] ? '✔' : '✘', $name, $c['message']), $c['ok'] ? 'info' : 'error');
            }
            DB::insert('update_history', [
                'action' => 'rollback',
                'from_version' => $from['version'],
                'to_version' => $to['version'],
                'commit_hash' => $to['commit'] ?: null,
                'status' => $health['ok'] ? 'success' : 'failed',
                'backup_file' => $safety,
                'log' => $u->logText(),
                'started_by' => $userId,
                'started_at' => date('Y-m-d H:i:s', (int) $u->started),
                'finished_at' => now(),
            ]);
            ActivityLog::add('system_rollback', 'update', null, $from['version'] . ' → ' . $to['version'] . ' (' . $backupName . ')');
            return ['ok' => $health['ok'], 'log' => $u->entries(), 'error' => $health['ok'] ? null : 'Health check reported problems after rollback'];
        } catch (Throwable $e) {
            $u->log('Rollback failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'log' => $u->entries(), 'error' => $e->getMessage()];
        }
    }
}
