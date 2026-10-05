<?php
declare(strict_types=1);

/**
 * Validated media uploads: extension + real MIME check, size limit, random rename,
 * image resize/re-encode (strips EXIF & any embedded payload), thumbnails,
 * optional ffmpeg video compression when available.
 */
final class Uploader
{
    public const IMAGE_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp',
    ];
    public const VIDEO_TYPES = [
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
        'mkv' => 'video/x-matroska', 'mov' => 'video/quicktime', '3gp' => 'video/3gpp',
    ];

    /**
     * Handle one uploaded file from $_FILES. $kind = image|video|logo|apk.
     * Files are stored per hotel: uploads/h{hotel_id}/media/YYYY/MM/…, uploads/h{id}/branding/…,
     * storage/apk/h{id}/… ; $scope 'platform' stores platform-wide branding in uploads/platform/.
     * (Files uploaded before 2.0 keep their old paths, e.g. media/2026/10/x.jpg — still valid.)
     * Returns ['path' => relative path under its root, 'size' => int, 'mime' => string, 'thumb' => ?string].
     *
     * @throws RuntimeException with a user-friendly message
     */
    public static function handle(array $file, string $kind, ?string $scope = null): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException(__('Invalid upload.'));
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('File is larger than the server upload limit (:s).', ['s' => ini_get('upload_max_filesize')]),
                UPLOAD_ERR_NO_FILE => __('No file was selected.'),
                UPLOAD_ERR_PARTIAL => __('Upload was interrupted. Please try again.'),
                default => __('Upload failed (code :c).', ['c' => $file['error']]),
            });
        }
        if (!is_uploaded_file($file['tmp_name']) && !defined('HC_TESTING')) {
            throw new RuntimeException(__('Invalid upload.'));
        }

        $maxMb = $kind === 'image' || $kind === 'logo' ? 25 : Settings::int('max_upload_mb', 200);
        if ($kind === 'apk') {
            $maxMb = 300;
        }
        if ($file['size'] > $maxMb * 1024 * 1024) {
            throw new RuntimeException(__('File too large. Maximum is :m MB.', ['m' => $maxMb]));
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';

        switch ($kind) {
            case 'image':
            case 'logo':
                if (!isset(self::IMAGE_TYPES[$ext]) || !in_array($mime, self::IMAGE_TYPES, true) || @getimagesize($file['tmp_name']) === false) {
                    throw new RuntimeException(__('Only JPG, PNG, GIF or WEBP images are allowed.'));
                }
                return self::storeImage($file['tmp_name'], $mime, self::root($scope) . '/' . ($kind === 'logo' ? 'branding' : 'media'), $kind === 'logo' ? 600 : Settings::int('image_max_width', 1920));
            case 'video':
                if (!isset(self::VIDEO_TYPES[$ext]) || !str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') {
                    throw new RuntimeException(__('Only MP4, WEBM, MKV, MOV or 3GP videos are allowed.'));
                }
                return self::storeVideo($file['tmp_name'], $ext, $mime, self::root($scope) . '/media');
            case 'apk':
                if ($ext !== 'apk' || !in_array($mime, ['application/vnd.android.package-archive', 'application/zip', 'application/java-archive', 'application/octet-stream'], true)) {
                    throw new RuntimeException(__('Only .apk files are allowed.'));
                }
                $zip = new ZipArchive();
                if ($zip->open($file['tmp_name']) !== true || $zip->locateName('AndroidManifest.xml') === false) {
                    throw new RuntimeException(__('This file is not a valid Android APK.'));
                }
                $zip->close();
                $sub = self::root($scope);
                $dir = HC_ROOT . '/storage/apk/' . $sub;
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $name = 'hotelcast_' . date('Ymd_His') . '_' . random_token(4) . '.apk';
                self::move($file['tmp_name'], $dir . '/' . $name);
                return ['path' => 'apk/' . $sub . '/' . $name, 'size' => (int) filesize($dir . '/' . $name), 'mime' => 'application/vnd.android.package-archive', 'thumb' => null];
        }
        throw new RuntimeException('Unknown upload kind');
    }

    /** Storage root for the current hotel ("h3") or the platform ("platform"). */
    public static function root(?string $scope = null): string
    {
        if ($scope === 'platform' || !Tenant::has()) {
            return 'platform';
        }
        return 'h' . Tenant::id();
    }

    private static function move(string $tmp, string $dest): void
    {
        $ok = defined('HC_TESTING') ? copy($tmp, $dest) : move_uploaded_file($tmp, $dest);
        if (!$ok) {
            throw new RuntimeException(__('Could not save the uploaded file. Check folder permissions.'));
        }
        @chmod($dest, 0644);
    }

    private static function subdir(string $base): array
    {
        $rel = $base . '/' . date('Y/m');
        $abs = HC_ROOT . '/uploads/' . $rel;
        if (!is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
        return [$rel, $abs];
    }

    public static function storeImage(string $tmp, string $mime, string $base, int $maxWidth): array
    {
        [$rel, $abs] = self::subdir($base);
        $name = random_token(12);
        $isGif = $mime === 'image/gif';
        $keepPng = $mime === 'image/png' || str_ends_with($base, 'branding');
        $ext = $isGif ? 'gif' : ($keepPng ? 'png' : 'jpg');
        $dest = $abs . '/' . $name . '.' . $ext;

        if ($isGif || !function_exists('imagecreatefromstring')) {
            // Animated GIFs are kept as-is (GD would drop animation).
            self::move($tmp, $dest);
        } else {
            $src = @imagecreatefromstring((string) file_get_contents($tmp));
            if (!$src) {
                throw new RuntimeException(__('Could not read this image.'));
            }
            $img = self::resize($src, $maxWidth);
            if ($keepPng) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                imagepng($img, $dest, 7);
            } else {
                imagejpeg($img, $dest, 85);
            }
            if ($img !== $src) {
                imagedestroy($img);
            }
            imagedestroy($src);
            @chmod($dest, 0644);
        }

        $thumb = self::thumbnail($dest, $rel, $name);
        return ['path' => $rel . '/' . $name . '.' . $ext, 'size' => (int) filesize($dest), 'mime' => $isGif ? 'image/gif' : ($keepPng ? 'image/png' : 'image/jpeg'), 'thumb' => $thumb];
    }

    private static function resize(GdImage $src, int $maxWidth): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= $maxWidth) {
            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
            return $dst;
        }
        $nh = (int) round($h * $maxWidth / $w);
        $dst = imagecreatetruecolor($maxWidth, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
        return $dst;
    }

    private static function thumbnail(string $file, string $rel, string $name): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($file));
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $tw = 320;
        $th = max(1, (int) round($h * $tw / max(1, $w)));
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $path = HC_ROOT . '/uploads/' . $rel . '/' . $name . '_thumb.jpg';
        imagejpeg($dst, $path, 75);
        imagedestroy($dst);
        imagedestroy($src);
        return $rel . '/' . $name . '_thumb.jpg';
    }

    private static function storeVideo(string $tmp, string $ext, string $mime, string $base = 'media'): array
    {
        [$rel, $abs] = self::subdir($base);
        $name = random_token(12);
        $dest = $abs . '/' . $name . '.' . $ext;
        self::move($tmp, $dest);

        $thumb = null;
        $ffmpeg = self::ffmpeg();
        if ($ffmpeg) {
            // Compress / normalise to H.264 MP4 720p-1080p for TV compatibility.
            $out = $abs . '/' . $name . '.mp4';
            $tmpOut = $abs . '/' . $name . '.tmp.mp4';
            $cmd = sprintf(
                '%s -y -i %s -vf "scale=\'min(1920,iw)\':-2" -c:v libx264 -preset veryfast -crf 26 -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($dest),
                escapeshellarg($tmpOut)
            );
            @exec($cmd, $o, $code);
            if ($code === 0 && is_file($tmpOut) && filesize($tmpOut) > 0 && filesize($tmpOut) < filesize($dest)) {
                @unlink($dest);
                rename($tmpOut, $out);
                $dest = $out;
                $ext = 'mp4';
                $mime = 'video/mp4';
            } else {
                @unlink($tmpOut);
            }
            $thumbPath = $abs . '/' . $name . '_thumb.jpg';
            @exec(sprintf('%s -y -ss 2 -i %s -frames:v 1 -vf scale=320:-2 %s 2>&1', escapeshellarg($ffmpeg), escapeshellarg($dest), escapeshellarg($thumbPath)), $o2, $c2);
            if ($c2 === 0 && is_file($thumbPath)) {
                $thumb = $rel . '/' . $name . '_thumb.jpg';
            }
        }
        return ['path' => $rel . '/' . $name . '.' . $ext, 'size' => (int) filesize($dest), 'mime' => $mime, 'thumb' => $thumb];
    }

    /** Path to ffmpeg if exec() is allowed and ffmpeg is installed, else null. */
    public static function ffmpeg(): ?string
    {
        static $path = false;
        if ($path === false) {
            $path = null;
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (function_exists('exec') && !in_array('exec', $disabled, true)) {
                $found = @exec('command -v ffmpeg 2>/dev/null');
                if ($found && is_executable($found)) {
                    $path = $found;
                }
            }
        }
        return $path;
    }

    /** Delete a stored upload (and its thumbnail). */
    public static function delete(?string $relPath, ?string $thumb = null): void
    {
        foreach ([$relPath, $thumb] as $p) {
            if ($p && !str_contains($p, '..')) {
                @unlink(HC_ROOT . '/uploads/' . $p);
            }
        }
    }
}
