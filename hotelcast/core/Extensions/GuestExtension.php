<?php
declare(strict_types=1);

/**
 * Guests & guest services in the TV Content object (V2_SPEC § TV contract):
 *   guest, welcome (#1), checkout_reminder (#6), services + guest_menu QR items (#2 #3 #8),
 *   and check-in mode (#9): a vacant room is switched off (mode "off", off_reason "vacant") or shows
 *   the idle welcome screen (mode "empty"). Emergencies, a suspended hotel and rooms that are already
 *   off are never overridden. Texts are in the guest's language (en / gu / hi).
 */
final class GuestExtension implements ContentExtension
{
    /** Runs first: sets the guest (and language) that other extensions use for labels. */
    public const PRIORITY = 10;

    public function apply(array &$content, array $room): void
    {
        $mode = (string) ($content['mode'] ?? '');
        if ($mode === 'suspended' || $mode === 'emergency' || empty($room['id'])) {
            return;
        }
        $guestsOn = Guests::enabled();
        $servicesOn = GuestServices::enabled();
        if (!$guestsOn && !$servicesOn) {
            return;
        }
        $roomId = (int) $room['id'];
        $stay = $guestsOn ? Guests::activeStay($roomId) : null;
        $lang = $stay ? Guests::lang((string) $stay['language']) : Guests::hotelLang();

        if ($mode === 'off' && !isset($content['off_reason'])) {
            $content['off_reason'] = isset($content['power_schedule_id']) ? 'schedule' : 'admin';
        }

        // Check-in mode: vacant rooms.
        if (!$stay && Guests::checkinMode()) {
            $vacant = Guests::vacantMode();
            if ($vacant === 'off' && $mode !== 'off') {
                $content['mode'] = 'off';
                $content['screen_on'] = false;
                $content['off_reason'] = 'vacant';
                $content['playlist'] = null;
                $content['items'] = [];
            } elseif ($vacant === 'welcome' && $mode !== 'off') {
                $content['mode'] = 'empty';
                $content['playlist'] = null;
                $content['items'] = [];
            }
        }

        if ($stay) {
            $content['guest'] = Guests::guestObject($stay, $lang);
            $content['welcome'] = Guests::welcomeObject($stay, $room, $lang);
            $content['checkout_reminder'] = Guests::reminderObject($stay, $room, $lang);
        }

        // Room services QR (only while the room's guest link is valid).
        if (!$servicesOn) {
            return;
        }
        $token = Guests::roomToken($roomId);
        $tokenRow = DB::one('SELECT * FROM guest_tokens WHERE hotel_id = :hid AND room_id = :r', ['hid' => Tenant::id(), 'r' => $roomId]);
        if (!Guests::tokenUsable($tokenRow, $stay)) {
            return;
        }
        $menu = [];
        if (GuestServices::servicesOn()) {
            $menu[] = ['id' => 'services', 'type' => 'qr', 'title' => I18n::translate('Room Service', $lang), 'icon' => 'food', 'url' => Guests::guestUrl($token)];
        }
        if (GuestServices::requestsOn()) {
            $menu[] = ['id' => 'requests', 'type' => 'qr', 'title' => I18n::translate('Requests', $lang), 'icon' => 'bell', 'url' => Guests::guestUrl($token, 'requests')];
        }
        if (GuestServices::feedbackOn()) {
            $menu[] = ['id' => 'feedback', 'type' => 'qr', 'title' => I18n::translate('Feedback', $lang), 'icon' => 'star', 'url' => Guests::guestUrl($token, 'feedback')];
        }
        if (!$menu) {
            return;
        }
        $content['services'] = [
            'enabled' => true,
            'url' => Guests::guestUrl($token),
            'label' => I18n::translate(GuestServices::servicesOn() ? 'Scan for Room Service' : 'Scan for guest services', $lang),
        ];
        // Other modules append their own entries (live TV, HDMI, cast, local guide…).
        $content['guest_menu'] = array_merge(is_array($content['guest_menu'] ?? null) ? $content['guest_menu'] : [], $menu);
    }
}
