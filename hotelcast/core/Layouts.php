<?php
declare(strict_types=1);

/**
 * Split screen layouts (2.3): one content item of type 'layout' divides the screen into zones; each
 * zone plays its own content item or playlist. See docs/modules/layouts.md.
 *
 * Stored in content_items.settings:
 *   {"bg_color":"#000000","audio":"auto"|"none"|"z2",
 *    "zones":[{"id":"z1","x":0,"y":0,"w":70,"h":100,"content_id":5,"playlist_id":null,
 *              "scale":"fit","transition":"fade","loop":true}, …]}
 *
 * TV contract (ContentItem.layout): {"bg_color", "zones":[{"id","x","y","w","h","items":[ContentItem…],
 * "loop","transition","scale","mute"}]} — percent of the stage, at most one zone with sound.
 */
final class Layouts
{
    public const MAX_ZONES = 6;
    /** First TV app version code that understands layouts (2.3.0). Older apps get the largest zone. */
    public const MIN_APP_CODE = 10;
    public const SCALES = ['fit', 'fill', 'zoom'];
    public const TRANSITIONS = ['fade', 'none'];
    /** Item types that can produce sound (audio zone default: the first zone containing one). */
    public const SOUND_TYPES = ['video', 'stream', 'youtube'];

    /** Preset templates: key => [label, zones [x, y, w, h]]. */
    public static function presets(): array
    {
        return [
            'strip' => [__('Full screen + bottom strip'), [[0, 0, 100, 85], [0, 85, 100, 15]]],
            'left70' => [__('70 / 30 (video left)'), [[0, 0, 70, 100], [70, 0, 30, 100]]],
            'left30' => [__('30 / 70'), [[0, 0, 30, 100], [30, 0, 70, 100]]],
            'half' => [__('50 / 50'), [[0, 0, 50, 100], [50, 0, 50, 100]]],
            'three' => [__('3 columns'), [[0, 0, 33.33, 100], [33.33, 0, 33.34, 100], [66.67, 0, 33.33, 100]]],
            'grid4' => [__('2 × 2 grid'), [[0, 0, 50, 50], [50, 0, 50, 50], [0, 50, 50, 50], [50, 50, 50, 50]]],
            'lshape' => [__('L-shape (main + side + bottom)'), [[0, 0, 75, 85], [75, 0, 25, 85], [0, 85, 100, 15]]],
            'hmf' => [__('Header + main + footer'), [[0, 0, 100, 12], [0, 12, 100, 76], [0, 88, 100, 12]]],
        ];
    }

    // ------------------------------------------------------------------ validation

