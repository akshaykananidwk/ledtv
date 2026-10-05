package com.hotelcast.tv

import android.app.admin.DeviceAdminReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Device admin receiver. Make the app device owner (factory-fresh device, no accounts) with:
 *
 *   adb shell dpm set-device-owner com.hotelcast.tv/.AdminReceiver
 *
 * This unlocks lock-task kiosk mode, silent APK installs and DevicePolicyManager.reboot().
 */
class AdminReceiver : DeviceAdminReceiver() {

    override fun onEnabled(context: Context, intent: Intent) {
        super.onEnabled(context, intent)
        Log.i("AdminReceiver", "Device admin enabled; device owner=${KioskHelper.isDeviceOwner(context)}")
        KioskHelper.applyDeviceOwnerPolicies(context)
    }

    override fun onProfileProvisioningComplete(context: Context, intent: Intent) {
        super.onProfileProvisioningComplete(context, intent)
        KioskHelper.applyDeviceOwnerPolicies(context)
    }

    override fun onDisabled(context: Context, intent: Intent) {
        super.onDisabled(context, intent)
        Log.i("AdminReceiver", "Device admin disabled")
    }
}
