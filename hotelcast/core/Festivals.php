<?php
declare(strict_types=1);

/**
 * Festival calendar (#28, display app "festivals"). Tenant table `festivals` (migrations/021_widgets.sql),
 * managed in admin/festivals.php, shown by core/Apps/FestivalsApp.php.
 * A festival is "today" when starts_on <= today <= ends_on (ends_on empty = one day). Upcoming = starts
 * after today, nearest first. Names in English / Gujarati / Hindi (empty → English).
 *
 * STARTER: major Hindu / Indian festivals 2026–2027, imported with one click. The dates were cross-checked
 * against the offline panchang (core/Panchang.php, ±1 tithi), but local temples / panchangs may differ
 * by a day — hotels are asked to verify them (flag is_starter until edited).
 */
final class Festivals
{
    public const MAX_DESC = 1000;

    public const DEFAULTS = [
        'id' => 0, 'name_en' => '', 'name_gu' => '', 'name_hi' => '', 'starts_on' => '', 'ends_on' => null,
        'description' => '', 'theme' => '', 'image_path' => null, 'thumb_path' => null, 'is_starter' => 0, 'is_active' => 1,
    ];

    /**
     * Starter list: [date, end date|null, English, Gujarati, Hindi, theme, expected udaya tithi (1–30, Amanta) | null].
     * The tithi column is only used by the tests (engine cross-check), never shown.
     */
    public const STARTER = [
        ['2026-01-14', null, 'Makar Sankranti (Uttarayan)', 'ઉત્તરાયણ (મકર સંક્રાંતિ)', 'मकर संक्रांति', 'temple_saffron', null],
        ['2026-01-23', null, 'Vasant Panchami', 'વસંત પંચમી', 'वसंत पंचमी', '', 5],
        ['2026-01-26', null, 'Republic Day', 'પ્રજાસત્તાક દિન', 'गणतंत्र दिवस', '', null],
        ['2026-02-15', null, 'Maha Shivaratri', 'મહાશિવરાત્રી', 'महाशिवरात्रि', 'temple_saffron', 29],
        ['2026-03-03', null, 'Holika Dahan', 'હોળી', 'होलिका दहन', 'holi', 15],
        ['2026-03-04', null, 'Holi (Dhuleti)', 'ધુળેટી', 'होली', 'holi', 16],
        ['2026-03-19', null, 'Chaitra Navratri / Gudi Padwa', 'ચૈત્રી નવરાત્રી / ગુડી પડવો', 'चैत्र नवरात्रि / गुड़ी पड़वा', '', 1],
        ['2026-03-26', null, 'Ram Navami', 'રામ નવમી', 'राम नवमी', 'temple_saffron', 9],
        ['2026-03-31', null, 'Mahavir Jayanti', 'મહાવીર જયંતી', 'महावीर जयंती', '', 13],
        ['2026-04-02', null, 'Hanuman Jayanti', 'હનુમાન જયંતી', 'हनुमान जयंती', 'temple_saffron', 15],
        ['2026-04-20', null, 'Akshaya Tritiya', 'અખાત્રીજ', 'अक्षय तृतीया', '', 3],
        ['2026-07-16', null, 'Rath Yatra', 'રથયાત્રા', 'रथ यात्रा', 'temple_saffron', 2],
        ['2026-07-29', null, 'Guru Purnima', 'ગુરુ પૂર્ણિમા', 'गुरु पूर्णिमा', '', 15],
        ['2026-08-15', null, 'Independence Day', 'સ્વાતંત્ર્ય દિન', 'स्वतंत्रता दिवस', '', null],
        ['2026-08-28', null, 'Raksha Bandhan', 'રક્ષાબંધન', 'रक्षाबंधन', '', 15],
        ['2026-09-04', null, 'Janmashtami', 'જન્માષ્ટમી', 'जन्माष्टमी', 'janmashtami', 23],
        ['2026-09-14', null, 'Ganesh Chaturthi', 'ગણેશ ચતુર્થી', 'गणेश चतुर्थी', 'temple_saffron', 4],
        ['2026-10-11', '2026-10-19', 'Navratri', 'નવરાત્રી', 'नवरात्रि', 'navratri', 1],
        ['2026-10-20', null, 'Dussehra', 'દશેરા', 'दशहरा', 'navratri', 10],
        ['2026-10-26', null, 'Sharad Purnima', 'શરદ પૂનમ', 'शरद पूर्णिमा', '', 15],
        ['2026-11-06', null, 'Dhanteras', 'ધનતેરસ', 'धनतेरस', 'diwali', 28],
        ['2026-11-08', null, 'Diwali', 'દિવાળી', 'दीपावली', 'diwali', 30],
        ['2026-11-10', null, 'Gujarati New Year (Bestu Varas)', 'નૂતન વર્ષ (બેસતું વર્ષ)', 'गुजराती नव वर्ष', 'diwali', 1],
        ['2026-11-11', null, 'Bhai Dooj', 'ભાઈબીજ', 'भाई दूज', 'diwali', 2],
        ['2026-11-14', null, 'Labh Pancham', 'લાભ પાંચમ', 'लाभ पंचमी', 'diwali', 5],
        ['2026-11-24', null, 'Dev Diwali (Kartik Purnima)', 'દેવ દિવાળી (કારતક પૂનમ)', 'देव दीपावली (कार्तिक पूर्णिमा)', 'diwali', 15],
        ['2026-12-25', null, 'Christmas', 'નાતાલ', 'क्रिसमस', 'christmas', null],
        ['2027-01-14', null, 'Makar Sankranti (Uttarayan)', 'ઉત્તરાયણ (મકર સંક્રાંતિ)', 'मकर संक्रांति', 'temple_saffron', null],
        ['2027-01-26', null, 'Republic Day', 'પ્રજાસત્તાક દિન', 'गणतंत्र दिवस', '', null],
        ['2027-02-11', null, 'Vasant Panchami', 'વસંત પંચમી', 'वसंत पंचमी', '', 5],
        ['2027-03-06', null, 'Maha Shivaratri', 'મહાશિવરાત્રી', 'महाशिवरात्रि', 'temple_saffron', 29],
        ['2027-03-22', null, 'Holika Dahan', 'હોળી', 'होलिका दहन', 'holi', 15],
        ['2027-03-23', null, 'Holi (Dhuleti)', 'ધુળેટી', 'होली', 'holi', 16],
        ['2027-04-07', null, 'Chaitra Navratri / Gudi Padwa', 'ચૈત્રી નવરાત્રી / ગુડી પડવો', 'चैत्र नवरात्रि / गुड़ी पड़वा', '', 1],
        ['2027-04-15', null, 'Ram Navami', 'રામ નવમી', 'राम नवमी', 'temple_saffron', 9],
        ['2027-04-20', null, 'Hanuman Jayanti', 'હનુમાન જયંતી', 'हनुमान जयंती', 'temple_saffron', 15],
        ['2027-05-09', null, 'Akshaya Tritiya', 'અખાત્રીજ', 'अक्षय तृतीया', '', 3],
        ['2027-07-05', null, 'Rath Yatra', 'રથયાત્રા', 'रथ यात्रा', 'temple_saffron', 2],
        ['2027-07-18', null, 'Guru Purnima', 'ગુરુ પૂર્ણિમા', 'गुरु पूर्णिमा', '', 15],
        ['2027-08-15', null, 'Independence Day', 'સ્વાતંત્ર્ય દિન', 'स्वतंत्रता दिवस', '', null],
        ['2027-08-17', null, 'Raksha Bandhan', 'રક્ષાબંધન', 'रक्षाबंधन', '', 15],
        ['2027-08-25', null, 'Janmashtami', 'જન્માષ્ટમી', 'जन्माष्टमी', 'janmashtami', 23],
        ['2027-09-04', null, 'Ganesh Chaturthi', 'ગણેશ ચતુર્થી', 'गणेश चतुर्थी', 'temple_saffron', 4],
        ['2027-09-30', '2027-10-08', 'Navratri', 'નવરાત્રી', 'नवरात्रि', 'navratri', 1],
        ['2027-10-09', null, 'Dussehra', 'દશેરા', 'दशहरा', 'navratri', 10],
        ['2027-10-27', null, 'Dhanteras', 'ધનતેરસ', 'धनतेरस', 'diwali', 28],
        ['2027-10-29', null, 'Diwali', 'દિવાળી', 'दीपावली', 'diwali', 30],
        ['2027-10-30', null, 'Gujarati New Year (Bestu Varas)', 'નૂતન વર્ષ (બેસતું વર્ષ)', 'गुजराती नव वर्ष', 'diwali', 1],
        ['2027-10-31', null, 'Bhai Dooj', 'ભાઈબીજ', 'भाई दूज', 'diwali', 2],
        ['2027-11-13', null, 'Dev Diwali (Kartik Purnima)', 'દેવ દિવાળી (કારતક પૂનમ)', 'देव दीपावली (कार्तिक पूर्णिमा)', 'diwali', 15],
        ['2027-12-25', null, 'Christmas', 'નાતાલ', 'क्रिसमस', 'christmas', null],
    ];

