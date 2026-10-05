<?php
declare(strict_types=1);
/**
 * Template library (#12) and local guide (#7): gallery → fill fields (EN / GU / HI defaults) → live
 * preview → saved as a normal content item (type html, settings {template_id, fields, lang}) that can be
 * edited again here. "Local guide" picks a content item shown in the TV guest menu. Manager+.
 *   templates.php                         gallery (+ ?lang=en|gu|hi for the thumbnails)
 *   templates.php?action=new&tpl=diwali   form for a new item
 *   templates.php?action=edit&id=N        edit a content item made from a template
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('templates.manage');
$pageTitle = __('Templates');
$activeNav = 'templates';
if (!Tenant::feature('templates')) {
    http_response_code(403);
    require __DIR__ . '/partials/header.php';
    echo '<div class="alert alert-warning">' . e(__('The template library is not included in your plan.')) . '</div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}
Csrf::check();

$langs = ['en' => 'English', 'gu' => 'ગુજરાતી', 'hi' => 'हिन्दी'];
$uiLang = isset($langs[$_GET['lang'] ?? '']) ? (string) $_GET['lang'] : (I18n::lang() === 'gu' ? 'gu' : 'en');

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    if ($op === 'save') {
        require_can('content.manage');
        $tpl = Templates::find(req_str('tpl', $_POST, 40));
        $id = req_int('id', $_POST);
        $existing = $id ? ContentManager::find($id) : null;
        if (!$tpl || ($id && (!$existing || !Templates::fromContent($existing)))) {
            flash('warning', __('Unknown template.'));
            redirect(admin_url('templates.php'));
        }
        $fields = Templates::normalize($tpl, is_array($_POST['fields'] ?? null) ? $_POST['fields'] : []);
        $lang = isset($langs[$_POST['lang'] ?? '']) ? (string) $_POST['lang'] : 'en';
        $errors = Templates::errors($tpl, $fields);
        $title = req_str('title', $_POST, 190);
        if ($errors) {
            flash('danger', implode("\n", $errors));
            redirect($existing ? admin_url('templates.php', ['action' => 'edit', 'id' => $id]) : admin_url('templates.php', ['action' => 'new', 'tpl' => $tpl['id'], 'lang' => $lang]));
        }
        $newId = Templates::save($existing ? $id : null, $tpl, $fields, $title, req_int('duration', $_POST), !empty($_POST['is_active']), $lang, Auth::id());
        ActivityLog::add($existing ? 'content_update' : 'content_create', 'content', $newId, 'template ' . $tpl['id'] . ': ' . ($title ?: Templates::name($tpl, $lang)));
        flash('success', __('":t" saved.', ['t' => $title ?: Templates::name($tpl, $lang)]) . ' ' . __('Find it in the Content Library to assign it to rooms or playlists.'));
        redirect(admin_url('templates.php', ['action' => 'edit', 'id' => $newId]));
    }
    if ($op === 'guide') {
        $cid = req_int('local_guide_content_id', $_POST);
        if ($cid && !ContentManager::find($cid)) {
            $cid = 0;
        }
        Settings::set('local_guide_content_id', $cid ? (string) $cid : '');
        Settings::set('local_guide_title', req_str('local_guide_title', $_POST, 60));
        Settings::bumpContentVersion();
        ActivityLog::add('settings_update', 'settings', null, 'local_guide_content_id=' . ($cid ?: '-'));
        flash('success', $cid ? __('The local guide is now in the TV guest menu.') : __('Local guide removed from the TV guest menu.'));
        redirect(admin_url('templates.php'));
    }
    flash('warning', __('Unknown action.'));
    redirect(admin_url('templates.php'));
}

$action = req_str('action', $_GET, 20);
$fitScript = <<<'HTML'
<script>
// Scale the 1920×1080 template iframes to their box.
(function () {
  function fit() {
    document.querySelectorAll('[data-scale-frame]').forEach(function (box) {
      var f = box.querySelector('iframe');
      if (f) f.style.transform = 'scale(' + (box.clientWidth / 1920) + ')';
    });
  }
  window.addEventListener('resize', fit);
  document.addEventListener('DOMContentLoaded', fit);
  fit();
})();
</script>
HTML;

// ---------------------------------------------------------------- form (new / edit)
if ($action === 'new' || $action === 'edit') {
    if ($action === 'edit') {
        $item = ContentManager::find(req_int('id', $_GET));
        $data = $item ? Templates::fromContent($item) : null;
        if (!$data) {
            flash('warning', __('This content was not made from a template.'));
            redirect(admin_url('content.php'));
        }
        $tpl = Templates::find($data['template_id']);
        $lang = isset($langs[$data['lang']]) ? $data['lang'] : 'en';
        $fields = Templates::normalize($tpl, $data['fields'] + Templates::defaults($tpl, $lang));
        $title = (string) $item['title'];
        $duration = (int) $item['duration'];
        $active = (bool) (int) $item['is_active'];
        $usage = ContentManager::usage((int) $item['id']);
    } else {
        $tpl = Templates::find(req_str('tpl', $_GET, 40));
        if (!$tpl) {
            redirect(admin_url('templates.php'));
        }
        $item = null;
        $lang = $uiLang;
        $fields = Templates::defaults($tpl, $lang);
        $title = Templates::name($tpl, $lang);
        $duration = 15;
        $active = true;
        $usage = null;
    }
    $pageTitle = ($item ? __('Edit') : __('New')) . ' · ' . Templates::name($tpl);
    require __DIR__ . '/partials/header.php';
    $fid = fn (string $k) => 'tf_' . preg_replace('/[^a-z0-9_]/', '', $k);
    ?>
    <div class="page-head">
      <h1><span class="me-1"><?= e((string) $tpl['icon']) ?></span> <?= e($pageTitle) ?></h1>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($item): ?>
          <a class="btn btn-light border" href="<?= e(admin_url('preview.php', ['content_id' => $item['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-tv"></i> <?= e(__('TV preview')) ?></a>
        <?php else: ?>
          <div class="btn-group" role="group" aria-label="<?= e(__('Language of the default text')) ?>">
            <?php foreach ($langs as $code => $name): ?>
              <a class="btn btn-sm <?= $code === $lang ? 'btn-secondary' : 'btn-light border' ?>" href="<?= e(admin_url('templates.php', ['action' => 'new', 'tpl' => $tpl['id'], 'lang' => $code])) ?>"><?= e($name) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <a class="btn btn-light border" href="<?= e(admin_url('templates.php')) ?>"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <form method="post" id="tplForm" data-tpl="<?= e($tpl['id']) ?>">
      <?= Csrf::field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="tpl" value="<?= e($tpl['id']) ?>">
      <input type="hidden" name="id" value="<?= (int) ($item['id'] ?? 0) ?>"><input type="hidden" name="lang" value="<?= e($lang) ?>">
      <div class="row g-3">
        <div class="col-xl-5 order-2 order-xl-1">
          <div class="card mb-3"><div class="card-body row g-3">
            <?php foreach ($tpl['fields'] as $f):
                $k = (string) $f['key'];
                $v = $fields[$k] ?? '';
                $label = __((string) $f['label']) . (!empty($f['required']) ? ' *' : '');
                $id = $fid($k); ?>
              <?php if ($f['type'] === 'color'): ?>
                <div class="col-6"><label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
                  <input type="color" class="form-control form-control-color w-100" id="<?= e($id) ?>" name="fields[<?= e($k) ?>]" value="<?= e((string) $v) ?>"></div>
              <?php elseif ($f['type'] === 'list'): ?>
                <div class="col-12"><label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
                  <textarea class="form-control" id="<?= e($id) ?>" name="fields[<?= e($k) ?>]" rows="<?= max(4, min(12, count((array) $v) + 1)) ?>" spellcheck="false"><?= e(implode("\n", (array) $v)) ?></textarea>
                  <div class="form-text"><?= e(__('One row per line. Columns separated by |')) ?><?php if (!empty($f['help'])): ?> — <?= e(__('Format')) ?>: <code><?= e(__((string) $f['help'])) ?></code><?php endif; ?></div></div>
              <?php elseif ($f['type'] === 'textarea'): ?>
                <div class="col-12"><label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
                  <textarea class="form-control" id="<?= e($id) ?>" name="fields[<?= e($k) ?>]" rows="3" maxlength="2000"><?= e((string) $v) ?></textarea></div>
              <?php else: ?>
                <div class="col-12<?= $f['type'] === 'time' ? ' col-md-6' : '' ?>"><label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
                  <input class="form-control" type="<?= $f['type'] === 'url' ? 'url' : 'text' ?>" id="<?= e($id) ?>" name="fields[<?= e($k) ?>]" value="<?= e((string) $v) ?>" maxlength="<?= $f['type'] === 'url' ? 1000 : 200 ?>"<?= !empty($f['required']) ? ' required' : '' ?><?= $f['type'] === 'url' ? ' placeholder="https://"' : '' ?>></div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div></div>
          <div class="card mb-3"><div class="card-body row g-3">
            <div class="col-12"><label class="form-label" for="t_title"><?= e(__('Name in the Content Library')) ?></label>
              <input class="form-control" id="t_title" name="title" value="<?= e($title) ?>" maxlength="190"></div>
            <div class="col-6"><label class="form-label" for="t_dur"><?= e(__('Show for (seconds)')) ?></label>
              <input class="form-control" type="number" id="t_dur" name="duration" min="0" max="86400" value="<?= (int) $duration ?>">
              <div class="form-text"><?= e(__('Used inside playlists.')) ?></div></div>
            <div class="col-6 d-flex align-items-center"><div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="t_active" name="is_active" value="1"<?= $active ? ' checked' : '' ?>>
              <label class="form-check-label" for="t_active"><?= e(__('Active (can be shown on TVs)')) ?></label></div></div>
            <?php if ($usage !== null && array_sum($usage) > 0): ?>
              <div class="col-12 small text-muted"><i class="bi bi-info-circle"></i> <?= e(__('In use: :r rooms, :g groups, :p playlists. Saving updates those TVs.', ['r' => $usage['rooms'], 'g' => $usage['groups'], 'p' => $usage['playlists']])) ?></div>
            <?php endif; ?>
          </div></div>
          <div class="d-flex gap-2 flex-wrap mb-3">
            <button class="btn btn-primary btn-lg"<?= Auth::can('content.manage') ? '' : ' disabled' ?>><i class="bi bi-check-lg"></i> <?= e(__('Save to Content Library')) ?></button>
            <?php if ($item): ?><a class="btn btn-light border btn-lg" href="<?= e(admin_url('content.php', ['action' => 'edit', 'id' => $item['id'], 'raw' => 1])) ?>" title="<?= e(__('Edit the HTML by hand (the template link is removed when you save there).')) ?>"><i class="bi bi-code-slash"></i> HTML</a><?php endif; ?>
          </div>
        </div>
        <div class="col-xl-7 order-1 order-xl-2">
          <div class="card position-sticky" style="top:4.5rem">
            <div class="card-header d-flex align-items-center"><span><i class="bi bi-eye"></i> <?= e(__('Live preview')) ?></span><span class="small text-muted ms-auto" id="tplStatus"></span></div>
            <div class="card-body p-2">
              <div class="tpl-stage" data-scale-frame style="position:relative;width:100%;aspect-ratio:16/9;overflow:hidden;background:#000;border-radius:.4rem">
                <iframe id="tplPreview" title="<?= e(__('Live preview')) ?>" sandbox="" srcdoc="<?= e(Templates::render($tpl, $fields)) ?>" style="position:absolute;left:0;top:0;width:1920px;height:1080px;border:0;transform-origin:0 0"></iframe>
              </div>
              <div class="form-text"><?= e(__('Shown as on a 1920×1080 TV. Emoji look slightly different on each TV.')) ?></div>
            </div>
          </div>
        </div>
      </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const form = document.getElementById('tplForm');
      const frame = document.getElementById('tplPreview');
      const status = document.getElementById('tplStatus');
      let timer = null, seq = 0;
      const collect = () => {
        const fields = {};
        new FormData(form).forEach((v, k) => { const m = /^fields\[(.+)\]$/.exec(k); if (m) fields[m[1]] = v; });
        return fields;
      };
      const refresh = async () => {
        const my = ++seq;
        status.textContent = '…';
        try {
          const d = await HC.api('tpl_render', { data: { tpl: form.dataset.tpl, fields: collect() } });
          if (my === seq) { frame.srcdoc = d.html; status.textContent = ''; }
        } catch (e) { status.textContent = e.message; }
      };
      form.addEventListener('input', (ev) => {
        if (!ev.target.name || !ev.target.name.startsWith('fields[')) return;
        clearTimeout(timer); timer = setTimeout(refresh, 450);
      });
    });
    </script>
    <?= $fitScript ?>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- gallery
$grouped = Templates::grouped();
$mine = [];
foreach (DB::all("SELECT id, title, settings, is_active, updated_at FROM content_items WHERE hotel_id = :hid AND type = 'html' ORDER BY updated_at DESC LIMIT 300", hid()) as $row) {
    $s = ContentManager::settings($row);
    if (!empty($s['template_id']) && is_string($s['template_id']) && Templates::find($s['template_id'])) {
        $row['template_id'] = $s['template_id'];
        $mine[] = $row;
    }
}
$guideId = Settings::int('local_guide_content_id', 0);
$activeCat = isset(Templates::CATEGORIES[$_GET['cat'] ?? '']) ? (string) $_GET['cat'] : '';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Templates')) ?></h1>
    <p class="lead-sm"><?= e(__('Ready-made designs for festivals, hotel notices, temple timings and a local guide. Pick one, change the text, save — done.')) ?></p>
  </div>
  <div class="btn-group" role="group" aria-label="<?= e(__('Language of the default text')) ?>">
    <?php foreach ($langs as $code => $name): ?>
      <a class="btn btn-sm <?= $code === $uiLang ? 'btn-secondary' : 'btn-light border' ?>" href="<?= e(self_url(['lang' => $code])) ?>"><?= e($name) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="d-flex flex-wrap gap-1 mb-3">
  <a class="btn btn-sm <?= $activeCat === '' ? 'btn-primary' : 'btn-light border' ?>" href="<?= e(self_url(['cat' => null])) ?>"><?= e(__('All')) ?> (<?= count(Templates::all()) ?>)</a>
  <?php foreach ($grouped as $cat => $list): ?>
    <a class="btn btn-sm <?= $activeCat === $cat ? 'btn-primary' : 'btn-light border' ?>" href="<?= e(self_url(['cat' => $cat])) ?>"><?= e(__(Templates::CATEGORIES[$cat])) ?> (<?= count($list) ?>)</a>
  <?php endforeach; ?>
</div>

<style>
.tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1rem}
.tpl-card .tpl-thumb{position:relative;width:100%;aspect-ratio:16/9;overflow:hidden;background:#111;border-radius:.4rem .4rem 0 0;display:block}
.tpl-card .tpl-thumb iframe{position:absolute;left:0;top:0;width:1920px;height:1080px;border:0;transform-origin:0 0;pointer-events:none}
.tpl-card:hover{box-shadow:0 .4rem 1.2rem rgba(0,0,0,.12)}
</style>
<?php foreach ($grouped as $cat => $list):
    if ($activeCat !== '' && $activeCat !== $cat) {
        continue;
    } ?>
  <h2 class="h5 mt-2 mb-2"><?= e(__(Templates::CATEGORIES[$cat])) ?></h2>
  <div class="tpl-grid mb-4">
    <?php foreach ($list as $id => $t): ?>
      <div class="card tpl-card">
        <a class="tpl-thumb" data-scale-frame href="<?= e(admin_url('templates.php', ['action' => 'new', 'tpl' => $id, 'lang' => $uiLang])) ?>" aria-label="<?= e(Templates::name($t)) ?>">
          <iframe loading="lazy" sandbox="" tabindex="-1" title="<?= e(Templates::name($t, $uiLang)) ?>" srcdoc="<?= e(Templates::render($t, Templates::defaults($t, $uiLang), ['lang' => $uiLang])) ?>"></iframe>
        </a>
        <div class="card-body p-2 d-flex align-items-center gap-2">
          <span class="fs-5"><?= e((string) $t['icon']) ?></span>
          <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?= e(Templates::name($t)) ?></div>
            <?php if (I18n::lang() !== 'gu' && isset($t['name']['gu'])): ?><div class="small text-muted text-truncate"><?= e($t['name']['gu']) ?></div><?php endif; ?></div>
          <a class="btn btn-sm btn-primary" href="<?= e(admin_url('templates.php', ['action' => 'new', 'tpl' => $id, 'lang' => $uiLang])) ?>"><?= e(__('Use')) ?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-map"></i> <?= e(__('Local guide on the TV')) ?></div>
      <div class="card-body">
        <p class="small text-muted"><?= e(__('Guests open the guest menu on the TV (OK button) and find this page as "Local guide". Make one with a Local guide template first, e.g. Nearby attractions.')) ?></p>
        <form method="post" class="row g-2">
          <?= Csrf::field() ?><input type="hidden" name="op" value="guide">
          <div class="col-md-7"><label class="form-label" for="guide_c"><?= e(__('Content to show')) ?></label>
            <select class="form-select" id="guide_c" name="local_guide_content_id">
              <option value=""><?= e(__('— none (hidden) —')) ?></option>
              <?php foreach (hc_content_list() as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $guideId ? ' selected' : '' ?>><?= e($c['title']) ?> (<?= e(__(ContentManager::TYPES[$c['type']] ?? $c['type'])) ?>)<?= (int) $c['is_active'] ? '' : ' — ' . e(__('inactive')) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-5"><label class="form-label" for="guide_t"><?= e(__('Menu title (optional)')) ?></label>
            <input class="form-control" id="guide_t" name="local_guide_title" maxlength="60" value="<?= e((string) Settings::get('local_guide_title', '')) ?>" placeholder="<?= e(__('Local guide')) ?>"></div>
          <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-collection"></i> <?= e(__('Made from templates')) ?></div>
      <ul class="list-group list-group-flush" style="max-height:320px;overflow:auto">
        <?php foreach ($mine as $m): $mt = Templates::find($m['template_id']); ?>
          <li class="list-group-item d-flex align-items-center gap-2">
            <span><?= e((string) $mt['icon']) ?></span>
            <div class="min-w-0 flex-grow-1"><div class="text-truncate"><?= e($m['title']) ?><?= (int) $m['id'] === $guideId ? ' <span class="badge text-bg-info">' . e(__('Local guide')) . '</span>' : '' ?><?= (int) $m['is_active'] ? '' : ' <span class="badge text-bg-secondary">' . e(__('inactive')) . '</span>' ?></div>
              <div class="small text-muted"><?= e(Templates::name($mt)) ?> · <?= e(time_ago($m['updated_at'])) ?></div></div>
            <a class="btn btn-sm btn-light border" href="<?= e(admin_url('preview.php', ['content_id' => $m['id']])) ?>" target="_blank" rel="noopener" title="<?= e(__('Preview')) ?>"><i class="bi bi-eye"></i></a>
            <a class="btn btn-sm btn-primary" href="<?= e(admin_url('templates.php', ['action' => 'edit', 'id' => $m['id']])) ?>"><i class="bi bi-pencil"></i> <?= e(__('Edit')) ?></a>
          </li>
        <?php endforeach; ?>
        <?php if (!$mine): ?><li class="list-group-item text-muted small"><?= e(__('Nothing yet. Pick a template above.')) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?= $fitScript ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
