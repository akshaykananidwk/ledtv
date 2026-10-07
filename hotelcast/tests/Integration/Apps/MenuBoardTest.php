<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Menu board (display app "menu_board", core/MenuBoard.php, admin/menu_board.php): shared menu with
 * room service, board data (category filter, dayparting, sold-out hide / strike, specials, item hours,
 * languages), live data JSON, rendering of every layout, management page (CRUD, photo upload, AJAX
 * switches, reorder, CSRF, permissions, restricted users, hotels without the guest-services plan
 * feature), tenancy (another hotel's ids → 404) and XSS.
 */
final class MenuBoardTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'mbMgr', 'staff' => 'mbStaff', 'reception' => 'mbRecep'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$id['r101'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Room 101', 'floor' => '1']);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'mbBoss2', 'email' => 'mb2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['h2cat'] = DB::insert('guest_menu_categories', ['name_en' => 'H2-CAT-SECRET', 'created_at' => now()]);
            self::$id['h2item'] = DB::insert('guest_menu_items', ['category_id' => self::$id['h2cat'], 'name_en' => 'H2-DISH-SECRET', 'price' => 10, 'created_at' => now()]);
        });
        Tenant::set(1);
        DisplayApps::reset();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Access::$userOverride = null;
        Access::forget();
        DisplayApps::reset();
        Tenant::forget();
        Settings::flush();
        I18n::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
    }

    private static function cat(string $name, array $extra = []): int
    {
        return DB::insert('guest_menu_categories', $extra + ['name_en' => $name, 'sort_order' => 0, 'created_at' => now()]);
    }

    private static function dish(int $cat, string $name, array $extra = []): int
    {
        return DB::insert('guest_menu_items', $extra + ['category_id' => $cat, 'name_en' => $name, 'price' => 100, 'food_type' => 'veg', 'created_at' => now()]);
    }

    private static function wipe(): void
    {
        DB::query('DELETE FROM guest_menu_items WHERE hotel_id = 1');
        DB::query('DELETE FROM guest_menu_categories WHERE hotel_id = 1');
    }

    private static function names(array $board): array
    {
        $out = [];
        foreach ($board['categories'] as $c) {
            foreach ($c['items'] as $i) {
                $out[] = $c['name'] . '/' . $i['name'];
            }
        }
        return $out;
    }

    public function testBoardDataFiltersDaypartingSoldOutAndLanguages(): void
    {
        self::wipe();
        $b = self::cat('Breakfast', ['name_gu' => 'નાસ્તો', 'board_from' => '07:00:00', 'board_to' => '11:00:00', 'sort_order' => 1]);
        $d = self::cat('Drinks', ['sort_order' => 2]);
        $off = self::cat('Hidden cat', ['is_active' => 0, 'sort_order' => 3]);
        $notv = self::cat('Not on TV', ['show_on_board' => 0, 'sort_order' => 4]);
        self::dish($b, 'Dosa', ['name_gu' => 'ઢોસા', 'price' => 120, 'price_old' => 150, 'is_special' => 1, 'badge' => 'chef', 'description_en' => 'Crisp', 'sort_order' => 1]);
        self::dish($b, 'Omelette', ['food_type' => 'egg', 'is_sold_out' => 1, 'sort_order' => 2]);
        self::dish($d, 'Tea', ['price' => 20.5]);
        self::dish($d, 'Lassi', ['show_on_board' => 0]);
        self::dish($d, 'Inactive', ['is_active' => 0]);
        self::dish($d, 'Night only', ['available_from' => '22:00:00', 'available_to' => '02:00:00']);
        self::dish($d, 'Chicken', ['food_type' => 'nonveg', 'badge' => 'bogus']);
        self::dish($off, 'X1');
        self::dish($notv, 'X2');

        $o = ['categories' => [], 'sold_out' => 'strike', 'currency' => '₹', 'lang' => 'en', 'daypart' => true, 'now' => '08:30:00'];
        $board = MenuBoard::board($o);
        $this->assertSame(['Breakfast/Dosa', 'Breakfast/Omelette', 'Drinks/Tea', 'Drinks/Chicken'], self::names($board));
        $dosa = $board['categories'][0]['items'][0];
        $this->assertSame(['₹120', '₹150', 'veg', true, "Chef's special", 'Crisp', false], [$dosa['price'], $dosa['old'], $dosa['food'], $dosa['special'], $dosa['badge'], $dosa['desc'], $dosa['sold']]);
        $this->assertTrue($board['categories'][0]['items'][1]['sold']);
        $this->assertSame('₹20.50', $board['categories'][1]['items'][0]['price']);
        $this->assertSame(['nonveg', ''], [$board['categories'][1]['items'][1]['food'], $board['categories'][1]['items'][1]['badge']]);
        $this->assertSame(['Dosa'], array_column($board['specials'], 'name'));
        // Sold-out hidden; dayparting outside breakfast hours; overnight item hours; no dayparting.
        $this->assertSame(['Breakfast/Dosa', 'Drinks/Tea', 'Drinks/Chicken'], self::names(MenuBoard::board(['sold_out' => 'hide'] + $o)));
        $this->assertSame(['Drinks/Tea', 'Drinks/Night only', 'Drinks/Chicken'], self::names(MenuBoard::board(['now' => '23:15:00'] + $o)));
        $this->assertSame(['Breakfast/Dosa', 'Breakfast/Omelette', 'Drinks/Tea', 'Drinks/Night only', 'Drinks/Chicken'], self::names(MenuBoard::board(['now' => '01:00:00', 'daypart' => false] + $o)));
        // Category filter (one TV breakfast, another drinks) and Gujarati names with English fallback.
        $this->assertSame(['Drinks/Tea', 'Drinks/Chicken'], self::names(MenuBoard::board(['categories' => [$d]] + $o)));
        $gu = MenuBoard::board(['lang' => 'gu'] + $o);
        $this->assertSame(['નાસ્તો/ઢોસા', 'નાસ્તો/Omelette', 'Drinks/Tea', 'Drinks/Chicken'], self::names($gu));
        $this->assertTrue(MenuBoard::inWindow(null, null, '03:00:00'));
        $this->assertFalse(MenuBoard::inWindow('07:00:00', '11:00:00', '11:00:00'));
        $this->assertSame('$1,250', MenuBoard::price(1250, '$'));

        // Shared menu: a sold-out dish cannot be ordered through room service either.
        $omelette = DB::one("SELECT * FROM guest_menu_items WHERE name_en = 'Omelette'");
        $this->assertFalse(GuestServices::availableNow($omelette));
        $menu = GuestServices::menuForGuest();
        $this->assertFalse($menu[0]['items'][1]['available']);
        $this->assertTrue($menu[0]['items'][0]['available']);
    }

    public function testAppValidationRenderingLiveDataAndXss(): void
    {
        self::wipe();
        $app = DisplayApps::find('menu_board');
        $this->assertNotNull($app);
        $this->assertSame('business', $app->category());
        $this->assertSame(admin_url('menu_board.php'), $app->adminPage());
        $c1 = self::cat('Main ' . self::XSS);
        $c2 = self::cat('Drinks');
        $dish = self::dish($c1, 'Paneer ' . self::XSS, ['description_en' => 'Desc ' . self::XSS, 'is_special' => 1, 'photo_path' => 'h1/media/x.jpg']);
        [$cfg, $err] = $app->validate(['layout' => 'grid', 'columns' => '9', 'categories' => [(string) $c1, '999999', 'abc'], 'sold_out' => 'nope', 'currency' => 'Rs.', 'page_sec' => '1', 'daypart' => '0', 'show_desc' => '1']);
        $this->assertSame([], $err);
        $this->assertSame(['grid', 3, [$c1], 'strike', 'Rs.', 4, false, true], [$cfg['layout'], $cfg['columns'], $cfg['categories'], $cfg['sold_out'], $cfg['currency'], $cfg['page_sec'], $cfg['daypart'], $cfg['show_desc']]);
        try {
            $app->validate(['categories' => [(string) self::$id['h2cat']]]);
            $this->fail('another hotel\'s category must be denied');
        } catch (TenantException) {
            $this->assertTrue(true);
        }

        foreach (['list', 'grid', 'special'] as $layout) {
            $item = DisplayAppsTestKit::createItem('menu_board', ['layout' => $layout, 'heading' => 'H ' . self::XSS], ['lang' => 'gu'], 'Menu ' . $layout);
            $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
            $this->assertStringContainsString('mb-layout-' . $layout, $html);
            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringContainsString('Paneer &lt;script&gt;', $html);
            $this->assertStringContainsString('"name":"Paneer ' . "\\u003Cscript", $html, 'boot JSON escaped');
            $this->assertStringContainsString('menu_board.js', $html);
            $this->assertStringNotContainsString('H2-DISH-SECRET', $html);
        }
        $this->assertStringContainsString('mb-hero', $html);
        $this->assertStringContainsString(I18n::translate("Today's special", 'gu'), $html);

        // Live data: the sold-out switch reaches the TV on the next refresh (10 s).
        $item = DisplayAppsTestKit::createItem('menu_board', ['categories' => [$c1], 'sold_out' => 'hide']);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertSame(10, $json['refresh_sec']);
        $this->assertSame(['Paneer ' . self::XSS], array_column($json['data']['categories'][0]['items'], 'name'));
        $this->assertSame('/uploads/h1/media/x.jpg', parse_url((string) $json['data']['categories'][0]['items'][0]['photo'], PHP_URL_PATH));
        MenuBoard::toggle(MenuBoard::findItem($dish), 'sold_out');
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([], $json['data']['categories']);
        $this->assertSame('The menu will be available soon.', $json['data']['t']['empty']);
        // Empty board: preview shows a sample menu, the TV the empty text.
        self::wipe();
        $empty = DisplayAppsTestKit::createItem('menu_board', []);
        [, $html] = DisplayAppsTestKit::page(self::$url, $empty, ['preview' => 1]);
        $this->assertStringContainsString('Masala dosa', $html);
        [, $html] = DisplayAppsTestKit::page(self::$url, $empty);
        $this->assertStringContainsString('The menu will be available soon.', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testAppItemFormRejectsForeignCategoryOverHttp(): void
    {
        self::wipe();
        $c = self::cat('Lunch');
        $s = new AdminSession(self::$url, 'mbMgr');
        [$code, , $html] = $s->get('apps.php?action=new&app=menu_board');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('name="cfg[categories][]"', $html);
        $this->assertStringContainsString('Lunch', $html);
        $this->assertStringNotContainsString('H2-CAT-SECRET', $html);
        $base = ['op' => 'save', 'id' => 0, 'app' => 'menu_board', 'title' => 'Lunch board', 'duration' => 20, 'is_active' => 1, 'theme' => 'restaurant_warm', 'font' => 'auto', 'lang' => 'en', 'cfg[layout]' => 'list'];
        [$code] = $s->post('apps.php', $base + ['cfg[categories]' => [(string) $c]]);
        $this->assertSame(302, $code);
        $row = DB::one("SELECT * FROM content_items WHERE title = 'Lunch board'");
        $this->assertSame([$c], json_decode((string) $row['settings'], true)['config']['categories']);
        [$code] = $s->post('apps.php', ['title' => 'Evil board'] + $base + ['cfg[categories]' => [(string) self::$id['h2cat']]]);
        $this->assertSame(404, $code);
        $this->assertNull(DB::one("SELECT id FROM content_items WHERE title = 'Evil board'"));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testManagementPageCrudSwitchesReorderAndTenancy(): void
    {
        self::wipe();
        $s = new AdminSession(self::$url, 'mbStaff');
        [$code, , $html] = $s->get('menu_board.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringNotContainsString('H2-CAT-SECRET', $html);
        // Category: validation (dayparting needs both times), create.
        [$code, , $html] = $s->post('menu_board.php', ['op' => 'cat_save', 'id' => 0, 'name_en' => 'Breakfast', 'board_from' => '07:00', 'board_to' => '', 'show_on_board' => 1, 'is_active' => 1]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString(e('Enter both "shown from" and "shown until" times, or leave both empty.'), $html);
        [$code] = $s->post('menu_board.php', ['op' => 'cat_save', 'id' => 0, 'name_en' => 'Breakfast ' . self::XSS, 'name_gu' => 'નાસ્તો', 'board_from' => '07:00', 'board_to' => '11:00', 'show_on_board' => 1, 'is_active' => 1]);
        $this->assertSame(302, $code);
        [$code] = $s->post('menu_board.php', ['op' => 'cat_save', 'id' => 0, 'name_en' => 'Drinks', 'show_on_board' => 1, 'is_active' => 1]);
        $cats = MenuBoard::categories();
        $this->assertSame(['07:00:00', '11:00:00', 1], [$cats[0]['board_from'], $cats[0]['board_to'], (int) $cats[0]['show_on_board']]);
        $this->assertSame(['Drinks'], [$cats[1]['name_en']]);
        $this->assertLessThan((int) $cats[1]['sort_order'], (int) $cats[0]['sort_order']);
        [$bf, $dr] = [(int) $cats[0]['id'], (int) $cats[1]['id']];

        // CSRF.
        [$code] = TestEnv::http('POST', self::$url . 'admin/menu_board.php', null, [], $s->jar, ['op' => 'cat_save', 'id' => '0', 'name_en' => 'NO-CSRF']);
        $this->assertSame(419, $code);

        // Dish with photo upload, old price, badge, special.
        [$code, , $html] = $s->post('menu_board.php', ['op' => 'item_save', 'id' => 0, 'category_id' => $bf, 'name_en' => '', 'price' => 'abc']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Name (English) is required.', $html);
        $png = tempnam(sys_get_temp_dir(), 'mbimg') . '.png';
        $im = imagecreatetruecolor(40, 30);
        imagepng($im, $png);
        [$code] = TestEnv::http('POST', self::$url . 'admin/menu_board.php', null, [], $s->jar, [
            '_csrf' => $s->csrf, 'op' => 'item_save', 'id' => '0', 'category_id' => (string) $bf, 'name_en' => 'Dosa ' . self::XSS, 'name_hi' => 'डोसा', 'price' => '120', 'price_old' => '150',
            'food_type' => 'veg', 'badge' => 'bestseller', 'is_special' => '1', 'show_on_board' => '1', 'is_active' => '1', 'photo' => new CURLFile($png, 'image/png', 'dosa.png'),
        ]);
        @unlink($png);
        $this->assertSame(302, $code);
        $dosa = DB::one('SELECT * FROM guest_menu_items WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame(['120.00', '150.00', 'bestseller', 1, 0, 1], [$dosa['price'], $dosa['price_old'], $dosa['badge'], (int) $dosa['is_special'], (int) $dosa['is_sold_out'], (int) $dosa['show_on_board']]);
        $this->assertNotEmpty($dosa['photo_path']);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $dosa['photo_path']);
        $s->post('menu_board.php', ['op' => 'item_save', 'id' => 0, 'category_id' => $bf, 'name_en' => 'Idli', 'price' => '80', 'food_type' => 'veg', 'show_on_board' => 1, 'is_active' => 1]);
        $idli = (int) DB::value("SELECT id FROM guest_menu_items WHERE name_en = 'Idli'");
        $this->assertGreaterThan((int) $dosa['sort_order'], (int) DB::value('SELECT sort_order FROM guest_menu_items WHERE id = :id', ['id' => $idli]));
        [, , $html] = $s->get('menu_board.php');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('Dosa &lt;script&gt;', $html);
        $this->assertStringContainsString('data-mb-toggle', $html);
        [$code, , $html] = $s->get('menu_board.php?action=item&id=' . $dosa['id']);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('value="150.00"', $html);

        // One-click switches: AJAX (JSON) and plain form post.
        $ajax = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];
        [$code, $json] = TestEnv::http('POST', self::$url . 'admin/menu_board.php', null, $ajax, $s->jar, ['_csrf' => $s->csrf, 'op' => 'toggle', 'field' => 'sold_out', 'id' => (string) $dosa['id']]);
        $this->assertSame(200, $code);
        $this->assertSame(['id' => (int) $dosa['id'], 'field' => 'sold_out', 'value' => 1], $json['data']);
        $this->assertSame(1, (int) DB::value('SELECT is_sold_out FROM guest_menu_items WHERE id = :id', ['id' => $dosa['id']]));
        [$code] = $s->post('menu_board.php', ['op' => 'toggle', 'field' => 'sold_out', 'id' => $dosa['id']]);
        $this->assertSame(302, $code);
        $this->assertSame(0, (int) DB::value('SELECT is_sold_out FROM guest_menu_items WHERE id = :id', ['id' => $dosa['id']]));
        $s->post('menu_board.php', ['op' => 'toggle', 'field' => 'special', 'id' => $dosa['id']]);
        $this->assertSame(0, (int) DB::value('SELECT is_special FROM guest_menu_items WHERE id = :id', ['id' => $dosa['id']]));
        [$code, $json] = TestEnv::http('POST', self::$url . 'admin/menu_board.php', null, $ajax, $s->jar, ['_csrf' => $s->csrf, 'op' => 'toggle', 'field' => 'price', 'id' => (string) $dosa['id']]);
        $this->assertSame(422, $code);

        // Reorder: Idli up, Drinks up.
        $s->post('menu_board.php', ['op' => 'item_move', 'id' => $idli, 'dir' => 'up']);
        $this->assertSame([$idli, (int) $dosa['id']], array_map('intval', DB::column('SELECT id FROM guest_menu_items WHERE hotel_id = 1 AND category_id = :c ORDER BY sort_order, id', ['c' => $bf])));
        $s->post('menu_board.php', ['op' => 'cat_move', 'id' => $dr, 'dir' => 'up']);
        $this->assertSame([$dr, $bf], array_map('intval', array_column(MenuBoard::categories(), 'id')));

        // Edit (move to Drinks, remove the photo) keeps the room-service fields.
        [$code] = $s->post('menu_board.php', ['op' => 'item_save', 'id' => $dosa['id'], 'category_id' => $dr, 'name_en' => 'Dosa', 'price' => '130', 'price_old' => '', 'food_type' => 'veg', 'badge' => '', 'show_on_board' => 1, 'is_active' => 1, 'remove_photo' => 1]);
        $this->assertSame(302, $code);
        $e = DB::one('SELECT * FROM guest_menu_items WHERE id = :id', ['id' => $dosa['id']]);
        $this->assertSame([$dr, '130.00', null, null, null], [(int) $e['category_id'], $e['price'], $e['price_old'], $e['badge'], $e['photo_path']]);
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $dosa['photo_path']);

        // Another hotel cannot touch hotel 1's menu (404) and vice versa.
        $b = new AdminSession(self::$url, 'mbBoss2');
        [, , $html] = $b->get('menu_board.php');
        $this->assertStringContainsString('H2-DISH-SECRET', $html);
        $this->assertStringNotContainsString('Idli', $html);
        [$code] = $b->get('menu_board.php?action=item&id=' . $idli);
        $this->assertSame(404, $code);
        foreach ([['op' => 'toggle', 'field' => 'sold_out', 'id' => $idli], ['op' => 'item_save', 'id' => $idli, 'category_id' => $bf, 'name_en' => 'HACK', 'price' => 1],
            ['op' => 'item_delete', 'id' => $idli], ['op' => 'item_move', 'id' => $idli, 'dir' => 'up'], ['op' => 'cat_delete', 'id' => $bf], ['op' => 'cat_save', 'id' => $bf, 'name_en' => 'HACK']] as $p) {
            [$code] = $b->post('menu_board.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        [$code, $json] = TestEnv::http('POST', self::$url . 'admin/menu_board.php', null, $ajax, $b->jar, ['_csrf' => $b->csrf, 'op' => 'toggle', 'field' => 'sold_out', 'id' => (string) $idli]);
        $this->assertSame([404, 'NOT_FOUND'], [$code, $json['error']['code']]);
        $this->assertSame('Idli', DB::value('SELECT name_en FROM guest_menu_items WHERE id = :id', ['id' => $idli]));
        $this->assertSame(0, (int) DB::value('SELECT is_sold_out FROM guest_menu_items WHERE id = :id', ['id' => $idli]));
        [$code] = $s->post('menu_board.php', ['op' => 'toggle', 'field' => 'sold_out', 'id' => self::$id['h2item']]);
        $this->assertSame(404, $code);
        [$code] = $s->post('menu_board.php', ['op' => 'item_save', 'id' => 0, 'category_id' => self::$id['h2cat'], 'name_en' => 'Into H2', 'price' => 1]);
        $this->assertSame(404, $code, 'category of another hotel');

        // Delete dish and category (with its dishes).
        $s->post('menu_board.php', ['op' => 'item_delete', 'id' => $idli]);
        $this->assertNull(DB::one('SELECT id FROM guest_menu_items WHERE id = :id', ['id' => $idli]));
        $s->post('menu_board.php', ['op' => 'cat_delete', 'id' => $dr]);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM guest_menu_items WHERE category_id = :c', ['c' => $dr]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'menu_category_delete' AND entity_id = :id", ['id' => $dr]));
        $this->assertSame('H2-DISH-SECRET', Tenant::run(2, fn () => DB::value('SELECT name_en FROM guest_menu_items WHERE id = :id', ['id' => self::$id['h2item']])));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testPermissionsRestrictedUsersAndPlansWithoutGuestServices(): void
    {
        self::wipe();
        // Reception may not manage the menu.
        [$code] = (new AdminSession(self::$url, 'mbRecep'))->get('menu_board.php');
        $this->assertSame(403, $code);
        // Staff limited to one room (per-user TV access) still manages the hotel-wide menu.
        DB::insert('user_access', ['user_id' => self::$id['mbStaff'], 'target_type' => 'room', 'target_id' => self::$id['r101'], 'created_at' => now()]);
        // A restaurant on a plan without guest services still gets the menu board page.
        $plan = DB::insert('plans', ['name' => 'Signage only', 'price_per_tv_month' => 0, 'features' => json_encode(['signage'])]);
        DB::query('UPDATE hotels SET plan_id = :p WHERE id = 1', ['p' => $plan]);
        Tenant::forget();
        $this->assertFalse(GuestServices::enabled());
        $s = new AdminSession(self::$url, 'mbStaff');
        [$code, , $html] = $s->get('menu_board.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('/admin/menu_board.php"', $html, 'sidebar entry');
        [$code] = $s->post('menu_board.php', ['op' => 'cat_save', 'id' => 0, 'name_en' => 'Snacks', 'show_on_board' => 1, 'is_active' => 1]);
        $this->assertSame(302, $code);
        $cat = (int) DB::value("SELECT id FROM guest_menu_categories WHERE hotel_id = 1 AND name_en = 'Snacks'");
        [$code] = $s->post('menu_board.php', ['op' => 'item_save', 'id' => 0, 'category_id' => $cat, 'name_en' => 'Samosa', 'price' => '25', 'food_type' => 'veg', 'show_on_board' => 1, 'is_active' => 1]);
        $this->assertSame(302, $code);
        $item = DisplayAppsTestKit::createItem('menu_board', []);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame('Samosa', $json['data']['categories'][0]['items'][0]['name']);
        $this->assertSame('₹25', $json['data']['categories'][0]['items'][0]['price']);
        DB::query('UPDATE hotels SET plan_id = NULL WHERE id = 1');
        DB::query('DELETE FROM user_access');
        Tenant::forget();
        $this->assertSame('', TestEnv::phpErrors());
    }
}
