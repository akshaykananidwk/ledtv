<?php
declare(strict_types=1);

/**
 * PMS integration (#10): REST endpoints /api/pms/* with a per-hotel API key (Bearer, stored as SHA-256)
 * and a generic incoming webhook whose payload fields are mapped by a configurable JSON mapping
 * (presets for eZee / Hotelogix / StayFlexi style payloads). All actions are idempotent so a PMS may
 * safely retry: check-in by external_ref (or same guest in the same room), check-out of an
 * already checked-out stay, a move to the room the stay is already in.
 */
final class GuestPms
{
    /** Default mapping: field => dot path (or list of paths joined with a space). */
    public const DEFAULT_MAPPING = [
        'event' => 'event',
        'events' => [
            'checkin' => ['checkin', 'check_in', 'checked_in', 'arrival'],
            'checkout' => ['checkout', 'check_out', 'checked_out', 'departure'],
            'room_move' => ['room_move', 'roommove', 'room_change', 'move'],
            'update' => ['update', 'modify', 'modified', 'amend'],
        ],
        'room_number' => 'room_number',
        'guest_name' => 'guest_name',
        'salutation' => 'salutation',
        'language' => 'language',
        'phone' => 'phone',
        'checkout_at' => 'checkout_at',
        'external_ref' => 'external_ref',
        'from_room' => 'from_room',
        'to_room' => 'to_room',
        'balance' => 'balance',
    ];

    /**
     * Example presets (payload styles seen from common Indian PMS / channel managers). The real
     * field names depend on the PMS configuration — hotels can edit the JSON on the setup page.
     */
    public const PRESETS = [
        'generic' => self::DEFAULT_MAPPING,
        'ezee' => [
            'event' => 'EventType',
            'events' => ['checkin' => ['CheckIn', 'checkin'], 'checkout' => ['CheckOut', 'checkout'], 'room_move' => ['RoomMove', 'RoomChange'], 'update' => ['Modify', 'Update']],
            'room_number' => 'RoomNo',
            'guest_name' => ['Guest.FirstName', 'Guest.LastName'],
            'salutation' => 'Guest.Salutation',
            'language' => 'Guest.Language',
            'phone' => 'Guest.Mobile',
            'checkout_at' => 'DepartureDate',
            'external_ref' => 'ReservationNo',
            'from_room' => 'OldRoomNo',
            'to_room' => 'NewRoomNo',
            'balance' => 'Balance',
        ],
        'hotelogix' => [
            'event' => 'action',
            'events' => ['checkin' => ['CHECKIN', 'checkin'], 'checkout' => ['CHECKOUT', 'checkout'], 'room_move' => ['ROOMCHANGE', 'roomchange'], 'update' => ['UPDATE', 'update']],
            'room_number' => 'booking.room.name',
            'guest_name' => ['booking.guest.fName', 'booking.guest.lName'],
            'salutation' => 'booking.guest.title',
            'language' => 'booking.guest.language',
            'phone' => 'booking.guest.phone',
            'checkout_at' => 'booking.checkOutDate',
            'external_ref' => 'booking.id',
            'from_room' => 'booking.oldRoom.name',
            'to_room' => 'booking.room.name',
            'balance' => 'booking.balance',
        ],
        'stayflexi' => [
            'event' => 'event_type',
            'events' => ['checkin' => ['BOOKING_CHECKED_IN', 'checkin'], 'checkout' => ['BOOKING_CHECKED_OUT', 'checkout'], 'room_move' => ['ROOM_SWITCHED', 'room_move'], 'update' => ['BOOKING_MODIFIED', 'update']],
            'room_number' => 'data.room_no',
            'guest_name' => 'data.customer_name',
            'salutation' => 'data.salutation',
            'language' => 'data.language',
            'phone' => 'data.customer_phone',
            'checkout_at' => 'data.checkout',
            'external_ref' => 'data.booking_id',
            'from_room' => 'data.from_room_no',
            'to_room' => 'data.to_room_no',
            'balance' => 'data.balance_due',
        ],
    ];

    /** The hotel's mapping (setting guest_pms_mapping, merged over the default). */
    public static function mapping(): array
    {
        $m = json_decode(Guests::setting('guest_pms_mapping'), true);
        return is_array($m) ? array_replace(self::DEFAULT_MAPPING, $m) : self::DEFAULT_MAPPING;
    }

