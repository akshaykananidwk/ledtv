<?php
declare(strict_types=1);
/**
 * Menu board (display app "menu_board"): categories and dishes of the shared restaurant / room-service
 * menu (core/MenuBoard.php), with photo upload, one-click "available / sold out" switch (big, for
 * phones), "today's special" flag, board visibility, category hours (dayparting) and reordering.
 * Permission menu_board.manage (staff+). Works without the guest-services plan feature, so a
 * restaurant that only uses the TV board can manage its menu here. Switches answer JSON for AJAX.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('menu_board.manage');
if (post_too_large()) {
    flash('danger', __('The file is larger than the server upload limit (:s). Ask your hosting provider to raise upload_max_filesize / post_max_size, or upload a smaller file.', ['s' => human_bytes(upload_limit())]));
    redirect(admin_url('menu_board.php'));
}
Csrf::check();

$ajax = Auth::isAjax();
$formItem = null;
$formCat = null;
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    switch ($op) {
        case 'toggle':
            $item = MenuBoard::findItem($id); // another hotel's id → 404
            if (!$item) {
                $ajax ? ajax_error(__('Dish not found.'), 404, 'NOT_FOUND') : redirect(admin_url('menu_board.php'));
            }
            $field = req_str('field', $_POST, 20);
            try {
                $v = MenuBoard::toggle($item, $field);
            } catch (InvalidArgumentException) {
                $ajax ? ajax_error(__('Unknown action.'), 422) : redirect(admin_url('menu_board.php'));
            }
            ActivityLog::add('menu_board_toggle', 'menu_item', $id, $field . '=' . $v . ': ' . mb_substr((string) $item['name_en'], 0, 100));
            if ($ajax) {
                ajax_ok(['id' => $id, 'field' => $field, 'value' => $v]);
            }
            if ($field === 'sold_out') {
                flash('success', $v ? __('":t" is sold out.', ['t' => $item['name_en']]) : __('":t" is available again.', ['t' => $item['name_en']]));
            } else {
                flash('success', __('Saved.'));
            }
            redirect(admin_url('menu_board.php') . '#item-' . $id);

        case 'item_save':
            $existing = $id ? MenuBoard::findItem($id) : null;
            if ($id && !$existing) {
                redirect(admin_url('menu_board.php'));
            }
            $in = $_POST;
            if ($existing) {
                $in['sort_order'] = $existing['sort_order'];
            } else {
                $in['sort_order'] = (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM guest_menu_items WHERE hotel_id = :h AND category_id = :c', ['h' => Tenant::id(), 'c' => req_int('category_id', $_POST)]);
            }
            $photo = null;
            if (isset($_FILES['photo']) && is_array($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $photo = Uploader::handle($_FILES['photo'], 'image');
                } catch (RuntimeException $e) {
                    $formErrors[] = $e->getMessage();
                }
            }
            if (!$formErrors) {
                [$newId, $formErrors] = MenuBoard::saveItem($in, $existing ? $id : null, $photo);
            }
            if ($formErrors) {
                if ($photo) {
                    Uploader::delete($photo['path'], $photo['thumb'] ?? null);
                }
                http_response_code(422);
                $formItem = array_merge($existing ?? [], array_intersect_key($_POST, array_flip(['category_id', 'name_en', 'name_gu', 'name_hi', 'description_en', 'description_gu', 'description_hi', 'price', 'price_old', 'food_type', 'badge', 'available_from', 'available_to'])), [
                    'id' => $existing ? $id : 0,
                    'is_active' => !empty($_POST['is_active']) ? 1 : 0, 'is_sold_out' => !empty($_POST['is_sold_out']) ? 1 : 0,
                    'is_special' => !empty($_POST['is_special']) ? 1 : 0, 'show_on_board' => !empty($_POST['show_on_board']) ? 1 : 0,
                    'photo_path' => $existing['photo_path'] ?? null,
                ]);
                break;
            }
            if ($existing && !$photo && !empty($_POST['remove_photo']) && $existing['photo_path']) {
                Uploader::delete((string) $existing['photo_path']);
                DB::update('guest_menu_items', ['photo_path' => null], 'id = :id', ['id' => $newId]);
            }
            ActivityLog::add($existing ? 'menu_item_update' : 'menu_item_create', 'menu_item', (int) $newId, mb_substr(req_str('name_en', $_POST, 120), 0, 120));
            flash('success', __('Dish ":t" saved.', ['t' => req_str('name_en', $_POST, 120)]));
            redirect(admin_url('menu_board.php') . '#item-' . $newId);

        case 'item_delete':
            $item = MenuBoard::findItem($id);
            if ($item && GuestServices::deleteItem($id)) {
                ActivityLog::add('menu_item_delete', 'menu_item', $id, mb_substr((string) $item['name_en'], 0, 120));
                flash('success', __('Dish ":t" deleted.', ['t' => $item['name_en']]));
            }
            redirect(admin_url('menu_board.php'));

        case 'item_move':
        case 'cat_move':
            $table = $op === 'item_move' ? 'guest_menu_items' : 'guest_menu_categories';
            $row = Tenant::find($table, $id);
            if ($row) {
                MenuBoard::move($table, $row, req_str('dir', $_POST, 5) === 'up' ? 'up' : 'down');
            }
            if ($ajax) {
                ajax_ok(['id' => $id]);
            }
            redirect(admin_url('menu_board.php') . ($op === 'item_move' ? '#item-' : '#cat-') . $id);

        case 'cat_save':
            $existing = $id ? MenuBoard::findCategory($id) : null;
            if ($id && !$existing) {
                redirect(admin_url('menu_board.php'));
            }
            $in = $_POST;
            $in['sort_order'] = $existing ? $existing['sort_order'] : (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM guest_menu_categories WHERE hotel_id = :h', ['h' => Tenant::id()]);
            [$newId, $formErrors] = MenuBoard::saveCategory($in, $existing ? $id : null);
            if ($formErrors) {
                http_response_code(422);
                $formCat = array_merge($existing ?? [], array_intersect_key($_POST, array_flip(['name_en', 'name_gu', 'name_hi', 'board_from', 'board_to'])), [
                    'id' => $existing ? $id : 0, 'is_active' => !empty($_POST['is_active']) ? 1 : 0, 'show_on_board' => !empty($_POST['show_on_board']) ? 1 : 0,
                ]);
                break;
            }
            ActivityLog::add($existing ? 'menu_category_update' : 'menu_category_create', 'menu_category', (int) $newId, mb_substr(req_str('name_en', $_POST, 100), 0, 100));
            flash('success', __('Category ":t" saved.', ['t' => req_str('name_en', $_POST, 100)]));
            redirect(admin_url('menu_board.php') . '#cat-' . $newId);

        case 'cat_delete':
            $cat = MenuBoard::findCategory($id);
            if ($cat && GuestServices::deleteCategory($id)) {
                ActivityLog::add('menu_category_delete', 'menu_category', $id, mb_substr((string) $cat['name_en'], 0, 100));
                flash('success', __('Category ":t" deleted.', ['t' => $cat['name_en']]));
            }
            redirect(admin_url('menu_board.php'));

        default:
            $ajax ? ajax_error(__('Unknown action.'), 422) : flash('warning', __('Unknown action.'));
            redirect(admin_url('menu_board.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'menu_board';
$pageTitle = __('Menu board');
$extraScripts = ['js/menu-board-admin.js'];
$categories = MenuBoard::categories();
$foodLabels = array_map('__', MenuBoard::FOOD_LABELS);
$badges = ['' => __('None')] + array_map('__', MenuBoard::BADGES);
$hm = static fn (?string $t): string => $t ? substr($t, 0, 5) : '';
$foodDot = static fn (string $f): string => isset(MenuBoard::FOOD_COLORS[$f]) ? '<span class="mbx-food" style="border-color:' . e(MenuBoard::FOOD_COLORS[$f]) . '"><span style="background:' . e(MenuBoard::FOOD_COLORS[$f]) . '"></span></span>' : '';

// ---------------------------------------------------------------- category form
if ($formCat !== null || $action === 'cat') {
    if ($formCat === null) {
        $formCat = ['id' => 0, 'name_en' => '', 'name_gu' => '', 'name_hi' => '', 'is_active' => 1, 'show_on_board' => 1, 'board_from' => null, 'board_to' => null];
        if (req_int('id', $_GET)) {
            $formCat = MenuBoard::findCategory(req_int('id', $_GET)) ?? redirect(admin_url('menu_board.php'));
        }
    }
    $c = $formCat;
    $pageTitle = (int) $c['id'] ? __('Edit category') : __('New category');
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-egg-fried"></i> <?= e($pageTitle) ?></h1></div>
      <a href="<?= e(admin_url('menu_board.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="<?= e(admin_url('menu_board.php')) ?>" class="card"><div class="card-body row g-3">
      <?= Csrf::field() ?><input type="hidden" name="op" value="cat_save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <div class="col-md-4"><label class="form-label" for="c_en"><?= e(__('Name (English)')) ?> *</label><input class="form-control" id="c_en" name="name_en" value="<?= e($c['name_en']) ?>" maxlength="100" required placeholder="<?= e(__('e.g. Breakfast')) ?>"></div>
      <div class="col-md-4"><label class="form-label" for="c_gu"><?= e(__('Name (Gujarati)')) ?></label><input class="form-control" id="c_gu" name="name_gu" value="<?= e((string) $c['name_gu']) ?>" maxlength="100"></div>
      <div class="col-md-4"><label class="form-label" for="c_hi"><?= e(__('Name (Hindi)')) ?></label><input class="form-control" id="c_hi" name="name_hi" value="<?= e((string) $c['name_hi']) ?>" maxlength="100"></div>
      <div class="col-6 col-md-3"><label class="form-label" for="c_from"><?= e(__('Shown on TV from')) ?></label><input class="form-control" type="time" id="c_from" name="board_from" value="<?= e($hm($c['board_from'] ?? null)) ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label" for="c_to"><?= e(__('Shown on TV until')) ?></label><input class="form-control" type="time" id="c_to" name="board_to" value="<?= e($hm($c['board_to'] ?? null)) ?>"></div>
      <div class="col-md-6 form-text align-self-end"><?= e(__('Dayparting: e.g. breakfast 07:00–11:00. Leave empty to show it all day. Uses the hotel time zone.')) ?></div>
      <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c_board" name="show_on_board" value="1"<?= (int) $c['show_on_board'] ? ' checked' : '' ?>><label class="form-check-label" for="c_board"><?= e(__('Show on TV menu boards')) ?></label></div></div>
      <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c_active" name="is_active" value="1"<?= (int) $c['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="c_active"><?= e(__('Active (room service and TV)')) ?></label></div></div>
      <div class="col-12"><button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
    </div></form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- dish form
if ($formItem !== null || $action === 'item') {
    if ($formItem === null) {
        $formItem = ['id' => 0, 'category_id' => req_int('category', $_GET), 'name_en' => '', 'name_gu' => '', 'name_hi' => '', 'description_en' => '', 'description_gu' => '', 'description_hi' => '',
            'price' => '', 'price_old' => '', 'food_type' => 'veg', 'badge' => '', 'available_from' => null, 'available_to' => null, 'is_active' => 1, 'is_sold_out' => 0,
            'is_special' => 0, 'show_on_board' => 1, 'photo_path' => null];
        if (req_int('id', $_GET)) {
            $formItem = MenuBoard::findItem(req_int('id', $_GET)) ?? redirect(admin_url('menu_board.php'));
        }
    }
    $it = $formItem;
    if (!$categories) {
        flash('warning', __('Add a category first.'));
        redirect(admin_url('menu_board.php', ['action' => 'cat']));
    }
    $pageTitle = (int) $it['id'] ? __('Edit dish') : __('New dish');
    require __DIR__ . '/partials/header.php';
    $sw = static fn (string $name, string $label, bool $on): string => '<div class="col-sm-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="i_' . e($name) . '" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '><label class="form-check-label" for="i_' . e($name) . '">' . e($label) . '</label></div></div>';
    ?>
    <div class="page-head">
      <div><h1><i class="bi bi-egg-fried"></i> <?= e($pageTitle) ?></h1><p class="lead-sm"><?= e(__('The same dish is shown on TV menu boards and in the room-service menu.')) ?></p></div>
      <a href="<?= e(admin_url('menu_board.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
    </div>
    <?php if ($formErrors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?= e(admin_url('menu_board.php')) ?>">
      <?= Csrf::field() ?><input type="hidden" name="op" value="item_save"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
      <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-body row g-3">
          <div class="col-md-4"><label class="form-label" for="i_en"><?= e(__('Name (English)')) ?> *</label><input class="form-control form-control-lg" id="i_en" name="name_en" value="<?= e((string) $it['name_en']) ?>" maxlength="120" required placeholder="<?= e(__('e.g. Masala dosa')) ?>"></div>
          <div class="col-md-4"><label class="form-label" for="i_gu"><?= e(__('Name (Gujarati)')) ?></label><input class="form-control form-control-lg" id="i_gu" name="name_gu" value="<?= e((string) $it['name_gu']) ?>" maxlength="120"></div>
          <div class="col-md-4"><label class="form-label" for="i_hi"><?= e(__('Name (Hindi)')) ?></label><input class="form-control form-control-lg" id="i_hi" name="name_hi" value="<?= e((string) $it['name_hi']) ?>" maxlength="120"></div>
          <?php foreach (['en' => __('Description (English)'), 'gu' => __('Description (Gujarati)'), 'hi' => __('Description (Hindi)')] as $l => $lbl): ?>
            <div class="col-md-4"><label class="form-label" for="i_d<?= e($l) ?>"><?= e($lbl) ?></label><textarea class="form-control" id="i_d<?= e($l) ?>" name="description_<?= e($l) ?>" rows="2" maxlength="300"><?= e((string) $it['description_' . $l]) ?></textarea></div>
          <?php endforeach; ?>
          <div class="col-12">
            <label class="form-label" for="i_photo"><?= e(__('Photo (optional)')) ?></label>
            <input class="form-control" type="file" id="i_photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
            <?php if (!empty($it['photo_path'])): ?>
              <div class="d-flex align-items-center gap-2 mt-2"><img src="<?= e(media_url((string) $it['photo_path'])) ?>" alt="" class="rounded border" style="max-height:80px">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="i_rm" name="remove_photo" value="1"><label class="form-check-label" for="i_rm"><?= e(__('Remove photo')) ?></label></div></div>
            <?php endif; ?>
          </div>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-body row g-3">
          <div class="col-12"><label class="form-label" for="i_cat"><?= e(__('Category')) ?></label>
            <select class="form-select" id="i_cat" name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $it['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name_en']) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label" for="i_price"><?= e(__('Price')) ?></label><input class="form-control" id="i_price" name="price" inputmode="decimal" value="<?= e((string) $it['price']) ?>" required></div>
          <div class="col-6"><label class="form-label" for="i_old"><?= e(__('Old price (struck)')) ?></label><input class="form-control" id="i_old" name="price_old" inputmode="decimal" value="<?= e((string) ($it['price_old'] ?? '')) ?>"></div>
          <div class="col-6"><label class="form-label" for="i_food"><?= e(__('Food type')) ?></label>
            <select class="form-select" id="i_food" name="food_type"><?php foreach ($foodLabels as $k => $l): ?><option value="<?= e($k) ?>"<?= $it['food_type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label" for="i_badge"><?= e(__('Badge')) ?></label>
            <select class="form-select" id="i_badge" name="badge"><?php foreach ($badges as $k => $l): ?><option value="<?= e($k) ?>"<?= (string) ($it['badge'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label" for="i_from"><?= e(__('Available from')) ?></label><input class="form-control" type="time" id="i_from" name="available_from" value="<?= e($hm($it['available_from'] ?? null)) ?>"></div>
          <div class="col-6"><label class="form-label" for="i_to"><?= e(__('Available until')) ?></label><input class="form-control" type="time" id="i_to" name="available_to" value="<?= e($hm($it['available_to'] ?? null)) ?>"></div>
          <?= $sw('is_special', __("Today's special"), (bool) (int) $it['is_special']) ?>
          <?= $sw('is_sold_out', __('Sold out'), (bool) (int) $it['is_sold_out']) ?>
          <?= $sw('show_on_board', __('Show on TV menu boards'), (bool) (int) $it['show_on_board']) ?>
          <?= $sw('is_active', __('Active (room service and TV)'), (bool) (int) $it['is_active']) ?>
        </div></div></div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
          <a class="btn btn-light border btn-lg" href="<?= e(admin_url('menu_board.php')) ?>"><?= e(__('Cancel')) ?></a>
        </div>
      </div>
    </form>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- list (mobile first)
$items = MenuBoard::items();
$byCat = [];
foreach ($items as $i) {
    $byCat[(int) $i['category_id']][] = $i;
}
$screens = (int) DB::value("SELECT COUNT(*) FROM content_items WHERE hotel_id = :hid AND type = 'app' AND settings LIKE :p", hid() + ['p' => '%"app":"menu_board"%']);
$soldCount = count(array_filter($items, static fn ($i) => (int) $i['is_sold_out']));
require __DIR__ . '/partials/header.php';
?>
<style>
.mbx-food{display:inline-block;width:16px;height:16px;border:2px solid;border-radius:2px;position:relative;vertical-align:-2px;margin-right:6px;background:#fff}
.mbx-food span{position:absolute;left:3px;top:3px;width:6px;height:6px;border-radius:50%}
.mbx-item{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem .75rem;padding:.75rem 1rem;border-top:1px solid var(--bs-border-color)}
.mbx-item .mbx-name{flex:1 1 12rem;min-width:0}
.mbx-thumb{width:48px;height:48px;object-fit:cover;border-radius:.4rem}
.mbx-sold-btn{min-width:9.5rem;font-weight:700}
.mbx-item.is-sold .mbx-title{text-decoration:line-through;opacity:.6}
@media (max-width:575.98px){.mbx-actions{width:100%;display:flex;gap:.5rem}.mbx-actions .mbx-sold-btn{flex:1}}
</style>
<div class="page-head">
  <div>
    <h1><i class="bi bi-egg-fried"></i> <?= e(__('Menu board')) ?></h1>
    <p class="lead-sm"><?= e(__('Dishes, prices and the sold-out switch for the Menu board screens. TVs update within 10 seconds.')) ?></p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (Auth::can('content.manage')): ?>
      <a class="btn btn-light border" href="<?= e($screens ? admin_url('apps.php') : admin_url('apps.php', ['action' => 'new', 'app' => 'menu_board'])) ?>"><i class="bi bi-grid-3x3-gap"></i> <?= e($screens ? __('Menu board screens (:n)', ['n' => $screens]) : __('Create a menu board screen')) ?></a>
    <?php endif; ?>
    <a class="btn btn-light border" href="<?= e(admin_url('menu_board.php', ['action' => 'cat'])) ?>"><i class="bi bi-folder-plus"></i> <?= e(__('New category')) ?></a>
    <?php if ($categories): ?><a class="btn btn-primary btn-lg" href="<?= e(admin_url('menu_board.php', ['action' => 'item'])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('New dish')) ?></a><?php endif; ?>
  </div>
</div>
<?php if ($soldCount): ?><div class="alert alert-warning py-2"><i class="bi bi-slash-circle"></i> <?= e(__(':n dishes are sold out right now.', ['n' => $soldCount])) ?></div><?php endif; ?>
<?php if (!$categories): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-egg-fried"></i><p class="text-muted"><?= e(__('No menu yet. Start with a category, e.g. Breakfast or Drinks.')) ?></p>
    <a class="btn btn-primary" href="<?= e(admin_url('menu_board.php', ['action' => 'cat'])) ?>"><?= e(__('New category')) ?></a></div></div>
<?php endif; ?>
<?php foreach ($categories as $ci => $c): ?>
  <div class="card mb-3" id="cat-<?= (int) $c['id'] ?>">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <strong class="me-auto"><?= e($c['name_en']) ?><?php if ($c['name_gu']): ?> <span class="text-muted fw-normal">· <?= e($c['name_gu']) ?></span><?php endif; ?></strong>
      <?php if ($c['board_from'] && $c['board_to']): ?><span class="badge text-bg-info"><i class="bi bi-clock"></i> <?= e($hm($c['board_from']) . '–' . $hm($c['board_to'])) ?></span><?php endif; ?>
      <?php if (!(int) $c['is_active']): ?><span class="badge text-bg-secondary"><?= e(__('Off')) ?></span><?php elseif (!(int) $c['show_on_board']): ?><span class="badge text-bg-light border"><?= e(__('Not on TV')) ?></span><?php endif; ?>
      <div class="btn-group btn-group-sm">
        <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="cat_move"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="dir" value="up"><button class="btn btn-light border" title="<?= e(__('Move up')) ?>"<?= $ci === 0 ? ' disabled' : '' ?>><i class="bi bi-arrow-up"></i></button></form>
        <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="cat_move"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="dir" value="down"><button class="btn btn-light border" title="<?= e(__('Move down')) ?>"<?= $ci === count($categories) - 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-down"></i></button></form>
      </div>
      <a class="btn btn-sm btn-light border" href="<?= e(admin_url('menu_board.php', ['action' => 'item', 'category' => $c['id']])) ?>" title="<?= e(__('New dish')) ?>"><i class="bi bi-plus-lg"></i></a>
      <a class="btn btn-sm btn-light border" href="<?= e(admin_url('menu_board.php', ['action' => 'cat', 'id' => $c['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
      <form method="post" class="d-inline" data-confirm="<?= e(__('Delete category ":t" and all its dishes?', ['t' => $c['name_en']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="cat_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button></form>
    </div>
    <?php $list = $byCat[(int) $c['id']] ?? []; ?>
    <?php if (!$list): ?><div class="p-3 text-muted small"><?= e(__('No dishes in this category yet.')) ?></div><?php endif; ?>
    <?php foreach ($list as $ii => $i): $sold = (int) $i['is_sold_out'] === 1; $sp = (int) $i['is_special'] === 1; ?>
      <div class="mbx-item<?= $sold ? ' is-sold' : '' ?>" id="item-<?= (int) $i['id'] ?>" data-item="<?= (int) $i['id'] ?>">
        <?php if ($i['photo_path']): ?><img class="mbx-thumb" alt="" src="<?= e(media_url((string) $i['photo_path'])) ?>"><?php endif; ?>
        <div class="mbx-name">
          <div class="mbx-title fw-semibold"><?= $foodDot((string) $i['food_type']) ?><?= e($i['name_en']) ?>
            <?php if ($i['badge'] && isset(MenuBoard::BADGES[$i['badge']])): ?><span class="badge text-bg-warning"><?= e(__(MenuBoard::BADGES[$i['badge']])) ?></span><?php endif; ?></div>
          <div class="small text-muted"><?php if ($i['price_old'] !== null): ?><s><?= e(MenuBoard::price((float) $i['price_old'], '₹')) ?></s> <?php endif; ?><?= e(MenuBoard::price((float) $i['price'], '₹')) ?>
            <?php if (!(int) $i['is_active']): ?> · <span class="text-danger"><?= e(__('Off')) ?></span><?php elseif (!(int) $i['show_on_board']): ?> · <?= e(__('Not on TV')) ?><?php endif; ?></div>
        </div>
        <div class="mbx-actions d-flex align-items-center gap-2">
          <form method="post" class="d-inline" data-mb-toggle><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="field" value="special"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
            <button class="btn btn-lg <?= $sp ? 'btn-warning' : 'btn-outline-secondary' ?>" title="<?= e(__("Today's special")) ?>" aria-pressed="<?= $sp ? 'true' : 'false' ?>" data-on-class="btn-warning" data-off-class="btn-outline-secondary"><i class="bi <?= $sp ? 'bi-star-fill' : 'bi-star' ?>"></i></button></form>
          <form method="post" class="d-inline" data-mb-toggle><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="field" value="sold_out"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
            <button class="btn btn-lg mbx-sold-btn <?= $sold ? 'btn-danger' : 'btn-success' ?>" aria-pressed="<?= $sold ? 'true' : 'false' ?>" data-on-text="<?= e(__('Sold out')) ?>" data-off-text="<?= e(__('Available')) ?>"><?= e($sold ? __('Sold out') : __('Available')) ?></button></form>
          <div class="dropdown">
            <button class="btn btn-lg btn-light border" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e(__('More')) ?>"><i class="bi bi-three-dots-vertical"></i></button>
            <div class="dropdown-menu dropdown-menu-end">
              <a class="dropdown-item" href="<?= e(admin_url('menu_board.php', ['action' => 'item', 'id' => $i['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
              <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="item_move"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="dir" value="up"><button class="dropdown-item"<?= $ii === 0 ? ' disabled' : '' ?>><i class="bi bi-arrow-up"></i> <?= e(__('Move up')) ?></button></form>
              <form method="post"><?= Csrf::field() ?><input type="hidden" name="op" value="item_move"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="dir" value="down"><button class="dropdown-item"<?= $ii === count($list) - 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-down"></i> <?= e(__('Move down')) ?></button></form>
              <form method="post" data-mb-toggle><?= Csrf::field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="field" value="board"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="dropdown-item" data-reload><i class="bi bi-tv"></i> <?= e((int) $i['show_on_board'] ? __('Hide from TV boards') : __('Show on TV boards')) ?></button></form>
              <form method="post" data-confirm="<?= e(__('Delete dish ":t"?', ['t' => $i['name_en']])) ?>"><?= Csrf::field() ?><input type="hidden" name="op" value="item_delete"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="dropdown-item text-danger"><i class="bi bi-trash"></i> <?= e(__('Delete')) ?></button></form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
