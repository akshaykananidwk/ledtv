<?php
declare(strict_types=1);

/**
 * Token / queue display (hospital, clinic, bank, office): the token at every counter in big digits,
 * the last calls, the waiting count per service and an optional QR code for self-service tokens.
 * A new call blinks for 5 s with a chime (assets/display/apps/queue_display_chime.wav, generated,
 * no third-party audio) and an optional spoken announcement (speechSynthesis, when the WebView has
 * it). Data: core/Queue.php::display(), refreshed every 2 s.
 */
final class QueueDisplayApp extends DisplayApp
{
    public function key(): string
    {
        return 'queue_display';
    }

    public function label(): string
    {
        return __('Token display');
    }

    public function description(): string
    {
        return __('Queue / token numbers for clinics, hospitals, banks and offices: now serving at each counter, chime and voice announcement.');
    }

    public function icon(): string
    {
        return 'bi-people';
    }

    public function category(): string
    {
        return 'business';
    }

    public function adminPage(): ?string
    {
        return admin_url('queue.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'services' => [],
            'show_recent' => true,
            'show_waiting' => true,
            'show_qr' => true,
            'chime' => true,
            'speak' => true,
            'show_clock' => true,
        ];
    }

    public function validate(array $in): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter((array) ($in['services'] ?? []), 'is_scalar')), static fn ($v) => $v > 0)));
        // Another hotel's service id → 404 (Tenant::deny); unknown ids are dropped.
        $ids = $ids ? array_values(array_intersect($ids, Tenant::assertOwnsAll('queue_services', $ids))) : [];
        return [[
            'heading' => self::str($in, 'heading', 120),
            'services' => $ids,
            'show_recent' => self::bool($in, 'show_recent'),
            'show_waiting' => self::bool($in, 'show_waiting'),
            'show_qr' => self::bool($in, 'show_qr'),
            'chime' => self::bool($in, 'chime'),
            'speak' => self::bool($in, 'speak'),
            'show_clock' => self::bool($in, 'show_clock'),
        ], []];
    }

    public function form(array $config): string
    {
        $svc = [];
        foreach (Queue::services() as $s) {
            $svc[(int) $s['id']] = (string) $s['name'];
        }
        $h = self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Now serving')]);
        if ($svc) {
            $h .= self::checkboxes('services', __('Show these services (none ticked = all)'), $svc, $config['services']);
        } else {
            $h .= '<div class="col-12"><div class="alert alert-info mb-0">' . e(__('No services yet. Add services and counters on the Token queue page.')) . '</div></div>';
        }
        return $h
            . self::checkbox('show_recent', __('Show the last calls'), (bool) $config['show_recent'])
            . self::checkbox('show_waiting', __('Show waiting counts'), (bool) $config['show_waiting'])
            . self::checkbox('show_qr', __('Show a QR code for self-service tokens'), (bool) $config['show_qr'])
            . self::checkbox('chime', __('Chime on a new call'), (bool) $config['chime'])
            . self::checkbox('speak', __('Spoken announcement (when the TV supports it)'), (bool) $config['speak'])
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock']);
    }

    private function state(array $config, array $ctx): array
    {
        $d = Queue::display(array_map('intval', (array) $config['services']));
        if (!$d['counters'] && !empty($ctx['preview'])) {
            $d = [
                'counters' => [
                    ['id' => -1, 'name' => __('Counter :n', ['n' => 1]), 'room' => '', 'service' => __('OPD'), 'token' => 'A-024', 'key' => '', 'status' => 'serving', 'say' => ''],
                    ['id' => -2, 'name' => __('Counter :n', ['n' => 2]), 'room' => '', 'service' => __('OPD'), 'token' => 'A-025', 'key' => '', 'status' => 'called', 'say' => ''],
                    ['id' => -3, 'name' => __('Counter :n', ['n' => 3]), 'room' => '', 'service' => __('Cash counter'), 'token' => '', 'key' => '', 'status' => '', 'say' => ''],
                ],
                'recent' => [['token' => 'A-025', 'counter' => __('Counter :n', ['n' => 2])], ['token' => 'A-024', 'counter' => __('Counter :n', ['n' => 1])]],
                'services' => [['id' => 0, 'name' => __('OPD'), 'waiting' => 7], ['id' => 0, 'name' => __('Cash counter'), 'waiting' => 2]],
            ];
        }
        return $d;
    }

    /** Self-service services shown as QR codes (max 2). */
    private function qrServices(array $config): array
    {
        if (!$config['show_qr']) {
            return [];
        }
        $out = [];
        foreach (Queue::services(true) as $s) {
            if ((int) $s['self_service'] && (!$config['services'] || in_array((int) $s['id'], array_map('intval', (array) $config['services']), true))) {
                $out[] = $s;
                if (count($out) >= 2) {
                    break;
                }
            }
        }
        return $out;
    }

    public function data(array $config, array $ctx): ?array
    {
        return $this->state($config, $ctx) + [
            'chime' => (bool) $config['chime'],
            'speak' => (bool) $config['speak'],
            'speech_lang' => ['gu' => 'gu-IN', 'hi' => 'hi-IN'][$ctx['lang']] ?? 'en-IN',
        ];
    }

    public function refreshSec(array $config): int
    {
        return 2;
    }

    /** Same markup as assets/display/apps/queue_display.js → counterHtml(). */
    public static function counterHtml(array $c): string
    {
        return '<div class="qd-counter hc-card' . ($c['token'] !== '' ? ' has-token' : '') . '" data-counter="' . (int) $c['id'] . '">'
            . '<div class="qd-cname">' . e($c['name']) . '</div>'
            . '<div class="qd-token">' . ($c['token'] !== '' ? e($c['token']) : '—') . '</div>'
            . '<div class="qd-csub">' . e(dot_trim($c['service'] . ($c['room'] !== '' ? ' · ' . $c['room'] : ''))) . '</div></div>';
    }

    public function render(array $config, array $ctx): string
    {
        $d = $this->state($config, $ctx);
        $heading = $config['heading'] !== '' ? $config['heading'] : __('Now serving');
        $h = '<div class="hc-header">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="hc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<div class="hc-title"><h1>' . e($heading) . '</h1></div>';
        if ($config['show_clock']) {
            $h .= '<div><div class="hc-clock" data-hc-clock="12"></div><div class="hc-date" data-hc-date></div></div>';
        }
        $h .= '</div><div class="hc-body qd-body">';
        $h .= '<div class="qd-main"><div class="qd-counters" id="qdCounters">';
        foreach ($d['counters'] as $c) {
            $h .= self::counterHtml($c);
        }
        if (!$d['counters']) {
            $h .= '<div class="hc-empty">' . e(__('No counters yet.')) . '</div>';
        }
        $h .= '</div></div>';
        $side = '';
        if ($config['show_recent']) {
            $side .= '<div class="qd-panel qd-list hc-card"><div class="qd-ptitle">' . e(__('Last called')) . '</div><div id="qdRecent">';
            foreach ($d['recent'] as $r) {
                $side .= '<div class="qd-row"><b>' . e($r['token']) . '</b><span>' . e($r['counter']) . '</span></div>';
            }
            $side .= '</div></div>';
        }
        if ($config['show_waiting']) {
            $side .= '<div class="qd-panel qd-list hc-card"><div class="qd-ptitle">' . e(__('Waiting')) . '</div><div id="qdWaiting">';
            foreach ($d['services'] as $s) {
                $side .= '<div class="qd-row"><span>' . e($s['name']) . '</span><b>' . (int) $s['waiting'] . '</b></div>';
            }
            $side .= '</div></div>';
        }
        $qrs = $this->qrServices($config);
        if (count($qrs) === 1) {
            $side .= '<div class="qd-panel hc-card qd-qr"><div class="qd-qr-img">' . QrCode::svg(Queue::publicUrl($qrs[0])) . '</div>'
                . '<div class="qd-qr-text"><b>' . e(__('Get your token on your phone')) . '</b><span>' . e($qrs[0]['name']) . '</span></div></div>';
        } elseif ($qrs) { // two services: one panel with the codes side by side, so the lists keep their room
            $side .= '<div class="qd-panel hc-card qd-qr qd-qr2"><div class="qd-qr-text"><b>' . e(__('Get your token on your phone')) . '</b></div><div class="qd-qr-pair">';
            foreach ($qrs as $s) {
                $side .= '<div class="qd-qr-cell"><div class="qd-qr-img">' . QrCode::svg(Queue::publicUrl($s)) . '</div><span>' . e($s['name']) . '</span></div>';
            }
            $side .= '</div></div>';
        }
        if ($side !== '') {
            $h .= '<div class="qd-side">' . $side . '</div>';
        }
        $h .= '</div><div class="qd-flash" id="qdFlash" style="display:none"><div class="qd-flash-token" id="qdFlashToken"></div><div class="qd-flash-arrow">→</div><div class="qd-flash-counter" id="qdFlashCounter"></div></div>';
        if ($config['chime']) {
            $h .= '<audio id="qdChime" preload="auto" src="' . e(asset('display/apps/queue_display_chime.wav')) . '"></audio>';
        }
        return $h;
    }
}