    /** Validate a mapping JSON string. Returns [normalised JSON|'' , error|null]. */
    public static function validateMapping(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return ['', null];
        }
        $m = json_decode($json, true);
        if (!is_array($m) || array_is_list($m)) {
            return ['', __('Mapping must be a JSON object.')];
        }
        foreach ($m as $k => $v) {
            if (!array_key_exists($k, self::DEFAULT_MAPPING)) {
                return ['', __('Unknown mapping field: :f', ['f' => (string) $k])];
            }
            if ($k === 'events') {
                if (!is_array($v)) {
                    return ['', __('"events" must be an object.')];
                }
                continue;
            }
            if (!is_string($v) && !(is_array($v) && array_is_list($v) && array_filter($v, 'is_string') === $v)) {
                return ['', __('Mapping values must be a path string or a list of paths.')];
            }
        }
        return [json_out($m), null];
    }

    /** Value at a dot path ("booking.guest.fName"), or list of paths joined with spaces. */
    public static function pluck(array $payload, string|array $path): ?string
    {
        if (is_array($path)) {
            $parts = array_filter(array_map(fn ($p) => self::pluck($payload, (string) $p), $path), fn ($v) => $v !== null && $v !== '');
            return $parts ? implode(' ', $parts) : null;
        }
        $cur = $payload;
        foreach (explode('.', $path) as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } elseif (is_array($cur) && array_is_list($cur) && isset($cur[0][$seg])) {
                $cur = $cur[0][$seg]; // first element of a list (e.g. rooms[0].number)
            } else {
                return null;
            }
        }
        return is_scalar($cur) ? trim((string) $cur) : null;
    }

    /** Map a webhook payload → [action, normalised fields]. Action null when the event is unknown. */
    public static function mapPayload(array $payload, ?array $mapping = null): array
    {
        $mapping ??= self::mapping();
        $fields = [];
        foreach ($mapping as $k => $path) {
            if ($k === 'event' || $k === 'events') {
                continue;
            }
            $v = self::pluck($payload, $path);
            if ($v !== null && $v !== '') {
                $fields[$k] = $v;
            }
        }
        $event = strtolower((string) self::pluck($payload, $mapping['event'] ?? 'event'));
        $action = null;
        foreach ((array) ($mapping['events'] ?? []) as $act => $names) {
            foreach ((array) $names as $n) {
                if ($event !== '' && strtolower((string) $n) === $event) {
                    $action = $act;
                    break 2;
                }
            }
        }
        return [$action, $fields];
    }

    public static function normaliseLanguage(?string $v): string
    {
        $v = strtolower(trim((string) $v));
        return match (true) {
            str_starts_with($v, 'gu') => 'gu',
            str_starts_with($v, 'hi') => 'hi',
            default => 'en',
        };
    }

    // ------------------------------------------------------------------ actions (tenant already set)

    /** @return array{0:int,1:array} [HTTP status, data] — throws PmsError on failure. */
    public static function checkin(array $in): array
    {
        $roomNo = self::str($in, 'room_number', 20);
        $name = self::str($in, 'guest_name', 120);
        if ($roomNo === '' || $name === '') {
            throw new PmsError('VALIDATION_ERROR', 'room_number and guest_name are required', 400);
        }
        $room = Guests::roomByNumber($roomNo);
        if (!$room) {
            throw new PmsError('ROOM_NOT_FOUND', 'Room ' . $roomNo . ' does not exist', 404);
        }
        $ref = self::str($in, 'external_ref', 100);
        $fields = ['guest_name' => $name, 'salutation' => self::str($in, 'salutation', 20), 'language' => self::normaliseLanguage($in['language'] ?? null)];
        if (array_key_exists('phone', $in)) {
            $fields['phone'] = self::str($in, 'phone', 30);
        }
        if (($co = self::str($in, 'checkout_at', 40)) !== '') {
            $fields['checkout_at'] = $co;
        }
        if (array_key_exists('balance', $in)) {
            $fields['balance_text'] = self::str($in, 'balance', 255);
        }
        if ($ref !== '') {
            $fields['external_ref'] = $ref;
        }
        [, $errors] = Guests::validate($fields);
        if ($errors) {
            throw new PmsError('VALIDATION_ERROR', implode(' ', $errors), 400);
        }

        // Idempotency 1: the booking is already checked in.
        $existing = $ref !== '' ? Guests::activeStayByRef($ref) : null;
        // Idempotency 2 (no ref): the same guest is already in this room.
        $current = Guests::activeStay((int) $room['id']);
        if (!$existing && $ref === '' && $current && mb_strtolower(trim((string) $current['guest_name'])) === mb_strtolower($name)) {
            $existing = $current;
        }
        if ($existing) {
            if ((int) $existing['room_id'] !== (int) $room['id']) {
                self::moveStay($existing, $room);
            }
            Guests::update((int) $existing['id'], array_diff_key($fields, ['external_ref' => 1]));
            return [200, ['stay_id' => (int) $existing['id'], 'room_number' => $room['room_number'], 'status' => 'checked_in', 'idempotent' => true]];
        }
        $replaced = null;
        if ($current) {
            // The PMS is the source of truth: a missed check-out of the previous guest is closed now.
            Guests::checkOut((int) $current['id'], 'pms');
            $replaced = (int) $current['id'];
        }
        try {
            $id = Guests::checkIn((int) $room['id'], $fields, 'pms');
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'ROOM_OCCUPIED') {
                throw new PmsError('ROOM_OCCUPIED', 'Room ' . $roomNo . ' is occupied', 409);
            }
            throw $e;
        }
        return [201, ['stay_id' => $id, 'room_number' => $room['room_number'], 'status' => 'checked_in', 'idempotent' => false, 'replaced_stay_id' => $replaced]];
    }

    public static function checkout(array $in): array
    {
        $ref = self::str($in, 'external_ref', 100);
        $roomNo = self::str($in, 'room_number', 20);
        if ($ref === '' && $roomNo === '') {
            throw new PmsError('VALIDATION_ERROR', 'room_number or external_ref is required', 400);
        }
        if ($ref !== '') {
            $stay = Guests::activeStayByRef($ref);
            if (!$stay) {
                $past = DB::one('SELECT id FROM guest_stays WHERE hotel_id = :hid AND external_ref = :r AND checked_out_at IS NOT NULL ORDER BY id DESC LIMIT 1', ['hid' => Tenant::id(), 'r' => $ref]);
                if ($past) {
                    return [200, ['stay_id' => (int) $past['id'], 'status' => 'checked_out', 'idempotent' => true]];
                }
                throw new PmsError('STAY_NOT_FOUND', 'No stay with external_ref ' . $ref, 404);
            }
        } else {
            $room = Guests::roomByNumber($roomNo);
            if (!$room) {
                throw new PmsError('ROOM_NOT_FOUND', 'Room ' . $roomNo . ' does not exist', 404);
            }
            $stay = Guests::activeStay((int) $room['id']);
            if (!$stay) {
                return [200, ['stay_id' => null, 'room_number' => $room['room_number'], 'status' => 'checked_out', 'idempotent' => true]];
            }
        }
        Guests::checkOut((int) $stay['id'], 'pms');
        return [200, ['stay_id' => (int) $stay['id'], 'status' => 'checked_out', 'idempotent' => false]];
    }

    public static function roomMove(array $in): array
    {
        $ref = self::str($in, 'external_ref', 100);
        $fromNo = self::str($in, 'from_room', 20);
        $toNo = self::str($in, 'to_room', 20);
        if ($toNo === '' || ($fromNo === '' && $ref === '')) {
            throw new PmsError('VALIDATION_ERROR', 'to_room and from_room (or external_ref) are required', 400);
        }
        $to = Guests::roomByNumber($toNo);
        if (!$to) {
            throw new PmsError('ROOM_NOT_FOUND', 'Room ' . $toNo . ' does not exist', 404);
        }
        $stay = $ref !== '' ? Guests::activeStayByRef($ref) : null;
        if (!$stay && $fromNo !== '') {
            $from = Guests::roomByNumber($fromNo);
            if (!$from) {
                throw new PmsError('ROOM_NOT_FOUND', 'Room ' . $fromNo . ' does not exist', 404);
            }
            $stay = Guests::activeStay((int) $from['id']);
            // Retry after a successful move: the guest is already in the target room.
            if (!$stay && ($already = Guests::activeStay((int) $to['id'])) && ($ref === '' || $already['external_ref'] === $ref)) {
                return [200, ['stay_id' => (int) $already['id'], 'room_number' => $to['room_number'], 'status' => 'moved', 'idempotent' => true]];
            }
        }
        if (!$stay) {
            throw new PmsError('STAY_NOT_FOUND', 'No checked-in guest found to move', 404);
        }
        if ((int) $stay['room_id'] === (int) $to['id']) {
            return [200, ['stay_id' => (int) $stay['id'], 'room_number' => $to['room_number'], 'status' => 'moved', 'idempotent' => true]];
        }
        self::moveStay($stay, $to);
        return [200, ['stay_id' => (int) $stay['id'], 'room_number' => $to['room_number'], 'status' => 'moved', 'idempotent' => false]];
    }

    /** Update a stay (balance, expected checkout, name, language…) by external_ref or room_number. */
    public static function updateStay(array $in): array
    {
        $ref = self::str($in, 'external_ref', 100);
        $roomNo = self::str($in, 'room_number', 20);
        $stay = $ref !== '' ? Guests::activeStayByRef($ref) : null;
        if (!$stay && $roomNo !== '' && ($room = Guests::roomByNumber($roomNo))) {
            $stay = Guests::activeStay((int) $room['id']);
        }
        if (!$stay) {
            throw new PmsError('STAY_NOT_FOUND', 'No checked-in guest found', 404);
        }
        $fields = [];
        foreach (['guest_name' => 120, 'salutation' => 20, 'phone' => 30, 'checkout_at' => 40] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $fields[$k] = self::str($in, $k, $max);
            }
        }
        if (array_key_exists('language', $in)) {
            $fields['language'] = self::normaliseLanguage($in['language']);
        }
        if (array_key_exists('balance', $in)) {
            $fields['balance_text'] = self::str($in, 'balance', 255);
        }
        try {
            Guests::update((int) $stay['id'], $fields);
        } catch (InvalidArgumentException $e) {
            throw new PmsError('VALIDATION_ERROR', $e->getMessage(), 400);
        }
        return [200, ['stay_id' => (int) $stay['id'], 'status' => 'updated']];
    }

    /** GET /api/pms/rooms — occupancy of every room. */
    public static function rooms(): array
    {
        $stays = Guests::activeStays();
        $out = [];
        foreach (DB::all('SELECT id, room_number, name, floor FROM rooms WHERE hotel_id = :hid ORDER BY LENGTH(room_number), room_number', ['hid' => Tenant::id()]) as $r) {
            $s = $stays[(int) $r['id']] ?? null;
            $out[] = [
                'room_number' => (string) $r['room_number'],
                'name' => (string) ($r['name'] ?? ''),
                'floor' => (string) ($r['floor'] ?? ''),
                'occupied' => $s !== null,
                'stay' => $s ? [
                    'stay_id' => (int) $s['id'],
                    'guest_name' => trim($s['salutation'] . ' ' . $s['guest_name']),
                    'language' => $s['language'],
                    'checkin_at' => iso_time((string) $s['checkin_at']),
                    'checkout_at' => $s['expected_checkout_at'] ? iso_time((string) $s['expected_checkout_at']) : null,
                    'external_ref' => $s['external_ref'],
                    'source' => $s['source'],
                ] : null,
            ];
        }
        return [200, ['rooms' => $out, 'occupied' => count($stays), 'total' => count($out)]];
    }

    /** Webhook: map the payload and run the action. */
    public static function webhook(array $payload): array
    {
        [$action, $fields] = self::mapPayload($payload);
        return match ($action) {
            'checkin' => self::checkin($fields),
            'checkout' => self::checkout($fields),
            'room_move' => self::roomMove($fields + (isset($fields['room_number']) && !isset($fields['to_room']) ? ['to_room' => $fields['room_number']] : [])),
            'update' => self::updateStay($fields),
            default => throw new PmsError('UNKNOWN_EVENT', 'Event type not recognised; check the field mapping', 422),
        };
    }

    private static function moveStay(array $stay, array $to): void
    {
        try {
            Guests::move((int) $stay['id'], (int) $to['id']);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'ROOM_OCCUPIED') {
                throw new PmsError('ROOM_OCCUPIED', 'Room ' . $to['room_number'] . ' is occupied', 409);
            }
            throw $e;
        }
    }

    private static function str(array $in, string $k, int $max): string
    {
        $v = $in[$k] ?? '';
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            throw new PmsError('VALIDATION_ERROR', $k . ' must be a string', 400);
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $v));
        if (mb_strlen($v) > $max) {
            throw new PmsError('VALIDATION_ERROR', $k . ' is longer than ' . $max . ' characters', 400);
        }
        return $v;
    }
}

/** API error raised by GuestPms (code, message, HTTP status). */
final class PmsError extends RuntimeException
{
    public function __construct(public readonly string $apiCode, string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
