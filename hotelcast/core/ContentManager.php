<?php
declare(strict_types=1);

/**
 * Content items & playlists: CRUD helpers and conversion into the TV ContentItem format.
 */
final class ContentManager
{
    public const TYPES = [
        'image' => 'Image',
        'video' => 'Video',
        'stream' => 'Live Stream',
        'timetable' => 'Temple Timetable',
        'announcement' => 'Announcement',
        'html' => 'Custom HTML',
        'url' => 'Web URL',
        'youtube' => 'YouTube',
        'clock' => 'Clock',
    ];

    public const TYPE_ICONS = [
        'image' => 'bi-image', 'video' => 'bi-film', 'stream' => 'bi-broadcast',
        'timetable' => 'bi-calendar3', 'announcement' => 'bi-megaphone', 'html' => 'bi-code-slash',
        'url' => 'bi-globe', 'youtube' => 'bi-youtube', 'clock' => 'bi-clock',
    ];

    /** Content item of the current hotel (404 when it belongs to another hotel). */
    public static function find(int $id): ?array
    {
        return Tenant::find('content_items', $id);
    }

    /** Scoped lookups without the cross-hotel 404 (used by ContentResolver on TV polls). */
    public static function findOwn(int $id): ?array
    {
        return DB::one('SELECT * FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => $id, 'h' => Tenant::id()]);
    }

    public static function findOwnPlaylist(int $id): ?array
    {
        return DB::one('SELECT * FROM content_playlists WHERE id = :id AND hotel_id = :h', ['id' => $id, 'h' => Tenant::id()]);
    }

    /** Playlist of the current hotel (404 when it belongs to another hotel). */
    public static function findPlaylist(int $id): ?array
    {
        return Tenant::find('content_playlists', $id);
    }

    public static function settings(array $item): array
    {
        $s = $item['settings'] ?? null;
        if (is_string($s) && $s !== '') {
            $s = json_decode($s, true);
        }
        return is_array($s) ? $s : [];
    }

    /** Items of a playlist in order (with per-item duration override). */
    public static function playlistItems(int $playlistId, bool $activeOnly = true): array
    {
        return DB::all(
            'SELECT c.*, pi.id AS pli_id, pi.sort_order, pi.duration AS pli_duration
             FROM playlist_items pi
             JOIN content_playlists p ON p.id = pi.playlist_id AND p.hotel_id = :h
             JOIN content_items c ON c.id = pi.content_id AND c.hotel_id = :h2
             WHERE pi.playlist_id = :p' . ($activeOnly ? ' AND c.is_active = 1' : '') . '
             ORDER BY pi.sort_order, pi.id',
            ['p' => $playlistId, 'h' => Tenant::id(), 'h2' => Tenant::id()]
        );
    }

    /** Convert a DB row into the ContentItem JSON the TV understands. */
    public static function toTvItem(array $item, ?int $durationOverride = null): array
    {
        $s = self::settings($item);
        $type = $item['type'];
        $out = [
            'id' => (int) $item['id'],
            'type' => $type,
            'title' => (string) $item['title'],
            'duration' => $durationOverride ?? (int) $item['duration'],
        ];
        switch ($type) {
            case 'image':
                $out['url'] = $item['file_path'] ? media_url($item['file_path']) : (string) $item['url'];
                break;
            case 'video':
                $out['url'] = $item['file_path'] ? media_url($item['file_path']) : (string) $item['url'];
                $out['loop'] = (bool) ($s['loop'] ?? true);
                $out['mute'] = (bool) ($s['mute'] ?? false);
                break;
            case 'stream':
                $out['url'] = (string) $item['url'];
                $out['mute'] = (bool) ($s['mute'] ?? false);
                break;
            case 'timetable':
                $out['html'] = self::renderTimetable($item);
                $out['refresh_sec'] = (int) ($s['refresh_sec'] ?? 60);
                break;
            case 'announcement':
                $out['text'] = (string) $item['body'];
                $out['subtitle'] = (string) ($s['subtitle'] ?? '');
                $out['style'] = in_array($s['style'] ?? '', ['fullscreen', 'marquee'], true) ? $s['style'] : 'fullscreen';
                $out['bg_color'] = clean_color($s['bg_color'] ?? null, '#1A237E');
                $out['text_color'] = clean_color($s['text_color'] ?? null, '#FFFFFF');
                $out['font_size'] = max(12, min(200, (int) ($s['font_size'] ?? 48)));
                break;
            case 'html':
                $out['html'] = self::wrapHtml((string) $item['body']);
                break;
            case 'url':
                $out['url'] = (string) $item['url'];
                break;
            case 'youtube':
                $out['url'] = (string) $item['url'];
                $out['embed_url'] = self::youtubeEmbed((string) $item['url']);
                break;
            case 'clock':
                $out['style'] = ($s['style'] ?? 'digital') === 'analog' ? 'analog' : 'digital';
                $out['bg_color'] = clean_color($s['bg_color'] ?? null, '#000000');
                $out['text_color'] = clean_color($s['text_color'] ?? null, '#FFFFFF');
                break;
        }
        return $out;
    }

    /** Wrap an HTML fragment into a full document; full documents pass through unchanged. */
    public static function wrapHtml(string $html): string
    {
        if (stripos($html, '<html') !== false) {
            return $html;
        }
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>html,body{margin:0;padding:0;background:#000;color:#fff;font-family:"Noto Sans Gujarati","Noto Sans",Arial,sans-serif}</style>'
            . '</head><body>' . $html . '</body></html>';
    }

    public static function youtubeEmbed(string $url): string
    {
        $id = null;
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|live/|shorts/))([A-Za-z0-9_-]{11})~', $url, $m)) {
            $id = $m[1];
        }
        if (!$id) {
            return $url;
        }
        return 'https://www.youtube.com/embed/' . $id . '?autoplay=1&mute=0&controls=0&loop=1&playlist=' . $id . '&rel=0&modestbranding=1&playsinline=1';
    }

    /**
     * Timetable body JSON:
     * {"heading":"...","subheading":"...","columns":["Time","Darshan"],"rows":[["06:00","Mangla Aarti"],...],
     *  "footer":"...","bg_color":"#4A0E0E","text_color":"#FFF8E1","accent_color":"#FFB300"}
     * The first column is treated as a time ("HH:MM" or "HH:MM - HH:MM") for live highlighting.
     */
    public static function timetableData(array $item): array
    {
        $data = json_decode((string) $item['body'], true);
        if (!is_array($data)) {
            $data = [];
        }
        $data += [
            'heading' => $item['title'],
            'subheading' => '',
            'columns' => ['Time', 'Darshan'],
            'rows' => [],
            'footer' => '',
            'bg_color' => '#4A0E0E',
            'text_color' => '#FFF8E1',
            'accent_color' => '#FFB300',
        ];
        $data['rows'] = array_values(array_filter((array) $data['rows'], fn ($r) => is_array($r) && implode('', $r) !== ''));
        return $data;
    }

    public static function renderTimetable(array $item): string
    {
        $d = self::timetableData($item);
        $bg = clean_color($d['bg_color'], '#4A0E0E');
        $fg = clean_color($d['text_color'], '#FFF8E1');
        $ac = clean_color($d['accent_color'], '#FFB300');
        $head = '';
        foreach ((array) $d['columns'] as $c) {
            $head .= '<th>' . e($c) . '</th>';
        }
        $rows = '';
        foreach ($d['rows'] as $r) {
            $cells = '';
            foreach (array_values($r) as $i => $cell) {
                $cells .= '<td' . ($i === 0 ? ' class="t"' : '') . '>' . e($cell) . '</td>';
            }
            $rows .= '<tr data-time="' . e((string) ($r[0] ?? '')) . '">' . $cells . '</tr>';
        }
        $hotel = e(Settings::get('hotel_name', ''));
        $heading = e($d['heading']);
        $sub = e($d['subheading']);
        $footer = e($d['footer']);
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box}html,body{margin:0;height:100%;background:{$bg};color:{$fg};font-family:"Noto Sans Gujarati","Noto Sans",Arial,sans-serif}
.wrap{display:flex;flex-direction:column;height:100%;padding:3vh 5vw}
h1{margin:0;text-align:center;font-size:6vh;color:{$ac};letter-spacing:.05em}
h2{margin:.5vh 0 2vh;text-align:center;font-size:3.2vh;font-weight:400;opacity:.85}
table{width:100%;border-collapse:collapse;font-size:3.6vh;flex:1}
th{text-align:left;color:{$ac};border-bottom:3px solid {$ac};padding:1vh 2vw;font-size:3vh;text-transform:uppercase}
td{padding:1.1vh 2vw;border-bottom:1px solid rgba(255,255,255,.15)}
td.t{white-space:nowrap;font-weight:700;width:30%}
tr.now{background:{$ac};color:#000}tr.now td{font-weight:700}
tr.past{opacity:.45}
.foot{display:flex;justify-content:space-between;margin-top:2vh;font-size:2.6vh;opacity:.85}
</style></head><body><div class="wrap">
<h1>{$heading}</h1><h2>{$sub}</h2>
<table><thead><tr>{$head}</tr></thead><tbody>{$rows}</tbody></table>
<div class="foot"><span>{$footer}</span><span>{$hotel} · <b id="clk"></b></span></div>
</div>
<script>
function mins(s){var m=/(\d{1,2})[:.](\d{2})\s*(am|pm|AM|PM)?/.exec(s||'');if(!m)return null;var h=+m[1],mi=+m[2],ap=(m[3]||'').toLowerCase();if(ap==='pm'&&h<12)h+=12;if(ap==='am'&&h===12)h=0;return h*60+mi;}
function upd(){var d=new Date(),n=d.getHours()*60+d.getMinutes();var rows=[].slice.call(document.querySelectorAll('tbody tr'));var cur=-1;
rows.forEach(function(r,i){var t=r.getAttribute('data-time');var parts=t.split(/\s*[-–]\s*/);var s=mins(parts[0]),e=parts[1]?mins(parts[1]):null;r.className='';
if(s===null)return;if(e!==null){if(n>=s&&n<e)cur=i;else if(n>=e)r.className='past';}else{if(n>=s)cur=i;}});
rows.forEach(function(r,i){if(i<cur&&!r.className)r.className='past';});if(cur>=0)rows[cur].className='now';
var h=d.getHours(),m=d.getMinutes(),ap=h>=12?'PM':'AM';h=h%12||12;document.getElementById('clk').textContent=h+':'+(m<10?'0':'')+m+' '+ap;}
upd();setInterval(upd,30000);
</script></body></html>
HTML;
    }

    /** Validate & normalise content form input. Returns [data, errors]. */
    public static function validate(array $in, string $type, bool $hasFile): array
    {
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 190) {
            $errors[] = __('Title is required (max 190 characters).');
        }
        if (!isset(self::TYPES[$type])) {
            $errors[] = __('Invalid content type.');
        }
        $duration = max(0, min(86400, (int) ($in['duration'] ?? 10)));
        $data = ['title' => $title, 'type' => $type, 'duration' => $duration, 'url' => null, 'body' => null, 'settings' => []];

        $url = trim((string) ($in['url'] ?? ''));
        switch ($type) {
            case 'image':
            case 'video':
                if (!$hasFile && $url === '' && empty($in['_existing_file'])) {
                    $errors[] = __('Please upload a file or give a URL.');
                }
                if ($url !== '' && !self::validUrl($url, ['http', 'https'])) {
                    $errors[] = __('Invalid URL.');
                }
                $data['url'] = $url ?: null;
                if ($type === 'video') {
                    $data['settings'] = ['loop' => !empty($in['loop']), 'mute' => !empty($in['mute'])];
                }
                break;
            case 'stream':
                if (!self::validUrl($url, ['http', 'https', 'rtsp', 'rtmp'])) {
                    $errors[] = __('Enter a valid stream URL (HLS .m3u8 / RTSP / HTTP).');
                }
                $data['url'] = $url;
                $data['settings'] = ['mute' => !empty($in['mute'])];
                break;
            case 'url':
            case 'youtube':
                if (!self::validUrl($url, ['http', 'https'])) {
                    $errors[] = __('Enter a valid http(s) URL.');
                }
                $data['url'] = $url;
                break;
            case 'announcement':
                $text = trim((string) ($in['body'] ?? ''));
                if ($text === '') {
                    $errors[] = __('Announcement text is required.');
                }
                $data['body'] = $text;
                $data['settings'] = [
                    'subtitle' => trim((string) ($in['subtitle'] ?? '')),
                    'style' => ($in['style'] ?? '') === 'marquee' ? 'marquee' : 'fullscreen',
                    'bg_color' => clean_color($in['bg_color'] ?? null, '#1A237E'),
                    'text_color' => clean_color($in['text_color'] ?? null, '#FFFFFF'),
                    'font_size' => max(12, min(200, (int) ($in['font_size'] ?? 48))),
                ];
                break;
            case 'html':
                $data['body'] = (string) ($in['body'] ?? '');
                if (trim($data['body']) === '') {
                    $errors[] = __('HTML content is required.');
                }
                break;
            case 'timetable':
                $rows = [];
                $times = (array) ($in['tt_time'] ?? []);
                $names = (array) ($in['tt_name'] ?? []);
                $notes = (array) ($in['tt_note'] ?? []);
                $hasNotes = trim(implode('', $notes)) !== '';
                foreach ($times as $i => $t) {
                    $t = trim((string) $t);
                    $n = trim((string) ($names[$i] ?? ''));
                    if ($t === '' && $n === '') {
                        continue;
                    }
                    $row = [$t, $n];
                    if ($hasNotes) {
                        $row[] = trim((string) ($notes[$i] ?? ''));
                    }
                    $rows[] = $row;
                }
                $columns = [trim((string) ($in['tt_col1'] ?? 'Time')) ?: 'Time', trim((string) ($in['tt_col2'] ?? 'Darshan')) ?: 'Darshan'];
                if ($hasNotes) {
                    $columns[] = trim((string) ($in['tt_col3'] ?? 'Notes')) ?: 'Notes';
                }
                if (!$rows) {
                    $errors[] = __('Add at least one timetable row.');
                }
                $data['body'] = json_out([
                    'heading' => trim((string) ($in['tt_heading'] ?? $title)) ?: $title,
                    'subheading' => trim((string) ($in['tt_subheading'] ?? '')),
                    'columns' => $columns,
                    'rows' => $rows,
                    'footer' => trim((string) ($in['tt_footer'] ?? '')),
                    'bg_color' => clean_color($in['bg_color'] ?? null, '#4A0E0E'),
                    'text_color' => clean_color($in['text_color'] ?? null, '#FFF8E1'),
                    'accent_color' => clean_color($in['accent_color'] ?? null, '#FFB300'),
                ]);
                $data['settings'] = ['refresh_sec' => max(10, (int) ($in['refresh_sec'] ?? 60))];
                break;
            case 'clock':
                $data['settings'] = [
                    'style' => ($in['style'] ?? '') === 'analog' ? 'analog' : 'digital',
                    'bg_color' => clean_color($in['bg_color'] ?? null, '#000000'),
                    'text_color' => clean_color($in['text_color'] ?? null, '#FFFFFF'),
                ];
                break;
        }
        return [$data, $errors];
    }

    public static function validUrl(string $url, array $schemes): bool
    {
        if ($url === '' || strlen($url) > 1000) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, $schemes, true) && (bool) parse_url($url, PHP_URL_HOST);
    }

    /** Number of places a content item is used (rooms, groups, playlists, broadcasts). */
    public static function usage(int $contentId): array
    {
        $h = Tenant::id();
        return [
            'rooms' => (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :h AND content_id = :id', ['id' => $contentId, 'h' => $h]),
            'groups' => (int) DB::value('SELECT COUNT(*) FROM room_groups WHERE hotel_id = :h AND content_id = :id', ['id' => $contentId, 'h' => $h]),
            'playlists' => (int) DB::value('SELECT COUNT(DISTINCT pi.playlist_id) FROM playlist_items pi JOIN content_playlists p ON p.id = pi.playlist_id WHERE p.hotel_id = :h AND pi.content_id = :id', ['id' => $contentId, 'h' => $h]),
            'broadcasts' => (int) DB::value("SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = :h AND content_id = :id AND status IN ('scheduled','active')", ['id' => $contentId, 'h' => $h]),
        ];
    }

    public static function deleteItem(int $id): void
    {
        $item = self::find($id);
        if (!$item) {
            return;
        }
        DB::delete('content_items', 'id = :id', ['id' => $id]);
        Uploader::delete($item['file_path'], $item['thumb_path']);
        if ((string) Settings::get('default_content_id') === (string) $id) {
            Settings::set('default_content_id', '');
        }
        Settings::bumpContentVersion();
    }

    /** Thumbnail URL for admin lists. */
    public static function thumbUrl(array $item): ?string
    {
        if (!empty($item['thumb_path'])) {
            return media_url($item['thumb_path']);
        }
        if ($item['type'] === 'image') {
            return $item['file_path'] ? media_url($item['file_path']) : ($item['url'] ?: null);
        }
        if ($item['type'] === 'youtube' && preg_match('~([A-Za-z0-9_-]{11})~', (string) parse_url(self::youtubeEmbed((string) $item['url']), PHP_URL_PATH), $m)) {
            return 'https://img.youtube.com/vi/' . $m[1] . '/mqdefault.jpg';
        }
        return null;
    }
}
