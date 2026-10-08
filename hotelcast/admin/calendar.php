<?php
/**
 * Calendar (2.4, #31): month / week / day view of everything scheduled — content schedules, TV power
 * schedules, content start / expiry dates, holidays and device schedules. Drag & drop moves schedules
 * and holidays, selecting a time range adds a schedule. Data: admin/ajax.d/calendar.php, core/Calendar.php.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

$user = Auth::require('schedule.manage');
Scheduler::tick();

$pageTitle = __('Calendar');
$activeNav = 'calendar';
$extraScripts = ['vendor/fullcalendar/index.global.min.js'];
$legend = [
    ['schedule', __('Content schedule')], ['schedule_active', __('Running now')], ['power', __('TV power')],
    ['content_start', __('Content starts')], ['content_end', __('Content expires')],
    ['holiday', __('Holiday')], ['holiday_off', __('Holiday · TVs off')], ['device', __('Device schedule')],
];
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e(__('Calendar')) ?></h1>
    <p class="lead-sm"><?= e(__('Everything that is planned for your TVs. Drag an entry to move it, select a time to add a schedule, click an entry to edit it.')) ?></p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-light border" href="<?= e(admin_url('schedule.php')) ?>"><i class="bi bi-list-ul"></i> <?= e(__('Schedule list')) ?></a>
    <?php if (Auth::can('holidays.manage')): ?><a class="btn btn-light border" href="<?= e(admin_url('holidays.php')) ?>"><i class="bi bi-balloon"></i> <?= e(__('Holidays')) ?></a><?php endif; ?>
    <button type="button" class="btn btn-primary" id="calAddBtn"><i class="bi bi-plus-lg"></i> <?= e(__('Add schedule')) ?></button>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap gap-2 small mb-2" aria-label="<?= e(__('Colours')) ?>">
      <?php foreach ($legend as [$k, $lbl]): ?>
        <span class="d-inline-flex align-items-center gap-1"><span class="rounded" style="display:inline-block;width:12px;height:12px;background:<?= e(Calendar::COLORS[$k]) ?>"></span><?= e($lbl) ?></span>
      <?php endforeach; ?>
    </div>
    <div id="hcCalendar"></div>
    <div class="form-text mt-2"><i class="bi bi-info-circle"></i> <?= e(__('Times are local time (:tz). Repeating schedules move as a whole series: moving one day moves every repeat. To change only one day, add a separate schedule for it.', ['tz' => date_default_timezone_get()])) ?></div>
  </div>
</div>

<div class="modal fade" id="calAddModal" tabindex="-1" aria-labelledby="calAddTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="calAddForm">
      <div class="modal-header">
        <h2 class="modal-title h5" id="calAddTitle"><i class="bi bi-calendar-plus"></i> <?= e(__('Add schedule')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('Close')) ?>"></button>
      </div>
      <div class="modal-body row g-3">
        <div class="col-md-6">
          <label class="form-label" for="calSource"><?= e(__('What to show')) ?> *</label>
          <?= source_select('source', '', __('— Choose content or playlist —'), ['id' => 'calSource', 'required' => 'required']) ?>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="calTitle"><?= e(__('Name (optional)')) ?></label>
          <input class="form-control" id="calTitle" name="title" maxlength="190">
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(__('On which TVs')) ?></label>
          <div id="calTarget"><?= target_picker('cal') ?></div>
        </div>
        <div class="col-12">
          <div class="btn-group" role="group">
            <input type="radio" class="btn-check" name="mode" value="window" id="calModeWindow" checked>
            <label class="btn btn-outline-primary" for="calModeWindow"><?= e(__('Show between start and end')) ?></label>
            <input type="radio" class="btn-check" name="mode" value="once" id="calModeOnce">
            <label class="btn btn-outline-primary" for="calModeOnce"><?= e(__('Switch at start time (stays)')) ?></label>
          </div>
        </div>
        <div class="col-sm-6">
          <label class="form-label" for="calStart"><?= e(__('Start at')) ?> *</label>
          <input type="datetime-local" class="form-control" id="calStart" name="start" required>
        </div>
        <div class="col-sm-6" data-cal-end>
          <label class="form-label" for="calEnd"><?= e(__('Until')) ?> *</label>
          <input type="datetime-local" class="form-control" id="calEnd" name="end">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal"><?= e(__('Cancel')) ?></button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= e(__('Save')) ?></button>
      </div>
    </form>
  </div>
</div>

<script type="application/json" id="calData"><?= json_embed([
    'now' => date('Y-m-d\TH:i:s'),
    'lang' => I18n::lang(),
    't' => [
        'today' => __('Today'), 'month' => __('Month'), 'week' => __('Week'), 'day' => __('Day'), 'list' => __('List'),
        'none' => __('Nothing scheduled'), 'saved' => __('Saved.'),
        'series' => __('This entry repeats. Moving it changes the whole series (every repeat), not only this day. Continue?'),
        'readonly' => __('This entry cannot be moved here. Click it to edit it on its own page.'),
    ],
]) ?></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('hcCalendar');
  if (!window.FullCalendar || !el) return;
  const D = JSON.parse(document.getElementById('calData').textContent);
  const T = D.t;
  const mobile = window.matchMedia('(max-width: 767px)').matches;
  const modalEl = document.getElementById('calAddModal');
  const form = document.getElementById('calAddForm');
  const pad = (n) => (n < 10 ? '0' : '') + n;
  // FullCalendar runs in "UTC" so that it shows the hotel's wall-clock time: UTC parts = hotel time.
  const local = (d) => d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + 'T' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
  const syncMode = () => {
    const once = form.querySelector('input[name=mode]:checked').value === 'once';
    form.querySelector('[data-cal-end]').hidden = once;
    form.querySelector('#calEnd').required = !once;
  };
  form.querySelectorAll('input[name=mode]').forEach((r) => r.addEventListener('change', syncMode));
  const openAdd = (start, end) => {
    form.reset();
    form.querySelector('#calStart').value = start ? local(start) : '';
    form.querySelector('#calEnd').value = end ? local(end) : '';
    syncMode();
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  };
  const cal = new FullCalendar.Calendar(el, {
    timeZone: 'UTC',
    now: D.now,
    initialView: mobile ? 'listWeek' : 'timeGridWeek',
    headerToolbar: mobile ? { left: 'prev,next', center: 'title', right: 'timeGridDay,listWeek,dayGridMonth' } : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
    firstDay: 1,
    nowIndicator: true,
    height: mobile ? 'auto' : 720,
    locale: D.lang === 'gu' ? 'gu' : 'en',
    buttonText: { today: T.today, month: T.month, week: T.week, day: T.day, list: T.list },
    noEventsText: T.none,
    selectable: true,
    selectMirror: true,
    editable: true,
    eventDurationEditable: true,
    select: (info) => { openAdd(info.start, info.allDay ? null : info.end); cal.unselect(); },
    events: (info, ok, fail) => {
      HC.api('calendar_events', { params: { start: info.startStr, end: info.endStr } })
        .then((d) => ok(d.events)).catch((e) => { HC.toast(e.message, 'danger'); fail(e); });
    },
    eventDidMount: (info) => {
      const p = info.event.extendedProps;
      const tip = [info.event.title, p.source || ''].filter(Boolean).join('\n');
      info.el.setAttribute('title', tip);
    },
  });
  const move = async (info) => {
    const ev = info.event;
    const p = ev.extendedProps;
    if (!['schedule', 'holiday'].includes(p.kind)) { HC.toast(T.readonly, 'warning'); info.revert(); return; }
    if (p.repeating && !(await HC.confirm(T.series, { danger: false }))) { info.revert(); return; }
    try {
      await HC.api('calendar_move', { data: { kind: p.kind, id: p.ref, start: ev.startStr, end: ev.endStr || '', orig_start: info.oldEvent.startStr } });
      HC.toast(T.saved);
    } catch (e) {
      HC.toast(e.message, 'danger');
      info.revert();
    }
    cal.refetchEvents();
  };
  cal.setOption('eventDrop', move);
  cal.setOption('eventResize', move);
  cal.render();

  document.getElementById('calAddBtn').addEventListener('click', () => {
    const s = new Date(Date.parse(D.now + 'Z') + 3600000);
    s.setUTCMinutes(0, 0, 0);
    openAdd(s, new Date(s.getTime() + 3600000));
  });
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    const mode = form.querySelector('input[name=mode]:checked').value;
    const data = Object.assign({
      source: form.querySelector('#calSource').value,
      title: form.querySelector('#calTitle').value,
      start: form.querySelector('#calStart').value,
      end: mode === 'once' ? '' : form.querySelector('#calEnd').value,
      mode,
    }, HC.targetFrom(document.getElementById('calTarget')));
    try {
      await HC.api('calendar_add', { data });
      bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      HC.toast(T.saved);
      cal.refetchEvents();
    } catch (e) {
      HC.toast(e.message, 'danger');
    }
    btn.disabled = false;
  });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
