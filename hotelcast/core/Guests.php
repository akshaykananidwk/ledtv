<?php
declare(strict_types=1);

/**
 * Guests / stays / front desk (V2_SPEC §1, #1 #6 #9 #10).
 *
 *  - A stay (`guest_stays`) is active while checked_out_at IS NULL; at most one active stay per room.
 *  - Every room has a secret guest token (`guest_tokens`, URL /g/{token}) that is rotated on every
 *    check-in, check-out and room move, so a previous guest's phone loses access immediately.
 *  - Token validity: the room has an active stay that the token was issued for, or — when the hotel
 *    does not use check-in mode — the room's current token is always valid.
 *  - All queries are scoped to Tenant::id(); tokens are the only cross-hotel lookup (they identify
 *    the hotel, like a device token) and set the tenant.
 */
final class Guests
{
    public const SALUTATIONS = ['', 'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Shri', 'Smt.', 'Kum.'];
    public const VACANT_MODES = ['normal', 'off', 'welcome'];
    public const SOURCES = ['manual', 'pms', 'api'];
    public const TOKEN_LENGTH = 20;

    /** Module defaults (hotel settings `guest_*`; empty template = built-in translated default). */
    public const DEFAULTS = [
        'guest_checkin_mode' => '0',
        'guest_vacant_mode' => 'normal',
        'guest_welcome_duration' => '20',
        'guest_wifi_ssid' => '',
        'guest_wifi_password' => '',
        'guest_checkout_time' => '10:00',
        'guest_reminder_enabled' => '1',
        'guest_reminder_time' => '07:00',
        'guest_reminder_balance' => '1',
        'guest_retention_days' => '30',
        'guest_google_review_url' => '',
        'guest_reception_phone' => '',
        'guest_pms_key_hash' => '',
        'guest_pms_key_hint' => '',
        'guest_pms_mapping' => '',
        'guest_services_enabled' => '1',
        'guest_requests_enabled' => '1',
        'guest_feedback_enabled' => '1',
        'guest_tv_notify' => '1',
        'guest_notify_external' => '0',
    ];

    /** Built-in templates (English text = translation key, translated per guest language). */
    public const TPL_WELCOME_TITLE = 'Welcome {salutation} {name} 🙏';
    public const TPL_WELCOME_MESSAGE = 'We are delighted to have you at {hotel}. Enjoy your stay in room {room}!';
    public const TPL_REMINDER = 'Checkout today at {time}. Need a late checkout? Call reception or scan the QR code.';
    public const TPL_BALANCE = 'Bill summary: {balance}';

    // ------------------------------------------------------------------ settings

    public static function setting(string $key): string
    {
        return (string) Settings::get($key, self::DEFAULTS[$key] ?? '');
    }

    /** Is the guests module (front desk, PMS, welcome, check-in mode) enabled for the hotel's plan? */
    public static function enabled(): bool
    {
        return Tenant::feature('guests');
    }

    /** Check-in mode (#9): vacant rooms follow guest_vacant_mode. */
    public static function checkinMode(): bool
    {
        return self::enabled() && self::setting('guest_checkin_mode') === '1';
    }

    public static function vacantMode(): string
    {
        $m = self::setting('guest_vacant_mode');
        return in_array($m, self::VACANT_MODES, true) ? $m : 'normal';
    }

    /** Guest-facing language code (en / gu / hi). */
    public static function lang(?string $lang): string
    {
        $lang = strtolower(trim((string) $lang));
        return isset(I18n::GUEST_LANGUAGES[$lang]) ? $lang : 'en';
    }

    public static function hotelLang(): string
    {
        return self::lang((string) Settings::get('default_language', 'en'));
    }

    // ------------------------------------------------------------------ stays

    public static function findStay(int $id): ?array
    {
        return Tenant::find('guest_stays', $id);
    }

    public static function activeStay(int $roomId): ?array
    {
        return DB::one(
            'SELECT * FROM guest_stays WHERE hotel_id = :hid AND room_id = :r AND checked_out_at IS NULL ORDER BY id DESC LIMIT 1',
            ['hid' => Tenant::id(), 'r' => $roomId]
        );
    }

