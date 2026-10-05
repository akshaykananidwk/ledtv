<?php
declare(strict_types=1);

/** File-based cache in storage/cache/<namespace>/. */
final class Cache
{
    private static function dir(string $ns): string
    {
        $dir = HC_ROOT . '/storage/cache/' . preg_replace('/[^a-z0-9_]/i', '', $ns);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function path(string $ns, string $key): string
    {
        return self::dir($ns) . '/' . sha1($key) . '.json';
    }

    public static function get(string $ns, string $key, int $ttl): mixed
    {
        $file = self::path($ns, $key);
        if (!is_file($file) || (time() - (int) @filemtime($file)) > $ttl) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return $data['v'] ?? null;
    }

    public static function set(string $ns, string $key, mixed $value): void
    {
        $file = self::path($ns, $key);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_out(['v' => $value])) !== false) {
            @rename($tmp, $file);
        }
    }

    public static function remember(string $ns, string $key, int $ttl, callable $fn): mixed
    {
        $v = self::get($ns, $key, $ttl);
        if ($v === null) {
            $v = $fn();
            if ($v !== null) {
                self::set($ns, $key, $v);
            }
        }
        return $v;
    }

    public static function clear(?string $ns = null): void
    {
        $base = HC_ROOT . '/storage/cache';
        $target = $ns === null ? $base : $base . '/' . preg_replace('/[^a-z0-9_]/i', '', $ns);
        if (!is_dir($target)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.json')) {
                @unlink($f->getPathname());
            }
        }
    }
}
