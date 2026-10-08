<?php
declare(strict_types=1);

/**
 * 2.4 device features: per-room USB mode (#42) and HDMI-CEC mode (#50) in the TV Content object.
 *   usb_mode: true      → the TV plays its USB / SD "KrishnaCloud" folder (when one is plugged in)
 *   cec_mode: box | tv  → power handling of an Android box driving the TV over HDMI-CEC / a built-in panel
 * Only non-default values are sent, so rooms without these settings keep their content hash.
 */
final class DeviceFeaturesExtension implements ContentExtension
{
    public function apply(array &$content, array $room): void
    {
        $flags = DeviceFeatures::roomFlags($room);
        if ($flags['usb_mode']) {
            $content['usb_mode'] = true;
        }
        if ($flags['cec_mode'] !== 'auto') {
            $content['cec_mode'] = $flags['cec_mode'];
        }
    }
}
