<?php
declare(strict_types=1);

/**
 * Guest services from the phone (V2_SPEC §2, #2 #3 #8): room-service menu & orders, service requests,
 * feedback, staff alerts and TV messages. Everything is scoped to the current hotel (Tenant).
 * Guest-side methods take the context returned by Guests::resolveToken().
 */
final class GuestServices
{
    public const ORDER_STATUSES = ['new', 'accepted', 'preparing', 'delivered', 'cancelled'];
    /** Allowed transitions (staff). */
    public const ORDER_FLOW = [
        'new' => ['accepted', 'preparing', 'delivered', 'cancelled'],
        'accepted' => ['preparing', 'delivered', 'cancelled'],
        'preparing' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];
    public const OPEN_ORDER_STATUSES = ['new', 'accepted', 'preparing'];
    public const REQUEST_STATUSES = ['open', 'done', 'cancelled'];
    public const FOOD_TYPES = ['veg', 'nonveg', 'egg', 'none'];

    public const MAX_ORDER_LINES = 30;
    public const MAX_QTY = 20;

    /** Default request buttons [code, English name, icon, needs_time] seeded once per hotel. */
    public const DEFAULT_REQUEST_TYPES = [
        ['water', 'Drinking water', '💧', 0],
        ['towels', 'Fresh towels', '🧺', 0],
        ['cleaning', 'Room cleaning', '🧹', 0],
        ['extra_bed', 'Extra bed', '🛏️', 0],
        ['call_me', 'Please call me', '📞', 0],
        ['maintenance', 'Maintenance', '🔧', 0],
        ['laundry', 'Laundry pickup', '👕', 0],
        ['wakeup', 'Wake-up call', '⏰', 1],
    ];

    public static function enabled(): bool
    {
        return Tenant::feature('services');
    }

    public static function servicesOn(): bool
    {
        return Guests::setting('guest_services_enabled') === '1';
    }

    public static function requestsOn(): bool
    {
        return Guests::setting('guest_requests_enabled') === '1';
    }

    public static function feedbackOn(): bool
    {
        return Guests::setting('guest_feedback_enabled') === '1';
    }

    /** Localised column ("name" → name_gu, falling back to name_en). */
    public static function loc(array $row, string $field, string $lang): string
    {
        $v = trim((string) ($row[$field . '_' . $lang] ?? ''));
        return $v !== '' ? $v : (string) ($row[$field . '_en'] ?? '');
    }

    // ------------------------------------------------------------------ menu

    public static function categories(bool $activeOnly = false): array
    {
        return DB::all(
            'SELECT * FROM guest_menu_categories WHERE hotel_id = :hid' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id',
            ['hid' => Tenant::id()]
        );
    }

    public static function items(bool $activeOnly = false): array
    {
        return DB::all(
            'SELECT i.* FROM guest_menu_items i JOIN guest_menu_categories c ON c.id = i.category_id AND c.hotel_id = i.hotel_id
             WHERE i.hotel_id = :hid' . ($activeOnly ? ' AND i.is_active = 1 AND c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.id, i.sort_order, i.id',
            ['hid' => Tenant::id()]
        );
    }

    /** Is an item orderable at $ts (available hours, overnight windows allowed)? */
    public static function availableNow(array $item, ?int $ts = null): bool
    {
        if (empty($item['available_from']) || empty($item['available_to'])) {
            return true;
        }
        $t = date('H:i:s', $ts ?? time());
        $s = (string) $item['available_from'];
        $e = (string) $item['available_to'];
        if ($s === $e) {
            return true;
        }
        return $s < $e ? ($t >= $s && $t < $e) : ($t >= $s || $t < $e);
    }

    public static function hoursLabel(array $item): string
    {
        if (empty($item['available_from']) || empty($item['available_to'])) {
            return '';
        }
        return date('h:i A', (int) strtotime((string) $item['available_from'])) . ' – ' . date('h:i A', (int) strtotime((string) $item['available_to']));
    }

    /** Menu for the guest app (all three languages, so the guest can switch without reloading). */
    public static function menuForGuest(): array
    {
        $cats = [];
        foreach (self::categories(true) as $c) {
            $cats[(int) $c['id']] = [
                'id' => (int) $c['id'],
                'name' => ['en' => $c['name_en'], 'gu' => self::loc($c, 'name', 'gu'), 'hi' => self::loc($c, 'name', 'hi')],
                'items' => [],
            ];
        }
        foreach (self::items(true) as $i) {
            if (!isset($cats[(int) $i['category_id']])) {
                continue;
            }
            $cats[(int) $i['category_id']]['items'][] = [
                'id' => (int) $i['id'],
                'name' => ['en' => $i['name_en'], 'gu' => self::loc($i, 'name', 'gu'), 'hi' => self::loc($i, 'name', 'hi')],
                'description' => ['en' => (string) $i['description_en'], 'gu' => self::loc($i, 'description', 'gu'), 'hi' => self::loc($i, 'description', 'hi')],
                'price' => round((float) $i['price'], 2),
                'food_type' => $i['food_type'],
                'photo_url' => $i['photo_path'] ? media_url((string) $i['photo_path']) : null,
                'hours' => self::hoursLabel($i),
                'available' => self::availableNow($i),
            ];
        }
        return array_values(array_filter($cats, fn ($c) => $c['items']));
    }

