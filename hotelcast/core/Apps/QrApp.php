<?php
declare(strict_types=1);

/**
 * QR code (#18): one big QR (server-side SVG from core/QrCode.php, works offline) for a web link,
 * a UPI payment (upi://pay?pa=…&pn=…&am=…), a WhatsApp chat (wa.me/<phone>?text=…), plain text or
 * Wi-Fi login (WIFI:T:WPA;S:…;P:…;;), with a title and caption.
 */
final class QrApp extends DisplayApp
{
    public const MODES = ['url' => 'Web link', 'upi' => 'UPI payment', 'whatsapp' => 'WhatsApp chat', 'text' => 'Plain text', 'wifi' => 'Wi-Fi login'];
    public const WIFI_ENC = ['WPA' => 'WPA / WPA2', 'WEP' => 'WEP', 'nopass' => 'Open (no password)'];

    public function key(): string
    {
        return 'qr';
    }

    public function label(): string
    {
        return __('QR code');
    }

    public function description(): string
    {
        return __('A big QR code for a web link, UPI payment, WhatsApp chat, Wi-Fi login or any text, with a title and caption.');
    }

    public function icon(): string
    {
        return 'bi-qr-code';
    }

    public function category(): string
    {
        return 'widget';
    }

    public function defaults(): array
    {
        return [
            'mode' => 'url',
            'title' => __('Scan me'),
            'caption' => '',
            'url' => '',
            'upi_pa' => '', 'upi_pn' => '', 'upi_am' => '', 'upi_tn' => '',
            'wa_phone' => '', 'wa_text' => '',
            'text' => '',
            'wifi_ssid' => '', 'wifi_pass' => '', 'wifi_enc' => 'WPA', 'wifi_hidden' => false, 'wifi_show_pass' => true,
            'layout' => 'side',
            'dark' => '#000000',
            'light' => '#FFFFFF',
        ];
    }

