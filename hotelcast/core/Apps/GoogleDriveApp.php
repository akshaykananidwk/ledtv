<?php
declare(strict_types=1);

/**
 * 2.9.7 Google Drive slideshow: paste the link of a Drive folder ("Anyone with the link") and every photo and
 * video in it plays on the TV as a slideshow. New / removed files show up by themselves within a few minutes
 * (data() every 60 s, folder list cached GoogleDrive::CACHE_MIN minutes). Photos cross-fade; videos play to the
 * end (or up to the set maximum) and the slideshow continues. See core/GoogleDrive.php.
 */
final class GoogleDriveApp extends DisplayApp
{
    public function key(): string
    {
        return 'gdrive_slideshow';
    }

    public function label(): string
    {
        return __('Google Drive slideshow');
    }

    public function description(): string
    {
        return __('Paste a Google Drive folder link: all photos and videos in it play on the TV as a slideshow. Add files to the folder and they appear on the TV by themselves.');
    }

    public function icon(): string
    {
        return 'bi-google';
    }

    public function category(): string
    {
        return 'content';
    }

    public function defaults(): array
    {
        return [
            'link' => '',
            'heading' => '',
            'interval' => 8,
            'fit' => 'contain',
            'videos' => true,
            'video_max' => 0,
            'mute' => false,
            'shuffle' => false,
            'names' => false,
            'kenburns' => false,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $link = self::str($in, 'link', 1000);
        if ($link === '' || GoogleDrive::folderId($link) === null) {
            $errors[] = __('Paste the link of a Google Drive folder (drive.google.com/drive/folders/…).');
        }
        return [[
            'link' => $link,
            'heading' => self::str($in, 'heading', 120),
            'interval' => self::int($in, 'interval', 3, 120, 8),
            'fit' => self::choice($in, 'fit', ['contain', 'cover'], 'contain'),
            'videos' => self::bool($in, 'videos'),
            'video_max' => self::int($in, 'video_max', 0, 3600, 0),
            'mute' => self::bool($in, 'mute'),
            'shuffle' => self::bool($in, 'shuffle'),
            'names' => self::bool($in, 'names'),
            'kenburns' => self::bool($in, 'kenburns'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $status = '';
        if ($config['link'] !== '') {
            $r = GoogleDrive::folder($config['link']);
            $photos = count(array_filter($r['items'], static fn ($i) => $i['kind'] === 'image'));
            $videos = count($r['items']) - $photos;
            $status = $r['error'] !== '' && !$r['items']
                ? '<div class="col-12"><div class="alert alert-warning small mb-0" id="gdStatus">' . e(GoogleDrive::errorText($r['error'])) . '</div></div>'
                : '<div class="col-12"><div class="alert alert-success small mb-0" id="gdStatus"><i class="bi bi-check-circle"></i> ' . e(__(':p photo(s) and :v video(s) found in the folder.', ['p' => $photos, 'v' => $videos])) . '</div></div>';
        }
        return self::input('link', __('Google Drive folder link'), $config['link'], 'url', ['maxlength' => 1000, 'placeholder' => 'https://drive.google.com/drive/folders/…'],
                __('In Google Drive: right-click the folder → Share → General access "Anyone with the link" → Copy link.'), 'col-12')
            . $status
            . self::input('heading', __('Heading (optional)'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('interval', __('Photo changes every (seconds)'), $config['interval'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-4')
            . self::select('fit', __('Photo size'), ['contain' => __('Whole photo (blurred edges)'), 'cover' => __('Fill the screen (may crop)')], $config['fit'], '', 'col-md-4')
            . self::input('video_max', __('Play videos for at most (seconds, 0 = whole video)'), $config['video_max'], 'number', ['min' => 0, 'max' => 3600], '', 'col-md-4')
            . self::checkbox('videos', __('Play videos from the folder'), (bool) $config['videos'], 'col-md-4')
            . self::checkbox('mute', __('Videos without sound'), (bool) $config['mute'], 'col-md-4')
            . self::checkbox('shuffle', __('Shuffle'), (bool) $config['shuffle'], 'col-md-4')
            . self::checkbox('names', __('Show the file name'), (bool) $config['names'], 'col-md-4')
            . self::checkbox('kenburns', __('Slow zoom (Ken Burns)'), (bool) $config['kenburns'], 'col-md-4');
    }

    public function data(array $config, array $ctx): ?array
    {
        $r = GoogleDrive::folder((string) $config['link']);
        $items = [];
        foreach ($r['items'] as $i) {
            if ($i['kind'] === 'video' && !$config['videos']) {
                continue;
            }
            $items[] = ['id' => $i['id'], 'url' => $i['url'], 'kind' => $i['kind'], 'caption' => $config['names'] ? pathinfo($i['name'], PATHINFO_FILENAME) : ''];
        }
        return ['items' => $items, 'empty' => $items ? '' : (GoogleDrive::errorText($r['error'] ?: 'empty'))];
    }

    public function refreshSec(array $config): int
    {
        return 60;
    }

    public function render(array $config, array $ctx): string
    {
        $opts = [
            'sec' => (int) $config['interval'], 'fit' => $config['fit'], 'kenburns' => (bool) $config['kenburns'],
            'shuffle' => (bool) $config['shuffle'], 'captions' => (bool) $config['names'],
            'mute' => (bool) $config['mute'], 'video_max' => (int) $config['video_max'],
        ];
        $h = ContentApps::slidesAssets()
            . '<div id="gdShow" class="gd-show" data-opts="' . e(json_out($opts)) . '"></div>'
            . '<video id="gdVideo" class="gd-video" playsinline preload="auto"' . ($config['mute'] ? ' muted' : '') . '></video>'
            . '<div class="hcs-empty gd-empty" id="gdEmpty" style="display:none"></div>';
        if ($config['heading'] !== '') {
            $h .= '<div class="gd-heading">' . e($config['heading']) . '</div>';
        }
        return $h;
    }
}
