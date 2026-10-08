<?php
declare(strict_types=1);

/**
 * 2.4 device features (docs/modules/device_features.md): per-room USB mode (#42) and HDMI-CEC
 * mode (#50), sent to the TV in the Content object by DeviceFeaturesExtension, and the PLAY_SOUND
 * payload. SPEAK payloads / "Announce" use DeviceSchedules::speakPayload() / announce() (same
 * Broadcaster command path as the device-schedules module).
 */
final class DeviceFeatures
{
    /** auto = the TV detects box / panel; box = Android box driving the TV over HDMI; tv = built-in panel. */
    public const CEC_MODES = ['auto', 'box', 'tv'];

    /** Room flags of a room row. */
    public static function roomFlags(array $room): array
    {
        $cec = (string) ($room['cec_mode'] ?? 'auto');
        return [
            'usb_mode' => !empty($room['usb_mode']),
            'cec_mode' => in_array($cec, self::CEC_MODES, true) ? $cec : 'auto',
        ];
    }

    /**
     * Save USB / CEC mode of a room (current hotel). Caller checks rooms.manage; Access is checked here.
     * Throws InvalidArgumentException on bad input.
     */
    public static function saveRoomFlags(int $roomId, array $in): array
    {
        $room = Tenant::find('rooms', $roomId);
        if (!$room) {
            throw new InvalidArgumentException(__('Room not found.'));
        }
        Access::requireRoom($roomId);
        $cec = (string) ($in['cec_mode'] ?? 'auto');
        if (!in_array($cec, self::CEC_MODES, true)) {
            throw new InvalidArgumentException(__('Choose a valid CEC mode.'));
        }
        $flags = ['usb_mode' => !empty($in['usb_mode']) && $in['usb_mode'] !== '0' ? 1 : 0, 'cec_mode' => $cec];
        DB::update('rooms', $flags, 'id = :id', ['id' => $roomId]);
        Settings::bumpContentVersion();
        ActivityLog::add('room_device_features', 'room', $roomId, 'usb_mode=' . $flags['usb_mode'] . ' cec_mode=' . $cec);
        return self::roomFlags($flags);
    }

    /**
     * PLAY_SOUND payload {url, volume, repeat}. Throws InvalidArgumentException.
     * Security (2.4 review): the TV fetches this URL from inside the hotel network, so only sounds of the
     * current hotel's library are accepted — a `sound` reference ("b:school_bell" / "u:12", Sounds::resolve)
     * or exactly the URL of one of them. Any other URL (intranet hosts, router admin pages, other sites)
     * is refused, so admin users cannot use the TVs as a request proxy into the LAN.
     */
    public static function playSoundPayload(array $in): array
    {
        $ref = trim((string) ($in['sound'] ?? ''));
        $url = trim((string) ($in['url'] ?? ''));
        if ($ref !== '') {
            $url = (string) (Sounds::resolve($ref)['url'] ?? ''); // another hotel's sound id → 404
        }
        if ($url === '' || strlen($url) > 2048 || !ContentManager::validUrl($url, ['http', 'https'])) {
            throw new InvalidArgumentException(__('Enter a valid sound URL (http or https).'));
        }
        if (!in_array($url, array_column(Sounds::all(), 'url'), true)) {
            throw new InvalidArgumentException(__('Choose a sound from the sound library (Device schedules → Sounds).'));
        }
        $vol = $in['volume'] ?? 100;
        $rep = $in['repeat'] ?? 1;
        if (!is_numeric($vol) || (int) $vol < 0 || (int) $vol > 100) {
            throw new InvalidArgumentException(__('Volume must be between 0 and 100.'));
        }
        if (!is_numeric($rep) || (int) $rep < 1 || (int) $rep > 10) {
            throw new InvalidArgumentException(__('Repeat must be between 1 and 10 times.'));
        }
        return ['url' => $url, 'volume' => (int) $vol, 'repeat' => (int) $rep];
    }
}
