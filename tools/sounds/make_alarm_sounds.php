<?php
/**
 * Generates the built-in alarm / notice sounds of 2.4.1 (hotelcast/assets/sounds/*.wav).
 * Pure synthesis (no samples, no third-party audio): royalty-free, reproducible.
 *   php tools/sounds/make_alarm_sounds.php [output dir]
 *
 * Format: mono, 22 050 Hz, 16-bit PCM WAV. Loops start and end at a zero crossing (sine at phase 0 or
 * silence after a short fade), so they repeat without a click. Loudness: every sound is scaled so the RMS
 * of its audible part is -6 dBFS, with the peak capped at -1 dBFS.
 */
declare(strict_types=1);

const SR = 22050;

$out = rtrim($argv[1] ?? __DIR__ . '/../../hotelcast/assets/sounds', '/');

/** Raised-cosine gain for a fade of $len samples at both ends of a segment of $n samples. */
function edge(int $i, int $n, int $len): float
{
    if ($i < $len) {
        return 0.5 - 0.5 * cos(M_PI * $i / $len);
    }
    if ($i >= $n - $len) {
        return 0.5 - 0.5 * cos(M_PI * ($n - 1 - $i) / $len);
    }
    return 1.0;
}

/** Tone burst: sine + a little 3rd harmonic (cuts through TV speakers), 6 ms fades. */
function burst(array &$buf, float $start, float $dur, float $freq): void
{
    $s0 = (int) round($start * SR);
    $n = (int) round($dur * SR);
    $fade = (int) (0.006 * SR);
    for ($i = 0; $i < $n; $i++) {
        $t = $i / SR;
        $v = sin(2 * M_PI * $freq * $t) + 0.22 * sin(2 * M_PI * 3 * $freq * $t);
        $buf[$s0 + $i] += $v * edge($i, $n, $fade);
    }
}

/** 1 kHz "beep-beep", 2.0 s: two 0.22 s beeps, then silence (loop = beep-beep … beep-beep). */
function beep(): array
{
    $buf = array_fill(0, (int) (2.0 * SR), 0.0);
    burst($buf, 0.00, 0.22, 1000.0);
    burst($buf, 0.36, 0.22, 1000.0);
    burst($buf, 1.00, 0.22, 1000.0);
    burst($buf, 1.36, 0.22, 1000.0);
    return $buf;
}

/**
 * Wail siren, 3.0 s: frequency 600 → 1200 → 600 Hz (raised cosine). The phase is integrated analytically;
 * the mean frequency (900 Hz) × 3 s = 2700 whole cycles, so the last sample runs straight into the first.
 */
function siren(): array
{
    $T = 3.0;
    $n = (int) round($T * SR);
    $buf = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $i / SR;
        // φ(t) = 2π (900 t − 300 T/(2π) sin(2π t/T))
        $phi = 2 * M_PI * (900 * $t - 300 * $T / (2 * M_PI) * sin(2 * M_PI * $t / $T));
        $buf[] = sin($phi) + 0.25 * sin(3 * $phi) + 0.1 * sin(5 * $phi);
    }
    return $buf;
}

/** Fire alarm "whoop": 4 fast rising sweeps 500 → 1300 Hz (0.42 s each + 0.08 s gap) = 2.0 s. */
function whoop(): array
{
    $buf = array_fill(0, (int) (2.0 * SR), 0.0);
    $dur = 0.42;
    $n = (int) round($dur * SR);
    $fade = (int) (0.008 * SR);
    for ($k = 0; $k < 4; $k++) {
        $s0 = (int) round($k * 0.5 * SR);
        $phase = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = $i / $n;
            $f = 500 + 800 * $x * $x * (3 - 2 * $x) * 0.6 + 800 * 0.4 * $x;
            $phase += 2 * M_PI * $f / SR;
            $v = sin($phase) + 0.3 * sin(2 * $phase) + 0.15 * sin(3 * $phase);
            $buf[$s0 + $i] += $v * edge($i, $n, $fade);
        }
    }
    return $buf;
}

/** Pleasant 3-tone "ding-dong-ding" (E6, C6, G5 bell-like partials, exponential decay), 2.4 s. */
function chime(): array
{
    $total = (int) (2.4 * SR);
    $buf = array_fill(0, $total, 0.0);
    $notes = [[0.00, 1318.51], [0.40, 1046.50], [0.80, 783.99]];
    foreach ($notes as [$start, $f]) {
        $s0 = (int) round($start * SR);
        $n = $total - $s0;
        $attack = (int) (0.004 * SR);
        for ($i = 0; $i < $n; $i++) {
            $t = $i / SR;
            $env = exp(-3.2 * $t) * min(1.0, $i / $attack);
            $v = sin(2 * M_PI * $f * $t) + 0.35 * sin(2 * M_PI * 2.0 * $f * $t) * exp(-4 * $t) + 0.12 * sin(2 * M_PI * 3.01 * $f * $t) * exp(-6 * $t);
            $buf[$s0 + $i] += $v * $env;
        }
    }
    // Fade the tail to exact silence.
    $fade = (int) (0.25 * SR);
    for ($i = 0; $i < $fade; $i++) {
        $buf[$total - 1 - $i] *= $i / $fade;
    }
    return $buf;
}

/** RMS of the audible part → -6 dBFS, peak ≤ -1 dBFS. */
function normalize(array $buf): array
{
    $peak = 0.0;
    $sum = 0.0;
    $cnt = 0;
    foreach ($buf as $v) {
        $a = abs($v);
        $peak = max($peak, $a);
        if ($a > 1e-3) {
            $sum += $v * $v;
            $cnt++;
        }
    }
    $rms = $cnt ? sqrt($sum / $cnt) : 1.0;
    $gain = min(10 ** (-6 / 20) / $rms, 10 ** (-1 / 20) / max($peak, 1e-9));
    return array_map(static fn ($v) => $v * $gain, $buf);
}

function wav(string $file, array $buf): void
{
    $pcm = '';
    foreach ($buf as $v) {
        $pcm .= pack('v', (int) round(max(-1.0, min(1.0, $v)) * 32767) & 0xffff);
    }
    $hdr = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, SR, SR * 2, 2, 16)
        . 'data' . pack('V', strlen($pcm));
    file_put_contents($file, $hdr . $pcm);
    printf("%-22s %6.2f s %7d bytes\n", basename($file), count($buf) / SR, strlen($hdr . $pcm));
}

foreach (['emergency_beep' => beep(), 'emergency_siren' => siren(), 'fire_alarm' => whoop(), 'notice_chime' => chime()] as $name => $buf) {
    wav($out . '/' . $name . '.wav', normalize($buf));
}
