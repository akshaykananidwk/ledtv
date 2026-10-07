<?php
declare(strict_types=1);

/**
 * Helpers for display app tests (tests/Integration/Apps/*Test.php). Not a test itself:
 *   require_once __DIR__ . '/DisplayAppsTestKit.php';
 *
 *   $item = DisplayAppsTestKit::createItem('qr', ['mode' => 'text', 'text' => 'Hi'], ['lang' => 'gu']);
 *   [$code, $html] = DisplayAppsTestKit::page(self::$url, $item);          // GET /display/?c=&s=
 *   [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);          // GET /display/data.php
 *   DisplayAppsTestKit::assertRenders($this, self::$url, $item);           // 200, no PHP warning, app class
 *   $html = DisplayAppsTestKit::renderInProcess($item);                    // DisplayApps::renderPage()
 */
final class DisplayAppsTestKit
{
    /** Insert an app content item for the current hotel (Tenant) with the app defaults merged under $config. */
    public static function createItem(string $app, array $config = [], array $settings = [], string $title = 'Test app', int $duration = 30): array
    {
        $a = DisplayApps::find($app);
        if (!$a) {
            throw new RuntimeException('Unknown display app ' . $app);
        }
        $id = DB::insert('content_items', [
            'title' => $title, 'type' => 'app', 'duration' => $duration, 'is_active' => 1, 'created_at' => now(),
            'settings' => json_out($settings + ['app' => $app, 'config' => $config + $a->defaults(), 'theme' => DisplayApps::DEFAULT_THEME, 'font' => 'auto', 'accent' => null, 'lang' => 'en']),
        ]);
        return DB::one('SELECT * FROM content_items WHERE id = :id', ['id' => $id]);
    }

    /** Path of an absolute app URL relative to the installation (strips base_url()). */
    public static function rel(string $url): string
    {
        $base = base_url();
        return str_starts_with($url, $base) ? substr($url, strlen($base)) : (string) preg_replace('#^https?://[^/]+/#', '', $url);
    }

    /** @return array{0:int,1:string} status, HTML of the signed TV page */
    public static function page(string $serverUrl, array $item, array $extra = []): array
    {
        [$code, , $html] = TestEnv::http('GET', $serverUrl . self::rel(DisplayApps::displayUrl($item, $extra)));
        return [$code, $html];
    }

    /** @return array{0:int,1:?array} status, decoded JSON of display/data.php */
    public static function data(string $serverUrl, array $item): array
    {
        [$code, $json] = TestEnv::http('GET', $serverUrl . self::rel(DisplayApps::dataUrl($item)));
        return [$code, $json];
    }

    /** The page answers 200, shows no PHP warning and carries the app's body class. Returns the HTML. */
    public static function assertRenders(\PHPUnit\Framework\TestCase $t, string $serverUrl, array $item, array $extra = []): string
    {
        [$code, $html] = self::page($serverUrl, $item, $extra);
        $app = (string) (ContentManager::settings($item)['app'] ?? '');
        $t->assertSame(200, $code, $app . ': HTTP status');
        $t->assertFalse(TestEnv::hasPhpError($html), $app . ': PHP warning in the page');
        $t->assertStringContainsString('hc-app-' . $app, $html);
        $t->assertStringContainsString('window.HC_DISPLAY=', $html);
        return $html;
    }

    public static function renderInProcess(array $item, bool $preview = false): string
    {
        return DisplayApps::renderPage($item, $preview);
    }
}
