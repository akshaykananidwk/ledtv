<?php
declare(strict_types=1);

/**
 * TV device controls (#4 #5 #15 #16): adds the `volume` policy and the guest menu items
 * live_tv / input (HDMI 1–4) / cast to the Content object (V2_SPEC § TV contract).
 * Items are APPENDED to $content['guest_menu'] (other modules append QR / local guide items).
 *
 * Hotel settings (Admin → TV controls):
 *   tv_volume_enabled, volume_default, volume_max, volume_night_enabled, volume_night_max,
 *   volume_night_from, volume_night_to;
 *   guest_menu_live_tv, guest_menu_inputs (JSON {"hdmi1": "label", …} of the enabled inputs),
 *   guest_menu_cast, guest_cast_text_en / _gu / _hi, wifi_ssid, wifi_password (shared with the guests module).
 */
final class DeviceControlsExtension implements ContentExtension
{
    public const INPUTS = ['hdmi1', 'hdmi2', 'hdmi3', 'hdmi4'];

    public function apply(array &$content, array $room): void
    {
        $volume = self::volume();
        if ($volume !== null) {
            $content['volume'] = $volume;
        }
        if (($content['mode'] ?? '') === 'suspended') {
            return;
        }
        $lang = self::language($content);
        $items = self::menuItems($lang);
        if ($items) {
            $content['guest_menu'] = array_merge(is_array($content['guest_menu'] ?? null) ? $content['guest_menu'] : [], $items);
        }
    }

    /** `volume` object or null when the hotel did not enable a volume policy. */
    public static function volume(): ?array
    {
        if (!Settings::bool('tv_volume_enabled')) {
            return null;
        }
        $pct = static function (string $key, ?int $default): ?int {
            $v = trim((string) Settings::get($key, ''));
            return $v === '' || !is_numeric($v) ? $default : max(0, min(100, (int) $v));
        };
        $night = Settings::bool('volume_night_enabled');
        $from = Broadcaster::parseTime((string) Settings::get('volume_night_from', '22:00'));
        $to = Broadcaster::parseTime((string) Settings::get('volume_night_to', '06:00'));
        $max = $pct('volume_max', 100);
        $default = $pct('volume_default', null);
        return [
            'default' => $default !== null ? min($default, $max) : null,
            'max' => $max,
            'night_max' => $night && $from && $to ? $pct('volume_night_max', 25) : null,
            'night_from' => $night && $from ? substr($from, 0, 5) : null,
            'night_to' => $night && $to ? substr($to, 0, 5) : null,
        ];
    }

    /** Guest language (set by the guests module when a guest is checked in) or the hotel default. */
    private static function language(array $content): string
    {
        $l = (string) ($content['guest']['language'] ?? Settings::get('default_language', 'en'));
        return isset(I18n::GUEST_LANGUAGES[$l]) ? $l : 'en';
    }

    /** Enabled HDMI inputs: ['hdmi1' => 'custom label or ""', …] in port order. */
    public static function inputs(): array
    {
        $raw = json_decode((string) Settings::get('guest_menu_inputs', ''), true);
        $out = [];
        foreach (self::INPUTS as $in) {
            if (is_array($raw) && array_key_exists($in, $raw)) {
                $out[$in] = mb_substr(trim((string) $raw[$in]), 0, 40);
            }
        }
        return $out;
    }

    /** Cast instructions in $lang: custom text (placeholders {ssid} {password}) or the default text. */
    public static function castText(string $lang): string
    {
        $ssid = trim((string) Settings::get('wifi_ssid', ''));
        $pw = trim((string) Settings::get('wifi_password', ''));
        // Custom text of the guest's language; without one the built-in text in that language is used.
        $custom = trim((string) Settings::get('guest_cast_text_' . $lang, ''));
        if ($custom !== '') {
            return strtr($custom, ['{ssid}' => $ssid, '{password}' => $pw]);
        }
        if ($ssid === '') {
            return I18n::translate('Connect your phone to the same Wi-Fi as this TV, open YouTube or another app with the Cast icon, tap Cast and choose this TV.', $lang);
        }
        $t = I18n::translate("Connect your phone to the Wi-Fi ':ssid', open YouTube or another app with the Cast icon, tap Cast and choose this TV.", $lang, ['ssid' => $ssid]);
        return $pw !== '' ? $t . ' ' . I18n::translate('Wi-Fi password: :pw', $lang, ['pw' => $pw]) : $t;
    }

    /** live_tv / input / cast items for the guest menu. */
    public static function menuItems(string $lang): array
    {
        $items = [];
        if (Settings::bool('guest_menu_live_tv')) {
            $items[] = ['id' => 'live_tv', 'type' => 'live_tv', 'title' => I18n::translate('Live TV', $lang), 'icon' => 'tv'];
        }
        foreach (self::inputs() as $in => $label) {
            $items[] = [
                'id' => $in, 'type' => 'input',
                'title' => $label !== '' ? $label : I18n::translate('HDMI :n', $lang, ['n' => substr($in, 4)]),
                'icon' => 'hdmi', 'input' => $in,
            ];
        }
        if (Settings::bool('guest_menu_cast')) {
            $items[] = ['id' => 'cast', 'type' => 'cast', 'title' => I18n::translate('Cast from phone', $lang), 'icon' => 'cast', 'text' => self::castText($lang)];
        }
        return $items;
    }
}