    /**
     * Validate the editor input. $in['layout_json'] (string, from the admin form) or $in['layout'] (array):
     * {"bg_color", "audio": "auto"|"none"|<zone id>|<zone index>, "zones": [{"id"?, "x","y","w","h",
     *  "source": "c:<id>"|"p:<id>" (or "content_id" / "playlist_id"), "scale", "transition", "loop"?}]}.
     * Ids are checked with Tenant::find (another hotel's id → 404 / TenantException). Returns [settings, errors].
     */
    public static function validate(array $in): array
    {
        $raw = $in['layout'] ?? null;
        if (!is_array($raw)) {
            $raw = json_decode((string) ($in['layout_json'] ?? ''), true);
        }
        if (!is_array($raw)) {
            return [['bg_color' => '#000000', 'audio' => 'auto', 'zones' => []], [__('Add at least one zone to the layout.')]];
        }
        $errors = [];
        $zonesIn = array_values(array_filter((array) ($raw['zones'] ?? []), 'is_array'));
        if (!$zonesIn) {
            $errors[] = __('Add at least one zone to the layout.');
        }
        if (count($zonesIn) > self::MAX_ZONES) {
            $errors[] = __('A layout can have at most :n zones.', ['n' => self::MAX_ZONES]);
            $zonesIn = array_slice($zonesIn, 0, self::MAX_ZONES);
        }
        $audioIn = $raw['audio'] ?? 'auto';
        $audio = 'auto';
        if ($audioIn === 'none') {
            $audio = 'none';
        }
        $zones = [];
        foreach ($zonesIn as $i => $z) {
            $n = $i + 1;
            $id = 'z' . $n;
            if ($audioIn !== 'auto' && $audioIn !== 'none' && $audioIn !== null && $audioIn !== ''
                && ((string) $audioIn === (string) ($z['id'] ?? $id) || (is_int($audioIn) && $audioIn === $i))) {
                $audio = $id;
            }
            $rect = [];
            foreach (['x', 'y', 'w', 'h'] as $k) {
                $v = $z[$k] ?? null;
                $rect[$k] = is_numeric($v) && is_finite((float) $v) ? round((float) $v, 2) : NAN;
            }
            if (in_array(true, array_map('is_nan', $rect), true)
                || $rect['x'] < 0 || $rect['y'] < 0 || $rect['w'] < 1 || $rect['h'] < 1
                || $rect['x'] + $rect['w'] > 100.001 || $rect['y'] + $rect['h'] > 100.001) {
                $errors[] = __('Zone :n is outside the screen (x, y, width and height are percent, 0–100).', ['n' => $n]);
            }
            [$cid, $pid] = self::parseSource($z);
            if ($cid) {
                $c = Tenant::find('content_items', $cid);
                if (!$c) {
                    $errors[] = __('Zone :n: the selected content no longer exists.', ['n' => $n]);
                } elseif ($c['type'] === 'layout') {
                    $errors[] = __('Zone :n: a layout cannot contain another layout.', ['n' => $n]);
                }
            } elseif ($pid) {
                $p = Tenant::find('content_playlists', $pid);
                if (!$p) {
                    $errors[] = __('Zone :n: the selected playlist no longer exists.', ['n' => $n]);
                } elseif ((int) DB::value("SELECT COUNT(*) FROM playlist_items pi JOIN content_items c ON c.id = pi.content_id WHERE pi.playlist_id = :p AND c.type = 'layout'", ['p' => $pid]) > 0) {
                    $errors[] = __('Zone :n: the playlist contains a layout (a layout cannot contain another layout).', ['n' => $n]);
                }
            } else {
                $errors[] = __('Zone :n: choose a content item or playlist.', ['n' => $n]);
            }
            $zones[] = [
                'id' => $id,
                'x' => is_nan($rect['x']) ? 0.0 : $rect['x'],
                'y' => is_nan($rect['y']) ? 0.0 : $rect['y'],
                'w' => is_nan($rect['w']) ? 0.0 : $rect['w'],
                'h' => is_nan($rect['h']) ? 0.0 : $rect['h'],
                'content_id' => $cid ?: null,
                'playlist_id' => $cid ? null : ($pid ?: null),
                'scale' => in_array($z['scale'] ?? '', self::SCALES, true) ? $z['scale'] : 'fit',
                'transition' => in_array($z['transition'] ?? '', self::TRANSITIONS, true) ? $z['transition'] : 'fade',
                'loop' => !array_key_exists('loop', $z) || !empty($z['loop']),
            ];
        }
        foreach (self::overlaps($zones) as [$a, $b]) {
            $errors[] = __('Zones :a and :b overlap.', ['a' => $a + 1, 'b' => $b + 1]);
        }
        return [['bg_color' => clean_color(isset($raw['bg_color']) ? (string) $raw['bg_color'] : null, '#000000'), 'audio' => $audio, 'zones' => $zones], array_values(array_unique($errors))];
    }

    /** [content_id, playlist_id] of a zone ("source" => "c:5" | "p:3", or explicit ids). */
    public static function parseSource(array $z): array
    {
        $src = (string) ($z['source'] ?? '');
        if (preg_match('/^([cp]):(\d{1,10})$/', $src, $m)) {
            return $m[1] === 'c' ? [(int) $m[2], 0] : [0, (int) $m[2]];
        }
        $cid = is_numeric($z['content_id'] ?? null) ? (int) $z['content_id'] : 0;
        $pid = is_numeric($z['playlist_id'] ?? null) ? (int) $z['playlist_id'] : 0;
        return [max(0, $cid), $cid > 0 ? 0 : max(0, $pid)];
    }

