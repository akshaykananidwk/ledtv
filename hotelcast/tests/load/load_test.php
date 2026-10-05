<?php
/**
 * HotelCast load test — simulates many TVs polling at the same time.
 *
 *   php tests/load/load_test.php --url=https://hotel.com/hotelcast/ --key=REGISTRATIONKEY \
 *        [--tvs=80] [--interval=8] [--duration=60] [--rooms-prefix=9]
 *
 * Each simulated TV registers (rooms 9001, 9002 … are auto-created when "auto create rooms" is on),
 * then polls /api/device/command/{id}?hash=… every --interval seconds (with jitter, like real TVs)
 * and sends a heartbeat every 60 s. Prints latency percentiles and error counts.
 * Afterwards delete the test rooms in Admin → Rooms (filter "9").
 */
declare(strict_types=1);

$opt = getopt('', ['url:', 'key:', 'tvs::', 'interval::', 'duration::', 'rooms-prefix::']) + [
    'tvs' => 80, 'interval' => 8, 'duration' => 60, 'rooms-prefix' => '9',
];
if (empty($opt['url']) || empty($opt['key'])) {
    fwrite(STDERR, "Usage: php load_test.php --url=BASE_URL --key=REGISTRATION_KEY [--tvs=80] [--interval=8] [--duration=60]\n");
    exit(1);
}
$base = rtrim($opt['url'], '/') . '/api/';
$n = (int) $opt['tvs'];
$interval = max(1, (int) $opt['interval']);
$duration = max(5, (int) $opt['duration']);

function req(string $method, string $url, ?array $body, array $headers = []): CurlHandle
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    return $ch;
}

// 1. Register all TVs concurrently
echo "Registering $n TVs…\n";
$mh = curl_multi_init();
$tvs = [];
for ($i = 1; $i <= $n; $i++) {
    $uid = sprintf('loadtest-%04d-%s', $i, bin2hex(random_bytes(4)));
    $room = $opt['rooms-prefix'] . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    $ch = req('POST', $base . 'device/register', ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $opt['key'], 'app_version' => 'load']);
    $tvs[(int) $ch] = ['uid' => $uid, 'ch' => $ch];
    curl_multi_add_handle($mh, $ch);
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 1);
} while ($running);
$clients = [];
foreach ($tvs as $t) {
    $j = json_decode((string) curl_multi_getcontent($t['ch']), true);
    curl_multi_remove_handle($mh, $t['ch']);
    if (!empty($j['data']['token'])) {
        $clients[] = ['uid' => $t['uid'], 'token' => $j['data']['token'], 'hash' => '', 'next' => microtime(true) + mt_rand(0, $interval * 1000) / 1000, 'hb' => microtime(true) + 60];
    }
}
printf("Registered %d / %d\n", count($clients), $n);
if (!$clients) {
    exit(1);
}

// 2. Poll loop
$lat = [];
$errors = [];
$requests = 0;
$contentDownloads = 0;
$end = microtime(true) + $duration;
$inflight = [];
printf("Polling every %ds for %ds…\n", $interval, $duration);
while (microtime(true) < $end || $inflight) {
    $now = microtime(true);
    if ($now < $end) {
        foreach ($clients as $k => &$c) {
            if ($c['next'] <= $now && !isset($inflight[$k])) {
                $hdr = ['Authorization: Bearer ' . $c['token'], 'X-Device-Id: ' . $c['uid']];
                if ($c['hb'] <= $now) {
                    $ch = req('POST', $base . 'device/heartbeat', ['app_version' => 'load', 'battery' => -1, 'network_type' => 'wifi'], $hdr);
                    $c['hb'] = $now + 60;
                    $kind = 'heartbeat';
                } else {
                    $ch = req('GET', $base . 'device/command/' . rawurlencode($c['uid']) . '?hash=' . $c['hash'], null, $hdr);
                    $kind = 'poll';
                }
                curl_multi_add_handle($mh, $ch);
                $inflight[$k] = ['ch' => $ch, 'start' => $now, 'kind' => $kind];
                $c['next'] = $now + $interval;
            }
        }
        unset($c);
    }
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        foreach ($inflight as $k => $f) {
            if ($f['ch'] === $ch) {
                $ms = (microtime(true) - $f['start']) * 1000;
                $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $requests++;
                if ($code === 200) {
                    $lat[] = $ms;
                    if ($f['kind'] === 'poll') {
                        $j = json_decode((string) curl_multi_getcontent($ch), true);
                        if (!empty($j['data']['content_changed'])) {
                            $clients[$k]['hash'] = $j['data']['content_hash'];
                            $contentDownloads++;
                        }
                        foreach ($j['data']['commands'] ?? [] as $cmd) {
                            $ack = req('POST', $base . 'device/ack', ['command_id' => $cmd['id'], 'status' => 'acked'], ['Authorization: Bearer ' . $clients[$k]['token']]);
                            curl_exec($ack);
                        }
                    }
                } else {
                    $errors[$code ?: curl_error($ch)] = ($errors[$code ?: curl_error($ch)] ?? 0) + 1;
                }
                curl_multi_remove_handle($mh, $ch);
                unset($inflight[$k]);
                break;
            }
        }
    }
}

sort($lat);
$pct = fn (float $p) => $lat ? $lat[(int) min(count($lat) - 1, floor($p / 100 * count($lat)))] : 0;
$ok = count($lat);
echo "\n=== Results ===\n";
printf("Simulated TVs:      %d\n", count($clients));
printf("Requests:           %d (%.1f req/s)\n", $requests, $requests / $duration);
printf("Successful:         %d (%.2f%%)\n", $ok, $requests ? $ok / $requests * 100 : 0);
printf("Content downloads:  %d\n", $contentDownloads);
printf("Latency ms:         p50 %.0f · p90 %.0f · p95 %.0f · p99 %.0f · max %.0f\n", $pct(50), $pct(90), $pct(95), $pct(99), $lat ? end($lat) : 0);
printf("Errors:             %s\n", $errors ? json_encode($errors) : 'none');
exit($errors ? 2 : 0);