    public static function find(int $id): ?array
    {
        return Tenant::find('festivals', $id);
    }

    public static function all(): array
    {
        return DB::all('SELECT * FROM festivals WHERE hotel_id = :h ORDER BY starts_on, id', ['h' => Tenant::id()]);
    }

    /** Name in a language (falls back to English). */
    public static function name(array $f, string $lang): string
    {
        $n = in_array($lang, ['gu', 'hi'], true) ? trim((string) ($f['name_' . $lang] ?? '')) : '';
        return $n !== '' ? $n : (string) $f['name_en'];
    }

    /** Last day of a festival (ends_on or starts_on). */
    public static function lastDay(array $f): string
    {
        return !empty($f['ends_on']) ? (string) $f['ends_on'] : (string) $f['starts_on'];
    }

    /** Active festivals happening on $today ('Y-m-d'). */
    public static function today(string $today): array
    {
        return DB::all(
            'SELECT * FROM festivals WHERE hotel_id = :h AND is_active = 1 AND starts_on <= :t1 AND COALESCE(ends_on, starts_on) >= :t2 ORDER BY starts_on, id',
            ['h' => Tenant::id(), 't1' => $today, 't2' => $today]
        );
    }

    /** Next $limit active festivals starting after $today within $days days. */
    public static function upcoming(string $today, int $limit = 6, int $days = 400): array
    {
        $to = date('Y-m-d', (int) strtotime($today . ' +' . max(1, $days) . ' days'));
        return DB::all(
            'SELECT * FROM festivals WHERE hotel_id = :h AND is_active = 1 AND starts_on > :t AND starts_on <= :to ORDER BY starts_on, id LIMIT ' . max(1, min(50, $limit)),
            ['h' => Tenant::id(), 't' => $today, 'to' => $to]
        );
    }

