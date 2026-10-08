package com.hotelcast.tv

import android.annotation.SuppressLint
import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent
import android.net.wifi.WifiManager
import android.os.Build
import android.os.PowerManager
import android.util.Log
import java.util.concurrent.TimeUnit

/**
 * Real TV power control (standby / wake) driven by the server.
 *
 * The desired state comes from the Content object (`mode = off` / `screen_on = false`: room switched
 * off in the admin panel or inside a scheduled "TV off" window) or the SCREEN_OFF / SCREEN_ON commands.
 * An emergency always wakes the TV.
 *
 * How the panel is actually switched:
 *  - Sleep: DevicePolicyManager.lockNow() when the app is device owner (TV goes to standby / screen off)
 *           → `su -c input keyevent 223` (KEYCODE_SLEEP) on rooted boxes → otherwise only a black screen.
 *  - Wake:  a full wake lock with ACQUIRE_CAUSES_WAKEUP + bringing MainActivity to front (turnScreenOn)
 *           → `su -c input keyevent 224` (KEYCODE_WAKEUP) on rooted boxes.
 * Waking only works while the Android system is still running in standby (screen-off / "quick start" /
 * "network standby"). If the TV was switched off at the mains, nothing can switch it on remotely.
 *
 * A guest can always turn the TV on with the remote: pressing a key on the black "off" screen sets a local
 * override that lasts until the server's desired state changes again.
 */
@SuppressLint("StaticFieldLeak")
object PowerController {
    private const val TAG = "Power"

    private lateinit var app: Context
    private var cpuLock: PowerManager.WakeLock? = null
    private var wifiLock: WifiManager.WifiLock? = null

    fun init(context: Context) {
        if (::app.isInitialized) return
        app = context.applicationContext
    }