    /** Index pairs of overlapping zones (touching edges are fine). */
    public static function overlaps(array $zones): array
    {
        $out = [];
        $eps = 0.001;
        $n = count($zones);
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $p = $zones[$a];
                $q = $zones[$b];
                if ($p['x'] < $q['x'] + $q['w'] - $eps && $q['x'] < $p['x'] + $p['w'] - $eps
                    && $p['y'] < $q['y'] + $q['h'] - $eps && $q['y'] < $p['y'] + $p['h'] - $eps) {
                    $out[] = [$a, $b];
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ TV output

    /** ContentItem.layout for the TV (called by ContentManager::toTvItem). Missing sources give empty zones. */
    public static function toTv(array $settings): array
    {
        $zones = [];
        foreach (array_slice(array_values(array_filter((array) ($settings['zones'] ?? []), 'is_array')), 0, self::MAX_ZONES) as $i => $z) {
            $zones[] = [
                'id' => 'z' . ($i + 1),
                'x' => round((float) ($z['x'] ?? 0), 2),
                'y' => round((float) ($z['y'] ?? 0), 2),
                'w' => round((float) ($z['w'] ?? 0), 2),
                'h' => round((float) ($z['h'] ?? 0), 2),
                'items' => self::zoneItems($z),
                'loop' => !array_key_exists('loop', $z) || !empty($z['loop']),
                'transition' => in_array($z['transition'] ?? '', self::TRANSITIONS, true) ? $z['transition'] : 'fade',
                'scale' => in_array($z['scale'] ?? '', self::SCALES, true) ? $z['scale'] : 'fit',
                'mute' => true,
            ];
        }
        $audio = (string) ($settings['audio'] ?? 'auto');
        $soundZone = null;
        if ($audio === 'auto' || $audio === '') {
            foreach ($zones as $z) {
                foreach ($z['items'] as $it) {
                    if (in_array($it['type'], self::SOUND_TYPES, true)) {
                        $soundZone = $z['id'];
                        break 2;
                    }
                }
            }
        } elseif ($audio !== 'none') {
            $soundZone = $audio;
        }
        foreach ($zones as &$z) {
            $z['mute'] = $z['id'] !== $soundZone;
        }
        unset($z);
        return ['bg_color' => clean_color(isset($settings['bg_color']) ? (string) $settings['bg_color'] : null, '#000000'), 'zones' => $zones];
    }

    /** Items of one zone: own hotel only, active only, never a layout. */
    public static function zoneItems(array $zone): array
    {
        [$cid, $pid] = self::parseSource($zone);
        $items = [];
        if ($pid) {
            if (!ContentManager::findOwnPlaylist($pid)) {
                return [];
            }
            foreach (ContentManager::playlistItems($pid) as $row) {
                if ($row['type'] !== 'layout') {
                    $items[] = ContentManager::toTvItem($row, $row['pli_duration'] !== null ? (int) $row['pli_duration'] : null);
                }
            }
        } elseif ($cid) {
            $row = ContentManager::findOwn($cid);
            if ($row && ContentRules::playable($row) && $row['type'] !== 'layout') { // 2.4: approved + validity window
                $tv = ContentManager::toTvItem($row);
                $tv['duration'] = 0; // a single item stays in its zone
                $items[] = $tv;
            }
        }
        return $items;
    }

    // ------------------------------------------------------------------ old TV apps

    /** Does this device need the fallback (app older than 2.3.0, or version unknown)? */
    public static function legacyDevice(array $device): bool
    {
        $code = $device['app_version_code'] ?? null;
        return $code === null || $code === '' || (int) $code < self::MIN_APP_CODE;
    }

    /**
     * Replace every layout item by the items of its largest zone (old apps do not know 'layout').
     * The hash is recomputed so old and new apps each get a stable hash for their own version.
     */
    public static function downgradeContent(array $content): array
    {
        $items = $content['items'] ?? [];
        if (!is_array($items) || !in_array('layout', array_column($items, 'type'), true)) {
            return $content;
        }
        $out = [];
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'layout') {
                array_push($out, ...self::fallbackItems($it));
            } else {
                $out[] = $it;
            }
        }
        $content['items'] = $out;
        unset($content['hash'], $content['generated_at']);
        $content['hash'] = sha1(json_out($content));
        $content['generated_at'] = date('c');
        return $content;
    }

