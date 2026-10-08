<?php
declare(strict_types=1);

/**
 * Panchang + Choghadiya (#27), computed offline — no API.
 *
 *  - Sunrise / sunset: PHP date_sun_info() for the hotel's latitude / longitude and time zone.
 *  - Choghadiya: the standard 8 day + 8 night segments (equal parts of sunrise→sunset and
 *    sunset→next sunrise); the first day segment is ruled by the weekday lord, the night starts with the
 *    lord of the fifth weekday; day order steps +1, night order −2 in the cycle
 *    Udveg, Char, Labh, Amrit, Kaal, Shubh, Rog.
 *  - Tithi / paksha / nakshatra / yoga / lunar month: from apparent Sun and Moon longitudes using Jean
 *    Meeus' low-precision formulas (Astronomical Algorithms ch. 25 Sun, ch. 47 Moon with the main
 *    periodic terms; typical error ≈ 0.01° for the Sun and < 0.05° for the Moon, i.e. tithi boundaries
 *    within a few minutes) and the Lahiri (Chitrapaksha) ayanamsa as a linear approximation
 *    (sidereal = tropical − ayanamsa) for nakshatra, yoga and the month's solar sign.
 *  - Month: Gujarati Amanta calendar (month runs new moon → new moon, named after the Sun's sidereal
 *    sign at the starting new moon; two new moons in the same sign = Adhik month). Vikram Samvat
 *    starts on Kartak sud 1 (the day after Diwali).
 *
 * The results are APPROXIMATE: the day's tithi is the one prevailing at sunrise (udaya tithi);
 * near a boundary a printed panchang may differ by one tithi / nakshatra, and festivals follow
 * additional rules (madhyahna / pradosh / nishita vyapti) that are not modelled. The admin may type the
 * day's tithi text as an override (PanchangApp). See docs/modules/widgets_26_30.md.
 */
final class Panchang
{
    /** Choghadiya cycle (index order of the day sequence). quality: good | neutral | bad. */
    public const CHOGHADIYA = [
        ['Udveg', 'bad'], ['Char', 'neutral'], ['Labh', 'good'], ['Amrit', 'good'],
        ['Kaal', 'bad'], ['Shubh', 'good'], ['Rog', 'bad'],
    ];
    /** First day segment per weekday (0 = Sunday): Sun Udveg, Mon Amrit, Tue Rog, Wed Labh, Thu Shubh, Fri Char, Sat Kaal. */
    private const DAY_START = [0, 3, 6, 2, 5, 1, 4];

    /** Tithi names 1–15 (shukla 1–15, krishna 1–14 reuse 1–14, 30 = Amavasya). */
    public const TITHIS = [
        1 => 'Pratipada', 'Dwitiya', 'Tritiya', 'Chaturthi', 'Panchami', 'Shashthi', 'Saptami', 'Ashtami',
        'Navami', 'Dashami', 'Ekadashi', 'Dwadashi', 'Trayodashi', 'Chaturdashi', 'Purnima',
    ];
    public const AMAVASYA = 'Amavasya';

    public const NAKSHATRAS = [
        1 => 'Ashwini', 'Bharani', 'Krittika', 'Rohini', 'Mrigashira', 'Ardra', 'Punarvasu', 'Pushya', 'Ashlesha',
        'Magha', 'Purva Phalguni', 'Uttara Phalguni', 'Hasta', 'Chitra', 'Swati', 'Vishakha', 'Anuradha', 'Jyeshtha',
        'Mula', 'Purva Ashadha', 'Uttara Ashadha', 'Shravana', 'Dhanishta', 'Shatabhisha', 'Purva Bhadrapada', 'Uttara Bhadrapada', 'Revati',
    ];

    public const YOGAS = [
        1 => 'Vishkambha', 'Priti', 'Ayushman', 'Saubhagya', 'Shobhana', 'Atiganda', 'Sukarma', 'Dhriti', 'Shula',
        'Ganda', 'Vriddhi', 'Dhruva', 'Vyaghata', 'Harshana', 'Vajra', 'Siddhi', 'Vyatipata', 'Variyana',
        'Parigha', 'Shiva', 'Siddha', 'Sadhya', 'Shubha', 'Shukla', 'Brahma', 'Indra', 'Vaidhriti',
    ];