    /**
     * Keep CPU + Wi-Fi alive while the screen is off so polling continues in standby and the TV can be
     * woken by the server. TVs are mains powered, so this has no battery cost.
     */
    @Synchronized
    fun holdBackgroundLocks(context: Context) {
        init(context)
        try {
            if (cpuLock?.isHeld != true) {
                val pm = app.getSystemService(Context.POWER_SERVICE) as PowerManager
                cpuLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "HotelCast:poll").apply {
                    setReferenceCounted(false)
                    acquire()
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "partial wake lock failed", e)
        }
        try {
            if (wifiLock?.isHeld != true) {
                val wm = app.getSystemService(Context.WIFI_SERVICE) as? WifiManager
                @Suppress("DEPRECATION")
                val mode = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) WifiManager.WIFI_MODE_FULL_LOW_LATENCY else WifiManager.WIFI_MODE_FULL_HIGH_PERF
                wifiLock = wm?.createWifiLock(mode, "HotelCast:wifi")?.apply {
                    setReferenceCounted(false)
                    acquire()
                }
            }
        } catch (e: Exception) {
            Log.w(TAG, "wifi lock failed", e)
        }
    }

    @Synchronized
    fun releaseBackgroundLocks() {
        try { if (cpuLock?.isHeld == true) cpuLock?.release() } catch (_: Exception) {}
        try { if (wifiLock?.isHeld == true) wifiLock?.release() } catch (_: Exception) {}
    }

    /** Desired "off" state from content + forced command state (emergency always means on). */
    fun desiredOff(content: Content?): Boolean {
        if (content?.isEmergency == true) return false
        return Prefs.forcedScreenOff || content?.isOff == true
    }

    /** True when the screen should show content even though the server says "off" (guest pressed a key). */
    fun isLocallyOverridden(): Boolean = Prefs.powerOverride

    @Volatile private var sleptAt = 0L

    /**
     * True when the app put the TV to standby itself more than 20 s ago and the state is still "off" —
     * i.e. the screen is now on because a guest used the remote.
     */
    fun wasSleptBySchedule(): Boolean =
        Prefs.lastPowerOff && sleptAt > 0 && System.currentTimeMillis() - sleptAt > 20_000 && isScreenInteractive()

    /** Guest woke the TV with the remote while it is scheduled off. */
    fun guestOverride() {
        Prefs.powerOverride = true
    }

    /**
     * Apply the desired state if it changed since the last time we acted. Called after every content
     * update and after SCREEN_ON / SCREEN_OFF commands.
     */
    @Synchronized
    fun evaluate(content: Content?) {
        if (!::app.isInitialized) return
        val off = desiredOff(content)
        val last = Prefs.lastPowerOff
        if (last == off) return
        Prefs.lastPowerOff = off
        Prefs.powerOverride = false
        Log.i(TAG, "Power state change → ${if (off) "OFF (standby)" else "ON"}")
        sleptAt = if (off) System.currentTimeMillis() else 0L
        // Never block the caller (may be the main thread): wake() waits between retries.
        Thread({
            try {
                if (off) Log.i(TAG, "sleep via " + sleep()) else Log.i(TAG, "wake via " + wake())
                SyncManager.heartbeatSoon()
            } catch (e: Exception) {
                Log.w(TAG, "power change failed", e)
            }
        }, "HotelCast-power").start()
    }

    fun isScreenInteractive(): Boolean = try {
        (app.getSystemService(Context.POWER_SERVICE) as PowerManager).isInteractive
    } catch (_: Exception) {
        true
    }

    /** True when the hotel chose "black screen" instead of real standby (Admin → TV Power). */
    fun blackMode(content: Content?): Boolean =
        content?.powerOffMode == "black" &&
            // 2.4 CEC box mode: a box must really sleep so HDMI-CEC switches the TV off (CecControl).
            !CecControl.boxMode(if (::app.isInitialized) app else null, content)

    /**
     * Explicit SCREEN_ON / SCREEN_OFF command from the admin panel: always act, even if our remembered
     * state already matches (e.g. a guest switched the TV off with the remote and admin presses "Turn ON").
     * Returns a short result that is sent back in the command ack (visible in the broadcast log).
     */
    fun force(on: Boolean, content: Content?): String {
        if (!::app.isInitialized) return "not initialised"
        synchronized(this) {
            Prefs.lastPowerOff = !on
            Prefs.powerOverride = false
            sleptAt = if (on) 0L else System.currentTimeMillis()
        }
        return if (on) {
            val how = wake()
            val cec = cecNote(content, true)
            if (isScreenInteractive()) "Screen on ($how)$cec" else "Wake FAILED: TV did not switch on ($how)$cec"
        } else {
            if (blackMode(content)) "Black screen (black-screen mode)" else "Screen off (" + sleep() + ")" + cecNote(content, false)
        }
    }

    /**
     * 2.4 CEC box mode: the box sleeping / waking makes Android send HDMI-CEC Standby / One Touch
     * Play (when CEC is enabled in the box settings). Also tries HdmiControlManager (system builds
     * only). Returns a note for the ack, "" for a TV with a built-in panel.
     */
    private fun cecNote(content: Content?, on: Boolean): String {
        if (!CecControl.boxMode(app, content)) return ""
        val direct = CecControl.tryHdmiControl(app, standby = !on)
        return "; CEC box: " + (direct ?: if (on) "TV follows via One Touch Play" else "TV follows to standby via HDMI-CEC")
    }

    /** Put the TV into standby (screen off). Returns a short description of the method used. */
    fun sleep(): String {
        if (blackMode(SyncManager.content.value)) return "black screen mode"
        if (KioskHelper.isDeviceOwner(app)) {
            try {
                (app.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager).lockNow()
                return "device-owner lockNow"
            } catch (e: Exception) {
                Log.w(TAG, "lockNow failed", e)
            }
        }
        if (su("input keyevent 223")) return "root KEYCODE_SLEEP"
        return "black screen only (make the app device owner for real standby)"
    }

    /**
     * Wake the TV from standby and bring the player to the front. Tries several times because some TVs
     * need a moment after leaving standby. Blocking (max ~8 s) — always called from a background thread.
     */
    @Suppress("DEPRECATION")
    fun wake(): String {
        val pm = app.getSystemService(Context.POWER_SERVICE) as PowerManager
        var method = "already on"
        for (attempt in 1..3) {
            if (pm.isInteractive && attempt > 1) break
            if (!pm.isInteractive) {
                try {
                    pm.newWakeLock(
                        PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP or PowerManager.ON_AFTER_RELEASE,
                        "HotelCast:wake"
                    ).acquire(TimeUnit.SECONDS.toMillis(15))
                    method = "wake lock"
                } catch (e: Exception) {
                    Log.w(TAG, "wake lock failed", e)
                }
            }
            try {
                app.startActivity(
                    Intent(app, MainActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
                        .putExtra(MainActivity.EXTRA_WAKE, true)
                )
            } catch (e: Exception) {
                Log.w(TAG, "start activity on wake failed", e)
            }
            if (!pm.isInteractive && su("input keyevent 224")) method = "root KEYCODE_WAKEUP"
            try {
                Thread.sleep(2_500)
            } catch (_: InterruptedException) {
                break
            }
        }
        Log.i(TAG, "wake → interactive=${pm.isInteractive} via $method")
        return method
    }

    private fun su(cmd: String): Boolean = try {
        val p = Runtime.getRuntime().exec(arrayOf("su", "-c", cmd))
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            p.waitFor(5, TimeUnit.SECONDS) && p.exitValue() == 0
        } else {
            p.waitFor() == 0
        }
    } catch (_: Exception) {
        false
    }
}