    /** WhatsApp number: digits only, 10-digit Indian numbers get 91. */
    public static function phone(string $v): string
    {
        $digits = preg_replace('/\D+/', '', $v) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }
        return strlen($digits) === 10 ? '91' . $digits : $digits;
    }

    private static function wifiEscape(string $v): string
    {
        return preg_replace('/([\\\\;,:"])/', '\\\\$1', $v) ?? $v;
    }

    /** Text encoded in the QR ('' when the needed fields are empty). */
    public static function payload(array $c): string
    {
        switch ($c['mode']) {
            case 'upi':
                if ($c['upi_pa'] === '') {
                    return '';
                }
                $q = ['pa' => $c['upi_pa']];
                if ($c['upi_pn'] !== '') {
                    $q['pn'] = $c['upi_pn'];
                }
                if ($c['upi_am'] !== '') {
                    $q['am'] = $c['upi_am'];
                }
                $q['cu'] = 'INR';
                if ($c['upi_tn'] !== '') {
                    $q['tn'] = $c['upi_tn'];
                }
                return 'upi://pay?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
            case 'whatsapp':
                $p = self::phone((string) $c['wa_phone']);
                return $p === '' ? '' : 'https://wa.me/' . $p . ($c['wa_text'] !== '' ? '?text=' . rawurlencode($c['wa_text']) : '');
            case 'text':
                return (string) $c['text'];
            case 'wifi':
                if ($c['wifi_ssid'] === '') {
                    return '';
                }
                $enc = $c['wifi_enc'];
                return 'WIFI:T:' . $enc . ';S:' . self::wifiEscape($c['wifi_ssid']) . ';' . ($enc !== 'nopass' ? 'P:' . self::wifiEscape($c['wifi_pass']) . ';' : '') . ($c['wifi_hidden'] ? 'H:true;' : '') . ';';
            default:
                return (string) $c['url'];
        }
    }

    public function validate(array $in): array
    {
        $errors = [];
        $c = [
            'mode' => self::choice($in, 'mode', self::MODES, 'url'),
            'title' => self::str($in, 'title', 120),
            'caption' => self::str($in, 'caption', 300),
            'url' => '',
            'upi_pa' => self::str($in, 'upi_pa', 120), 'upi_pn' => self::str($in, 'upi_pn', 100),
            'upi_am' => self::str($in, 'upi_am', 12), 'upi_tn' => self::str($in, 'upi_tn', 80),
            'wa_phone' => self::str($in, 'wa_phone', 25), 'wa_text' => self::str($in, 'wa_text', 300),
            'text' => self::text($in, 'text', 600),
            'wifi_ssid' => self::str($in, 'wifi_ssid', 64), 'wifi_pass' => self::str($in, 'wifi_pass', 64),
            'wifi_enc' => self::choice($in, 'wifi_enc', self::WIFI_ENC, 'WPA'),
            'wifi_hidden' => self::bool($in, 'wifi_hidden'), 'wifi_show_pass' => self::bool($in, 'wifi_show_pass'),
            'layout' => self::choice($in, 'layout', ['side', 'center'], 'side'),
            'dark' => self::color($in, 'dark', '#000000'),
            'light' => self::color($in, 'light', '#FFFFFF'),
        ];
        switch ($c['mode']) {
            case 'url':
                $c['url'] = self::url($in, 'url', $errors, __('Web link'));
                if ($c['url'] === '' && !$errors) {
                    $errors[] = __('Enter the web link for the QR code.');
                }
                break;
            case 'upi':
                if (!preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,63}$/', $c['upi_pa'])) {
                    $errors[] = __('Enter a valid UPI ID, e.g. shopname@okaxis.');
                }
                if ($c['upi_am'] !== '' && (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $c['upi_am']) || (float) $c['upi_am'] <= 0)) {
                    $errors[] = __('The amount must be a number like 500 or 499.50.');
                }
                break;
            case 'whatsapp':
                $p = self::phone($c['wa_phone']);
                if (strlen($p) < 8 || strlen($p) > 15) {
                    $errors[] = __('Enter a WhatsApp number with country code, e.g. 91 98765 43210.');
                }
                break;
            case 'text':
                if ($c['text'] === '') {
                    $errors[] = __('Enter the text for the QR code.');
                }
                break;
            case 'wifi':
                if ($c['wifi_ssid'] === '') {
                    $errors[] = __('Enter the Wi-Fi name (SSID).');
                }
                if ($c['wifi_enc'] !== 'nopass' && $c['wifi_pass'] === '') {
                    $errors[] = __('Enter the Wi-Fi password, or choose an open network.');
                }
                break;
        }
        if (!$errors && strlen(self::payload($c)) > QrCode::capacity('L')) {
            $errors[] = __('The QR content is too long. Please shorten it.');
        }
        if (strtoupper($c['dark']) === strtoupper($c['light'])) {
            $errors[] = __('The QR colours must be different.');
        }
        return [$c, $errors];
    }

    public function form(array $config): string
    {
        $modes = array_map('__', self::MODES);
        $enc = array_map('__', self::WIFI_ENC);
        $group = static fn (string $mode, string $html): string => '<div class="col-12" data-qr-mode="' . e($mode) . '"' . ($config['mode'] === $mode ? '' : ' hidden') . '><div class="row g-3">' . $html . '</div></div>';
        return self::select('mode', __('QR code for'), $modes, $config['mode'], '', 'col-md-6', ['data-qr-switch' => '1'])
            . self::select('layout', __('Layout'), ['side' => __('QR on the left, text on the right'), 'center' => __('QR in the middle')], $config['layout'])
            . self::input('title', __('Title'), $config['title'], 'text', ['maxlength' => 120])
            . self::input('caption', __('Caption'), $config['caption'], 'text', ['maxlength' => 300, 'placeholder' => __('e.g. Scan to see our menu')])
            . $group('url', self::input('url', __('Web link'), $config['url'], 'url', ['maxlength' => 1000, 'placeholder' => 'https://'], '', 'col-12'))
            . $group('upi', self::input('upi_pa', __('UPI ID'), $config['upi_pa'], 'text', ['placeholder' => 'hotelname@okaxis'])
                . self::input('upi_pn', __('Payee name'), $config['upi_pn'], 'text', ['maxlength' => 100])
                . self::input('upi_am', __('Amount (₹, optional)'), $config['upi_am'], 'text', ['inputmode' => 'decimal', 'placeholder' => '500'])
                . self::input('upi_tn', __('Payment note (optional)'), $config['upi_tn'], 'text', ['maxlength' => 80]))
            . $group('whatsapp', self::input('wa_phone', __('WhatsApp number'), $config['wa_phone'], 'tel', ['placeholder' => '91 98765 43210'])
                . self::input('wa_text', __('Ready message (optional)'), $config['wa_text'], 'text', ['maxlength' => 300]))
            . $group('text', self::textarea('text', __('Text'), (string) $config['text'], 3, '', 'col-12', 600))
            . $group('wifi', self::input('wifi_ssid', __('Wi-Fi name (SSID)'), $config['wifi_ssid'], 'text', ['maxlength' => 64])
                . self::input('wifi_pass', __('Password'), $config['wifi_pass'], 'text', ['maxlength' => 64])
                . self::select('wifi_enc', __('Security'), $enc, $config['wifi_enc'])
                . self::checkbox('wifi_hidden', __('Hidden network'), (bool) $config['wifi_hidden'], 'col-md-3')
                . self::checkbox('wifi_show_pass', __('Show the password on the TV'), (bool) $config['wifi_show_pass'], 'col-md-3'))
            . self::colorInput('dark', __('QR colour'), $config['dark'])
            . self::colorInput('light', __('QR background'), $config['light'])
            . '<script>document.addEventListener("DOMContentLoaded",function(){var s=document.querySelector("[data-qr-switch]");if(!s)return;'
            . 'var f=function(){document.querySelectorAll("[data-qr-mode]").forEach(function(g){g.hidden=g.getAttribute("data-qr-mode")!==s.value;});};s.addEventListener("change",f);f();});</script>';
    }

    public function render(array $config, array $ctx): string
    {
        $payload = self::payload($config);
        $qr = '';
        if ($payload !== '') {
            try {
                $qr = QrCode::svg($payload, $config['dark'], $config['light'], $config['title']);
            } catch (Throwable) {
                $qr = '';
            }
        }
        $details = '';
        if ($config['mode'] === 'upi') {
            $details = ($config['upi_pn'] !== '' ? '<div class="qr-detail">' . e($config['upi_pn']) . '</div>' : '')
                . ($config['upi_am'] !== '' ? '<div class="qr-amount">₹ ' . e($config['upi_am']) . '</div>' : '')
                . '<div class="qr-detail hc-muted">' . e($config['upi_pa']) . '</div>';
        } elseif ($config['mode'] === 'wifi' && $config['wifi_ssid'] !== '') {
            $details = '<div class="qr-detail"><span class="hc-muted">' . e(__('Wi-Fi')) . ':</span> ' . e($config['wifi_ssid']) . '</div>'
                . ($config['wifi_show_pass'] && $config['wifi_enc'] !== 'nopass' ? '<div class="qr-detail"><span class="hc-muted">' . e(__('Password')) . ':</span> ' . e($config['wifi_pass']) . '</div>' : '');
        } elseif ($config['mode'] === 'whatsapp' && $config['wa_phone'] !== '') {
            $details = '<div class="qr-detail">+' . e(self::phone($config['wa_phone'])) . '</div>';
        }
        $box = $qr !== '' ? '<div class="qr-box" style="background:' . e($config['light']) . '">' . $qr . '</div>'
            : '<div class="qr-box qr-empty hc-card"><span>' . e(__('QR code')) . '</span></div>';
        $title = $config['title'] !== '' ? '<h1 class="qr-title">' . e($config['title']) . '</h1>' : '';
        $text = ($config['caption'] !== '' ? '<div class="qr-caption">' . e($config['caption']) . '</div>' : '') . $details;
        if ($config['layout'] === 'center') {
            return '<div class="qr-wrap qr-center">' . $title . $box . '<div class="qr-text">' . $text . '</div></div>';
        }
        return '<div class="qr-wrap qr-side">' . $box . '<div class="qr-text">' . $title . $text . '</div></div>';
    }
}
