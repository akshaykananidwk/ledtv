package com.hotelcast.tv

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleService

/**
 * Foreground service that keeps the fast poll/heartbeat loop ([SyncManager]) alive while the TV is on,
 * even if the activity is briefly in the background (installer, Android settings…).
 */
class PollService : LifecycleService() {

    override fun onCreate() {
        super.onCreate()
        try {
            startAsForeground()
        } catch (e: Exception) {
            // Some OEM builds refuse FGS from background; the loop still runs while the process lives.
            Log.w(TAG, "startForeground failed", e)
        }
        SyncManager.start(this)
        PowerController.holdBackgroundLocks(this)
    }

    override fun onDestroy() {
        PowerController.releaseBackgroundLocks()
        super.onDestroy()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        super.onStartCommand(intent, flags, startId)
        if (!Prefs.isRegistered) {
            stopSelf()
            return START_NOT_STICKY
        }
        SyncManager.start(this)
        return START_STICKY
    }

    private fun startAsForeground() {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL, getString(R.string.service_channel), NotificationManager.IMPORTANCE_MIN)
            )
        }
        val n: Notification = NotificationCompat.Builder(this, CHANNEL)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(getString(R.string.service_running))
            .setSmallIcon(R.drawable.ic_launcher)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_MIN)
            .build()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
        } else {
            startForeground(NOTIFICATION_ID, n)
        }
    }

    companion object {
        private const val TAG = "PollService"
        private const val CHANNEL = "hotelcast_sync"
        private const val NOTIFICATION_ID = 1001

        /** Start the service; falls back to running the loop in-process if the OS refuses. */
        fun start(context: Context) {
            SyncManager.init(context)
            try {
                ContextCompat.startForegroundService(context, Intent(context, PollService::class.java))
            } catch (e: Exception) {
                Log.w(TAG, "Cannot start PollService (${e.javaClass.simpleName}); running loop in-process")
            }
            SyncManager.start(context)
        }

        fun stop(context: Context) {
            try {
                context.stopService(Intent(context, PollService::class.java))
            } catch (_: Exception) {
            }
        }
    }
}
