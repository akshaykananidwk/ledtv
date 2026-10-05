package com.hotelcast.tv

import android.content.Context
import android.util.Log
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit

/**
 * Fallback watchdog (WorkManager minimum period is 15 minutes). The real 5–10 s poll loop lives in
 * [SyncManager]/[PollService]; this worker makes sure that loop is running and, if the process had
 * been killed, performs one heartbeat + poll so the server still sees the TV.
 */
class PollWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        Prefs.init(applicationContext)
        if (!Prefs.isRegistered) return Result.success()
        SyncManager.init(applicationContext)
        val wasRunning = SyncManager.isRunning
        try {
            PollService.start(applicationContext)
        } catch (e: Exception) {
            Log.w(TAG, "PollService start failed", e)
        }
        if (!wasRunning) {
            try {
                SyncManager.heartbeatOnce()
                SyncManager.pollOnce()
            } catch (e: Exception) {
                Log.w(TAG, "Fallback poll failed: ${ApiClient.describe(e)}")
                return Result.retry()
            }
        }
        return Result.success()
    }

    companion object {
        private const val TAG = "PollWorker"
        private const val NAME = "hotelcast-fallback-poll"

        fun schedule(context: Context) {
            try {
                val req = PeriodicWorkRequestBuilder<PollWorker>(15, TimeUnit.MINUTES)
                    .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                    .build()
                WorkManager.getInstance(context).enqueueUniquePeriodicWork(NAME, ExistingPeriodicWorkPolicy.KEEP, req)
            } catch (e: Exception) {
                Log.w(TAG, "schedule failed", e)
            }
        }
    }
}