    /** Lunar months, 0 = Chaitra (English keys; Gujarati names via __(), e.g. Kartika → કારતક). */
    public const MONTHS = [
        'Chaitra', 'Vaishakha', 'Jyeshtha', 'Ashadha', 'Shravana', 'Bhadrapada',
        'Ashwin', 'Kartika', 'Margashirsha', 'Pausha', 'Magha', 'Phalguna',
    ];

    private const SYNODIC = 29.530588853;
    private const DELTA_T = 69.0;          // TT − UT in seconds (≈ 2020s); 1 minute ≈ 0.008° of Moon motion
    private const ELONG_RATE = 12.190749;   // mean Moon − Sun motion, degrees / day

    // ------------------------------------------------------------------ astronomy

    public static function jd(float $unix): float
    {
        return $unix / 86400.0 + 2440587.5;
    }

    private static function norm(float $deg): float
    {
        $d = fmod($deg, 360.0);
        return $d < 0 ? $d + 360.0 : $d;
    }

    /** Julian centuries (TT) since J2000.0 for a unix time (UT). */
    private static function centuries(float $unix): float
    {
        return (self::jd($unix + self::DELTA_T) - 2451545.0) / 36525.0;
    }

    /** Apparent geocentric ecliptic longitude of the Sun (tropical, degrees) — Meeus ch. 25. */
    public static function sunLongitude(float $unix): float
    {
        $t = self::centuries($unix);
        $l0 = 280.46646 + 36000.76983 * $t + 0.0003032 * $t * $t;
        $m = deg2rad(357.52911 + 35999.05029 * $t - 0.0001537 * $t * $t);
        $c = (1.914602 - 0.004817 * $t - 0.000014 * $t * $t) * sin($m) + (0.019993 - 0.000101 * $t) * sin(2 * $m) + 0.000289 * sin(3 * $m);
        $omega = deg2rad(125.04 - 1934.136 * $t);
        return self::norm($l0 + $c - 0.00569 - 0.00478 * sin($omega));
    }

    /** Main periodic terms of the Moon's longitude (Meeus table 47.A): D, M, M', F, coefficient (1e-6 °). */
    private const MOON_TERMS = [
        [0, 0, 1, 0, 6288774], [2, 0, -1, 0, 1274027], [2, 0, 0, 0, 658314], [0, 0, 2, 0, 213618],
        [0, 1, 0, 0, -185116], [0, 0, 0, 2, -114332], [2, 0, -2, 0, 58793], [2, -1, -1, 0, 57066],
        [2, 0, 1, 0, 53322], [2, -1, 0, 0, 45758], [0, 1, -1, 0, -40923], [1, 0, 0, 0, -34720],
        [0, 1, 1, 0, -30383], [2, 0, 0, -2, 15327], [0, 0, 1, 2, -12528], [0, 0, 1, -2, 10980],
        [4, 0, -1, 0, 10675], [0, 0, 3, 0, 10034], [4, 0, -2, 0, 8548], [2, 1, -1, 0, -7888],
        [2, 1, 0, 0, -6766], [1, 0, -1, 0, -5163], [1, 1, 0, 0, 4987], [2, -1, 1, 0, 4036],
        [2, 0, 2, 0, 3994], [4, 0, 0, 0, 3861], [2, 0, -3, 0, 3665], [0, 1, -2, 0, -2689],
        [2, 0, -1, 2, -2602], [2, -1, -2, 0, 2390], [1, 0, 1, 0, -2348], [2, -2, 0, 0, 2236],
        [0, 1, 2, 0, -2120], [0, 2, 0, 0, -2069], [2, -2, -1, 0, 2048], [2, 0, 1, -2, -1773],
        [2, 0, 0, 2, -1595], [4, -1, -1, 0, 1215], [0, 0, 2, 2, -1110], [3, 0, -1, 0, -892],
        [2, 1, 1, 0, -810], [4, -1, -2, 0, 759], [0, 2, -1, 0, -713], [2, 2, -1, 0, -700],
        [2, 1, -2, 0, 691], [2, -1, 0, -2, 596], [4, 0, 1, 0, 549], [0, 0, 4, 0, 537],
        [4, -1, 0, 0, 520], [1, 0, -2, 0, -487], [2, 1, 0, -2, -399], [0, 0, 2, -2, -381],
        [1, 1, 1, 0, 351], [3, 0, -2, 0, -340], [4, 0, -3, 0, 330], [2, -1, 2, 0, 327],
        [0, 2, 1, 0, -323], [1, 1, -1, 0, 299], [2, 0, 3, 0, 294],
    ];

