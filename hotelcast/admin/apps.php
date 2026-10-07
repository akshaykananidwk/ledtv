<?php
declare(strict_types=1);
/**
 * Apps (2.3): gallery of the registered display apps (core/Apps/*App.php) grouped by category,
 * create / edit / delete app screens (content items of type 'app') with title, duration, theme,
 * font, accent colour, language and the app's own fields, and a live preview of the signed TV page.
 * Saved app screens appear in the Content Library and playlists like any other content.
 * Permissions: content.manage (same as the Content Library forms).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('content.manage');
Csrf::check();

/** App item of this hotel (another hotel's id → 404 via Tenant::find); null when missing or not an app. */
$loadItem = static function (int $id): ?array {
    $item = $id ? ContentManager::find($id) : null;
    return $item && $item['type'] === 'app' ? $item : null;
};

$formItem = null;     // item array shown in the form (after a validation error)
$formErrors = [];

if (is_post()) {
    $op = req_str('op', $_POST, 20);
    $id = req_int('id', $_POST);
    $existing = $id ? $loadItem($id) : null;
    if ($id && !$existing && in_array($op, ['save', 'delete'], true)) {
        flash('warning', __('App screen not found.'));
        redirect(admin_url('apps.php'));
    }
    $in = $_POST;
    if ($existing) {
        $in['app'] = (string) (ContentManager::settings($existing)['app'] ?? '');
    }

    if ($op === 'preview') {
        // Draft preview of the form (not saved): the TV page as HTML for the preview iframe.
        [$data] = ContentManager::validate($in, 'app', false);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo DisplayApps::renderPage(['id' => 0, 'hotel_id' => Tenant::id(), 'title' => $data['title'], 'duration' => $data['duration'], 'settings' => json_out($data['settings'])], true);
        exit;
    }

    if ($op === 'save') {
        [$data, $formErrors] = ContentManager::validate($in, 'app', false);
        if ($formErrors) {
            http_response_code(422);
            $formItem = [
                'id' => $existing ? (int) $existing['id'] : 0, 'title' => $data['title'], 'duration' => $data['duration'],
                'is_active' => !empty($_POST['is_active']) ? 1 : 0, 'settings' => $data['settings'],
            ];
        } else {
            $row = [
                'title' => $data['title'],
                'duration' => $data['duration'],
                'url' => null,
                'body' => null,
                'settings' => json_out($data['settings']),
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($existing) {
                DB::update('content_items', $row, 'id = :id', ['id' => $id]);
            } else {
                $id = DB::insert('content_items', $row + ['type' => 'app', 'created_by' => Auth::id(), 'created_at' => now()]);
            }
            Settings::bumpContentVersion();
            ActivityLog::add($existing ? 'content_update' : 'content_create', 'content', $id, 'app ' . $data['settings']['app'] . ': ' . $data['title']);
            flash('success', __('":t" saved.', ['t' => $data['title']]));
            redirect(admin_url('apps.php', ['action' => 'edit', 'id' => $id]));
        }
    } elseif ($op === 'delete') {
        ContentManager::deleteItem((int) $existing['id']);
        ActivityLog::add('content_delete', 'content', (int) $existing['id'], 'app: ' . $existing['title']);
        flash('success', __('":t" deleted.', ['t' => $existing['title']]));
        redirect(admin_url('apps.php'));
    } else {
        flash('warning', __('Unknown action.'));
        redirect(admin_url('apps.php'));
    }
}

$action = req_str('action', $_GET, 20);
$activeNav = 'apps';
$pageTitle = __('Apps');
$catLabels = array_map('__', DisplayApp::CATEGORIES);

// ---------------------------------------------------------------- create / edit form
if ($action === 'new' || $action === 'edit' || $formItem !== null) {
    if ($formItem !== null) {
        $item = $formItem;
    } elseif ($action === 'edit') {
        $item = $loadItem(req_int('id', $_GET));
        if (!$item) {
            flash('warning', __('App screen not found.'));
            redirect(admin_url('apps.php'));
        }
    } else {
        $key = req_str('app', $_GET, 40);
        $app = DisplayApps::find($key);
        if (!$app) {
            flash('warning', __('Unknown display app.'));
            redirect(admin_url('apps.php'));
        }
        $item = ['id' => 0, 'title' => $app->label(), 'duration' => 30, 'is_active' => 1,
            'settings' => ['app' => $key, 'config' => $app->defaults(), 'theme' => DisplayApps::DEFAULT_THEME, 'font' => 'auto', 'accent' => null, 'lang' => 'auto']];
    }
    $s = DisplayApps::normalize(is_array($item['settings']) ? $item['settings'] : ContentManager::settings($item));
    $app = DisplayApps::find($s['app']);
    if (!$app) {
        flash('warning', __('This app is not available.'));
        redirect(admin_url('apps.php'));
    }
    $config = $app->config($s['config']);
    $isNew = !(int) $item['id'];
    $saved = $isNew ? null : $loadItem((int) $item['id']);
    $previewUrl = $saved ? DisplayApps::displayUrl($saved, ['preview' => 1]) : '';
    $pageTitle = ($isNew ? __('New app screen') : __('Edit app screen')) . ' · ' . $app->label();
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
      <div>
        <h1><i class="bi <?= e($app->icon()) ?>"></i> <?= e($pageTitle) ?></h1>
        <p class="lead-sm"><?= e($app->description()) ?></p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($app->adminPage()): ?><a class="btn btn-outline-primary" href="<?= e($app->adminPage()) ?>"><i class="bi bi-box-arrow-up-right"></i> <?= e(__('Open management page')) ?></a><?php endif; ?>
        <?php if ($saved): ?><a class="btn btn-light border" href="<?= e(admin_url('preview.php', ['content_id' => $saved['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-tv"></i> <?= e(__('TV simulator')) ?></a><?php endif; ?>
        <a href="<?= e(admin_url('apps.php')) ?>" class="btn btn-light border"><i class="bi bi-arrow-left"></i> <?= e(__('Back')) ?></a>
      </div>
    </div>
    <?php if ($formErrors): ?>
      <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" action="<?= e(admin_url('apps.php')) ?>" id="appForm" novalidate>
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="app" value="<?= e($app->key()) ?>">
      <div class="row g-3">
        <div class="col-xl-7">
          <div class="card mb-3"><div class="card-body row g-3">
            <div class="col-md-8">
              <label class="form-label" for="title"><?= e(__('Title')) ?> *</label>
              <input class="form-control form-control-lg" id="title" name="title" value="<?= e($item['title']) ?>" required maxlength="190">
              <div class="form-text"><?= e(__('The name in the content library and playlists.')) ?></div>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="duration"><?= e(__('Show for (seconds)')) ?></label>
              <input class="form-control form-control-lg" type="number" id="duration" name="duration" min="0" max="86400" value="<?= (int) $item['duration'] ?>">
              <div class="form-text"><?= e(__('Used inside playlists.')) ?></div>
            </div>
            <div class="col-12"><div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= (int) $item['is_active'] ? ' checked' : '' ?>>
              <label class="form-check-label" for="is_active"><?= e(__('Active (can be shown on TVs)')) ?></label>
            </div></div>
          </div></div>

          <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi <?= e($app->icon()) ?>"></i> <?= e($app->label()) ?></div>
            <div class="card-body row g-3"><?= $app->form($config) ?></div>
          </div>

          <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-palette"></i> <?= e(__('Look')) ?></div>
            <div class="card-body row g-3">
              <div class="col-12">
                <div class="form-label"><?= e(__('Theme')) ?></div>
                <div class="app-themes">
                  <?php foreach (DisplayApps::THEMES as $tk => $t): ?>
                    <input type="radio" class="btn-check" name="theme" value="<?= e($tk) ?>" id="theme_<?= e($tk) ?>"<?= $s['theme'] === $tk ? ' checked' : '' ?> autocomplete="off">
                    <label class="app-theme" for="theme_<?= e($tk) ?>">
                      <span class="app-theme-swatch" style="background:linear-gradient(135deg,<?= e($t['bg']) ?>,<?= e($t['bg2']) ?>);color:<?= e($t['fg']) ?>"><span style="background:<?= e($t['accent']) ?>"></span>Aa</span>
                      <span class="small"><?= e(__($t['label'])) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="font"><?= e(__('Font')) ?></label>
                <select class="form-select" id="font" name="font">
                  <?php foreach (DisplayApps::FONTS as $fk => $fl): ?><option value="<?= e($fk) ?>"<?= $s['font'] === $fk ? ' selected' : '' ?>><?= e(__($fl)) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="lang"><?= e(__('TV language')) ?></label>
                <select class="form-select" id="lang" name="lang">
                  <?php foreach (DisplayApps::LANGS as $lk => $ll): ?><option value="<?= e($lk) ?>"<?= $s['lang'] === $lk ? ' selected' : '' ?>><?= e($lk === 'auto' ? __($ll) : $ll) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <div class="form-check form-switch mb-1">
                  <input class="form-check-input" type="checkbox" role="switch" id="accent_on" name="accent_on" value="1"<?= $s['accent'] ? ' checked' : '' ?>>
                  <label class="form-check-label" for="accent_on"><?= e(__('Own accent colour')) ?></label>
                </div>
                <input type="color" class="form-control form-control-color w-100" id="accent" name="accent" value="<?= e($s['accent'] ?? DisplayApps::THEMES[$s['theme']]['accent']) ?>" aria-label="<?= e(__('Accent colour')) ?>">
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap mb-3">
            <button class="btn btn-primary btn-lg" type="submit" name="op" value="save"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
            <a class="btn btn-light border btn-lg" href="<?= e(admin_url('apps.php')) ?>"><?= e(__('Cancel')) ?></a>
            <?php if ($saved): ?>
              <button class="btn btn-outline-danger btn-lg ms-auto" type="submit" name="op" value="delete" formnovalidate data-confirm="<?= e(__('Delete ":t"? TVs showing it fall back to their other content.', ['t' => $saved['title']])) ?>"><i class="bi bi-trash"></i> <?= e(__('Delete')) ?></button>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-xl-5">
          <div class="card app-preview-card"><div class="card-header d-flex align-items-center gap-2">
              <span class="fw-semibold"><i class="bi bi-eye"></i> <?= e(__('Live preview')) ?></span>
              <span class="small text-muted" id="appPreviewState"></span>
              <?php if ($previewUrl): ?><a class="btn btn-sm btn-light border ms-auto" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-arrows-fullscreen"></i> <?= e(__('Full screen')) ?></a><?php endif; ?>
            </div>
            <div class="app-preview-16x9"><iframe id="appPreview" title="<?= e(__('Live preview')) ?>"<?= $previewUrl ? ' src="' . e($previewUrl) . '"' : '' ?>></iframe></div>
            <div class="card-body small text-muted"><?= e(__('The preview updates while you type. TVs show the saved version.')) ?></div>
          </div>
        </div>
      </div>
    </form>
    <style>
    .app-themes{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:.5rem}
    .app-theme{display:flex;flex-direction:column;align-items:center;gap:.25rem;border:2px solid transparent;border-radius:.6rem;padding:.3rem;cursor:pointer}
    .btn-check:checked+.app-theme{border-color:var(--hc-accent,#7B1FA2);background:rgba(123,31,162,.06)}
    .btn-check:focus-visible+.app-theme{outline:2px solid var(--hc-accent,#7B1FA2)}
    .app-theme-swatch{position:relative;width:100%;height:46px;border-radius:.4rem;display:flex;align-items:center;justify-content:center;font-weight:700}
    .app-theme-swatch span{position:absolute;left:6px;bottom:6px;width:14px;height:14px;border-radius:50%}
    .app-preview-card{position:sticky;top:1rem}
    .app-preview-16x9{position:relative;padding-top:56.25%;background:#000}
    .app-preview-16x9 iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      const form = document.getElementById('appForm');
      const frame = document.getElementById('appPreview');
      const state = document.getElementById('appPreviewState');
      let timer = null, seq = 0;
      const refresh = () => {
        const fd = new FormData(form);
        fd.set('op', 'preview');
        const n = ++seq;
        state.textContent = <?= json_embed(__('Updating…')) ?>;
        fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then((r) => r.ok ? r.text() : Promise.reject(r.status))
          .then((html) => { if (n === seq) { frame.removeAttribute('src'); frame.srcdoc = html; state.textContent = ''; } })
          .catch(() => { state.textContent = <?= json_embed(__('Preview not available')) ?>; });
      };
      const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 600); };
      form.addEventListener('input', later);
      form.addEventListener('change', later);
      if (!frame.getAttribute('src')) refresh();
    });
    </script>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ---------------------------------------------------------------- gallery
$items = DB::all("SELECT * FROM content_items WHERE hotel_id = :hid AND type = 'app' ORDER BY title, id", hid());
$byApp = [];
foreach ($items as $it) {
    $byApp[(string) (ContentManager::settings($it)['app'] ?? '')][] = $it;
}
$apps = DisplayApps::all();
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><i class="bi bi-grid-3x3-gap"></i> <?= e(__('Apps')) ?></h1>
    <p class="lead-sm"><?= e(__('Ready-made TV screens: pick an app, fill in a few fields, choose a theme. Saved screens appear in the Content Library and playlists.')) ?></p>
  </div>
</div>

<?php foreach (DisplayApps::byCategory() as $cat => $catApps): if (!$catApps) { continue; } ?>
  <h2 class="h5 mt-3 mb-2"><?= e($catLabels[$cat] ?? $cat) ?></h2>
  <div class="row g-3 mb-2">
    <?php foreach ($catApps as $key => $app): $mine = $byApp[$key] ?? []; ?>
      <div class="col-md-6 col-xl-4">
        <div class="card h-100 app-card" data-app="<?= e($key) ?>"><div class="card-body d-flex flex-column">
          <div class="d-flex align-items-start gap-3 mb-2">
            <span class="fs-2 text-primary lh-1"><i class="bi <?= e($app->icon()) ?>"></i></span>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold"><?= e($app->label()) ?></div>
              <div class="small text-muted"><?= e($app->description()) ?></div>
            </div>
          </div>
          <?php if ($mine): ?>
            <ul class="list-unstyled small mb-2">
              <?php foreach ($mine as $it): ?>
                <li class="d-flex align-items-center gap-1 py-1 border-top">
                  <span class="text-truncate flex-grow-1<?= (int) $it['is_active'] ? '' : ' text-muted' ?>" title="<?= e($it['title']) ?>"><?= e($it['title']) ?><?= (int) $it['is_active'] ? '' : ' (' . e(__('inactive')) . ')' ?></span>
                  <a class="btn btn-sm btn-light border" href="<?= e(DisplayApps::displayUrl($it, ['preview' => 1])) ?>" target="_blank" rel="noopener" title="<?= e(__('Preview')) ?>"><i class="bi bi-eye"></i></a>
                  <a class="btn btn-sm btn-primary" href="<?= e(admin_url('apps.php', ['action' => 'edit', 'id' => $it['id']])) ?>" title="<?= e(__('Edit')) ?>"><i class="bi bi-pencil"></i></a>
                  <form method="post" class="d-inline" data-confirm="<?= e(__('Delete ":t"? TVs showing it fall back to their other content.', ['t' => $it['title']])) ?>">
                    <?= Csrf::field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="<?= e(__('Delete')) ?>"><i class="bi bi-trash"></i></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <div class="mt-auto d-flex gap-2 flex-wrap">
            <a class="btn btn-primary" href="<?= e(admin_url('apps.php', ['action' => 'new', 'app' => $key])) ?>"><i class="bi bi-plus-lg"></i> <?= e(__('Create')) ?></a>
            <?php if ($app->adminPage()): ?><a class="btn btn-outline-primary" href="<?= e($app->adminPage()) ?>"><i class="bi bi-box-arrow-up-right"></i> <?= e(__('Open management page')) ?></a><?php endif; ?>
          </div>
        </div></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php if (!$apps): ?>
  <div class="card"><div class="hc-empty"><i class="bi bi-grid-3x3-gap"></i><p class="text-muted"><?= e(__('No apps are installed.')) ?></p></div></div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
