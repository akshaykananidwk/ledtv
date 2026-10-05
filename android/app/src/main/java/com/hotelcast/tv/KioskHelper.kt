package com.hotelcast.tv

import android.app.Activity
import android.app.ActivityManager
import android.app.AlarmManager
import android.app.PendingIntent
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import android.os.Process
import android.os.SystemClock
import android.util.Log
import java.util.concurrent.TimeUnit
import kotlin.system.exitProcess

/** Device-owner / kiosk helpers: lock task, persistent HOME, reboot and app restart. */
object KioskHelper {
    private const val TAG = "Kiosk"

    fun adminComponent(context: Context) = ComponentName(context, AdminReceiver::class.java)

    private fun dpm(context: Context) =
        context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    fun isDeviceOwner(context: Context): Boolean = try {
        dpm(context).isDeviceOwnerApp(context.packageName)
    } catch (e: Exception) {
        false
    }

    /** As device owner: allow lock task for our package and make MainActivity the permanent HOME app. */
    fun applyDeviceOwnerPolicies(context: Context) {
        if (!isDeviceOwner(context)) return
        val dpm = dpm(context)
        val admin = adminComponent(context)
        try {
            dpm.setLockTaskPackages(admin, arrayOf(context.packageName))
        } catch (e: Exception) {
            Log.w(TAG, "setLockTaskPackages failed", e)
        }
        try {
            val filter = IntentFilter(Intent.ACTION_MAIN).apply {
                addCategory(Intent.CATEGORY_HOME)
                addCategory(Intent.CATEGORY_DEFAULT)
            }
            dpm.addPersistentPreferredActivity(admin, filter, ComponentName(context, MainActivity::class.java))
        } catch (e: Exception) {
            Log.w(TAG, "addPersistentPreferredActivity failed", e)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            try {
                dpm.setStatusBarDisabled(admin, true)
                dpm.setKeyguardDisabled(admin, true)
            } catch (e: Exception) {
                Log.w(TAG, "status bar / keyguard policy failed", e)
            }
        }
    }

    fun isInLockTask(context: Context): Boolean = try {
        val am = context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            am.lockTaskModeState != ActivityManager.LOCK_TASK_MODE_NONE
        } else {
            @Suppress("DEPRECATION")
            am.isInLockTaskMode
        }
    } catch (e: Exception) {
        false
    }

    /** Enter lock-task (true kiosk) when we are device owner and kiosk is not temporarily suspended. */
    fun enterKioskIfPossible(activity: Activity) {
        if (Prefs.isKioskSuspended || !isDeviceOwner(activity)) return
        if (isInLockTask(activity)) return
        try {
            activity.startLockTask()
        } catch (e: Exception) {
            Log.w(TAG, "startLockTask failed", e)
        }
    }

    fun exitKiosk(activity: Activity) {
        try {
            if (isInLockTask(activity)) activity.stopLockTask()
        } catch (e: Exception) {
            Log.w(TAG, "stopLockTask failed", e)
        }
        if (isDeviceOwner(activity) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            try {
                dpm(activity).setStatusBarDisabled(adminComponent(activity), false)
            } catch (_: Exception) {
            }
        }
    }

    /**
     * Reboot: DevicePolicyManager.reboot (device owner, API 24+) → `su -c reboot` (rooted boxes)
     * → restart the app process as the last resort.
     */
    fun reboot(context: Context) {
        if (isDeviceOwner(context) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            try {
                dpm(context).reboot(adminComponent(context))
                return
            } catch (e: Exception) {
                Log.w(TAG, "dpm.reboot failed", e)
            }
        }
        try {
            val pm = context.getSystemService(Context.POWER_SERVICE) as android.os.PowerManager
            pm.reboot(null) // only works for system-signed builds holding REBOOT
            return
        } catch (e: Exception) {
            Log.i(TAG, "PowerManager.reboot not permitted")
        }
        if (suReboot()) return
        restartApp(context)
    }

    private fun suReboot(): Boolean = try {
        val p = Runtime.getRuntime().exec(arrayOf("su", "-c", "reboot"))
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            p.waitFor(15, TimeUnit.SECONDS) && p.exitValue() == 0
        } else {
            p.waitFor() == 0
        }
    } catch (e: Exception) {
        Log.i(TAG, "su reboot unavailable: ${e.message}")
        false
    }

    /** Schedules MainActivity to start in ~1.5 s, then kills this process. */
    fun restartApp(context: Context, delayMs: Long = 1500) {
        try {
            val intent = Intent(context, MainActivity::class.java).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
            }
            var flags = PendingIntent.FLAG_CANCEL_CURRENT
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) flags = flags or PendingIntent.FLAG_IMMUTABLE
            val pi = PendingIntent.getActivity(context, 4711, intent, flags)
            val am = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val at = SystemClock.elapsedRealtime() + delayMs
            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && !am.canScheduleExactAlarms()) {
                    am.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pi)
                } else {
                    am.setExact(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pi)
                }
            } catch (e: SecurityException) {
                am.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, at, pi)
            }
        } catch (e: Exception) {
            Log.e(TAG, "restart scheduling failed", e)
        }
        Process.killProcess(Process.myPid())
        exitProcess(10)
    }
}
