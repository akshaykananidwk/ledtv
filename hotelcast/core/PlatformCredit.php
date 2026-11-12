<?php
declare(strict_types=1);

/**
 * 2.9.4 Platform credit line on every TV (docs/modules/platform_credit.md): one line from the platform owner —
 * who made the system and the phone number to call — appended to the ticker bar of every screen of every
 * customer, in Gujarati and English. Only the Super Admin edits it (Platform settings → TV credit line);
 * customers, resellers and TVs cannot change, hide or remove it: it is added on the server after all customer
 * tickers, plan filters and extensions (ContentResolver::finish), so it needs no TV app update and also
 * reaches screens without a ticker or without the ticker module in their plan.
 *
 * Not shown while a screen is off, suspended, showing an emergency, or as part of a video wall.
 * Placeholders in the texts: :brand (platform name) and :phone (credit phone, default the support phone).
 */
final class PlatformCredit
{
    public const K_ENABLED = 'platform_credit_enabled';
    public const K_GU = 'platform_credit_gu';
    public const K_EN = 'platform_credit_en';
    public const K_PHONE = 'platform_credit_phone';
    public const MAX = 300;
    private const NO_CREDIT_MODES = ['suspended', 'off', 'emergency'];

    public const DEFAULT_GU = '🖥️ આ ડિજિટલ TV સિસ્ટમ :brand દ્વારા બનાવવામાં આવી છે — તમારા મંદિર, હોટલ, દુકાન કે ઓફિસ માટે આવી સિસ્ટમ જોઈએ તો કોલ કરો 📞 :phone';
    public const DEFAULT_EN = 'Digital TV system by :brand — want one for your business? Call 📞 :phone';

    /** Current settings (defaults when never saved). */
    public static function config(): array
    {
        return [
            'enabled' => (string) Settings::platform(self::K_ENABLED, '1') !== '0',
            'gu' => (string) Settings::platform(self::K_GU, self::DEFAULT_GU),
            'en' => (string) Settings::platform(self::K_EN, self::DEFAULT_EN),
            'phone' => (string) Settings::platform(self::K_PHONE, ''),
        ];
    }

    /** Phone shown: the credit phone, else the platform support phone. */
    public static function phone(?array $cfg = null): string
    {
        $cfg ??= self::config();
        $p = trim($cfg['phone']);
        return $p !== '' ? $p : trim((string) Settings::platform('platform_support_phone', ''));
    }

    /** The line as the TVs show it ('' = off / nothing to show). */
    public static function line(?array $cfg = null): string
    {
        $cfg ??= self::config();
        if (!$cfg['enabled']) {
            return '';
        }
        $brand = trim((string) Settings::platform('platform_name', '')) ?: Branding::DEFAULT_PRODUCT;
        $phone = self::phone($cfg);
        $parts = [];
        foreach ([$cfg['gu'], $cfg['en']] as $t) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $t));
            if ($t === '') {
                continue;
            }
            $t = strtr($t, [':brand' => $brand, ':phone' => $phone]);
            if ($phone === '') {
                // No number configured yet: drop a dangling "📞" / "Call" ending instead of showing an empty slot.
                $t = rtrim((string) preg_replace('/\s*📞\s*$/u', '', $t), " \t—-:");
            }
            $parts[] = $t;
        }
        return implode(Tickers::SEPARATOR, $parts);
    }

    /** Append the credit to a TV content object (called last in ContentResolver::finish). */
    public static function apply(array &$content, array $room = []): void
    {
        try {
            $line = self::line();
        } catch (Throwable $e) {
            Logger::error('Platform credit failed: ' . $e->getMessage());
            return;
        }
        if ($line === '' || empty($room['id'])) {
            return;
        }
        $mode = (string) ($content['mode'] ?? '');
        if (in_array($mode, self::NO_CREDIT_MODES, true) || ($content['screen_on'] ?? true) === false || !empty($content['wall'])) {
            return;
        }
        if (!isset($content['overlay']) || !is_array($content['overlay'])) {
            $content['overlay'] = [];
        }
        $t = $content['overlay']['ticker'] ?? null;
        if (is_array($t) && trim((string) ($t['text'] ?? '')) !== '') {
            $messages = is_array($t['messages'] ?? null) ? array_values($t['messages']) : [(string) $t['text']];
            $messages[] = $line;
            $t['messages'] = $messages;
            $t['text'] = rtrim((string) $t['text']) . Tickers::SEPARATOR . $line;
            $content['overlay']['ticker'] = $t;
        } else {
            $content['overlay']['ticker'] = Tickers::payload([], [$line]);
        }
        $content['overlay']['ticker']['credit'] = $line;
    }

    /** Validate and save the Super Admin form. @return string[] errors */
    public static function save(array $in): array
    {
        $gu = trim(mb_substr((string) ($in['gu'] ?? ''), 0, self::MAX));
        $en = trim(mb_substr((string) ($in['en'] ?? ''), 0, self::MAX));
        $phone = trim(mb_substr((string) ($in['phone'] ?? ''), 0, 40));
        $errors = [];
        if ($gu === '' && $en === '') {
            $errors[] = __('Enter the credit line in Gujarati or English.');
        }
        if ($phone !== '' && !preg_match('/^[0-9+()\s\/-]{6,40}$/', $phone)) {
            $errors[] = __('Enter a valid phone number.');
        }
        if ($errors) {
            return $errors;
        }
        Settings::setPlatform(self::K_ENABLED, !empty($in['enabled']) ? '1' : '0');
        Settings::setPlatform(self::K_GU, $gu);
        Settings::setPlatform(self::K_EN, $en);
        Settings::setPlatform(self::K_PHONE, $phone);
        Settings::flush();
        ActivityLog::add('platform_credit', 'settings', null, (!empty($in['enabled']) ? 'on' : 'off') . ' · ' . $phone, null);
        Tenant::each(static function (): void {
            Settings::bumpContentVersion();
        });
        return [];
    }
}
