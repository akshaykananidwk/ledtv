<?php
declare(strict_types=1);

/** Simple file logger writing to /logs (blocked from web by .htaccess). */
final class Logger
{
    public static function write(string $channel, string $level, string $message, array $context = []): void
    {
        $dir = HC_ROOT . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
            $context ? ' ' . json_out($context) : ''
        );
        $file = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $channel) . '.log';
        if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.' . date('Ymd_His'));
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('app', 'info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', 'error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('app', 'warning', $message, $context);
    }

    /** Last $lines lines of a log file. */
    public static function tail(string $channel, int $lines = 200): array
    {
        $file = HC_ROOT . '/logs/' . preg_replace('/[^a-z0-9_]/i', '', $channel) . '.log';
        if (!is_file($file)) {
            return [];
        }
        $fh = fopen($file, 'rb');
        if (!$fh) {
            return [];
        }
        $size = filesize($file);
        $chunk = min($size, 256 * 1024);
        fseek($fh, -$chunk, SEEK_END);
        $data = fread($fh, $chunk) ?: '';
        fclose($fh);
        $all = preg_split('/\r?\n/', trim($data)) ?: [];
        return array_reverse(array_slice($all, -$lines));
    }
}