    /** Items of the largest zone (by area; first wins on a tie; empty zones are passed over). */
    public static function fallbackItems(array $tvItem): array
    {
        $zones = array_values(array_filter((array) ($tvItem['layout']['zones'] ?? []), fn ($z) => is_array($z) && !empty($z['items'])));
        if (!$zones) {
            return [];
        }
        usort($zones, static fn ($a, $b) => ((float) $b['w'] * (float) $b['h']) <=> ((float) $a['w'] * (float) $a['h']));
        $items = array_values($zones[0]['items']);
        if (count($items) === 1) {
            $items[0]['duration'] = (int) ($tvItem['duration'] ?? 0); // keeps the playlist rotating like the layout did
        }
        return $items;
    }

    // ------------------------------------------------------------------ admin helpers

    /**
     * Where content items / playlists are used by layouts of the current hotel:
     * ['c' => [content_id => [layout title, …]], 'p' => [playlist_id => [title, …]]].
     */
    public static function usageMap(): array
    {
        $map = ['c' => [], 'p' => []];
        $rows = DB::all("SELECT id, title, settings FROM content_items WHERE hotel_id = :h AND type = 'layout' ORDER BY title", ['h' => Tenant::id()]);
        foreach ($rows as $r) {
            foreach ((array) (ContentManager::settings($r)['zones'] ?? []) as $z) {
                if (!is_array($z)) {
                    continue;
                }
                [$cid, $pid] = self::parseSource($z);
                if ($cid) {
                    $map['c'][$cid][(int) $r['id']] = (string) $r['title'];
                } elseif ($pid) {
                    $map['p'][$pid][(int) $r['id']] = (string) $r['title'];
                }
            }
        }
        return $map;
    }

    /** HTML note "Used in layout X, Y" (escaped) or '' when not used. $kind = 'c' | 'p'. */
    public static function usageNote(array $map, string $kind, int $id): string
    {
        $titles = $map[$kind][$id] ?? [];
        if (!$titles) {
            return '';
        }
        return '<div class="small text-warning-emphasis" title="' . e(__('Deleting it leaves that zone empty.')) . '"><i class="bi bi-grid-1x2"></i> '
            . e(__('Used in layout :t', ['t' => implode(', ', array_map(fn ($t) => '"' . $t . '"', $titles))])) . '</div>';
    }

    /** Small SVG of the zones for admin lists (escaped). */
    public static function miniSvg(array $settings, string $class = ''): string
    {
        $bg = clean_color(isset($settings['bg_color']) ? (string) $settings['bg_color'] : null, '#000000');
        $out = '<svg class="' . e($class) . '" viewBox="0 0 160 90" preserveAspectRatio="none" aria-hidden="true"><rect width="160" height="90" fill="' . e($bg) . '"/>';
        $colors = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#dc2626', '#0891b2'];
        foreach (array_slice(array_values(array_filter((array) ($settings['zones'] ?? []), 'is_array')), 0, self::MAX_ZONES) as $i => $z) {
            $out .= sprintf(
                '<rect x="%.2F" y="%.2F" width="%.2F" height="%.2F" fill="%s" fill-opacity=".75" stroke="#fff" stroke-width="1"/>',
                (float) ($z['x'] ?? 0) * 1.6, (float) ($z['y'] ?? 0) * 0.9, (float) ($z['w'] ?? 0) * 1.6, (float) ($z['h'] ?? 0) * 0.9, $colors[$i % 6]
            );
        }
        return $out . '</svg>';
    }
}