    /** Apparent geocentric ecliptic longitude of the Moon (tropical, degrees) — Meeus ch. 47 (main terms). */
    public static function moonLongitude(float $unix): float
    {
        $t = self::centuries($unix);
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        $t4 = $t3 * $t;
        $lp = 218.3164477 + 481267.88123421 * $t - 0.0015786 * $t2 + $t3 / 538841 - $t4 / 65194000;
        $d = 297.8501921 + 445267.1114034 * $t - 0.0018819 * $t2 + $t3 / 545868 - $t4 / 113065000;
        $m = 357.5291092 + 35999.0502909 * $t - 0.0001536 * $t2 + $t3 / 24490000;
        $mp = 134.9633964 + 477198.8675055 * $t + 0.0087414 * $t2 + $t3 / 69699 - $t4 / 14712000;
        $f = 93.2720950 + 483202.0175233 * $t - 0.0036539 * $t2 - $t3 / 3526000 + $t4 / 863310000;
        $a1 = 119.75 + 131.849 * $t;
        $a2 = 53.09 + 479264.290 * $t;
        $e = 1 - 0.002516 * $t - 0.0000074 * $t2;
        $sum = 0.0;
        foreach (self::MOON_TERMS as [$cd, $cm, $cmp, $cf, $coef]) {
            $arg = deg2rad($cd * $d + $cm * $m + $cmp * $mp + $cf * $f);
            $k = abs($cm) === 1 ? $e : (abs($cm) === 2 ? $e * $e : 1.0);
            $sum += $coef * $k * sin($arg);
        }
        $sum += 3958 * sin(deg2rad($a1)) + 1962 * sin(deg2rad($lp - $f)) + 318 * sin(deg2rad($a2));
        $omega = deg2rad(125.04452 - 1934.136261 * $t);
        return self::norm($lp + $sum / 1000000.0 - 0.00478 * sin($omega));
    }

    /** Lahiri (Chitrapaksha) ayanamsa in degrees (≈ 23.857° at J2000, precession ≈ 50.29″ / year). */
    public static function ayanamsa(float $unix): float
    {
        $t = self::centuries($unix);
        return 23.857092 + 1.396971278 * $t + 0.000308889 * $t * $t;
    }

    public static function siderealSun(float $unix): float
    {
        return self::norm(self::sunLongitude($unix) - self::ayanamsa($unix));
    }

    public static function siderealMoon(float $unix): float
    {
        return self::norm(self::moonLongitude($unix) - self::ayanamsa($unix));
    }

    /** Moon − Sun, 0 … 360 (0 = new moon, 180 = full moon). */
    public static function elongation(float $unix): float
    {
        return self::norm(self::moonLongitude($unix) - self::sunLongitude($unix));
    }

    /** Angle used by each limb: tithi = elongation, nakshatra = sidereal Moon, yoga = sidereal Sun + Moon. */
    private static function angle(string $what, float $unix): float
    {
        return match ($what) {
            'tithi' => self::elongation($unix),
            'nakshatra' => self::siderealMoon($unix),
            'yoga' => self::norm(self::siderealSun($unix) + self::siderealMoon($unix)),
            default => throw new InvalidArgumentException($what),
        };
    }

