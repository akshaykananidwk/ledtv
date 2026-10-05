<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContentTest extends TestCase
{
    protected function setUp(): void
    {
        TestEnv::resetDatabase();
    }

    public function testValidateAnnouncement(): void
    {
        [$data, $errors] = ContentManager::validate(['title' => 'Hello', 'body' => 'જય દ્વારકાધીશ', 'style' => 'marquee', 'bg_color' => '#112233'], 'announcement', false);
        $this->assertSame([], $errors);
        $this->assertSame('marquee', $data['settings']['style']);
        $this->assertSame('#112233', $data['settings']['bg_color']);
    }

    public function testValidateRejectsBadUrls(): void
    {
        [, $errors] = ContentManager::validate(['title' => 'x', 'url' => 'javascript:alert(1)'], 'url', false);
        $this->assertNotEmpty($errors);
        [, $errors] = ContentManager::validate(['title' => 'x', 'url' => 'rtsp://cam.local/live'], 'stream', false);
        $this->assertSame([], $errors);
        [, $errors] = ContentManager::validate(['title' => '', 'url' => 'https://a.b'], 'url', false);
        $this->assertNotEmpty($errors);
    }

    public function testTimetableBuildsRowsAndHtml(): void
    {
        [$data, $errors] = ContentManager::validate([
            'title' => 'Darshan', 'tt_time' => ['06:30', '', '19:30'], 'tt_name' => ['Mangla <b>Aarti</b>', '', 'Sandhya'],
        ], 'timetable', false);
        $this->assertSame([], $errors);
        $body = json_decode($data['body'], true);
        $this->assertCount(2, $body['rows']);
        $html = ContentManager::renderTimetable(['title' => 'Darshan', 'body' => $data['body']]);
        $this->assertStringContainsString('Mangla &lt;b&gt;Aarti&lt;/b&gt;', $html, 'Timetable cells must be escaped');
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function testYoutubeEmbed(): void
    {
        foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/live/dQw4w9WgXcQ?si=1'] as $u) {
            $this->assertStringStartsWith('https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1', ContentManager::youtubeEmbed($u));
        }
    }

    public function testToTvItemPerType(): void
    {
        $id = DB::insert('content_items', ['title' => 'Vid', 'type' => 'video', 'file_path' => 'media/2026/10/abc.mp4', 'settings' => '{"loop":false,"mute":true}', 'duration' => 30]);
        $tv = ContentManager::toTvItem(ContentManager::find($id));
        $this->assertSame('video', $tv['type']);
        $this->assertFalse($tv['loop']);
        $this->assertTrue($tv['mute']);
        $this->assertStringEndsWith('uploads/media/2026/10/abc.mp4', $tv['url']);

        Settings::set('cdn_base_url', 'https://cdn.example.com');
        $tv = ContentManager::toTvItem(ContentManager::find($id));
        $this->assertSame('https://cdn.example.com/uploads/media/2026/10/abc.mp4', $tv['url']);
        Settings::set('cdn_base_url', '');
    }

    public function testImageUploadIsValidatedAndReencoded(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        $im = imagecreatetruecolor(2400, 1200);
        imagejpeg($im, $tmp);
        $saved = Uploader::handle(['name' => 'photo.JPG', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], 'image');
        $file = HC_ROOT . '/uploads/' . $saved['path'];
        $this->assertFileExists($file);
        $this->assertSame(1920, getimagesize($file)[0], 'Image resized to max width');
        $this->assertMatchesRegularExpression('#^h1/media/\d{4}/\d{2}/[a-f0-9]{24}\.jpg$#', $saved['path'], 'Renamed to random name');
        $this->assertNotNull($saved['thumb']);

        // A PHP file disguised as an image must be rejected.
        file_put_contents($tmp, '<?php echo 1;');
        $this->expectException(RuntimeException::class);
        Uploader::handle(['name' => 'evil.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 13], 'image');
    }

    public function testPhpExtensionRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        imagepng(imagecreatetruecolor(10, 10), $tmp);
        $this->expectException(RuntimeException::class);
        Uploader::handle(['name' => 'shell.php', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], 'image');
    }
}
