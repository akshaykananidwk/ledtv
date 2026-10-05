<?php
declare(strict_types=1);

/**
 * App icons for the admin PWA, drawn with GD from the white-label branding: the brand colour as
 * background and the brand logo (or a TV glyph when there is no logo) in white. Maskable icons
 * keep the artwork inside the 80 % safe zone. Results are cached in storage/cache/pwa/.
 * Without GD, the static PNGs in assets/pwa/ are used.
 */
final class PwaIcon
{
    public const SIZES = [48, 72, 96, 128, 144, 152, 180, 192, 256, 384, 512];

    public static function normaliseSize(int $s): int
    {
        foreach (self::SIZES as $size) {
            if ($s <= $size) {
                return $size;
            }
        }
        return 512;
    }

    /** PNG bytes of an icon. */
    public static function png(int $size, bool $maskable, string $color, ?string $logoFile = null): string
    {
        $size = self::normaliseSize($size);
        if (!function_exists('imagecreatetruecolor')) {
            return (string) @file_get_contents(self::staticFile($size, $maskable));
        }
        $key = md5(implode('|', [$size, (int) $maskable, $color, $logoFile ?? '', $logoFile && is_file($logoFile) ? filemtime($logoFile) : 0, 'v1']));
        $dir = HC_ROOT . '/storage/cache/pwa';
        $cached = $dir . '/' . $key . '.png';
        if (is_file($cached)) {
            return (string) file_get_contents($cached);
        }
        $png = self::draw($size, $maskable, $color, $logoFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($cached, $png, LOCK_EX);
        return $png;
    }

    public static function staticFile(int $size, bool $maskable): string
    {
        return HC_ROOT . '/assets/pwa/' . ($maskable ? 'icon-maskable-512.png' : ($size <= 192 ? 'icon-192.png' : 'icon-512.png'));
    }

    /** Draw at 3× and downsample for smooth edges. */
    public static function draw(int $size, bool $maskable, string $color, ?string $logoFile = null): string
    {
        $ss = 3;
        $S = $size * $ss;
        $im = imagecreatetruecolor($S, $S);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim(clean_color($color, '#7B1FA2'), '#'), 2));
        $bg = imagecolorallocate($im, $r, $g, $b);
        $white = imagecolorallocate($im, 255, 255, 255);
        if ($maskable) {
            imagefilledrectangle($im, 0, 0, $S - 1, $S - 1, $bg);
        } else {
            self::roundedRect($im, 0, 0, $S - 1, $S - 1, (int) ($S * 0.22), $bg);
        }
        // Artwork area: maskable icons must stay inside the inner 80 % circle.
        $area = $maskable ? 0.56 : 0.70;
        $logo = $logoFile && is_file($logoFile) && filesize($logoFile) < 5 * 1024 * 1024 ? @imagecreatefromstring((string) file_get_contents($logoFile)) : false;
        if ($logo) {
            $pad = (int) ($S * (1 - $area) / 2);
            $box = $S - 2 * $pad;
            self::roundedRect($im, $pad, $pad, $pad + $box, $pad + $box, (int) ($box * 0.18), $white);
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            $inner = (int) ($box * 0.82);
            $scale = min($inner / max(1, $lw), $inner / max(1, $lh));
            $w = max(1, (int) ($lw * $scale));
            $h = max(1, (int) ($lh * $scale));
            imagecopyresampled($im, $logo, (int) (($S - $w) / 2), (int) (($S - $h) / 2), 0, 0, $w, $h, $lw, $lh);
            imagedestroy($logo);
        } else {
            // TV glyph: screen outline, play triangle, stand.
            $w = $S * $area;
            $x0 = ($S - $w) / 2;
            $y0 = $S * 0.5 - $w * 0.42;
            $x1 = $x0 + $w;
            $y1 = $y0 + $w * 0.64;
            $t = max(2, (int) ($w * 0.075));
            $rad = (int) ($w * 0.09);
            self::roundedRect($im, (int) $x0, (int) $y0, (int) $x1, (int) $y1, $rad, $white);
            self::roundedRect($im, (int) $x0 + $t, (int) $y0 + $t, (int) $x1 - $t, (int) $y1 - $t, max(1, $rad - $t), $bg);
            $cx = ($x0 + $x1) / 2 + $w * 0.03;
            $cy = ($y0 + $y1) / 2;
            $tr = $w * 0.14;
            imagefilledpolygon($im, [
                (int) ($cx - $tr * 0.8), (int) ($cy - $tr),
                (int) ($cx - $tr * 0.8), (int) ($cy + $tr),
                (int) ($cx + $tr), (int) $cy,
            ], $white);
            $sy = (int) ($y1 + $w * 0.07);
            self::roundedRect($im, (int) ($S / 2 - $w * 0.06), (int) $y1, (int) ($S / 2 + $w * 0.06), $sy, 0, $white);
            self::roundedRect($im, (int) ($S / 2 - $w * 0.24), $sy, (int) ($S / 2 + $w * 0.24), (int) ($sy + $t), (int) ($t / 2), $white);
        }
        $out = imagecreatetruecolor($size, $size);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $S, $S);
        imagedestroy($im);
        ob_start();
        imagepng($out, null, 9);
        imagedestroy($out);
        return (string) ob_get_clean();
    }

    private static function roundedRect(GdImage $im, int $x0, int $y0, int $x1, int $y1, int $r, int $color): void
    {
        $r = max(0, min($r, (int) (($x1 - $x0) / 2), (int) (($y1 - $y0) / 2)));
        if ($r === 0) {
            imagefilledrectangle($im, $x0, $y0, $x1, $y1, $color);
            return;
        }
        imagefilledrectangle($im, $x0 + $r, $y0, $x1 - $r, $y1, $color);
        imagefilledrectangle($im, $x0, $y0 + $r, $x1, $y1 - $r, $color);
        foreach ([[$x0 + $r, $y0 + $r], [$x1 - $r, $y0 + $r], [$x0 + $r, $y1 - $r], [$x1 - $r, $y1 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }
}