    /** Degrees / day of each limb (mean) and its span. */
    private const LIMBS = ['tithi' => [12.190749, 12.0], 'nakshatra' => [13.176358, 360 / 27], 'yoga' => [14.162, 360 / 27]];

    /** Time when the limb's angle next reaches $target (degrees), starting near $unix (Newton steps). */
    private static function reach(string $what, float $target, float $unix): float
    {
        $rate = self::LIMBS[$what][0];
        $t = $unix;
        for ($i = 0; $i < 12; $i++) {
            $diff = self::norm($target - self::angle($what, $t) + 180.0) - 180.0;
            if (abs($diff) < 0.0003) {
                break;
            }
            $t += $diff / $rate * 86400.0;
        }
        return $t;
    }

    /** ['n' => 1-based number, 'end' => unix time when it ends] of tithi / nakshatra / yoga at $unix. */
    public static function limb(string $what, float $unix): array
    {
        $span = self::LIMBS[$what][1];
        $a = self::angle($what, $unix);
        $n = (int) floor($a / $span);
        $count = (int) round(360 / $span);
        $n = min($n, $count - 1);
        $end = self::reach($what, self::norm(($n + 1) * $span), $unix + (($n + 1) * $span - $a) / self::LIMBS[$what][0] * 86400.0);
        return ['n' => $n + 1, 'end' => (int) round($end)];
    }

    /** Tithi at $unix: n 1–30, paksha shukla|krishna, name (English key), end. */
    public static function tithi(float $unix): array
    {
        $l = self::limb('tithi', $unix);
        $n = $l['n'];
        $paksha = $n <= 15 ? 'shukla' : 'krishna';
        $name = $n === 30 ? self::AMAVASYA : self::TITHIS[$n <= 15 ? $n : $n - 15];
        return ['n' => $n, 'paksha' => $paksha, 'day' => $n <= 15 ? $n : $n - 15, 'name' => $name, 'end' => $l['end']];
    }

    public static function nakshatra(float $unix): array
    {
        $l = self::limb('nakshatra', $unix);
        return ['n' => $l['n'], 'name' => self::NAKSHATRAS[$l['n']], 'end' => $l['end']];
    }

    public static function yoga(float $unix): array
    {
        $l = self::limb('yoga', $unix);
        return ['n' => $l['n'], 'name' => self::YOGAS[$l['n']], 'end' => $l['end']];
    }

    /** Time of the new moon at or before $unix. */
    public static function newMoonBefore(float $unix): float
    {
        $t = $unix - self::elongation($unix) / self::ELONG_RATE * 86400.0;
        for ($i = 0; $i < 10; $i++) {
            $d = self::elongation($t);
            if ($d > 180) {
                $d -= 360;
            }
            if (abs($d) < 0.0003) {
                break;
            }
            $t -= $d / self::ELONG_RATE * 86400.0;
        }
        return $t > $unix ? self::newMoonBefore($unix - 2 * 86400) : $t;
    }

    /**
     * Amanta lunar month at $unix: index 0 = Chaitra … 11 = Phalguna, adhik (leap month),
     * samvat (Vikram Samvat, Gujarati: new year on Kartak sud 1), start / end (new moons).
     */
    public static function month(float $unix): array
    {
        $start = self::newMoonBefore($unix);
        $next = self::newMoonBefore($start + (self::SYNODIC + 2) * 86400.0);
        $sign = (int) floor(self::siderealSun($start) / 30.0);
        $nextSign = (int) floor(self::siderealSun($next) / 30.0);
        $idx = ($sign + 1) % 12;
        $gy = (int) gmdate('Y', (int) $start);
        $gm = (int) gmdate('n', (int) $start);
        $samvat = in_array($idx, [7, 8, 9], true) && $gm >= 9 ? $gy + 57 : $gy + 56;
        return ['index' => $idx, 'name' => self::MONTHS[$idx], 'adhik' => $sign === $nextSign, 'samvat' => $samvat, 'start' => (int) round($start), 'end' => (int) round($next)];
    }

