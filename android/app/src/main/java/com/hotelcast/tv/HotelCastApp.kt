package com.hotelcast.tv

import android.app.Application
import android.util.Log

class HotelCastApp : Application() {

    override fun onCreate() {
        super.onCreate()
        Prefs.init(this)
        TlsCompat.init(this) // before any HTTPS request (old CA lists, wrong TV clock)
        NetworkTime.checkAtStartup(this)
        installCrashRecovery()
        SyncManager.init(this)
        PollWorker.schedule(this)
    }

    /**
     * A kiosk must never stay on a crash dialog: log, then relaunch MainActivity via AlarmManager.
     * Crash loops are throttled (30 s delay if the previous crash was < 60 s ago).
     */
    private fun installCrashRecovery() {
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, error ->
            try {
                Log.e("HotelCastApp", "Uncaught exception on ${thread.name}", error)
                CrashReporter.store(this, thread, error) // sent to POST device/crash on the next start
                val now = System.currentTimeMillis()
                val rapid = now - Prefs.lastCrashTime < 60_000
                Prefs.lastCrashTime = now
                KioskHelper.restartApp(this, if (rapid) 30_000 else 2_000)
            } catch (e: Throwable) {
                previous?.uncaughtException(thread, error)
            }
        }
    }
}
