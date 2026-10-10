<?php
declare(strict_types=1);

/**
 * 2.7: reads an APK without the Android SDK (no aapt): package name, versionCode and versionName from the
 * binary AndroidManifest.xml, and the SHA-256 of the signing certificate from the APK Signing Block
 * (APK Signature Scheme v3, else v2). Used by Super Admin → APK Manager (docs/modules/apk_manager.md).
 *
 * The signer check compares the certificate the APK *claims*; the cryptographic signature itself is
 * verified by Android on install (an update signed with another key is refused by the TV anyway:
 * INSTALL_FAILED_UPDATE_INCOMPATIBLE). Checking it here stops a wrongly signed build before it reaches TVs.
 */
final class ApkInfo
{
    private const SIG_V2 = 0x7109871a;
    private const SIG_V3 = 0xf05368c0;

    /**
     * ['package' => string, 'version_code' => int, 'version_name' => string, 'signer_sha256' => ?string]
     *
     * @throws RuntimeException with a user-friendly message when the file is not a readable APK
     */
    public static function read(string $file): array
    {
        $zip = new ZipArchive();
        if (!is_file($file) || $zip->open($file) !== true) {
            throw new RuntimeException(__('This file is not a valid Android APK.'));
        }
        $xml = $zip->getFromName('AndroidManifest.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            throw new RuntimeException(__('This file is not a valid Android APK.'));
        }
        $m = self::manifest($xml);
        if ($m === null || $m['package'] === '' || $m['version_code'] < 1) {
            throw new RuntimeException(__('The APK manifest could not be read (package / versionCode missing).'));
        }
        $m['signer_sha256'] = self::signerSha256($file);
        return $m;
    }

    /** Package / versionCode / versionName of the <manifest> element of a binary AndroidManifest.xml (null = not AXML). */
    public static function manifest(string $b): ?array
    {
        $len = strlen($b);
        if ($len < 8 || self::u16($b, 0) !== 0x0003) {
            return null;
        }
        $strings = [];
        $pos = (int) self::u16($b, 2);
        while ($pos + 8 <= $len) {
            $type = self::u16($b, $pos);
            $hsize = self::u16($b, $pos + 2);
            $size = self::u32($b, $pos + 4);
            if ($size < 8 || $pos + $size > $len) {
                return null;
            }
            if ($type === 0x0001) {
                $strings = self::stringPool(substr($b, $pos, $size));
            } elseif ($type === 0x0102) {
                $name = $strings[self::u32($b, $pos + 20)] ?? '';
                if ($name === 'manifest') {
                    $attrStart = self::u16($b, $pos + 24);
                    $attrSize = self::u16($b, $pos + 26) ?: 20;
                    $count = self::u16($b, $pos + 28);
                    $out = ['package' => '', 'version_code' => 0, 'version_name' => ''];
                    for ($i = 0; $i < $count; $i++) {
                        $a = $pos + $hsize + $attrStart + $i * $attrSize;
                        if ($a + 20 > $len) {
                            break;
                        }
                        $an = $strings[self::u32($b, $a + 4)] ?? '';
                        $raw = self::u32($b, $a + 8);
                        $dataType = ord($b[$a + 15]);
                        $data = self::u32($b, $a + 16);
                        $str = $raw !== 0xffffffff ? ($strings[$raw] ?? '') : ($dataType === 0x03 ? ($strings[$data] ?? '') : '');
                        if ($an === 'package') {
                            $out['package'] = $str;
                        } elseif ($an === 'versionCode') {
                            $out['version_code'] = $dataType >= 0x10 && $dataType <= 0x1f ? $data : (int) $str;
                        } elseif ($an === 'versionName') {
                            $out['version_name'] = $str !== '' ? $str : ($dataType >= 0x10 && $dataType <= 0x1f ? (string) $data : '');
                        }
                    }
                    return $out;
                }
            }
            $pos += $size;
        }
        return null;
    }