    /** Whole days from $today to $date (both 'Y-m-d'). */
    public static function daysUntil(string $date, string $today): int
    {
        $a = new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /** 'today' | 'upcoming' | 'past' | 'off' */
    public static function state(array $f, string $today): string
    {
        if (!(int) $f['is_active']) {
            return 'off';
        }
        if ((string) $f['starts_on'] > $today) {
            return 'upcoming';
        }
        return self::lastDay($f) >= $today ? 'today' : 'past';
    }

    /** @return array{0: array, 1: string[]} [row data, errors] */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = BusinessApps::str($in, 'name_en', 400);
        if ($name === '') {
            $errors[] = __('The festival name (English) is required.');
        } elseif (mb_strlen($name) > 120) {
            $errors[] = __('The name can have at most 120 characters.');
        }
        $n = count($errors);
        $start = BusinessApps::date($in, 'starts_on', $errors);
        if ($start === null && count($errors) === $n) {
            $errors[] = __('Enter the festival date.');
        }
        $end = BusinessApps::date($in, 'ends_on', $errors);
        if ($start && $end && $end < $start) {
            $errors[] = __('The last day must not be before the first day.');
        }
        if ($end !== null && $end === $start) {
            $end = null;
        }
        $desc = BusinessApps::text($in, 'description', self::MAX_DESC + 1);
        if (mb_strlen($desc) > self::MAX_DESC) {
            $errors[] = __('The description can have at most :n characters.', ['n' => self::MAX_DESC]);
        }
        $theme = is_string($in['theme'] ?? null) && isset(DisplayApps::THEMES[$in['theme']]) ? $in['theme'] : '';
        return [[
            'name_en' => mb_substr($name, 0, 120),
            'name_gu' => BusinessApps::str($in, 'name_gu', 120),
            'name_hi' => BusinessApps::str($in, 'name_hi', 120),
            'starts_on' => $start ?? '',
            'ends_on' => $end,
            'description' => mb_substr($desc, 0, self::MAX_DESC),
            'theme' => $theme,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function save(?int $id, array $data): int
    {
        if ($id) {
            DB::update('festivals', $data + ['is_starter' => 0], 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('festivals', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        $f = self::find($id);
        if (!$f) {
            return;
        }
        DB::delete('festivals', 'id = :id', ['id' => $id]);
        Uploader::delete($f['image_path'], $f['thumb_path']);
    }

    /**
     * Import the starter list for the current hotel: festivals from $today on that the hotel does not
     * have yet (same English name and date). Returns the number of rows added.
     */
    public static function importStarter(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $have = [];
        foreach (DB::all('SELECT name_en, starts_on FROM festivals WHERE hotel_id = :h', ['h' => Tenant::id()]) as $r) {
            $have[mb_strtolower((string) $r['name_en']) . '|' . $r['starts_on']] = true;
        }
        $n = 0;
        foreach (self::STARTER as [$date, $end, $en, $gu, $hi, $theme]) {
            if (($end ?? $date) < $today || isset($have[mb_strtolower($en) . '|' . $date])) {
                continue;
            }
            DB::insert('festivals', ['name_en' => $en, 'name_gu' => $gu, 'name_hi' => $hi, 'starts_on' => $date, 'ends_on' => $end,
                'description' => '', 'theme' => $theme, 'is_starter' => 1, 'is_active' => 1, 'created_by' => Auth::id(), 'created_at' => now()]);
            $n++;
        }
        return $n;
    }

    public static function imageUrl(array $f): ?string
    {
        return !empty($f['image_path']) ? media_url((string) $f['image_path']) : null;
    }
}