    /** Validate + save a category. Returns [id|null, errors]. */
    public static function saveCategory(array $in, ?int $id = null): array
    {
        $data = [
            'name_en' => mb_substr(trim((string) ($in['name_en'] ?? '')), 0, 100),
            'name_gu' => mb_substr(trim((string) ($in['name_gu'] ?? '')), 0, 100) ?: null,
            'name_hi' => mb_substr(trim((string) ($in['name_hi'] ?? '')), 0, 100) ?: null,
            'sort_order' => max(-9999, min(9999, (int) ($in['sort_order'] ?? 0))),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        if ($data['name_en'] === '') {
            return [null, [__('Name (English) is required.')]];
        }
        if ($id) {
            if (!Tenant::find('guest_menu_categories', $id)) {
                return [null, [__('Not found.')]];
            }
            DB::update('guest_menu_categories', $data, 'id = :id', ['id' => $id]);
            return [$id, []];
        }
        return [DB::insert('guest_menu_categories', $data + ['created_at' => now()]), []];
    }

    /** Validate + save a menu item ($photo = Uploader result or null). Returns [id|null, errors]. */
    public static function saveItem(array $in, ?int $id = null, ?array $photo = null): array
    {
        $errors = [];
        $catId = (int) ($in['category_id'] ?? 0);
        if (!$catId || !Tenant::find('guest_menu_categories', $catId)) {
            $errors[] = __('Choose a category.');
        }
        $price = str_replace(',', '', trim((string) ($in['price'] ?? '0')));
        if (!is_numeric($price) || (float) $price < 0 || (float) $price > 1000000) {
            $errors[] = __('Price is not valid.');
        }
        $from = trim((string) ($in['available_from'] ?? ''));
        $to = trim((string) ($in['available_to'] ?? ''));
        $pf = $from === '' ? null : Broadcaster::parseTime($from);
        $pt = $to === '' ? null : Broadcaster::parseTime($to);
        if (($from !== '' && !$pf) || ($to !== '' && !$pt) || (($pf === null) !== ($pt === null))) {
            $errors[] = __('Enter both available-from and available-to times, or leave both empty.');
        }
        $data = [
            'category_id' => $catId,
            'price' => round((float) (is_numeric($price) ? $price : 0), 2),
            'food_type' => in_array($in['food_type'] ?? '', self::FOOD_TYPES, true) ? $in['food_type'] : 'veg',
            'available_from' => $pf,
            'available_to' => $pt,
            'sort_order' => max(-9999, min(9999, (int) ($in['sort_order'] ?? 0))),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        foreach (['en', 'gu', 'hi'] as $l) {
            $data['name_' . $l] = mb_substr(trim((string) ($in['name_' . $l] ?? '')), 0, 120) ?: null;
            $data['description_' . $l] = mb_substr(trim((string) ($in['description_' . $l] ?? '')), 0, 300) ?: null;
        }
        if (!$data['name_en']) {
            $errors[] = __('Name (English) is required.');
        }
        if ($errors) {
            return [null, $errors];
        }
        if ($photo) {
            $data['photo_path'] = $photo['path'];
        }
        if ($id) {
            $old = Tenant::find('guest_menu_items', $id);
            if (!$old) {
                return [null, [__('Not found.')]];
            }
            DB::update('guest_menu_items', $data, 'id = :id', ['id' => $id]);
            if ($photo && $old['photo_path']) {
                Uploader::delete((string) $old['photo_path']);
            }
            return [$id, []];
        }
        return [DB::insert('guest_menu_items', $data + ['created_at' => now()]), []];
    }

    public static function deleteItem(int $id): bool
    {
        $item = Tenant::find('guest_menu_items', $id);
        if (!$item) {
            return false;
        }
        DB::delete('guest_menu_items', 'id = :id', ['id' => $id]);
        if ($item['photo_path']) {
            Uploader::delete((string) $item['photo_path']);
        }
        return true;
    }

    public static function deleteCategory(int $id): bool
    {
        if (!Tenant::find('guest_menu_categories', $id)) {
            return false;
        }
        foreach (DB::all('SELECT id FROM guest_menu_items WHERE hotel_id = :hid AND category_id = :c', ['hid' => Tenant::id(), 'c' => $id]) as $i) {
            self::deleteItem((int) $i['id']);
        }
        DB::delete('guest_menu_categories', 'id = :id', ['id' => $id]);
        return true;
    }

    // ------------------------------------------------------------------ request types

    /** Seed the default request buttons once per hotel. */
    public static function ensureRequestTypes(): void
    {
        if (Settings::get('guest_request_types_seeded', '0') === '1') {
            return;
        }
        if (!(int) DB::value('SELECT COUNT(*) FROM guest_request_types WHERE hotel_id = :hid', ['hid' => Tenant::id()])) {
            foreach (self::DEFAULT_REQUEST_TYPES as $i => [$code, $name, $icon, $time]) {
                DB::insert('guest_request_types', [
                    'code' => $code, 'name_en' => $name,
                    'name_gu' => I18n::translate($name, 'gu') !== $name ? I18n::translate($name, 'gu') : null,
                    'name_hi' => I18n::translate($name, 'hi') !== $name ? I18n::translate($name, 'hi') : null,
                    'icon' => $icon, 'needs_time' => $time, 'sort_order' => ($i + 1) * 10, 'created_at' => now(),
                ]);
            }
        }
        Settings::set('guest_request_types_seeded', '1');
    }

    public static function requestTypes(bool $activeOnly = false): array
    {
        self::ensureRequestTypes();
        return DB::all(
            'SELECT * FROM guest_request_types WHERE hotel_id = :hid' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id',
            ['hid' => Tenant::id()]
        );
    }

    public static function requestTypesForGuest(): array
    {
        return array_map(fn ($t) => [
            'id' => (int) $t['id'],
            'name' => ['en' => $t['name_en'], 'gu' => self::loc($t, 'name', 'gu'), 'hi' => self::loc($t, 'name', 'hi')],
            'icon' => (string) $t['icon'],
            'needs_time' => (bool) (int) $t['needs_time'],
        ], self::requestTypes(true));
    }

    public static function saveRequestType(array $in, ?int $id = null): array
    {
        $data = [
            'name_en' => mb_substr(trim((string) ($in['name_en'] ?? '')), 0, 100),
            'name_gu' => mb_substr(trim((string) ($in['name_gu'] ?? '')), 0, 100) ?: null,
            'name_hi' => mb_substr(trim((string) ($in['name_hi'] ?? '')), 0, 100) ?: null,
            'icon' => mb_substr(trim((string) ($in['icon'] ?? '')), 0, 4),
            'needs_time' => !empty($in['needs_time']) ? 1 : 0,
            'sort_order' => max(-9999, min(9999, (int) ($in['sort_order'] ?? 0))),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        if ($data['name_en'] === '') {
            return [null, [__('Name (English) is required.')]];
        }
        if ($id) {
            if (!Tenant::find('guest_request_types', $id)) {
                return [null, [__('Not found.')]];
            }
            DB::update('guest_request_types', $data, 'id = :id', ['id' => $id]);
            return [$id, []];
        }
        return [DB::insert('guest_request_types', $data + ['code' => 'custom', 'created_at' => now()]), []];
    }

    /** Everything the guest web app needs (GET /api/guest/{token} and the page itself). */
    public static function appData(array $ctx): array
    {
        $room = $ctx['room'];
        $stay = $ctx['stay'];
        $brand = Branding::get();
        $logo = media_url((string) Settings::get('hotel_logo', '')) ?: $brand['logo_url'];
        $out = $stay && $stay['expected_checkout_at'] ? (string) $stay['expected_checkout_at'] : null;
        $std = Broadcaster::parseTime(Guests::setting('guest_checkout_time')) ?? '10:00:00';
        return [
            'hotel' => ['name' => (string) Settings::get('hotel_name', ''), 'logo_url' => $logo, 'color' => $brand['color']],
            'room' => ['number' => (string) $room['room_number'], 'name' => (string) ($room['name'] ?? '')],
            'guest' => $stay ? [
                'name' => ['en' => Guests::displayName($stay, 'en'), 'gu' => Guests::displayName($stay, 'gu'), 'hi' => Guests::displayName($stay, 'hi')],
                'language' => Guests::lang((string) $stay['language']),
            ] : null,
            'language' => $ctx['lang'],
            'wifi' => Guests::wifi($stay),
            'checkout_at' => $out ? iso_time($out) : null,
            'checkout_time' => date('h:i A', (int) strtotime($out ?? ('2000-01-01 ' . $std))),
            'checkout_date' => $out ? date('d M Y', (int) strtotime($out)) : null,
            'reception_phone' => Guests::setting('guest_reception_phone'),
            'currency' => '₹',
            'features' => ['services' => self::servicesOn(), 'requests' => self::requestsOn(), 'feedback' => self::feedbackOn()],
            'menu' => self::servicesOn() ? self::menuForGuest() : [],
            'request_types' => self::requestsOn() ? self::requestTypesForGuest() : [],
            'status' => self::statusForGuest($ctx),
        ];
    }

    // ------------------------------------------------------------------ guest actions

    /**
     * Place an order from the guest app. $ctx from Guests::resolveToken().
     * Input: {items: [{id, qty}], notes}. Prices are always taken from the database.
     * Throws InvalidArgumentException (VALIDATION_ERROR) or DomainException (ITEM_UNAVAILABLE).
     */
    public static function placeOrder(array $ctx, array $in): array
    {
        if (!self::servicesOn()) {
            throw new DomainException('SERVICE_DISABLED');
        }
        $lines = $in['items'] ?? null;
        if (!is_array($lines) || !$lines || count($lines) > self::MAX_ORDER_LINES || !array_is_list($lines)) {
            throw new InvalidArgumentException('items must be a list of 1–' . self::MAX_ORDER_LINES . ' entries');
        }
        $qty = [];
        foreach ($lines as $l) {
            if (!is_array($l) || !isset($l['id'], $l['qty']) || !is_int($l['id']) || !is_int($l['qty']) || $l['id'] <= 0 || $l['qty'] < 1 || $l['qty'] > self::MAX_QTY) {
                throw new InvalidArgumentException('each item needs an integer id and qty 1–' . self::MAX_QTY);
            }
            $qty[$l['id']] = min(self::MAX_QTY, ($qty[$l['id']] ?? 0) + $l['qty']);
        }
        $notes = self::text($in['notes'] ?? '', 300);
        [$in2, $p] = DB::in(array_keys($qty), 'i');
        $rows = DB::all(
            "SELECT i.* FROM guest_menu_items i JOIN guest_menu_categories c ON c.id = i.category_id AND c.hotel_id = i.hotel_id
             WHERE i.hotel_id = :hid AND i.id IN $in2 AND i.is_active = 1 AND c.is_active = 1",
            $p + ['hid' => Tenant::id()]
        );
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $unavailable = [];
        foreach (array_keys($qty) as $iid) {
            if (!isset($byId[$iid])) {
                $unavailable[] = '#' . $iid;
            } elseif (!self::availableNow($byId[$iid])) {
                $unavailable[] = self::loc($byId[$iid], 'name', $ctx['lang']);
            }
        }
        if ($unavailable) {
            throw new DomainException('ITEM_UNAVAILABLE:' . implode(', ', $unavailable));
        }
        $orderId = DB::transaction(function () use ($ctx, $qty, $byId, $notes) {
            $total = 0.0;
            $count = 0;
            foreach ($qty as $iid => $q) {
                $total += round((float) $byId[$iid]['price'] * $q, 2);
                $count += $q;
            }
            $oid = DB::insert('guest_orders', [
                'room_id' => (int) $ctx['room']['id'],
                'stay_id' => $ctx['stay']['id'] ?? null,
                'token_hash' => $ctx['token_hash'],
                'status' => 'new',
                'notes' => $notes,
                'item_count' => $count,
                'total' => round($total, 2),
                'language' => $ctx['lang'],
                'ip_address' => PHP_SAPI === 'cli' ? null : client_ip(),
                'created_at' => now(),
            ]);
            foreach ($qty as $iid => $q) {
                $it = $byId[$iid];
                DB::insert('guest_order_items', [
                    'order_id' => $oid, 'item_id' => $iid, 'name' => mb_substr((string) $it['name_en'], 0, 120),
                    'food_type' => (string) $it['food_type'], 'price' => (float) $it['price'], 'qty' => $q,
                    'line_total' => round((float) $it['price'] * $q, 2),
                ]);
            }
            return $oid;
        });
        $order = self::order($orderId);
        self::alertStaff(
            __('New room service order') . ' · ' . __('Room') . ' ' . $ctx['room']['room_number'],
            implode(', ', array_map(fn ($i) => $i['qty'] . '× ' . $i['name'], $order['items'])) . ' — ' . money($order['total'], 'INR'),
            admin_url('orders.php')
        );
        return self::orderForGuest($order);
    }

    /** Service request from the guest app: {type_id, time?: "HH:MM", notes?}. */
    public static function createRequest(array $ctx, array $in): array
    {
        if (!self::requestsOn()) {
            throw new DomainException('SERVICE_DISABLED');
        }
        $typeId = $in['type_id'] ?? null;
        if (!is_int($typeId) || $typeId <= 0) {
            throw new InvalidArgumentException('type_id is required');
        }
        $type = DB::one('SELECT * FROM guest_request_types WHERE id = :id AND hotel_id = :hid AND is_active = 1', ['id' => $typeId, 'hid' => Tenant::id()]);
        if (!$type) {
            throw new InvalidArgumentException('Unknown request type');
        }
        $requested = null;
        if ((int) $type['needs_time']) {
            $t = Broadcaster::parseTime(is_string($in['time'] ?? null) ? $in['time'] : '');
            if (!$t) {
                throw new InvalidArgumentException('time (HH:MM) is required for this request');
            }
            $ts = strtotime(date('Y-m-d') . ' ' . $t);
            if ($ts <= time()) {
                $ts = strtotime(date('Y-m-d', time() + 86400) . ' ' . $t);
            }
            $requested = date('Y-m-d H:i:s', $ts);
        }
        $notes = self::text($in['notes'] ?? '', 300);
        // Double taps: the same open request within 2 minutes is returned instead of duplicated.
        $dup = DB::one(
            "SELECT * FROM guest_requests WHERE hotel_id = :hid AND token_hash = :th AND type_id = :t AND status = 'open' AND created_at > :since
             AND (requested_time <=> :rt) ORDER BY id DESC LIMIT 1",
            ['hid' => Tenant::id(), 'th' => $ctx['token_hash'], 't' => $typeId, 'since' => date('Y-m-d H:i:s', time() - 120), 'rt' => $requested]
        );
        if ($dup) {
            return self::requestForGuest($dup) + ['duplicate' => true];
        }
        $id = DB::insert('guest_requests', [
            'room_id' => (int) $ctx['room']['id'],
            'stay_id' => $ctx['stay']['id'] ?? null,
            'token_hash' => $ctx['token_hash'],
            'type_id' => $typeId,
            'type_name' => (string) $type['name_en'],
            'requested_time' => $requested,
            'notes' => $notes,
            'status' => 'open',
            'language' => $ctx['lang'],
            'ip_address' => PHP_SAPI === 'cli' ? null : client_ip(),
            'created_at' => now(),
        ]);
        $row = DB::one('SELECT * FROM guest_requests WHERE id = :id AND hotel_id = :hid', ['id' => $id, 'hid' => Tenant::id()]);
        self::alertStaff(
            __('New guest request') . ' · ' . __('Room') . ' ' . $ctx['room']['room_number'],
            $type['name_en'] . ($requested ? ' @ ' . date('h:i A', (int) strtotime($requested)) : '') . ($notes ? ' — ' . $notes : ''),
            admin_url('orders.php')
        );
        return self::requestForGuest($row);
    }

    /** Feedback {rating 1–5, cleanliness?, staff?, food?, comment?}; one per guest token (re-submitting updates it). */
    public static function saveFeedback(array $ctx, array $in): array
    {
        if (!self::feedbackOn()) {
            throw new DomainException('SERVICE_DISABLED');
        }
        $rating = $in['rating'] ?? null;
        if (!is_int($rating) || $rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('rating must be an integer 1–5');
        }
        $data = ['rating' => $rating];
        foreach (['cleanliness', 'staff', 'food'] as $c) {
            $v = $in[$c] ?? ($in['categories'][$c] ?? null);
            if ($v === null || $v === 0 || $v === '') {
                $data['rating_' . $c] = null;
            } elseif (!is_int($v) || $v < 1 || $v > 5) {
                throw new InvalidArgumentException($c . ' must be an integer 1–5');
            } else {
                $data['rating_' . $c] = $v;
            }
        }
        $data['comment'] = self::text($in['comment'] ?? '', 1000);
        $data['language'] = $ctx['lang'];
        $existing = DB::one('SELECT id FROM guest_feedback WHERE hotel_id = :hid AND token_hash = :th', ['hid' => Tenant::id(), 'th' => $ctx['token_hash']]);
        if ($existing) {
            DB::update('guest_feedback', $data, 'id = :id', ['id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = DB::insert('guest_feedback', $data + [
                'room_id' => (int) $ctx['room']['id'], 'stay_id' => $ctx['stay']['id'] ?? null,
                'token_hash' => $ctx['token_hash'], 'created_at' => now(),
            ]);
            if ($rating <= 2) {
                self::alertStaff(__('Low guest rating') . ' · ' . __('Room') . ' ' . $ctx['room']['room_number'], str_repeat('★', $rating) . ' ' . (string) $data['comment'], admin_url('feedback.php'), 'guests.feedback');
            }
        }
        $review = trim(Guests::setting('guest_google_review_url'));
        return [
            'id' => $id,
            'rating' => $rating,
            'google_review_url' => $rating >= 4 && preg_match('#^https://#i', $review) ? $review : null,
        ];
    }

    /** Orders and requests of this guest token (never other rooms / earlier guests). */
    public static function statusForGuest(array $ctx): array
    {
        $p = ['hid' => Tenant::id(), 'th' => $ctx['token_hash'], 'since' => date('Y-m-d H:i:s', time() - 7 * 86400)];
        $orders = DB::all('SELECT * FROM guest_orders WHERE hotel_id = :hid AND token_hash = :th AND created_at > :since ORDER BY id DESC LIMIT 20', $p);
        $items = self::itemsFor(array_map(fn ($o) => (int) $o['id'], $orders));
        $requests = DB::all('SELECT * FROM guest_requests WHERE hotel_id = :hid AND token_hash = :th AND created_at > :since ORDER BY id DESC LIMIT 20', $p);
        $fb = DB::one('SELECT rating, rating_cleanliness, rating_staff, rating_food, comment FROM guest_feedback WHERE hotel_id = :hid AND token_hash = :th', ['hid' => Tenant::id(), 'th' => $ctx['token_hash']]);
        return [
            'orders' => array_map(fn ($o) => self::orderForGuest($o + ['items' => $items[(int) $o['id']] ?? []]), $orders),
            'requests' => array_map(fn ($r) => self::requestForGuest($r), $requests),
            'feedback' => $fb ? [
                'rating' => (int) $fb['rating'], 'cleanliness' => $fb['rating_cleanliness'] !== null ? (int) $fb['rating_cleanliness'] : null,
                'staff' => $fb['rating_staff'] !== null ? (int) $fb['rating_staff'] : null, 'food' => $fb['rating_food'] !== null ? (int) $fb['rating_food'] : null,
                'comment' => (string) $fb['comment'],
            ] : null,
        ];
    }

    private static function orderForGuest(array $o): array
    {
        return [
            'id' => (int) $o['id'],
            'status' => $o['status'],
            'created_at' => iso_time((string) $o['created_at']),
            'created_label' => date('h:i A', (int) strtotime((string) $o['created_at'])),
            'total' => round((float) $o['total'], 2),
            'notes' => (string) ($o['notes'] ?? ''),
            'items' => array_map(fn ($i) => ['item_id' => $i['item_id'] !== null ? (int) $i['item_id'] : null, 'name' => $i['name'], 'qty' => (int) $i['qty'], 'price' => round((float) $i['price'], 2)], $o['items'] ?? []),
        ];
    }

    private static function requestForGuest(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'type_id' => $r['type_id'] !== null ? (int) $r['type_id'] : null,
            'type' => (string) $r['type_name'],
            'status' => $r['status'],
            'time' => $r['requested_time'] ? iso_time((string) $r['requested_time']) : null,
            // Hotel-local labels (the guest's phone may be set to another time zone).
            'time_label' => $r['requested_time'] ? date('h:i A', (int) strtotime((string) $r['requested_time'])) : null,
            'notes' => (string) ($r['notes'] ?? ''),
            'created_at' => iso_time((string) $r['created_at']),
            'created_label' => date('h:i A', (int) strtotime((string) $r['created_at'])),
        ];
    }

    // ------------------------------------------------------------------ staff side

    /** Order (current hotel) with its items, or null. */
    public static function order(int $id): ?array
    {
        $o = Tenant::find('guest_orders', $id);
        if (!$o) {
            return null;
        }
        $o['items'] = self::itemsFor([$id])[$id] ?? [];
        return $o;
    }

    /** Order items keyed by order id. */
    public static function itemsFor(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }
        [$in, $p] = DB::in($orderIds, 'o');
        $out = [];
        foreach (DB::all("SELECT * FROM guest_order_items WHERE hotel_id = :hid AND order_id IN $in ORDER BY id", $p + ['hid' => Tenant::id()]) as $i) {
            $out[(int) $i['order_id']][] = $i;
        }
        return $out;
    }

    /** Staff changes an order's status; optionally tells the room TV (SHOW_MESSAGE). */
    public static function setOrderStatus(int $id, string $status, ?int $userId = null, ?bool $notifyTv = null): array
    {
        $o = Tenant::find('guest_orders', $id);
        if (!$o) {
            throw new InvalidArgumentException(__('Order not found.'));
        }
        Access::requireRoom((int) $o['room_id']); // staff limited to some TVs / rooms (core/Access.php)
        if (!in_array($status, self::ORDER_STATUSES, true) || ($status !== $o['status'] && !in_array($status, self::ORDER_FLOW[$o['status']], true))) {
            throw new InvalidArgumentException(__('This status change is not allowed.'));
        }
        if ($status === $o['status']) {
            return $o;
        }
        $data = ['status' => $status, 'updated_by' => $userId];
        if ($status === 'accepted' || ($o['accepted_at'] === null && in_array($status, ['preparing', 'delivered'], true))) {
            $data['accepted_at'] = now();
        }
        if ($status === 'delivered') {
            $data['delivered_at'] = now();
        }
        if ($status === 'cancelled') {
            $data['cancelled_at'] = now();
        }
        DB::update('guest_orders', $data, 'id = :id AND status = :old', ['id' => $id, 'old' => $o['status']]);
        ActivityLog::add('guest_order_' . $status, 'guest_order', $id, 'Room order #' . $id);
        if ($notifyTv ?? Guests::setting('guest_tv_notify') === '1') {
            $lang = Guests::lang((string) $o['language']);
            $msg = match ($status) {
                'accepted' => 'Your order has been accepted 👍',
                'preparing' => 'Your order is being prepared 🍳',
                'delivered' => 'Your order is on the way 🛎️',
                'cancelled' => 'Sorry, your order was cancelled. Please call reception.',
                default => '',
            };
            if ($msg !== '' && $o['room_id']) {
                self::tvMessage((int) $o['room_id'], I18n::translate('Room Service', $lang), I18n::translate($msg, $lang));
            }
        }
        return array_merge($o, $data);
    }

    public static function setRequestStatus(int $id, string $status, ?int $userId = null, ?bool $notifyTv = null): array
    {
        $r = Tenant::find('guest_requests', $id);
        if (!$r) {
            throw new InvalidArgumentException(__('Request not found.'));
        }
        Access::requireRoom((int) $r['room_id']);
        if (!in_array($status, self::REQUEST_STATUSES, true)) {
            throw new InvalidArgumentException(__('This status change is not allowed.'));
        }
        if ($status === $r['status']) {
            return $r;
        }
        $data = ['status' => $status, 'done_at' => $status === 'open' ? null : now(), 'done_by' => $status === 'open' ? null : $userId];
        DB::update('guest_requests', $data, 'id = :id', ['id' => $id]);
        ActivityLog::add('guest_request_' . $status, 'guest_request', $id, (string) $r['type_name']);
        if ($status === 'done' && $r['room_id'] && ($notifyTv ?? Guests::setting('guest_tv_notify') === '1')) {
            $lang = Guests::lang((string) $r['language']);
            $type = $r['type_id'] ? DB::one('SELECT * FROM guest_request_types WHERE id = :id AND hotel_id = :hid', ['id' => $r['type_id'], 'hid' => Tenant::id()]) : null;
            $name = $type ? self::loc($type, 'name', $lang) : (string) $r['type_name'];
            self::tvMessage((int) $r['room_id'], I18n::translate('Requests', $lang), I18n::translate('Your request ":r" has been taken care of ✔', $lang, ['r' => $name]));
        }
        return array_merge($r, $data);
    }

    /** Queue a SHOW_MESSAGE command (V2 TV contract) for the room's TVs. Returns number of TVs. */
    public static function tvMessage(int $roomId, string $title, string $message, int $durationSec = 15): int
    {
        $room = DB::one('SELECT * FROM rooms WHERE id = :id AND hotel_id = :hid', ['id' => $roomId, 'hid' => Tenant::id()]);
        if (!$room) {
            return 0;
        }
        return Broadcaster::queueForRooms([$room], 'SHOW_MESSAGE', ['title' => $title, 'message' => $message, 'duration_sec' => $durationSec]);
    }

    /** Live board data for the admin Orders & Requests page. */
    public static function board(): array
    {
        $hid = ['hid' => Tenant::id()];
        $since = date('Y-m-d H:i:s', strtotime('today'));
        [$accO, $apO] = Access::roomSql('o.room_id', 'aro');
        [$accQ, $apQ] = Access::roomSql('q.room_id', 'arq');
        $orders = DB::all(
            "SELECT o.*, r.room_number, s.guest_name, s.salutation FROM guest_orders o
             LEFT JOIN rooms r ON r.id = o.room_id AND r.hotel_id = o.hotel_id
             LEFT JOIN guest_stays s ON s.id = o.stay_id AND s.hotel_id = o.hotel_id
             WHERE o.hotel_id = :hid AND (o.status IN ('new','accepted','preparing') OR o.updated_at >= :since)$accO
             ORDER BY o.id DESC LIMIT 200",
            $hid + ['since' => $since] + $apO
        );
        $items = self::itemsFor(array_map(fn ($o) => (int) $o['id'], $orders));
        $requests = DB::all(
            "SELECT q.*, r.room_number, s.guest_name, s.salutation, t.icon FROM guest_requests q
             LEFT JOIN rooms r ON r.id = q.room_id AND r.hotel_id = q.hotel_id
             LEFT JOIN guest_stays s ON s.id = q.stay_id AND s.hotel_id = q.hotel_id
             LEFT JOIN guest_request_types t ON t.id = q.type_id AND t.hotel_id = q.hotel_id
             WHERE q.hotel_id = :hid AND (q.status = 'open' OR q.done_at >= :since)$accQ
             ORDER BY q.status = 'open' DESC, q.id DESC LIMIT 200",
            $hid + ['since' => $since] + $apQ
        );
        return [
            'orders' => array_map(fn ($o) => [
                'id' => (int) $o['id'], 'status' => $o['status'], 'room' => (string) ($o['room_number'] ?? '-'),
                'guest' => trim((string) ($o['salutation'] ?? '') . ' ' . (string) ($o['guest_name'] ?? '')),
                'total' => money($o['total'], 'INR'), 'notes' => (string) ($o['notes'] ?? ''),
                'created' => date('h:i A', (int) strtotime((string) $o['created_at'])), 'ago' => time_ago((string) $o['created_at']),
                'items' => array_map(fn ($i) => ['name' => $i['name'], 'qty' => (int) $i['qty'], 'food_type' => $i['food_type']], $items[(int) $o['id']] ?? []),
                'next' => self::ORDER_FLOW[$o['status']] ?? [],
            ], $orders),
            'requests' => array_map(fn ($q) => [
                'id' => (int) $q['id'], 'status' => $q['status'], 'room' => (string) ($q['room_number'] ?? '-'),
                'guest' => trim((string) ($q['salutation'] ?? '') . ' ' . (string) ($q['guest_name'] ?? '')),
                'type' => (string) $q['type_name'], 'icon' => (string) ($q['icon'] ?? ''), 'notes' => (string) ($q['notes'] ?? ''),
                'time' => $q['requested_time'] ? date('d M h:i A', (int) strtotime((string) $q['requested_time'])) : '',
                'created' => date('h:i A', (int) strtotime((string) $q['created_at'])), 'ago' => time_ago((string) $q['created_at']),
            ], $requests),
        ] + self::alertCounts();
    }

    /** Counters for the live alerts poll. */
    public static function alertCounts(): array
    {
        // Staff limited to some rooms (core/Access.php) count only their rooms.
        [$acc, $ap] = Access::roomSql('room_id');
        $hid = ['hid' => Tenant::id()] + $ap;
        return [
            'new_orders' => (int) DB::value("SELECT COUNT(*) FROM guest_orders WHERE hotel_id = :hid AND status = 'new'$acc", $hid),
            'open_orders' => (int) DB::value("SELECT COUNT(*) FROM guest_orders WHERE hotel_id = :hid AND status IN ('new','accepted','preparing')$acc", $hid),
            'open_requests' => (int) DB::value("SELECT COUNT(*) FROM guest_requests WHERE hotel_id = :hid AND status = 'open'$acc", $hid),
            'last_order_id' => (int) DB::value('SELECT COALESCE(MAX(id), 0) FROM guest_orders WHERE hotel_id = :hid' . $acc, $hid),
            'last_request_id' => (int) DB::value('SELECT COALESCE(MAX(id), 0) FROM guest_requests WHERE hotel_id = :hid' . $acc, $hid),
        ];
    }

    /** Orders / requests newer than the given ids (toasts in the admin panel). */
    public static function newSince(int $orderId, int $requestId): array
    {
        $hid = ['hid' => Tenant::id()];
        [$accO, $apO] = Access::roomSql('o.room_id', 'aro');
        [$accQ, $apQ] = Access::roomSql('q.room_id', 'arq');
        $o = DB::all(
            'SELECT o.id, o.total, o.item_count, r.room_number FROM guest_orders o LEFT JOIN rooms r ON r.id = o.room_id AND r.hotel_id = o.hotel_id
             WHERE o.hotel_id = :hid AND o.id > :id' . $accO . ' ORDER BY o.id DESC LIMIT 5',
            $hid + ['id' => $orderId] + $apO
        );
        $q = DB::all(
            'SELECT q.id, q.type_name, q.requested_time, r.room_number FROM guest_requests q LEFT JOIN rooms r ON r.id = q.room_id AND r.hotel_id = q.hotel_id
             WHERE q.hotel_id = :hid AND q.id > :id' . $accQ . ' ORDER BY q.id DESC LIMIT 5',
            $hid + ['id' => $requestId] + $apQ
        );
        return [
            'orders' => array_map(fn ($x) => ['id' => (int) $x['id'], 'room' => (string) ($x['room_number'] ?? '-'), 'items' => (int) $x['item_count'], 'total' => money($x['total'], 'INR')], $o),
            'requests' => array_map(fn ($x) => ['id' => (int) $x['id'], 'room' => (string) ($x['room_number'] ?? '-'), 'type' => (string) $x['type_name'],
                'time' => $x['requested_time'] ? date('h:i A', (int) strtotime((string) $x['requested_time'])) : ''], $q),
        ];
    }

    /** Push / email / WhatsApp to staff (StaffAlerts module when installed, else Notifier if enabled). */
    public static function alertStaff(string $title, string $body, string $url = '', string $permission = 'services.manage'): void
    {
        try {
            if (class_exists('StaffAlerts')) {
                StaffAlerts::send($permission, $title, $body, $url);
            } elseif (Guests::setting('guest_notify_external') === '1') {
                Notifier::send($title, $body . ($url !== '' ? "\n" . $url : ''));
            }
        } catch (Throwable $e) {
            Logger::error('Guest staff alert failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ feedback report

    /** Feedback statistics for [from, to] (Y-m-d). */
    public static function feedbackStats(string $from, string $to): array
    {
        $p = ['hid' => Tenant::id(), 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
        $agg = DB::one(
            'SELECT COUNT(*) AS n, AVG(rating) AS avg_all, AVG(rating_cleanliness) AS avg_clean, AVG(rating_staff) AS avg_staff, AVG(rating_food) AS avg_food
             FROM guest_feedback WHERE hotel_id = :hid AND created_at BETWEEN :f AND :t',
            $p
        ) ?? [];
        $dist = array_fill(1, 5, 0);
        foreach (DB::all('SELECT rating, COUNT(*) AS n FROM guest_feedback WHERE hotel_id = :hid AND created_at BETWEEN :f AND :t GROUP BY rating', $p) as $r) {
            $dist[(int) $r['rating']] = (int) $r['n'];
        }
        $avg = fn ($v) => $v !== null ? round((float) $v, 2) : null;
        return [
            'count' => (int) ($agg['n'] ?? 0),
            'average' => $avg($agg['avg_all'] ?? null),
            'cleanliness' => $avg($agg['avg_clean'] ?? null),
            'staff' => $avg($agg['avg_staff'] ?? null),
            'food' => $avg($agg['avg_food'] ?? null),
            'distribution' => $dist,
        ];
    }

    public static function feedbackRows(string $from, string $to, int $limit = 500, int $offset = 0, int $maxRating = 5): array
    {
        return DB::all(
            'SELECT f.*, r.room_number FROM guest_feedback f LEFT JOIN rooms r ON r.id = f.room_id AND r.hotel_id = f.hotel_id
             WHERE f.hotel_id = :hid AND f.created_at BETWEEN :f AND :t AND f.rating <= :mr ORDER BY f.id DESC LIMIT ' . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset),
            ['hid' => Tenant::id(), 'f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59', 'mr' => max(1, min(5, $maxRating))]
        );
    }

    // ------------------------------------------------------------------ helpers

    /** Plain single-paragraph text from untrusted input (control chars stripped), null when empty. */
    public static function text(mixed $v, int $max): ?string
    {
        if (!is_string($v)) {
            if ($v === null || $v === '') {
                return null;
            }
            throw new InvalidArgumentException('text fields must be strings');
        }
        $v = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
        if (mb_strlen($v) > $max) {
            throw new InvalidArgumentException('text is longer than ' . $max . ' characters');
        }
        return $v === '' ? null : $v;
    }
}