    /** Strings of a ResStringPool chunk. */
    private static function stringPool(string $c): array
    {
        $count = self::u32($c, 8);
        $utf8 = (self::u32($c, 16) & 0x100) !== 0;
        $start = self::u32($c, 20);
        $hsize = self::u16($c, 2);
        $out = [];
        $n = strlen($c);
        for ($i = 0; $i < $count && $i < 100000; $i++) {
            $off = $start + self::u32($c, $hsize + $i * 4);
            if ($off >= $n) {
                $out[] = '';
                continue;
            }
            if ($utf8) {
                $o = $off;
                $o += (ord($c[$o]) & 0x80) ? 2 : 1; // character count
                $bl = ord($c[$o]);
                if ($bl & 0x80) {
                    $bl = (($bl & 0x7f) << 8) | ord($c[$o + 1]);
                    $o += 2;
                } else {
                    $o += 1;
                }
                $out[] = substr($c, $o, $bl);
            } else {
                $cl = self::u16($c, $off);
                $o = $off + 2;
                if ($cl & 0x8000) {
                    $cl = (($cl & 0x7fff) << 16) | self::u16($c, $off + 2);
                    $o += 2;
                }
                $out[] = (string) mb_convert_encoding(substr($c, $o, $cl * 2), 'UTF-8', 'UTF-16LE');
            }
        }
        return $out;
    }

    /**
     * SHA-256 (hex) of the first signer's certificate from the APK Signing Block (v3, else v2), the same
     * value `apksigner verify --print-certs` shows. Null when the APK has no v2/v3 signature.
     */
    public static function signerSha256(string $file): ?string
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return null;
        }
        try {
            $size = (int) filesize($file);
            $tail = min($size, 65535 + 22);
            fseek($fh, $size - $tail);
            $buf = (string) fread($fh, $tail);
            $eocd = strrpos($buf, "PK\x05\x06");
            if ($eocd === false || $eocd + 22 > strlen($buf)) {
                return null;
            }
            $cdOffset = self::u32($buf, $eocd + 16);
            if ($cdOffset < 32 || $cdOffset > $size) {
                return null;
            }
            fseek($fh, $cdOffset - 24);
            $footer = (string) fread($fh, 24);
            if (strlen($footer) !== 24 || substr($footer, 8, 16) !== 'APK Sig Block 42') {
                return null;
            }
            $blockSize = self::u64($footer, 0);
            if ($blockSize < 24 || $blockSize > 64 * 1024 * 1024 || $blockSize + 8 > $cdOffset) {
                return null;
            }
            fseek($fh, $cdOffset - $blockSize);
            $pairs = (string) fread($fh, $blockSize - 24); // ID-value pairs (after the leading size field)
            $found = [];
            $p = 0;
            $n = strlen($pairs);
            while ($p + 12 <= $n) {
                $plen = self::u64($pairs, $p);
                if ($plen < 4 || $p + 8 + $plen > $n) {
                    break;
                }
                $id = self::u32($pairs, $p + 8);
                $found[$id] = substr($pairs, $p + 12, $plen - 4);
                $p += 8 + $plen;
            }
            foreach ([self::SIG_V3, self::SIG_V2] as $id) {
                if (isset($found[$id]) && ($cert = self::firstCert($found[$id])) !== null) {
                    return hash('sha256', $cert);
                }
            }
            return null;
        } finally {
            fclose($fh);
        }
    }

    /** First certificate (DER) of the first signer of a v2/v3 signature scheme block. */
    private static function firstCert(string $v): ?string
    {
        $r = 0;
        $signers = self::lp($v, $r);
        if ($signers === null) {
            return null;
        }
        $r = 0;
        $signer = self::lp($signers, $r);
        if ($signer === null) {
            return null;
        }
        $r = 0;
        $signed = self::lp($signer, $r);
        if ($signed === null) {
            return null;
        }
        $r = 0;
        if (self::lp($signed, $r) === null) { // digests
            return null;
        }
        $certs = self::lp($signed, $r);
        if ($certs === null) {
            return null;
        }
        $r = 0;
        return self::lp($certs, $r);
    }

    /** uint32-length-prefixed slice at $pos (advances $pos). */
    private static function lp(string $s, int &$pos): ?string
    {
        if ($pos + 4 > strlen($s)) {
            return null;
        }
        $l = self::u32($s, $pos);
        if ($l < 0 || $pos + 4 + $l > strlen($s)) {
            return null;
        }
        $out = substr($s, $pos + 4, $l);
        $pos += 4 + $l;
        return $out;
    }

    private static function u16(string $s, int $o): int
    {
        return $o + 2 <= strlen($s) ? unpack('v', $s, $o)[1] : 0;
    }

    private static function u32(string $s, int $o): int
    {
        return $o + 4 <= strlen($s) ? unpack('V', $s, $o)[1] : 0;
    }

    private static function u64(string $s, int $o): int
    {
        return $o + 8 <= strlen($s) ? unpack('P', $s, $o)[1] : 0;
    }
}
