<?php
declare(strict_types=1);

/**
 * Real-estate / product showcase (#9): Ken Burns slideshow of library images (and / or a photo album)
 * with an info panel — project name, tagline, key specs, price line, contact phone and a QR code
 * (brochure / 3D tour link or WhatsApp chat). Panel on the left, right or bottom.
 */
final class ShowcaseApp extends DisplayApp
{
    public const QR_TYPES = ['none', 'url', 'whatsapp'];

    public function key(): string
    {
        return 'showcase';
    }

    public function label(): string
    {
        return __('Showcase');
    }

    public function description(): string
    {
        return __('Real-estate project or product: photo slideshow with name, key features, price, phone and a QR code for the brochure, 3D tour or WhatsApp.');
    }

    public function icon(): string
    {
        return 'bi-buildings';
    }

    public function category(): string
    {
        return 'business';
    }

    public function defaults(): array
    {
        return [
            'project' => '',
            'tagline' => '',
            'images' => [],
            'album_id' => 0,
            'specs' => '',
            'price' => '',
            'phone' => '',
            'qr_type' => 'none',
            'qr_url' => '',
            'qr_label' => '',
            'layout' => 'right',
            'interval' => 7,
            'kenburns' => true,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $project = self::str($in, 'project', 120);
        if ($project === '') {
            $errors[] = __('Enter the project or product name.');
        }
        $images = ContentApps::imageIds($in['images'] ?? [], 30);
        $album = ContentApps::albumId($in, 'album_id', $errors);
        $qrType = self::choice($in, 'qr_type', self::QR_TYPES, 'none');
        $qrUrl = self::url($in, 'qr_url', $errors, __('QR code link'));
        $phone = self::str($in, 'phone', 40);
        if ($phone !== '' && !preg_match('/^[0-9+()\-.\s]{6,40}$/', $phone)) {
            $errors[] = __('Enter a valid phone number.');
            $phone = '';
        }
        if ($qrType === 'url' && $qrUrl === '') {
            $errors[] = __('Enter the link for the QR code (brochure, 3D tour or website).');
        }
        if ($qrType === 'whatsapp' && ContentApps::phoneDigits($phone) === '') {
            $errors[] = __('Enter the contact phone for the WhatsApp QR code.');
        }
        return [[
            'project' => $project,
            'tagline' => self::str($in, 'tagline', 190),
            'images' => $images,
            'album_id' => $album,
            'specs' => implode("\n", ContentApps::lines(self::text($in, 'specs', 3000), 12, 120)),
            'price' => self::str($in, 'price', 120),
            'phone' => $phone,
            'qr_type' => $qrType,
            'qr_url' => $qrUrl,
            'qr_label' => self::str($in, 'qr_label', 80),
            'layout' => self::choice($in, 'layout', ['left', 'right', 'bottom'], 'right'),
            'interval' => self::int($in, 'interval', 3, 60, 7),
            'kenburns' => self::bool($in, 'kenburns'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $images = ContentApps::imageOptions();
        $albums = [0 => __('None')] + Albums::options();
        $pick = $images
            ? self::checkboxes('images', __('Photos from the content library'), $images, array_map('strval', (array) $config['images']))
            : '<div class="col-12"><div class="form-label">' . e(__('Photos from the content library')) . '</div><div class="text-muted small">'
                . e(__('No images in the content library yet.')) . ' <a href="' . e(admin_url('content.php', ['action' => 'new', 'type' => 'image'])) . '">' . e(__('Upload images')) . '</a></div></div>';
        return self::input('project', __('Project / product name'), $config['project'], 'text', ['maxlength' => 120, 'required' => true, 'placeholder' => __('e.g. Shree Residency')])
            . self::input('tagline', __('Tagline'), $config['tagline'], 'text', ['maxlength' => 190, 'placeholder' => __('e.g. Luxury living near the river front')])
            . $pick
            . self::select('album_id', __('Or photos from an album'), $albums, (string) $config['album_id'], __('Albums can be filled from a phone on the Photo albums page.'))
            . self::input('interval', __('Change photo every (seconds)'), $config['interval'], 'number', ['min' => 3, 'max' => 60], '', 'col-md-3')
            . self::checkbox('kenburns', __('Slow zoom (Ken Burns)'), (bool) $config['kenburns'], 'col-md-3')
            . self::textarea('specs', __('Key features (one per line)'), (string) $config['specs'], 5, __('e.g. 2 & 3 BHK · Ready possession · RERA no. PR/GJ/…'), 'col-md-6', 3000)
            . '<div class="col-md-6 row g-3 m-0 p-0 align-content-start">'
            . self::input('price', __('Price line'), $config['price'], 'text', ['maxlength' => 120, 'placeholder' => __('e.g. Starting ₹ 45 lakh*')], '', 'col-12')
            . self::input('phone', __('Contact phone'), $config['phone'], 'tel', ['maxlength' => 40, 'placeholder' => '98765 43210'], '', 'col-12')
            . '</div>'
            . self::select('qr_type', __('QR code'), ['none' => __('No QR code'), 'url' => __('Link (brochure, 3D tour, website)'), 'whatsapp' => __('WhatsApp chat with the contact phone')], $config['qr_type'], '', 'col-md-4')
            . self::input('qr_url', __('QR code link'), $config['qr_url'], 'url', ['maxlength' => 1000, 'placeholder' => 'https://'], '', 'col-md-4')
            . self::input('qr_label', __('Text under the QR code'), $config['qr_label'], 'text', ['maxlength' => 80, 'placeholder' => __('Scan for the brochure')], '', 'col-md-4')
            . self::select('layout', __('Info panel'), ['right' => __('Right'), 'left' => __('Left'), 'bottom' => __('Bottom')], $config['layout'], '', 'col-md-4');
    }

    /** Slides: library images, then album photos. */
    private function slides(array $config): array
    {
        return array_merge(ContentApps::librarySlides((array) $config['images']), ContentApps::albumSlides((int) $config['album_id'], 200));
    }

    public function data(array $config, array $ctx): ?array
    {
        return ['photos' => $this->slides($config)];
    }

    public function refreshSec(array $config): int
    {
        return (int) $config['album_id'] > 0 ? 60 : 0;
    }

    private function qrPayload(array $config): string
    {
        return match ($config['qr_type']) {
            'url' => (string) $config['qr_url'],
            'whatsapp' => ContentApps::whatsappLink((string) $config['phone'], __('Hello, I am interested in :p.', ['p' => $config['project']])),
            default => '',
        };
    }

    public function render(array $config, array $ctx): string
    {
        $project = $config['project'] !== '' ? $config['project'] : ($ctx['hotel']['name'] ?: __('Your project name'));
        $opts = ['sec' => (int) $config['interval'], 'fit' => 'cover', 'kenburns' => (bool) $config['kenburns'], 'shuffle' => false, 'captions' => false, 'dates' => false];
        $h = ContentApps::slidesAssets() . '<div class="sc-wrap sc-' . e($config['layout']) . '">'
            . '<div class="sc-media"><div id="scShow" class="sc-show" data-opts="' . e(json_out($opts)) . '"></div></div>'
            . '<div class="sc-panel"><div class="sc-panel-in">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="sc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<h1 class="sc-project">' . e($project) . '</h1>';
        if ($config['tagline'] !== '') {
            $h .= '<div class="sc-tagline">' . e($config['tagline']) . '</div>';
        }
        $specs = ContentApps::lines((string) $config['specs'], 12, 120);
        if ($specs) {
            $h .= '<ul class="sc-specs">';
            foreach ($specs as $s) {
                $h .= '<li>' . e($s) . '</li>';
            }
            $h .= '</ul>';
        }
        if ($config['price'] !== '') {
            $h .= '<div class="sc-price">' . e($config['price']) . '</div>';
        }
        $foot = '';
        if ($config['phone'] !== '') {
            $foot .= '<div class="sc-phone"><span class="sc-phone-ic">&#9742;</span> ' . e($config['phone']) . '</div>';
        }
        $qr = ContentApps::qr($this->qrPayload($config));
        if ($qr !== '') {
            $label = $config['qr_label'] !== '' ? $config['qr_label'] : ($config['qr_type'] === 'whatsapp' ? __('Chat on WhatsApp') : __('Scan for the brochure'));
            $foot .= '<div class="sc-qr"><div class="sc-qr-box">' . $qr . '</div><div class="sc-qr-label">' . e($label) . '</div></div>';
        }
        if ($foot !== '') {
            $h .= '<div class="sc-foot">' . $foot . '</div>';
        }
        return $h . '</div></div></div>';
    }
}
