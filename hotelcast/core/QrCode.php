<?php
declare(strict_types=1);

/**
 * Small pure-PHP QR code encoder (byte mode, error correction L or M, versions 1–20) used by the
 * template library (Wi-Fi QR, map QR, room-service QR) — no external API, works offline.
 *
 * The algorithm follows the QR specification (ISO/IEC 18004) the same way as the well-known
 * "qrcode-generator" library by Kazuhiko Arase (MIT), so both produce identical matrices.
 *
 *   QrCode::svg('https://maps.google.com/?q=Dwarka')   // inline <svg>, black on white
 *   QrCode::matrix('WIFI:T:WPA;S:Hotel;P:secret;;')     // bool[][] (true = dark)
 */
final class QrCode
{
    /** [version => [L block spec, M block spec]]; spec = [count, total, data(, count2, total2, data2)]. */
    private const RS_BLOCKS = [
        1 => [[1, 26, 19], [1, 26, 16]],
        2 => [[1, 44, 34], [1, 44, 28]],
        3 => [[1, 70, 55], [1, 70, 44]],
        4 => [[1, 100, 80], [2, 50, 32]],
        5 => [[1, 134, 108], [2, 67, 43]],
        6 => [[2, 86, 68], [4, 43, 27]],
        7 => [[2, 98, 78], [4, 49, 31]],
        8 => [[2, 121, 97], [2, 60, 38, 2, 61, 39]],
        9 => [[2, 146, 116], [3, 58, 36, 2, 59, 37]],
        10 => [[2, 86, 68, 2, 87, 69], [4, 69, 43, 1, 70, 44]],
        11 => [[4, 101, 81], [1, 80, 50, 4, 81, 51]],
        12 => [[2, 116, 92, 2, 117, 93], [6, 58, 36, 2, 59, 37]],
        13 => [[4, 133, 107], [8, 59, 37, 1, 60, 38]],
        14 => [[3, 145, 115, 1, 146, 116], [4, 64, 40, 5, 65, 41]],
        15 => [[5, 109, 87, 1, 110, 88], [5, 65, 41, 5, 66, 42]],
        16 => [[5, 122, 98, 1, 123, 99], [7, 73, 45, 3, 74, 46]],
        17 => [[1, 135, 107, 5, 136, 108], [10, 74, 46, 1, 75, 47]],
        18 => [[5, 150, 120, 1, 151, 121], [9, 69, 43, 4, 70, 44]],
        19 => [[3, 141, 113, 4, 142, 114], [3, 70, 44, 11, 71, 45]],
        20 => [[3, 135, 107, 5, 136, 108], [3, 67, 41, 13, 68, 42]],
    ];

    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38],
        8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58],
        13 => [6, 34, 62], 14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86], 20 => [6, 34, 62, 90],
    ];

    /** Format-info value of the error correction levels. */
    private const ECL_BITS = ['L' => 1, 'M' => 0];

    private const G15 = 0b10100110111;
    private const G18 = 0b1111100100101;
    private const G15_MASK = 0b101010000010010;

    private static array $exp = [];
    private static array $log = [];

    private int $version;
    private int $size;
    private string $ecl;
    /** @var array<int, array<int, bool|null>> */
    private array $m = [];
    private array $data;

    private function __construct(string $bytes, string $ecl)
    {
        self::initGalois();
        $this->ecl = isset(self::ECL_BITS[$ecl]) ? $ecl : 'M';
        $this->version = self::pickVersion(strlen($bytes), $this->ecl);
        $this->size = $this->version * 4 + 17;
        $this->data = $this->createData($bytes);
        $best = 0;
        $min = INF;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->make(true, $mask);
            $lost = $this->lostPoint();
            if ($lost < $min) {
                $min = $lost;
                $best = $mask;
            }
        }
        $this->make(false, $best);
    }

    /** Largest payload (bytes) this encoder accepts for a level. */
    public static function capacity(string $ecl = 'M'): int
    {
        return self::dataCodewords(20, $ecl) - 3;
    }

    /** @return array<int, array<int, bool>> */
    public static function matrix(string $text, string $ecl = 'M'): array
    {
        $qr = new self($text, $ecl);
        return $qr->m;
    }

    /**
     * Inline SVG (crisp edges, quiet zone of 4 modules). $text is raw UTF-8 bytes. Long texts fall back
     * to level L; anything above ~850 bytes throws InvalidArgumentException.
     */
    public static function svg(string $text, string $dark = '#000000', string $light = '#FFFFFF', string $label = ''): string
    {
        $ecl = strlen($text) <= self::capacity('M') ? 'M' : 'L';
        $m = self::matrix($text, $ecl);
        $n = count($m);
        $q = 4;
        $path = '';
        foreach ($m as $r => $row) {
            $c = 0;
            while ($c < $n) {
                if ($row[$c]) {
                    $start = $c;
                    while ($c < $n && $row[$c]) {
                        $c++;
                    }
                    $path .= 'M' . ($start + $q) . ' ' . ($r + $q) . 'h' . ($c - $start) . 'v1h-' . ($c - $start) . 'z';
                } else {
                    $c++;
                }
            }
        }
        $dim = $n + 2 * $q;
        $dark = clean_color($dark, '#000000');
        $light = clean_color($light, '#FFFFFF');
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img"'
            . ($label !== '' ? ' aria-label="' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '') . '>'
            . '<rect width="' . $dim . '" height="' . $dim . '" fill="' . $light . '"/><path fill="' . $dark . '" d="' . $path . '"/></svg>';
    }

    // ------------------------------------------------------------------ construction

    private static function blocks(int $version, string $ecl): array
    {
        $spec = self::RS_BLOCKS[$version][$ecl === 'L' ? 0 : 1];
        $out = [];
        for ($i = 0; $i < count($spec); $i += 3) {
            for ($k = 0; $k < $spec[$i]; $k++) {
                $out[] = [$spec[$i + 1], $spec[$i + 2]];
            }
        }
        return $out;
    }

    private static function dataCodewords(int $version, string $ecl): int
    {
        return array_sum(array_map(fn ($b) => $b[1], self::blocks($version, $ecl)));
    }

    private static function lengthBits(int $version): int
    {
        return $version < 10 ? 8 : 16;
    }

    private static function pickVersion(int $len, string $ecl): int
    {
        for ($v = 1; $v <= 20; $v++) {
            if (4 + self::lengthBits($v) + 8 * $len <= self::dataCodewords($v, $ecl) * 8) {
                return $v;
            }
        }
        throw new InvalidArgumentException('Text too long for a QR code (' . $len . ' bytes).');
    }

    private function createData(string $bytes): array
    {
        $bits = [];
        $put = function (int $num, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = (($num >> $i) & 1) === 1;
            }
        };
        $put(4, 4); // byte mode
        $put(strlen($bytes), self::lengthBits($this->version));
        foreach (str_split($bytes) as $ch) {
            if ($ch !== '') {
                $put(ord($ch), 8);
            }
        }
        $total = self::dataCodewords($this->version, $this->ecl) * 8;
        if (count($bits) + 4 <= $total) {
            $put(0, 4);
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = false;
        }
        $pad = [0xEC, 0x11];
        for ($i = 0; count($bits) < $total; $i++) {
            $put($pad[$i % 2], 8);
        }
        $buffer = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $v = 0;
            foreach ($byte as $b) {
                $v = ($v << 1) | ($b ? 1 : 0);
            }
            $buffer[] = $v;
        }

        $offset = 0;
        $dc = [];
        $ec = [];
        $maxDc = 0;
        $maxEc = 0;
        foreach (self::blocks($this->version, $this->ecl) as $r => [$totalCount, $dataCount]) {
            $ecCount = $totalCount - $dataCount;
            $maxDc = max($maxDc, $dataCount);
            $maxEc = max($maxEc, $ecCount);
            $dc[$r] = array_slice($buffer, $offset, $dataCount);
            $offset += $dataCount;
            $gen = self::generator($ecCount);
            $raw = self::poly($dc[$r], count($gen) - 1);
            $mod = self::polyMod($raw, $gen);
            $ec[$r] = [];
            for ($i = 0; $i < count($gen) - 1; $i++) {
                $idx = $i + count($mod) - (count($gen) - 1);
                $ec[$r][$i] = $idx >= 0 ? ($mod[$idx] ?? 0) : 0;
            }
        }
        $out = [];
        for ($i = 0; $i < $maxDc; $i++) {
            foreach ($dc as $block) {
                if ($i < count($block)) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $maxEc; $i++) {
            foreach ($ec as $block) {
                if ($i < count($block)) {
                    $out[] = $block[$i];
                }
            }
        }
        return $out;
    }

    private function make(bool $test, int $mask): void
    {
        $n = $this->size;
        $this->m = array_fill(0, $n, array_fill(0, $n, null));
        $this->probe(0, 0);
        $this->probe($n - 7, 0);
        $this->probe(0, $n - 7);
        $this->alignment();
        for ($i = 8; $i < $n - 8; $i++) {
            if ($this->m[$i][6] === null) {
                $this->m[$i][6] = $i % 2 === 0;
            }
            if ($this->m[6][$i] === null) {
                $this->m[6][$i] = $i % 2 === 0;
            }
        }
        $this->typeInfo($test, $mask);
        if ($this->version >= 7) {
            $this->versionInfo($test);
        }
        $this->mapData($mask);
    }

    private function probe(int $row, int $col): void
    {
        for ($r = -1; $r <= 7; $r++) {
            if ($row + $r < 0 || $row + $r >= $this->size) {
                continue;
            }
            for ($c = -1; $c <= 7; $c++) {
                if ($col + $c < 0 || $col + $c >= $this->size) {
                    continue;
                }
                $this->m[$row + $r][$col + $c] = (0 <= $r && $r <= 6 && ($c === 0 || $c === 6))
                    || (0 <= $c && $c <= 6 && ($r === 0 || $r === 6))
                    || (2 <= $r && $r <= 4 && 2 <= $c && $c <= 4);
            }
        }
    }

    private function alignment(): void
    {
        $pos = self::ALIGN[$this->version];
        foreach ($pos as $row) {
            foreach ($pos as $col) {
                if ($this->m[$row][$col] !== null) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $this->m[$row + $r][$col + $c] = $r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0);
                    }
                }
            }
        }
    }

    private static function bchDigit(int $data): int
    {
        $d = 0;
        while ($data !== 0) {
            $d++;
            $data >>= 1;
        }
        return $d;
    }

    private function typeInfo(bool $test, int $mask): void
    {
        $data = (self::ECL_BITS[$this->ecl] << 3) | $mask;
        $d = $data << 10;
        while (self::bchDigit($d) - self::bchDigit(self::G15) >= 0) {
            $d ^= self::G15 << (self::bchDigit($d) - self::bchDigit(self::G15));
        }
        $bits = (($data << 10) | $d) ^ self::G15_MASK;
        $n = $this->size;
        for ($i = 0; $i < 15; $i++) {
            $mod = !$test && (($bits >> $i) & 1) === 1;
            if ($i < 6) {
                $this->m[$i][8] = $mod;
            } elseif ($i < 8) {
                $this->m[$i + 1][8] = $mod;
            } else {
                $this->m[$n - 15 + $i][8] = $mod;
            }
            if ($i < 8) {
                $this->m[8][$n - $i - 1] = $mod;
            } elseif ($i < 9) {
                $this->m[8][15 - $i - 1 + 1] = $mod;
            } else {
                $this->m[8][15 - $i - 1] = $mod;
            }
        }
        $this->m[$n - 8][8] = !$test;
    }

    private function versionInfo(bool $test): void
    {
        $d = $this->version << 12;
        while (self::bchDigit($d) - self::bchDigit(self::G18) >= 0) {
            $d ^= self::G18 << (self::bchDigit($d) - self::bchDigit(self::G18));
        }
        $bits = ($this->version << 12) | $d;
        $n = $this->size;
        for ($i = 0; $i < 18; $i++) {
            $mod = !$test && (($bits >> $i) & 1) === 1;
            $this->m[intdiv($i, 3)][$i % 3 + $n - 8 - 3] = $mod;
            $this->m[$i % 3 + $n - 8 - 3][intdiv($i, 3)] = $mod;
        }
    }

    private static function mask(int $p, int $i, int $j): bool
    {
        return match ($p) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => ($i * $j) % 2 + ($i * $j) % 3 === 0,
            6 => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
            default => (($i * $j) % 3 + ($i + $j) % 2) % 2 === 0,
        };
    }

    private function mapData(int $mask): void
    {
        $n = $this->size;
        $inc = -1;
        $row = $n - 1;
        $bit = 7;
        $byte = 0;
        $len = count($this->data);
        for ($col = $n - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            while (true) {
                for ($c = 0; $c < 2; $c++) {
                    if ($this->m[$row][$col - $c] === null) {
                        $dark = $byte < $len && (($this->data[$byte] >> $bit) & 1) === 1;
                        if (self::mask($mask, $row, $col - $c)) {
                            $dark = !$dark;
                        }
                        $this->m[$row][$col - $c] = $dark;
                        $bit--;
                        if ($bit === -1) {
                            $byte++;
                            $bit = 7;
                        }
                    }
                }
                $row += $inc;
                if ($row < 0 || $n <= $row) {
                    $row -= $inc;
                    $inc = -$inc;
                    break;
                }
            }
        }
    }

    private function lostPoint(): float
    {
        $n = $this->size;
        $m = $this->m;
        $lost = 0;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $same = 0;
                $dark = $m[$r][$c];
                for ($dr = -1; $dr <= 1; $dr++) {
                    if ($r + $dr < 0 || $r + $dr >= $n) {
                        continue;
                    }
                    for ($dc = -1; $dc <= 1; $dc++) {
                        if ($c + $dc < 0 || $c + $dc >= $n || ($dr === 0 && $dc === 0)) {
                            continue;
                        }
                        if ($dark === $m[$r + $dr][$c + $dc]) {
                            $same++;
                        }
                    }
                }
                if ($same > 5) {
                    $lost += 3 + $same - 5;
                }
            }
        }
        for ($r = 0; $r < $n - 1; $r++) {
            for ($c = 0; $c < $n - 1; $c++) {
                $cnt = (int) $m[$r][$c] + (int) $m[$r + 1][$c] + (int) $m[$r][$c + 1] + (int) $m[$r + 1][$c + 1];
                if ($cnt === 0 || $cnt === 4) {
                    $lost += 3;
                }
            }
        }
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n - 6; $c++) {
                if ($m[$r][$c] && !$m[$r][$c + 1] && $m[$r][$c + 2] && $m[$r][$c + 3] && $m[$r][$c + 4] && !$m[$r][$c + 5] && $m[$r][$c + 6]) {
                    $lost += 40;
                }
            }
        }
        for ($c = 0; $c < $n; $c++) {
            for ($r = 0; $r < $n - 6; $r++) {
                if ($m[$r][$c] && !$m[$r + 1][$c] && $m[$r + 2][$c] && $m[$r + 3][$c] && $m[$r + 4][$c] && !$m[$r + 5][$c] && $m[$r + 6][$c]) {
                    $lost += 40;
                }
            }
        }
        $darkCount = 0;
        foreach ($m as $row) {
            foreach ($row as $v) {
                $darkCount += (int) $v;
            }
        }
        // Same weighting as qrcode-generator (float): |100·dark/n² − 50| / 5 × 10.
        return $lost + abs(100 * $darkCount / $n / $n - 50) / 5 * 10;
    }

    // ------------------------------------------------------------------ Reed–Solomon (GF(256))

    private static function initGalois(): void
    {
        if (self::$exp) {
            return;
        }
        $exp = array_fill(0, 256, 0);
        $log = array_fill(0, 256, 0);
        for ($i = 0; $i < 8; $i++) {
            $exp[$i] = 1 << $i;
        }
        for ($i = 8; $i < 256; $i++) {
            $exp[$i] = $exp[$i - 4] ^ $exp[$i - 5] ^ $exp[$i - 6] ^ $exp[$i - 8];
        }
        for ($i = 0; $i < 255; $i++) {
            $log[$exp[$i]] = $i;
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    private static function gexp(int $n): int
    {
        while ($n < 0) {
            $n += 255;
        }
        while ($n >= 256) {
            $n -= 255;
        }
        return self::$exp[$n];
    }

    private static function poly(array $num, int $shift): array
    {
        $offset = 0;
        while ($offset < count($num) && $num[$offset] === 0) {
            $offset++;
        }
        $out = array_slice($num, $offset);
        for ($i = 0; $i < $shift; $i++) {
            $out[] = 0;
        }
        return $out;
    }

    private static function polyMultiply(array $a, array $b): array
    {
        $num = array_fill(0, count($a) + count($b) - 1, 0);
        foreach ($a as $i => $x) {
            foreach ($b as $j => $y) {
                $num[$i + $j] ^= self::gexp(self::$log[$x] + self::$log[$y]);
            }
        }
        return self::poly($num, 0);
    }

    private static function polyMod(array $a, array $e): array
    {
        while (count($a) - count($e) >= 0 && $a) {
            $ratio = self::$log[$a[0]] - self::$log[$e[0]];
            foreach ($e as $i => $y) {
                $a[$i] ^= self::gexp(self::$log[$y] + $ratio);
            }
            $a = self::poly($a, 0);
        }
        return $a;
    }

    private static function generator(int $ecLength): array
    {
        static $cache = [];
        if (isset($cache[$ecLength])) {
            return $cache[$ecLength];
        }
        $a = [1];
        for ($i = 0; $i < $ecLength; $i++) {
            $a = self::polyMultiply($a, [1, self::gexp($i)]);
        }
        return $cache[$ecLength] = $a;
    }
}