    // ------------------------------------------------------------------ sun & choghadiya

    /**
     * Sunrise and sunset of a local date ('Y-m-d') at lat / lon in $tz: [sunrise, sunset] unix times.
     * Polar day / night falls back to 06:00 / 18:00 local (never happens in India).
     */
    public static function sunTimes(string $date, float $lat, float $lon, string $tz): array
    {
        $zone = new DateTimeZone($tz);
        $noon = (new DateTimeImmutable($date . ' 12:00:00', $zone))->getTimestamp();
        $info = date_sun_info($noon, $lat, $lon);
        $rise = is_int($info['sunrise'] ?? null) ? $info['sunrise'] : (new DateTimeImmutable($date . ' 06:00:00', $zone))->getTimestamp();
        $set = is_int($info['sunset'] ?? null) ? $info['sunset'] : (new DateTimeImmutable($date . ' 18:00:00', $zone))->getTimestamp();
        return [$rise, $set];
    }

    /**
     * The 16 choghadiya segments of a Vedic day (pure function).
     * $weekday: 0 = Sunday … 6 = Saturday (of the sunrise date).
     * @return list<array{name:string, quality:string, part:string, start:int, end:int}>
     */
    public static function choghadiya(int $sunrise, int $sunset, int $nextSunrise, int $weekday): array
    {
        $out = [];
        $first = self::DAY_START[(($weekday % 7) + 7) % 7];
        $nightFirst = self::DAY_START[($weekday + 4) % 7];
        foreach ([['day', $sunrise, $sunset, $first, 1], ['night', $sunset, $nextSunrise, $nightFirst, -2]] as [$part, $from, $to, $start, $step]) {
            $len = ($to - $from) / 8;
            for ($i = 0; $i < 8; $i++) {
                $c = self::CHOGHADIYA[((($start + $step * $i) % 7) + 7) % 7];
                $out[] = ['name' => $c[0], 'quality' => $c[1], 'part' => $part, 'start' => (int) round($from + $len * $i), 'end' => (int) round($from + $len * ($i + 1))];
            }
        }
        return $out;
    }

    /**
     * Full panchang of the Vedic day that contains $now (sunrise → next sunrise) at lat / lon / tz:
     * date (local 'Y-m-d' of the sunrise), weekday, sunrise, sunset, next_sunrise, tithi, nakshatra, yoga,
     * month, choghadiya (16 segments), current (index of the segment at $now).
     */
    public static function day(int $now, float $lat, float $lon, string $tz): array
    {
        $zone = new DateTimeZone($tz);
        $date = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d');
        [$rise, $set] = self::sunTimes($date, $lat, $lon, $tz);
        if ($now < $rise) {
            $date = (new DateTimeImmutable($date . ' 12:00:00', $zone))->modify('-1 day')->format('Y-m-d');
            [$rise, $set] = self::sunTimes($date, $lat, $lon, $tz);
        }
        $nextDate = (new DateTimeImmutable($date . ' 12:00:00', $zone))->modify('+1 day')->format('Y-m-d');
        [$nextRise] = self::sunTimes($nextDate, $lat, $lon, $tz);
        $weekday = (int) (new DateTimeImmutable($date . ' 12:00:00', $zone))->format('w');
        $segments = self::choghadiya($rise, $set, $nextRise, $weekday);
        $current = null;
        foreach ($segments as $i => $s) {
            if ($now >= $s['start'] && $now < $s['end']) {
                $current = $i;
            }
        }
        return [
            'date' => $date, 'weekday' => $weekday, 'sunrise' => $rise, 'sunset' => $set, 'next_sunrise' => $nextRise,
            'tithi' => self::tithi($rise), 'nakshatra' => self::nakshatra($rise), 'yoga' => self::yoga($rise),
            'month' => self::month($rise), 'choghadiya' => $segments, 'current' => $current,
        ];
    }
}
