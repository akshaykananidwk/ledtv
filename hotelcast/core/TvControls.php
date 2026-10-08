<?php
declare(strict_types=1);

/**
 * TV device controls (#4 #5 #15 #16): validated commands with the payloads the Android app expects
 * (V2_SPEC § TV contract) and the hotel settings read by DeviceControlsExtension.
 */
final class TvControls
{
    /** Commands of the TV controls page and their payload builders. */
    public const COMMANDS = ['SET_VOLUME', 'MUTE', 'UNMUTE', 'OPEN_INPUT', 'SHOW_WELCOME', 'SHOW_MESSAGE',
        // 2.4: spoken announcement and sound clip (payloads: DeviceSchedules / DeviceFeatures).
        'SPEAK', 'PLAY_SOUND'];
    public const OPEN_INPUTS = ['live_tv', 'hdmi1', 'hdmi2', 'hdmi3', 'hdmi4'];

    /** Payload for a command from form / JSON input. Throws InvalidArgumentException. */
    public static function payload(string $command, array $in): array
    {
        switch ($command) {
            case 'SET_VOLUME':
                $level = $in['level'] ?? null;
                if (!is_numeric($level) || (int) $level < 0 || (int) $level > 100) {
                    throw new InvalidArgumentException(__('Volume must be between 0 and 100.'));
                }
                return ['level' => (int) $level];
            case 'OPEN_INPUT':
                $input = (string) ($in['input'] ?? '');
                if (!in_array($input, self::OPEN_INPUTS, true)) {
                    throw new InvalidArgumentException(__('Choose Live TV or an HDMI input.'));
                }
                return ['input' => $input];
            case 'SHOW_MESSAGE':
                $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
                $message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 1000);
                if ($title === '' && $message === '') {
                    throw new InvalidArgumentException(__('Enter a title or message.'));
                }
                $dur = is_numeric($in['duration_sec'] ?? null) ? (int) $in['duration_sec'] : 15;
                $p = ['title' => $title, 'message' => $message, 'duration_sec' => max(3, min(3600, $dur))];
                $sound = self::messageSound($in);
                if ($sound !== null) {
                    $p['sound'] = $sound; // 2.4.1; older apps ignore it
                }
                return $p;
            case 'SPEAK':
                return DeviceSchedules::speakPayload($in);
            case 'PLAY_SOUND':
                return DeviceFeatures::playSoundPayload($in);
            case 'MUTE':
            case 'UNMUTE':
            case 'SHOW_WELCOME':
                return [];
        }
        throw new InvalidArgumentException(__('Unknown command.'));
    }

    /**
     * Optional sound of a SHOW_MESSAGE (2.4.1): played when the message appears. Input `sound` = "" / "none"
     * (default: no sound), "b:notice_chime" or any sound of the hotel's library ("u:12"), `sound_repeat`
     * 1–5 (default 1), `sound_volume` 0–100 (default 80); or `sound` = {ref, repeat, volume} (JSON callers).
     * Returns {url, repeat, volume} or null. Only the hotel's own library (Sounds::libraryRef, the PLAY_SOUND
     * rule); another hotel's sound id → 404. Throws InvalidArgumentException.
     */
    public static function messageSound(array $in): ?array
    {
        $raw = $in['sound'] ?? '';
        if (is_array($raw)) {
            $in = ['sound_repeat' => $raw['repeat'] ?? null, 'sound_volume' => $raw['volume'] ?? null];
            $raw = $raw['ref'] ?? $raw['sound'] ?? '';
        }
        $ref = trim((string) $raw);
        if ($ref === '' || $ref === 'none') {
            return null;
        }
        $s = Sounds::libraryRef($ref);
        $rep = $in['sound_repeat'] ?? 1;
        $vol = $in['sound_volume'] ?? 80;
        $rep = $rep === '' || $rep === null ? 1 : $rep;
        $vol = $vol === '' || $vol === null ? 80 : $vol;
        if (!is_numeric($rep) || (int) $rep < 1 || (int) $rep > 5) {
            throw new InvalidArgumentException(__('Repeat must be between 1 and 5 times.'));
        }
        if (!is_numeric($vol) || (int) $vol < 0 || (int) $vol > 100) {
            throw new InvalidArgumentException(__('Volume must be between 0 and 100.'));
        }
        return ['url' => $s['url'], 'repeat' => (int) $rep, 'volume' => (int) $vol];
    }

    /**
     * Queue a command for the targeted rooms (Broadcaster::sendCommand, current hotel).
     * Returns [broadcastId, deviceCount].
     */
    public static function send(string $command, string $targetType, array $ids, array $in, ?int $userId = null): array
    {
        if (!in_array($command, self::COMMANDS, true)) {
            throw new InvalidArgumentException(__('Unknown command.'));
        }
        if ($targetType !== 'all' && !$ids) {
            throw new InvalidArgumentException(__('Select at least one target.'));
        }
        $payload = self::payload($command, $in);
        [$bid, $count] = Broadcaster::sendCommand($command, $targetType, $ids, $payload, $userId);
        ActivityLog::add('device_command', 'broadcast', $bid, $command . ($payload ? ' ' . json_out($payload) : '') . ' → ' . Broadcaster::describeTarget($targetType, $ids) . " ($count TVs)");
        return [$bid, $count];
    }

    /** Validate + save the volume policy (current hotel). Returns errors. */
    public static function saveVolume(array $in): array
    {
        $errors = [];
        $pct = static function (string $key, bool $optional) use ($in, &$errors): ?string {
            $v = trim((string) ($in[$key] ?? ''));
            if ($v === '' && $optional) {
                return '';
            }
            if (!preg_match('/^\d{1,3}$/', $v) || (int) $v > 100) {
                $errors[] = __('Volume must be between 0 and 100.');
                return null;
            }
            return (string) (int) $v;
        };
        $values = [
            'tv_volume_enabled' => !empty($in['tv_volume_enabled']) ? '1' : '0',
            'volume_default' => $pct('volume_default', true),
            'volume_max' => $pct('volume_max', false),
            'volume_night_enabled' => !empty($in['volume_night_enabled']) ? '1' : '0',
            'volume_night_max' => $pct('volume_night_max', false),
        ];
        foreach (['volume_night_from' => '22:00', 'volume_night_to' => '06:00'] as $k => $def) {
            $t = Broadcaster::parseTime((string) ($in[$k] ?? ''));
            if (!$t) {
                $errors[] = __('Enter night times as HH:MM.');
            }
            $values[$k] = $t ? substr($t, 0, 5) : $def;
        }
        if ($values['volume_night_enabled'] === '1' && $values['volume_night_from'] === $values['volume_night_to']) {
            $errors[] = __('Night start and end must be different.');
        }
        if ($errors) {
            return array_values(array_unique($errors));
        }
        Settings::setMany($values);
        Settings::bumpContentVersion();
        return [];
    }

    /** Validate + save guest menu settings (Live TV, HDMI inputs, Cast, Wi-Fi). Returns errors. */
    public static function saveMenu(array $in): array
    {
        $inputs = [];
        foreach (DeviceControlsExtension::INPUTS as $hdmi) {
            if (!empty($in['input_' . $hdmi])) {
                $inputs[$hdmi] = mb_substr(trim(strip_tags((string) ($in['label_' . $hdmi] ?? ''))), 0, 40);
            }
        }
        $values = [
            'guest_menu_live_tv' => !empty($in['guest_menu_live_tv']) ? '1' : '0',
            'guest_menu_inputs' => $inputs ? json_out((object) $inputs) : '',
            'guest_menu_cast' => !empty($in['guest_menu_cast']) ? '1' : '0',
        ];
        foreach (array_keys(I18n::GUEST_LANGUAGES) as $l) {
            $values['guest_cast_text_' . $l] = mb_substr(trim(strip_tags((string) ($in['guest_cast_text_' . $l] ?? ''))), 0, 500);
        }
        // Wi-Fi details are shared with the guests module (welcome card): only written when posted.
        if (array_key_exists('wifi_ssid', $in)) {
            $ssid = mb_substr(trim((string) $in['wifi_ssid']), 0, 64);
            $pw = mb_substr(trim((string) ($in['wifi_password'] ?? '')), 0, 64);
            if (preg_match('/[\x00-\x1f]/', $ssid . $pw)) {
                return [__('The Wi-Fi name or password contains invalid characters.')];
            }
            $values['wifi_ssid'] = $ssid;
            $values['wifi_password'] = $pw;
        }
        Settings::setMany($values);
        Settings::bumpContentVersion();
        return [];
    }
}
