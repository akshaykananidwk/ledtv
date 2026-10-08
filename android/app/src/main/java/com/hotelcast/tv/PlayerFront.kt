package com.hotelcast.tv

/**
 * 2.4: what RELOAD does. The device-schedules module uses RELOAD as "back to the player app" after a
 * Live TV / HDMI input schedule, so when the player is not on screen (Live TV, an HDMI input, another
 * app or settings in front) RELOAD brings MainActivity to the front (NEW_TASK | REORDER_TO_FRONT; as
 * device owner the kiosk allow list is restored first and MainActivity.onResume re-enters lock task).
 * When the player is on screen it restarts it as before. Pure, unit tested in DeviceFeatures24Test.
 */
object PlayerFront {
    enum class ReloadAction { RESTART_PLAYER, BRING_TO_FRONT }

    fun reloadAction(playerOnScreen: Boolean, externalAppActive: Boolean): ReloadAction =
        if (playerOnScreen && !externalAppActive) ReloadAction.RESTART_PLAYER else ReloadAction.BRING_TO_FRONT
}
