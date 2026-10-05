<?php
declare(strict_types=1);

/** Reads/writes version.json. */
final class Version
{
    public static function current(): array
    {
        $file = HC_ROOT . '/version.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return (is_array($data) ? $data : []) + ['version' => '0.0.0', 'commit' => '', 'date' => ''];
    }

    public static function write(array $data): void
    {
        file_put_contents(HC_ROOT . '/version.json', json_encode($data + self::current(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
