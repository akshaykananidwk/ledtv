<?php
declare(strict_types=1);

/**
 * Photo album (#19): cross-fade slideshow of an album uploaded from the phone (admin/album_upload.php,
 * core/Albums.php), optional captions and dates, shuffle, new photos appear within 30 s (data()).
 * Optional QR code of the album's guest upload link (weddings: guests add their own photos).
 */
final class PhotoAlbumApp extends DisplayApp
{
    public function key(): string
    {
        return 'photo_album';
    }

    public function label(): string
    {
        return __('Photo album');
    }

    public function description(): string
    {
        return __('Slideshow of photos uploaded from your phone. Guests can add photos with a QR code, e.g. at a wedding. New photos appear within 30 seconds.');
    }

    public function icon(): string
    {
        return 'bi-images';
    }

    public function category(): string
    {
        return 'content';
    }

    public function adminPage(): ?string
    {
        return admin_url('album_upload.php');
    }

    public function defaults(): array
    {
        return [
            'album_id' => 0,
            'heading' => '',
            'interval' => 8,
            'fit' => 'contain',
            'kenburns' => false,
            'shuffle' => false,
            'captions' => true,
            'dates' => false,
            'guest_qr' => false,
            'max' => 300,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $album = ContentApps::albumId($in, 'album_id', $errors);
        if ($album === 0 && !$errors) {
            $errors[] = __('Choose an album. Create albums on the Photo albums page.');
        }
        return [[
            'album_id' => $album,
            'heading' => self::str($in, 'heading', 120),
            'interval' => self::int($in, 'interval', 3, 120, 8),
            'fit' => self::choice($in, 'fit', ['contain', 'cover'], 'contain'),
            'kenburns' => self::bool($in, 'kenburns'),
            'shuffle' => self::bool($in, 'shuffle'),
            'captions' => self::bool($in, 'captions'),
            'dates' => self::bool($in, 'dates'),
            'guest_qr' => self::bool($in, 'guest_qr'),
            'max' => self::int($in, 'max', 1, Albums::MAX_PHOTOS, 300),
        ], $errors];
    }

    public function form(array $config): string
    {
        $albums = [0 => __('Choose…')] + Albums::options();
        return self::select('album_id', __('Album'), $albums, (string) $config['album_id'], __('Upload photos on the Photo albums page (works well from a phone).'))
            . self::input('heading', __('Heading (optional)'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('interval', __('Change every (seconds)'), $config['interval'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-4')
            . self::select('fit', __('Photo size'), ['contain' => __('Whole photo (blurred edges)'), 'cover' => __('Fill the screen (may crop)')], $config['fit'], '', 'col-md-4')
            . self::input('max', __('Maximum photos'), $config['max'], 'number', ['min' => 1, 'max' => Albums::MAX_PHOTOS], '', 'col-md-4')
            . self::checkbox('captions', __('Show captions'), (bool) $config['captions'], 'col-md-4')
            . self::checkbox('dates', __('Show the upload date'), (bool) $config['dates'], 'col-md-4')
            . self::checkbox('shuffle', __('Shuffle'), (bool) $config['shuffle'], 'col-md-4')
            . self::checkbox('kenburns', __('Slow zoom (Ken Burns)'), (bool) $config['kenburns'], 'col-md-4')
            . self::checkbox('guest_qr', __('Show the guest upload QR code (when the album link is on)'), (bool) $config['guest_qr'], 'col-md-8');
    }

    public function data(array $config, array $ctx): ?array
    {
        return [
            'photos' => ContentApps::albumSlides((int) $config['album_id'], (int) $config['max']),
            'empty' => __('No photos yet.'),
        ];
    }

    public function refreshSec(array $config): int
    {
        return 30;
    }

    public function render(array $config, array $ctx): string
    {
        $album = (int) $config['album_id'] > 0 ? DB::one('SELECT * FROM albums WHERE id = :id AND hotel_id = :h', ['id' => (int) $config['album_id'], 'h' => Tenant::id()]) : null;
        $opts = [
            'sec' => (int) $config['interval'], 'fit' => $config['fit'], 'kenburns' => (bool) $config['kenburns'],
            'shuffle' => (bool) $config['shuffle'], 'captions' => (bool) $config['captions'], 'dates' => (bool) $config['dates'],
        ];
        $h = ContentApps::slidesAssets()
            . '<div id="paShow" class="pa-show" data-opts="' . e(json_out($opts)) . '"><div class="hcs-empty" id="paEmpty" style="display:none">' . e(__('No photos yet.')) . '</div></div>';
        if ($config['heading'] !== '') {
            $h .= '<div class="pa-heading">' . e($config['heading']) . '</div>';
        }
        if ($config['guest_qr'] && $album && (int) $album['guest_upload']) {
            $qr = ContentApps::qr(Albums::guestUrl($album));
            if ($qr !== '') {
                $h .= '<div class="pa-qr"><div class="pa-qr-box">' . $qr . '</div><div class="pa-qr-text">' . e(__('Add your photos')) . '</div></div>';
            }
        }
        return $h;
    }
}