    /** Active stays of the hotel keyed by room id. */
    public static function activeStays(): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM guest_stays WHERE hotel_id = :hid AND checked_out_at IS NULL ORDER BY id', ['hid' => Tenant::id()]) as $s) {
            $out[(int) $s['room_id']] = $s;
        }
        return $out;
    }

    public static function activeStayByRef(string $ref): ?array
    {
        return DB::one(
            'SELECT * FROM guest_stays WHERE hotel_id = :hid AND external_ref = :ref AND checked_out_at IS NULL ORDER BY id DESC LIMIT 1',
            ['hid' => Tenant::id(), 'ref' => $ref]
        );
    }

    public static function roomByNumber(string $number): ?array
    {
        return DB::one('SELECT * FROM rooms WHERE hotel_id = :hid AND room_number = :n LIMIT 1', ['hid' => Tenant::id(), 'n' => trim($number)]);
    }

    /**
     * Validate stay fields (check-in / edit). Returns [data, errors]. $partial: only validate the
     * keys present (edit / PMS update).
     */
    public static function validate(array $in, bool $partial = false): array
    {
        $errors = [];
        $data = [];
        if (!$partial || array_key_exists('guest_name', $in)) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) (is_scalar($in['guest_name'] ?? null) ? $in['guest_name'] : '')));
            if ($name === '') {
                $errors[] = __('Guest name is required.');
            } elseif (mb_strlen($name) > 120) {
                $errors[] = __('Guest name is too long.');
            }
            $data['guest_name'] = mb_substr($name, 0, 120);
        }
        if (!$partial || array_key_exists('salutation', $in)) {
            $data['salutation'] = self::salutation(is_scalar($in['salutation'] ?? null) ? (string) $in['salutation'] : '');
        }
        if (!$partial || array_key_exists('language', $in)) {
            $data['language'] = self::lang(is_scalar($in['language'] ?? null) ? (string) $in['language'] : 'en');
        }
        if (array_key_exists('phone', $in)) {
            $phone = trim((string) (is_scalar($in['phone']) ? $in['phone'] : ''));
            if ($phone !== '' && !preg_match('/^\+?[0-9 ()\-]{6,20}$/', $phone)) {
                $errors[] = __('Phone number is not valid.');
            }
            $data['phone'] = $phone === '' ? null : $phone;
        }
        foreach (['checkout_at' => 'expected_checkout_at'] as $src => $col) {
            if (array_key_exists($src, $in) || array_key_exists($col, $in)) {
                $raw = $in[$src] ?? $in[$col] ?? '';
                $raw = is_scalar($raw) ? trim((string) $raw) : '';
                if ($raw === '') {
                    $data[$col] = null;
                } else {
                    $dt = self::parseCheckout($raw);
                    if ($dt === null) {
                        $errors[] = __('Checkout date is not valid.');
                    } else {
                        $data[$col] = $dt;
                    }
                }
            }
        }
        foreach (['notes' => 1000, 'balance_text' => 255, 'wifi_password' => 100, 'external_ref' => 100] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = trim((string) (is_scalar($in[$k]) ? $in[$k] : ''));
                $data[$k] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }
        return [$data, $errors];
    }

    /** "2026-10-06" (→ default checkout time), "2026-10-06T11:00", ISO-8601 with zone → local Y-m-d H:i:s. */
    public static function parseCheckout(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $raw .= ' ' . (Broadcaster::parseTime(self::setting('guest_checkout_time')) ?? '10:00:00');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $raw)) {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $raw));
        if (!$ts || $ts < strtotime('2000-01-01') || $ts > time() + 400 * 86400) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    public static function salutation(string $s): string
    {
        $s = trim($s);
        foreach (self::SALUTATIONS as $known) {
            if ($known !== '' && (strcasecmp($s, $known) === 0 || strcasecmp($s . '.', $known) === 0)) {
                return $known;
            }
        }
        return mb_substr(preg_replace('/[^\p{L}\p{M}. ]/u', '', $s) ?? '', 0, 20);
    }

    /**
     * Check a guest in. Throws InvalidArgumentException (validation) or RuntimeException('ROOM_OCCUPIED').
     * Returns the new stay id.
     */
    public static function checkIn(int $roomId, array $in, string $source = 'manual', ?int $userId = null): int
    {
        $room = Tenant::find('rooms', $roomId);
        if (!$room) {
            throw new InvalidArgumentException(__('Room not found.'));
        }
        [$data, $errors] = self::validate($in);
        if ($errors) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }
        if (empty($data['expected_checkout_at'])) {
            $data['expected_checkout_at'] = null;
        }
        $id = DB::transaction(function () use ($room, $data, $source, $userId) {
            // Lock the room row so two simultaneous check-ins cannot both succeed.
            DB::one('SELECT id FROM rooms WHERE id = :id AND hotel_id = :hid FOR UPDATE', ['id' => $room['id'], 'hid' => Tenant::id()]);
            if (self::activeStay((int) $room['id'])) {
                throw new RuntimeException('ROOM_OCCUPIED');
            }
            $id = DB::insert('guest_stays', $data + [
                'room_id' => (int) $room['id'],
                'checkin_at' => now(),
                'source' => in_array($source, self::SOURCES, true) ? $source : 'manual',
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            self::rotateToken((int) $room['id'], $id);
            return $id;
        });
        self::refreshRoom($room);
        ActivityLog::add('guest_checkin', 'guest_stay', $id, 'Room ' . $room['room_number'] . ' (' . $source . ')');
        return $id;
    }

    /** Check a stay out (idempotent: false when it was already checked out). */
    public static function checkOut(int $stayId, string $source = 'manual'): bool
    {
        $stay = self::findStay($stayId);
        if (!$stay || $stay['checked_out_at'] !== null) {
            return false;
        }
        DB::update('guest_stays', ['checked_out_at' => now()], 'id = :id AND checked_out_at IS NULL', ['id' => $stayId]);
        $room = $stay['room_id'] ? Tenant::find('rooms', (int) $stay['room_id']) : null;
        if ($room) {
            self::rotateToken((int) $room['id'], null);
            self::refreshRoom($room);
        }
        ActivityLog::add('guest_checkout', 'guest_stay', $stayId, 'Room ' . ($room['room_number'] ?? '-') . ' (' . $source . ')');
        return true;
    }

    /** Update an active (or past) stay's details. */
    public static function update(int $stayId, array $in): void
    {
        $stay = self::findStay($stayId);
        if (!$stay) {
            throw new InvalidArgumentException(__('Stay not found.'));
        }
        [$data, $errors] = self::validate($in, true);
        if ($errors) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }
        if (!$data) {
            return;
        }
        DB::update('guest_stays', $data, 'id = :id', ['id' => $stayId]);
        if ($stay['checked_out_at'] === null && $stay['room_id'] && ($room = Tenant::find('rooms', (int) $stay['room_id']))) {
            self::refreshRoom($room);
        }
        ActivityLog::add('guest_update', 'guest_stay', $stayId, implode(', ', array_keys($data)));
    }

    /** Move an active stay to another (vacant) room. Throws RuntimeException('ROOM_OCCUPIED'). */
    public static function move(int $stayId, int $toRoomId): void
    {
        $stay = self::findStay($stayId);
        if (!$stay || $stay['checked_out_at'] !== null) {
            throw new InvalidArgumentException(__('Stay not found.'));
        }
        $to = Tenant::find('rooms', $toRoomId);
        if (!$to) {
            throw new InvalidArgumentException(__('Room not found.'));
        }
        if ((int) $stay['room_id'] === $toRoomId) {
            return;
        }
        $from = $stay['room_id'] ? Tenant::find('rooms', (int) $stay['room_id']) : null;
        DB::transaction(function () use ($stayId, $to, $from) {
            DB::one('SELECT id FROM rooms WHERE id = :id AND hotel_id = :hid FOR UPDATE', ['id' => $to['id'], 'hid' => Tenant::id()]);
            if (self::activeStay((int) $to['id'])) {
                throw new RuntimeException('ROOM_OCCUPIED');
            }
            DB::update('guest_stays', ['room_id' => (int) $to['id']], 'id = :id', ['id' => $stayId]);
            // Open orders / requests follow the guest.
            DB::update('guest_orders', ['room_id' => (int) $to['id']], "stay_id = :s AND status IN ('new','accepted','preparing')", ['s' => $stayId]);
            DB::update('guest_requests', ['room_id' => (int) $to['id']], "stay_id = :s AND status = 'open'", ['s' => $stayId]);
            self::rotateToken((int) $to['id'], $stayId);
            if ($from) {
                self::rotateToken((int) $from['id'], null);
            }
        });
        self::refreshRoom($to);
        if ($from) {
            self::refreshRoom($from);
        }
        ActivityLog::add('guest_room_move', 'guest_stay', $stayId, ($from['room_number'] ?? '-') . ' → ' . $to['room_number']);
    }

    /** Content changed for a room: invalidate the hotel's content cache and tell the room's TVs to refresh. */
    public static function refreshRoom(array $room): void
    {
        Settings::bumpContentVersion();
        Broadcaster::queueForRooms([$room], 'SHOW_CONTENT');
    }

    /** Sum of the stay's room-service charges (orders not cancelled). */
    public static function charges(int $stayId): float
    {
        return (float) DB::value(
            "SELECT COALESCE(SUM(total), 0) FROM guest_orders WHERE hotel_id = :hid AND stay_id = :s AND status <> 'cancelled'",
            ['hid' => Tenant::id(), 's' => $stayId]
        );
    }

    // ------------------------------------------------------------------ guest tokens

    /** Random [A-Za-z0-9] string. */
    public static function randomToken(int $len = self::TOKEN_LENGTH): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /** Give the room a new token (bound to $stayId or none). Returns the token. */
    public static function rotateToken(int $roomId, ?int $stayId): string
    {
        $token = self::randomToken();
        DB::query(
            'INSERT INTO guest_tokens (hotel_id, room_id, token, stay_id, created_at) VALUES (:hid, :r, :t, :s, :c)
             ON DUPLICATE KEY UPDATE token = VALUES(token), stay_id = VALUES(stay_id), created_at = VALUES(created_at)',
            ['hid' => Tenant::id(), 'r' => $roomId, 't' => $token, 's' => $stayId, 'c' => now()]
        );
        return $token;
    }

    /** Current token of a room (created on first use). */
    public static function roomToken(int $roomId): string
    {
        $t = DB::value('SELECT token FROM guest_tokens WHERE hotel_id = :hid AND room_id = :r', ['hid' => Tenant::id(), 'r' => $roomId]);
        if (is_string($t) && $t !== '') {
            return $t;
        }
        $stay = self::activeStay($roomId);
        return self::rotateToken($roomId, $stay ? (int) $stay['id'] : null);
    }

    /** Is a room's current token usable right now (see class doc)? */
    public static function tokenUsable(?array $tokenRow, ?array $stay): bool
    {
        if (!$tokenRow) {
            return false;
        }
        if ($stay) {
            return (int) ($tokenRow['stay_id'] ?? 0) === (int) $stay['id'];
        }
        return !self::checkinMode() && $tokenRow['stay_id'] === null;
    }

    public static function guestUrl(string $token, string $fragment = ''): string
    {
        return base_url('g/' . rawurlencode($token)) . ($fragment !== '' ? '#' . $fragment : '');
    }

    public static function validTokenFormat(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{16,40}$/', $token);
    }

    /**
     * Resolve a guest token (public guest app / API). Sets the tenant to the token's hotel.
     * Returns ['token', 'token_hash', 'room', 'stay'|null, 'lang', 'hotel_id'] or null when the token
     * is unknown, rotated, the stay is over, the hotel is not active or guest services are not
     * part of the hotel's plan.
     */
    public static function resolveToken(string $token): ?array
    {
        if (!self::validTokenFormat($token)) {
            return null;
        }
        $row = DB::one('SELECT * FROM guest_tokens WHERE token = :t LIMIT 1', ['t' => $token]);
        if (!$row || !hash_equals((string) $row['token'], $token)) {
            return null;
        }
        Tenant::set((int) $row['hotel_id']);
        if (!Tenant::isActive() || !Tenant::feature('services')) {
            return null;
        }
        $room = DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :hid', ['id' => (int) $row['room_id'], 'hid' => Tenant::id()]);
        if (!$room) {
            return null;
        }
        $stay = self::activeStay((int) $room['id']);
        if (!self::tokenUsable($row, $stay)) {
            return null;
        }
        return [
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'room' => $room,
            'stay' => $stay,
            'lang' => $stay ? self::lang($stay['language']) : self::hotelLang(),
            'hotel_id' => Tenant::id(),
        ];
    }

    // ------------------------------------------------------------------ presentation

    /** "98•••••210" — phone numbers are never shown in full in lists. */
    public static function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 4) {
            return str_repeat('•', strlen($digits));
        }
        return substr($digits, 0, 2) . str_repeat('•', max(1, strlen($digits) - 5)) . substr($digits, -3);
    }

    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        return (string) ($parts[0] ?? '');
    }

    public static function lastName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        return (string) (end($parts) ?: '');
    }

    /** Salutation in the guest's language ("Mr." → "શ્રી" / "श्री"). */
    public static function salutationFor(string $salutation, string $lang): string
    {
        return $salutation === '' ? '' : I18n::translate($salutation, $lang);
    }

    /** "Mr. Shah" (salutation + last name) or the full name without salutation. */
    public static function displayName(array $stay, string $lang): string
    {
        $sal = self::salutationFor((string) $stay['salutation'], $lang);
        $name = (string) $stay['guest_name'];
        return $sal !== '' ? trim($sal . ' ' . self::lastName($name)) : $name;
    }

    /** Replace {placeholders} and collapse whitespace. */
    public static function render(string $tpl, array $vars): string
    {
        $out = $tpl;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return trim((string) preg_replace('/[ \t]{2,}/u', ' ', $out));
    }

    /** Template setting for a language, falling back to the translated built-in default. */
    public static function template(string $kind, string $lang): string
    {
        $custom = trim((string) Settings::get('guest_' . $kind . '_' . $lang, ''));
        if ($custom !== '') {
            return $custom;
        }
        $default = match ($kind) {
            'welcome_title' => self::TPL_WELCOME_TITLE,
            'welcome_message' => self::TPL_WELCOME_MESSAGE,
            'reminder_text' => self::TPL_REMINDER,
            'balance_text' => self::TPL_BALANCE,
            default => '',
        };
        return I18n::translate($default, $lang);
    }

    public static function templateVars(array $stay, array $room, string $lang): array
    {
        $out = $stay['expected_checkout_at'] ? strtotime((string) $stay['expected_checkout_at']) : null;
        return [
            'salutation' => self::salutationFor((string) $stay['salutation'], $lang),
            'name' => (string) $stay['guest_name'],
            'first_name' => self::firstName((string) $stay['guest_name']),
            'last_name' => self::lastName((string) $stay['guest_name']),
            'hotel' => (string) Settings::get('hotel_name', ''),
            'room' => (string) $room['room_number'],
            'time' => $out ? date('h:i A', $out) : (string) self::setting('guest_checkout_time'),
            'date' => $out ? date('d M Y', $out) : '',
            'balance' => (string) ($stay['balance_text'] ?? ''),
        ];
    }

    /** `guest` field of the TV Content object. */
    public static function guestObject(array $stay, string $lang): array
    {
        return [
            'name' => self::displayName($stay, $lang),
            'first_name' => self::firstName((string) $stay['guest_name']),
            'language' => $lang,
            'checkin_at' => iso_time((string) $stay['checkin_at']),
            'checkout_at' => $stay['expected_checkout_at'] ? iso_time((string) $stay['expected_checkout_at']) : null,
        ];
    }

    /** Wi-Fi for the welcome card / guest app (null when no SSID is configured). */
    public static function wifi(?array $stay): ?array
    {
        $ssid = trim(self::setting('guest_wifi_ssid'));
        if ($ssid === '') {
            return null;
        }
        $pw = $stay && !empty($stay['wifi_password']) ? (string) $stay['wifi_password'] : self::setting('guest_wifi_password');
        return ['ssid' => $ssid, 'password' => $pw];
    }

    /** `welcome` field (#1). */
    public static function welcomeObject(array $stay, array $room, string $lang): array
    {
        $vars = self::templateVars($stay, $room, $lang);
        return [
            'show' => true,
            'id' => 'stay-' . (int) $stay['id'],
            'title' => self::render(self::template('welcome_title', $lang), $vars),
            'message' => self::render(self::template('welcome_message', $lang), $vars),
            'wifi' => self::wifi($stay),
            'duration_sec' => max(5, min(600, (int) self::setting('guest_welcome_duration') ?: 20)),
        ];
    }

    /**
     * `checkout_reminder` (#6): on the expected checkout day, from guest_reminder_time until the
     * guest is checked out. Null when there is nothing to show.
     */
    public static function reminderObject(array $stay, array $room, string $lang, ?int $now = null): ?array
    {
        $now ??= time();
        if (self::setting('guest_reminder_enabled') !== '1' || empty($stay['expected_checkout_at'])) {
            return null;
        }
        $out = strtotime((string) $stay['expected_checkout_at']);
        $day = date('Y-m-d', $out);
        if (date('Y-m-d', $now) !== $day) {
            return null;
        }
        $from = Broadcaster::parseTime(self::setting('guest_reminder_time')) ?? '07:00:00';
        if (date('H:i:s', $now) < $from) {
            return null;
        }
        $vars = self::templateVars($stay, $room, $lang);
        $text = self::render(self::template('reminder_text', $lang), $vars);
        if (self::setting('guest_reminder_balance') === '1' && trim((string) ($stay['balance_text'] ?? '')) !== '') {
            $text .= ' ' . self::render(self::template('balance_text', $lang), $vars);
        }
        return ['show' => true, 'id' => 'co-' . (int) $stay['id'] . '-' . $day, 'text' => $text];
    }

    // ------------------------------------------------------------------ PMS key

    /** Generate (rotate) the hotel's PMS API key. Returns the plain key (shown once). */
    public static function rotatePmsKey(): string
    {
        $key = 'hcpms' . random_token(20);
        Settings::set('guest_pms_key_hash', hash('sha256', $key));
        Settings::set('guest_pms_key_hint', substr($key, -4));
        return $key;
    }

    public static function revokePmsKey(): void
    {
        Settings::set('guest_pms_key_hash', '');
        Settings::set('guest_pms_key_hint', '');
    }

    /** Hotel id owning a PMS key (keys are stored as SHA-256 only), or null. */
    public static function hotelForPmsKey(string $key): ?int
    {
        if (!preg_match('/^hcpms[a-f0-9]{40}$/', $key)) {
            return null;
        }
        $hash = hash('sha256', $key);
        $hid = DB::value(
            "SELECT hotel_id FROM system_settings WHERE setting_key = 'guest_pms_key_hash' AND setting_value = :h AND hotel_id > 0 LIMIT 1",
            ['h' => $hash]
        );
        return $hid !== null ? (int) $hid : null;
    }

    // ------------------------------------------------------------------ privacy

    /**
     * Anonymise guest PII of stays checked out more than $days days ago (current hotel): name, phone,
     * notes, Wi-Fi password, balance text, external ref; free-text notes / IPs on their orders and
     * requests are cleared and feedback is unlinked from the guest token. Returns the number of stays anonymised.
     */
    public static function purgePii(int $days): int
    {
        $days = max(1, $days);
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $ids = array_map('intval', DB::column(
            'SELECT id FROM guest_stays WHERE hotel_id = :hid AND checked_out_at IS NOT NULL AND checked_out_at < :c AND pii_deleted_at IS NULL LIMIT 1000',
            ['hid' => Tenant::id(), 'c' => $cutoff]
        ));
        if (!$ids) {
            return 0;
        }
        [$in, $p] = DB::in($ids, 's');
        $hid = ['hid' => Tenant::id()];
        DB::query("UPDATE guest_stays SET guest_name = '', salutation = '', phone = NULL, notes = NULL, wifi_password = NULL,
                   balance_text = NULL, external_ref = NULL, pii_deleted_at = :n WHERE hotel_id = :hid AND id IN $in", $p + $hid + ['n' => now()]);
        DB::query("UPDATE guest_orders SET notes = NULL, ip_address = NULL, token_hash = NULL WHERE hotel_id = :hid AND stay_id IN $in", $p + $hid);
        DB::query("UPDATE guest_requests SET notes = NULL, ip_address = NULL, token_hash = NULL WHERE hotel_id = :hid AND stay_id IN $in", $p + $hid);
        // Feedback stays (rating + comment for the report) but is no longer linked to the guest's token.
        DB::query("UPDATE guest_feedback SET token_hash = NULL WHERE hotel_id = :hid AND stay_id IN $in", $p + $hid);
        return count($ids);
    }
}
