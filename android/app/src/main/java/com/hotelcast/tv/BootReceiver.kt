package com.hotelcast.tv

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/** Starts the kiosk after boot and after an app update (MY_PACKAGE_REPLACED). */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action ?: return
        Log.i(TAG, "onReceive $action")
        if (action !in HANDLED) return
        if (action == Intent.ACTION_LOCKED_BOOT_COMPLETED) {
            // Credential-encrypted storage (prefs) is not available yet; just try to bring up the UI.
            launch(context)
            return
        }
        try {
            Prefs.init(context)
            if (Prefs.isRegistered) PollService.start(context)
            PollWorker.schedule(context)
        } catch (e: Exception) {
            Log.w(TAG, "Background start failed", e)
        }
        launch(context)
    }

    private fun launch(context: Context) {
        try {
            val i = Intent(context, MainActivity::class.java).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED)
            }
            context.startActivity(i)
        } catch (e: Exception) {
            Log.w(TAG, "Unable to launch MainActivity", e)
        }
    }

    companion object {
        private const val TAG = "BootReceiver"
        private val HANDLED = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_LOCKED_BOOT_COMPLETED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON",
            Intent.ACTION_MY_PACKAGE_REPLACED,
        )
    }
}
