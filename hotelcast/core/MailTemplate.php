<?php
declare(strict_types=1);

/**
 * Branded e-mail layout (2.8, docs/modules/email.md): logo / name / colour from Branding (white-label aware:
 * a customer's mails use the customer's / reseller's brand), optional big code, a button, footer with
 * the support contact. Always returns a plain-text alternative next to the HTML.
 *
 *   [$text, $html] = MailTemplate::render([
 *       'brand' => Branding::get($hotelId), 'lang' => 'gu', 'title' => '…', 'paragraphs' => ['…'],
 *       'code' => '123456', 'button' => ['label' => '…', 'url' => '…'], 'after' => ['…'], 'small' => ['…'],
 *   ]);
 */
final class MailTemplate
{
    public static function render(array $p): array
    {
        $brand = $p['brand'] ?? Branding::get(0);
        $lang = (string) ($p['lang'] ?? 'en');
        $t = static fn (string $k, array $r = []): string => I18n::translate($k, $lang, $r);
        $product = (string) $brand['product'];
        $color = clean_color((string) ($brand['color'] ?? '#7B1FA2'), '#7B1FA2');
        $title = trim((string) ($p['title'] ?? ''));
        $paras = array_values(array_filter(array_map('strval', (array) ($p['paragraphs'] ?? [])), static fn ($s) => trim($s) !== ''));
        $after = array_values(array_filter(array_map('strval', (array) ($p['after'] ?? [])), static fn ($s) => trim($s) !== ''));
        $small = array_values(array_filter(array_map('strval', (array) ($p['small'] ?? [])), static fn ($s) => trim($s) !== ''));
        $code = trim((string) ($p['code'] ?? ''));
        $button = is_array($p['button'] ?? null) && !empty($p['button']['url']) ? $p['button'] : null;
        $support = array_values(array_filter([trim((string) ($brand['support_phone'] ?? '')), trim((string) ($brand['support_email'] ?? ''))]));
        $footer = trim((string) ($brand['footer'] ?? ''));

        // ---- plain text
        $txt = [];
        if ($title !== '') {
            $txt[] = $title;
            $txt[] = str_repeat('=', min(60, max(3, mb_strlen($title))));
        }
        foreach ($paras as $s) {
            $txt[] = $s;
        }
        if ($code !== '') {
            $txt[] = '    ' . $code;
        }
        if ($button) {
            $txt[] = $button['label'] . ":\n" . $button['url'];
        }
        foreach ($after as $s) {
            $txt[] = $s;
        }
        foreach ($small as $s) {
            $txt[] = $s;
        }
        $sig = '-- ' . "\n" . $product;
        if ($support) {
            $sig .= "\n" . $t('Support') . ': ' . implode(' · ', $support);
        }
        if ($footer !== '') {
            $sig .= "\n" . $footer;
        }
        $txt[] = $sig;
        $text = implode("\n\n", $txt) . "\n";

        // ---- HTML (tables + inline styles for mail clients)
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $para = static function (string $s, string $style) use ($esc): string {
            $h = nl2br($esc($s), false);
            $h = (string) preg_replace_callback('~https?://[^\s<>"\']+~', static function ($m) {
                $url = rtrim($m[0], '.,;:)');
                $rest = substr($m[0], strlen($url));
                return '<a href="' . $url . '" style="color:inherit;word-break:break-all">' . $url . '</a>' . $rest;
            }, $h);
            return '<p style="' . $style . '">' . $h . '</p>';
        };
        $logo = (string) ($brand['logo_url'] ?? '');
        $logoOk = $logo !== '' && preg_match('~^https?://~', $logo) && !preg_match('~^https?://(localhost|127\.)~', $logo);
        $pStyle = 'margin:0 0 16px;font-size:15px;line-height:1.55;color:#1f2937';
        $h = '<!DOCTYPE html><html lang="' . $esc($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . $esc($title !== '' ? $title : $product) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Noto Sans Gujarati\',\'Noto Sans Devanagari\',Arial,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">'
            . '<tr><td style="background:' . $color . ';padding:20px 28px;color:#ffffff">';
        if ($logoOk) {
            $h .= '<img src="' . $esc($logo) . '" alt="' . $esc($product) . '" style="max-height:44px;max-width:180px;display:block;margin-bottom:8px;background:#ffffff;border-radius:6px;padding:4px">';
        }
        $h .= '<div style="font-size:18px;font-weight:700;color:#ffffff">' . $esc($product) . '</div></td></tr>'
            . '<tr><td style="padding:28px">';
        if ($title !== '') {
            $h .= '<h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;color:#111827">' . $esc($title) . '</h1>';
        }
        foreach ($paras as $s) {
            $h .= $para($s, $pStyle);
        }
        if ($code !== '') {
            $h .= '<div style="margin:8px 0 22px;text-align:center"><span style="display:inline-block;font-family:Consolas,Menlo,monospace;font-size:30px;letter-spacing:8px;font-weight:700;color:#111827;background:#f3f4f6;border:1px dashed #9ca3af;border-radius:8px;padding:12px 20px">' . $esc($code) . '</span></div>';
        }
        if ($button) {
            $url = $esc((string) $button['url']);
            $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 22px"><tr><td style="border-radius:8px;background:' . $color . '">'
                . '<a href="' . $url . '" style="display:inline-block;padding:13px 26px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px">' . $esc((string) $button['label']) . '</a></td></tr></table>'
                . '<p style="margin:0 0 18px;font-size:12px;line-height:1.5;color:#6b7280">' . $esc($t('If the button does not work, copy this link into your browser:')) . '<br><a href="' . $url . '" style="color:#6b7280;word-break:break-all">' . $url . '</a></p>';
        }
        foreach ($after as $s) {
            $h .= $para($s, $pStyle);
        }
        foreach ($small as $s) {
            $h .= $para($s, 'margin:0 0 10px;font-size:13px;line-height:1.5;color:#6b7280');
        }
        $h .= '</td></tr><tr><td style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280">'
            . '<strong style="color:#374151">' . $esc($product) . '</strong>';
        if ($support) {
            $h .= '<br>' . $esc($t('Support')) . ': ' . $esc(implode(' · ', $support));
        }
        if ($footer !== '') {
            $h .= '<br>' . nl2br($esc($footer), false);
        }
        $h .= '</td></tr></table></td></tr></table></body></html>';
        return [$text, $h];
    }

    /**
     * Wrap an existing plain-text notification (offline alerts, invoices, reminders …) in the layout:
     * blank lines separate paragraphs, URLs become links. A trailing line equal to the product name
     * (old signature) is dropped because the footer shows it.
     */
    public static function fromText(string $text, ?array $brand = null, string $lang = 'en', string $title = ''): array
    {
        $brand ??= Branding::get(0);
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $paras = preg_split('/\n{2,}/', $text) ?: [];
        if ($paras && trim((string) end($paras)) === (string) $brand['product']) {
            array_pop($paras);
        }
        return self::render(['brand' => $brand, 'lang' => $lang, 'title' => $title, 'paragraphs' => $paras]);
    }
}
