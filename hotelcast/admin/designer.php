<?php
declare(strict_types=1);
/**
 * Slide designer (#11): a simple drag & drop editor for 1920×1080 TV slides (fabric.js, assets/js/designer.js).
 * The browser exports a PNG and posts it with the design JSON to ajax.php?action=designer_save, which
 * stores an ordinary `image` content item (core/Designer.php). ?id=<content id> re-opens a saved design,
 * ?tpl=<starter id> / ?template=<hotel template id> starts from a template.
 * Access: like the content library (content.view to open the library, content.manage to design).
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('content.view');
require_can('content.manage');

$id = req_int('id', $_GET);
$item = null;
$design = null;
if ($id) {
    $item = ContentManager::find($id); // another hotel's item → 404
    if (!$item) {
        flash('warning', __('Content not found.'));
        redirect(admin_url('content.php'));
    }
    $row = $item['type'] === 'image' ? Designer::designFor($id) : null;
    if (!$row) {
        flash('warning', __('This item was not made with the designer.'));
        redirect(admin_url('content.php', ['action' => 'edit', 'id' => $id, 'raw' => 1]));
    }
    $design = json_decode((string) $row['data'], true);
}

$start = null;
if (!$design) {
    $tplId = req_str('tpl', $_GET, 40);
    $hotelTpl = req_int('template', $_GET);
    if ($tplId !== '' && isset(Designer::starters()[$tplId])) {
        $start = 'starter:' . $tplId;
    } elseif ($hotelTpl && Designer::findTemplate($hotelTpl)) {
        $start = 'template:' . $hotelTpl;
    }
}

$faces = Designer::fontFaces();
$fontOptions = Designer::fontOptions($faces);
$starters = [];
foreach (Designer::starters() as $t) {
    $starters[] = ['id' => $t['id'], 'name' => __($t['name']), 'design' => $t['design']];
}
$hotelTemplates = array_map(static fn ($t) => ['id' => $t['id'], 'name' => $t['name'], 'design' => $t['data']], Designer::templates());
$config = [
    'id' => $item ? (int) $item['id'] : 0,
    'title' => $item ? (string) $item['title'] : '',
    'duration' => $item ? (int) $item['duration'] : 10,
    'design' => $design,
    'start' => $start,
    'starters' => $starters,
    'templates' => $hotelTemplates,
    'library' => Designer::libraryImages(),
    'logo' => Designer::localUrl((string) Settings::get('hotel_logo', '')),
    'hotel' => (string) Settings::get('hotel_name', ''),
    'fonts' => $fontOptions,
    'families' => array_values(array_unique(array_merge(array_column($faces, 'family'), ['Noto Sans Gujarati', 'Noto Sans Devanagari']))),
    'width' => Designer::WIDTH,
    'height' => Designer::HEIGHT,
    'maxImage' => Designer::MAX_IMAGE_BYTES,
    'maxJson' => Designer::MAX_JSON_BYTES,
    'uploadLimit' => upload_limit(),
    'contentUrl' => admin_url('content.php'),
    'i18n' => [
        'heading' => __('Your heading'),
        'subheading' => __('Sub-heading text'),
        'body' => __('Write your text here'),
        'saving' => __('Saving…'),
        'saved' => __('Slide saved. It is in your content library.'),
        'title_required' => __('Please enter a title.'),
        'too_big' => __('The slide image is too large (maximum 8 MB). Use smaller pictures.'),
        'server_limit' => __('The slide image (:n) is larger than the server upload limit (:s). Use fewer photos, or ask your hosting provider to raise upload_max_filesize and post_max_size to 10M.', ['n' => '{n}', 's' => human_bytes(upload_limit())]),
        'json_too_big' => __('The design is too large (maximum :m MB). Use fewer or smaller pictures.', ['m' => 1]),
        'replace' => __('Replace the current design with this template?'),
        'template_name' => __('Template name'),
        'template_saved' => __('Template saved.'),
        'delete_template' => __('Delete this template?'),
        'no_logo' => __('No business logo yet. Upload it in Settings.'),
        'no_images' => __('No images in your content library yet. Use "Upload image".'),
        'uploading' => __('Uploading…'),
        'leave' => __('You have unsaved changes.'),
        'image_failed' => __('Some pictures of this design could not be loaded and were left out.'),
        'layer_text' => __('Text'),
        'layer_image' => __('Image'),
        'layer_shape' => __('Shape'),
        'layer_logo' => __('Logo'),
        'empty_layers' => __('Nothing on the slide yet.'),
    ],
];

$pageTitle = $item ? __('Edit design') : __('Design a slide');
$activeNav = 'designer';
$extraScripts = ['vendor/fabric/fabric.min.js', 'js/designer.js'];
$extraStyles = ['css/designer.css'];
require __DIR__ . '/partials/header.php';
$btn = static fn (string $act, string $icon, string $label, string $extra = '') => '<button type="button" class="btn btn-light border" data-act="' . e($act) . '" title="' . e($label) . '" aria-label="' . e($label) . '"' . $extra . '><i class="bi ' . e($icon) . '"></i></button>';
?>
<style><?= Designer::fontCss($faces) ?></style>
<div class="dz-app" id="dzApp">
  <div class="dz-topbar">
    <a href="<?= e(admin_url('content.php')) ?>" class="btn btn-light border" title="<?= e(__('Back')) ?>" aria-label="<?= e(__('Back')) ?>"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 m-0 d-none d-xl-block text-nowrap"><i class="bi bi-brush"></i> <?= e($pageTitle) ?></h1>
    <input class="form-control dz-title" id="dzTitle" maxlength="190" value="<?= e($config['title']) ?>" placeholder="<?= e(__('Slide title, e.g. Diwali greeting')) ?>" aria-label="<?= e(__('Title')) ?>">
    <div class="input-group dz-duration" title="<?= e(__('Show for (seconds)')) ?>">
      <span class="input-group-text"><i class="bi bi-stopwatch"></i></span>
      <input class="form-control" type="number" id="dzDuration" min="0" max="86400" value="<?= (int) $config['duration'] ?>" aria-label="<?= e(__('Show for (seconds)')) ?>">
    </div>
    <div class="btn-group">
      <?= $btn('undo', 'bi-arrow-counterclockwise', __('Undo') . ' (Ctrl+Z)', ' disabled') ?>
      <?= $btn('redo', 'bi-arrow-clockwise', __('Redo') . ' (Ctrl+Y)', ' disabled') ?>
    </div>
    <button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#dzTplModal"><i class="bi bi-grid-1x2"></i> <span class="d-none d-md-inline"><?= e(__('Templates')) ?></span></button>
    <button type="button" class="btn btn-light border" data-act="save-template"><i class="bi bi-bookmark-plus"></i> <span class="d-none d-lg-inline"><?= e(__('Save as template')) ?></span></button>
    <button type="button" class="btn btn-primary" data-act="save" id="dzSave"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
  </div>

  <div class="dz-body">
    <aside class="dz-tools" aria-label="<?= e(__('Add')) ?>">
      <div class="dz-group-label"><?= e(__('Text')) ?></div>
      <button type="button" class="btn btn-light border w-100 text-start" data-act="add-heading"><i class="bi bi-type-h1"></i> <?= e(__('Heading')) ?></button>
      <button type="button" class="btn btn-light border w-100 text-start" data-act="add-subheading"><i class="bi bi-type-h2"></i> <?= e(__('Sub-heading')) ?></button>
      <button type="button" class="btn btn-light border w-100 text-start" data-act="add-body"><i class="bi bi-text-paragraph"></i> <?= e(__('Body text')) ?></button>

      <div class="dz-group-label"><?= e(__('Pictures')) ?></div>
      <button type="button" class="btn btn-light border w-100 text-start" data-act="upload-image"><i class="bi bi-upload"></i> <?= e(__('Upload image')) ?></button>
      <button type="button" class="btn btn-light border w-100 text-start" data-act="library-image"><i class="bi bi-images"></i> <?= e(__('From library')) ?></button>
      <button type="button" class="btn btn-light border w-100 text-start" data-act="add-logo"><i class="bi bi-award"></i> <?= e(__('Business logo')) ?></button>

      <div class="dz-group-label"><?= e(__('Shapes')) ?></div>
      <div class="dz-shapes">
        <?= $btn('add-rect', 'bi-square-fill', __('Rectangle')) ?>
        <?= $btn('add-rounded', 'bi-app', __('Rounded box')) ?>
        <?= $btn('add-circle', 'bi-circle-fill', __('Circle')) ?>
        <?= $btn('add-triangle', 'bi-triangle-fill', __('Triangle')) ?>
        <?= $btn('add-line', 'bi-dash-lg', __('Line')) ?>
        <?= $btn('add-star', 'bi-star-fill', __('Star')) ?>
      </div>

      <div class="dz-group-label"><?= e(__('Background')) ?></div>
      <div class="d-flex gap-2 align-items-center mb-2">
        <input type="color" class="form-control form-control-color" id="dzBg1" value="#1a237e" title="<?= e(__('Colour')) ?>" aria-label="<?= e(__('Background colour')) ?>">
        <input type="color" class="form-control form-control-color" id="dzBg2" value="#880e4f" title="<?= e(__('Second colour')) ?>" aria-label="<?= e(__('Second colour')) ?>">
      </div>
      <select class="form-select form-select-sm mb-2" id="dzBgMode" aria-label="<?= e(__('Background')) ?>">
        <option value="solid"><?= e(__('Solid colour')) ?></option>
        <option value="h"><?= e(__('Gradient →')) ?></option>
        <option value="v"><?= e(__('Gradient ↓')) ?></option>
        <option value="d"><?= e(__('Gradient ↘')) ?></option>
      </select>
      <div class="d-flex gap-1">
        <button type="button" class="btn btn-sm btn-light border flex-grow-1" data-act="bg-image" title="<?= e(__('Background picture from library')) ?>"><i class="bi bi-image"></i> <?= e(__('Picture')) ?></button>
        <button type="button" class="btn btn-sm btn-light border" data-act="bg-upload" title="<?= e(__('Upload background picture')) ?>" aria-label="<?= e(__('Upload background picture')) ?>"><i class="bi bi-upload"></i></button>
        <button type="button" class="btn btn-sm btn-light border" data-act="bg-clear" title="<?= e(__('Remove background picture')) ?>" aria-label="<?= e(__('Remove background picture')) ?>"><i class="bi bi-x-lg"></i></button>
      </div>
      <input type="file" id="dzFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
    </aside>

    <main class="dz-stage" id="dzStage">
      <div class="dz-canvas-wrap" id="dzWrap"><canvas id="dzCanvas" width="960" height="540"></canvas></div>
      <div class="dz-hint small text-muted"><?= e(__('Drag to move · corners to resize · Delete key removes · arrow keys nudge (Shift = 10 px) · double-click text to edit')) ?></div>
      <div class="dz-progress" id="dzProgress" hidden>
        <div class="small mb-1" data-label><?= e(__('Saving…')) ?></div>
        <div class="progress" style="height:16px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
      </div>
    </main>

    <aside class="dz-props" id="dzProps" aria-label="<?= e(__('Properties')) ?>">
      <div class="dz-none text-muted small" data-panel="none"><i class="bi bi-hand-index"></i> <?= e(__('Click something on the slide to change it.')) ?></div>

      <section data-panel="text" hidden>
        <div class="dz-group-label"><?= e(__('Text')) ?></div>
        <label class="form-label small mb-1" for="pFont"><?= e(__('Font')) ?></label>
        <select class="form-select form-select-sm mb-2" id="pFont" data-prop="fontFamily">
          <?php foreach ($fontOptions as $label => $stack): ?><option value="<?= e($stack) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <div class="d-flex gap-2 mb-2">
          <div class="flex-grow-1"><label class="form-label small mb-1" for="pSize"><?= e(__('Size')) ?></label><input class="form-control form-control-sm" type="number" id="pSize" min="8" max="600" data-prop="fontSize"></div>
          <div><label class="form-label small mb-1" for="pColor"><?= e(__('Colour')) ?></label><input class="form-control form-control-sm form-control-color" type="color" id="pColor" data-prop="fill"></div>
        </div>
        <div class="btn-group btn-group-sm w-100 mb-2" role="group">
          <button type="button" class="btn btn-light border" data-toggle="bold" title="<?= e(__('Bold')) ?>" aria-label="<?= e(__('Bold')) ?>"><i class="bi bi-type-bold"></i></button>
          <button type="button" class="btn btn-light border" data-toggle="italic" title="<?= e(__('Italic')) ?>" aria-label="<?= e(__('Italic')) ?>"><i class="bi bi-type-italic"></i></button>
          <button type="button" class="btn btn-light border" data-toggle="underline" title="<?= e(__('Underline')) ?>" aria-label="<?= e(__('Underline')) ?>"><i class="bi bi-type-underline"></i></button>
        </div>
        <div class="btn-group btn-group-sm w-100 mb-2" role="group" aria-label="<?= e(__('Alignment')) ?>">
          <button type="button" class="btn btn-light border" data-align="left" title="<?= e(__('Align left')) ?>" aria-label="<?= e(__('Align left')) ?>"><i class="bi bi-text-left"></i></button>
          <button type="button" class="btn btn-light border" data-align="center" title="<?= e(__('Align centre')) ?>" aria-label="<?= e(__('Align centre')) ?>"><i class="bi bi-text-center"></i></button>
          <button type="button" class="btn btn-light border" data-align="right" title="<?= e(__('Align right')) ?>" aria-label="<?= e(__('Align right')) ?>"><i class="bi bi-text-right"></i></button>
        </div>
        <label class="form-label small mb-1" for="pLine"><?= e(__('Line spacing')) ?></label>
        <input class="form-range mb-2" type="range" id="pLine" min="0.7" max="2.5" step="0.05" data-prop="lineHeight">
        <div class="form-check form-switch small"><input class="form-check-input" type="checkbox" id="pShadow"><label class="form-check-label" for="pShadow"><?= e(__('Shadow')) ?></label></div>
        <div class="d-flex gap-2 mb-2 align-items-center" data-when="pShadow">
          <input class="form-control form-control-sm form-control-color" type="color" id="pShadowColor" value="#000000" aria-label="<?= e(__('Shadow colour')) ?>">
          <input class="form-range" type="range" id="pShadowBlur" min="0" max="60" value="16" aria-label="<?= e(__('Shadow softness')) ?>">
        </div>
        <div class="form-check form-switch small"><input class="form-check-input" type="checkbox" id="pOutline"><label class="form-check-label" for="pOutline"><?= e(__('Outline')) ?></label></div>
        <div class="d-flex gap-2 mb-2 align-items-center" data-when="pOutline">
          <input class="form-control form-control-sm form-control-color" type="color" id="pOutlineColor" value="#000000" aria-label="<?= e(__('Outline colour')) ?>">
          <input class="form-range" type="range" id="pOutlineWidth" min="1" max="20" value="4" aria-label="<?= e(__('Outline width')) ?>">
        </div>
      </section>

      <section data-panel="shape" hidden>
        <div class="dz-group-label"><?= e(__('Shape')) ?></div>
        <div class="d-flex gap-2 mb-2">
          <div><label class="form-label small mb-1" for="sFill"><?= e(__('Fill')) ?></label><input class="form-control form-control-sm form-control-color" type="color" id="sFill" data-prop="fill"></div>
          <div><label class="form-label small mb-1" for="sStroke"><?= e(__('Border')) ?></label><input class="form-control form-control-sm form-control-color" type="color" id="sStroke" data-prop="stroke"></div>
          <div class="flex-grow-1"><label class="form-label small mb-1" for="sStrokeW"><?= e(__('Border width')) ?></label><input class="form-control form-control-sm" type="number" id="sStrokeW" min="0" max="80" data-prop="strokeWidth"></div>
        </div>
        <div data-only="Rect"><label class="form-label small mb-1" for="sRadius"><?= e(__('Corner radius')) ?></label><input class="form-range mb-2" type="range" id="sRadius" min="0" max="300"></div>
      </section>

      <section data-panel="common" hidden>
        <label class="form-label small mb-1" for="cOpacity"><?= e(__('Transparency')) ?></label>
        <input class="form-range mb-2" type="range" id="cOpacity" min="0.05" max="1" step="0.05">
        <div class="dz-group-label"><?= e(__('Arrange')) ?></div>
        <div class="btn-group btn-group-sm w-100 mb-2">
          <?= $btn('front', 'bi-front', __('Bring to front')) ?>
          <?= $btn('forward', 'bi-chevron-up', __('Bring forward')) ?>
          <?= $btn('backward', 'bi-chevron-down', __('Send backward')) ?>
          <?= $btn('back', 'bi-back', __('Send to back')) ?>
        </div>
        <div class="btn-group btn-group-sm w-100 mb-2" aria-label="<?= e(__('Position on the slide')) ?>">
          <?= $btn('align-left', 'bi-align-start', __('Left edge')) ?>
          <?= $btn('align-hcenter', 'bi-align-center', __('Centre horizontally')) ?>
          <?= $btn('align-right', 'bi-align-end', __('Right edge')) ?>
          <?= $btn('align-top', 'bi-align-top', __('Top edge')) ?>
          <?= $btn('align-vcenter', 'bi-align-middle', __('Centre vertically')) ?>
          <?= $btn('align-bottom', 'bi-align-bottom', __('Bottom edge')) ?>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm btn-light border flex-grow-1" data-act="duplicate"><i class="bi bi-copy"></i> <?= e(__('Duplicate')) ?></button>
          <button type="button" class="btn btn-sm btn-outline-danger flex-grow-1" data-act="delete"><i class="bi bi-trash"></i> <?= e(__('Delete')) ?></button>
        </div>
      </section>

      <div class="dz-group-label mt-3"><?= e(__('Layers')) ?></div>
      <ol class="dz-layers list-unstyled" id="dzLayers"></ol>
      <div class="form-check form-switch small mt-2"><input class="form-check-input" type="checkbox" id="dzSnap" checked><label class="form-check-label" for="dzSnap"><?= e(__('Snap to guides')) ?></label></div>
    </aside>
  </div>
</div>

<div class="modal fade" id="dzTplModal" tabindex="-1" aria-labelledby="dzTplTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="dzTplTitle"><i class="bi bi-grid-1x2"></i> <?= e(__('Templates')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body">
        <div id="dzMyTplWrap"<?= $hotelTemplates ? '' : ' hidden' ?>>
          <h3 class="h6"><?= e(__('My templates')) ?></h3>
          <div class="dz-tpl-grid mb-4" id="dzMyTpl"></div>
        </div>
        <h3 class="h6"><?= e(__('Ready-made templates')) ?></h3>
        <div class="dz-tpl-grid" id="dzStarterTpl"></div>
        <div class="mt-3"><button type="button" class="btn btn-light border" data-act="blank"><i class="bi bi-file-earmark"></i> <?= e(__('Blank slide')) ?></button></div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="dzLibModal" tabindex="-1" aria-labelledby="dzLibTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="dzLibTitle"><i class="bi bi-images"></i> <?= e(__('Content library images')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body"><div class="dz-lib-grid" id="dzLib"></div></div>
    </div>
  </div>
</div>

<div class="modal fade" id="dzSaveTplModal" tabindex="-1" aria-labelledby="dzSaveTplTitle" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="dzSaveTplForm">
      <div class="modal-header">
        <h2 class="modal-title h5" id="dzSaveTplTitle"><i class="bi bi-bookmark-plus"></i> <?= e(__('Save as template')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="dzTplName"><?= e(__('Template name')) ?></label>
        <input class="form-control" id="dzTplName" maxlength="190" required>
        <div class="form-text"><?= e(__('Your team can start new slides from this template. A template with the same name is replaced.')) ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save template')) ?></button>
      </div>
    </form>
  </div>
</div>

<script type="application/json" id="dzConfig"><?= json_embed($config) ?></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
