package com.hotelcast.tv

import android.annotation.SuppressLint
import android.app.ActivityManager
import android.content.Context
import android.hardware.display.DisplayManager
import android.net.wifi.WifiManager
import android.os.Build
import android.os.Debug
import android.os.StatFs
import android.util.DisplayMetrics
import android.view.Display
import java.io.File
import java.util.Locale

/**
 * 2.4 TV health (#44): extra telemetry sent as `health` in every heartbeat. Every value is
 * best-effort (null when the TV does not expose it) and the collection never throws.
 *
 * Keys: storage_free_mb, storage_total_mb (internal storage = app files), cache_free_mb,
 * cache_total_mb, ram_avail_mb, ram_total_mb, ram_low, cpu_temp_c, wifi_rssi, wifi_link_mbps,
 * network (ethernet | wifi | …), uptime_sec, app_mem_mb, resolution ("3840x2160", the HDMI / panel
 * mode), ui_resolution, refresh_hz, android_version, sdk, device_owner, last_crash_at (ISO),
 * usb {…}, cec {…}.
 */
object DeviceHealth {

    fun collect(context: Context, extra: Map<String, Any?> = emptyMap()): Map<String, Any?> {
        val out = linkedMapOf<String, Any?>()
        storage(context.filesDir)?.let { (free, total) -> out["storage_free_mb"] = free; out["storage_total_mb"] = total }
        storage(context.cacheDir)?.let { (free, total) -> out["cache_free_mb"] = free; out["cache_total_mb"] = total }
        try {
            val am = context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
            val mi = ActivityManager.MemoryInfo()
            am.getMemoryInfo(mi)
            out["ram_avail_mb"] = mi.availMem / MB
            out["ram_total_mb"] = mi.totalMem / MB
            out["ram_low"] = mi.lowMemory
        } catch (_: Exception) {
        }
        out["cpu_temp_c"] = cpuTemperature()
        val net = DeviceInfo.networkType(context)
        out["network"] = net
        if (net == "wifi") {
            out["wifi_rssi"] = DeviceInfo.wifiSignal(context)
            out["wifi_link_mbps"] = wifiLinkSpeed(context)
        }
        out["uptime_sec"] = DeviceInfo.uptimeSec()
        out["app_mem_mb"] = appMemoryMb()
        display(context, out)
        out["android_version"] = DeviceInfo.androidVersion
        out["sdk"] = Build.VERSION.SDK_INT
        out["device_owner"] = KioskHelper.isDeviceOwner(context)
        out["last_crash_at"] = Prefs.lastCrashTime.takeIf { it > 0 }?.let { Utils.isoTime(it) }
        out.putAll(extra)
        return out
    }

    private const val MB = 1024L * 1024L

    private fun storage(dir: File?): Pair<Long, Long>? = try {
        val s = StatFs((dir ?: return null).absolutePath)
        (s.availableBytes / MB) to (s.totalBytes / MB)
    } catch (_: Exception) {
        null
    }

    @SuppressLint("MissingPermission")
    @Suppress("DEPRECATION")
    private fun wifiLinkSpeed(context: Context): Int? = try {
        val wm = context.applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
        wm.connectionInfo?.linkSpeed?.takeIf { it > 0 }
    } catch (_: Exception) {
        null
    }

    /** PSS of this app in MB (Java + native + graphics). */
    private fun appMemoryMb(): Long? = try {
        val mi = Debug.MemoryInfo()
        Debug.getMemoryInfo(mi)
        (mi.totalPss / 1024L).takeIf { it > 0 } ?: ((Runtime.getRuntime().totalMemory() - Runtime.getRuntime().freeMemory() + Debug.getNativeHeapAllocatedSize()) / MB)
    } catch (_: Exception) {
        null
    }

    @Suppress("DEPRECATION")
    private fun display(context: Context, out: MutableMap<String, Any?>) {
        try {
            val dm = context.getSystemService(Context.DISPLAY_SERVICE) as DisplayManager
            val d = dm.getDisplay(Display.DEFAULT_DISPLAY) ?: return
            val m = DisplayMetrics()
            d.getRealMetrics(m)
            val ui = resolution(m.widthPixels, m.heightPixels)
            out["ui_resolution"] = ui
            out["refresh_hz"] = Math.round(d.refreshRate)
            out["resolution"] = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                d.mode?.let { resolution(it.physicalWidth, it.physicalHeight) } ?: ui
            } else ui
        } catch (_: Exception) {
        }
    }

    fun resolution(w: Int, h: Int): String? = if (w > 0 && h > 0) "${maxOf(w, h)}x${minOf(w, h)}" else null

    /** Best-effort: /sys/class/thermal/thermal_zone{n}/temp (+ type). Null when unreadable. */
    fun cpuTemperature(): Double? = try {
        val zones = File("/sys/class/thermal").listFiles { f -> f.name.startsWith("thermal_zone") }.orEmpty()
        val readings = zones.take(32).mapNotNull { z ->
            val raw = try { File(z, "temp").readText().trim() } catch (_: Exception) { null } ?: return@mapNotNull null
            val type = try { File(z, "type").readText().trim() } catch (_: Exception) { "" }
            type to raw
        }
        pickTemperature(readings)
    } catch (_: Exception) {
        null
    }

    private val CPU_TYPES = Regex("cpu|soc|tsens|core|ap_therm|mtktscpu|aml_thermal|thermal-cpufreq|x86_pkg", RegexOption.IGNORE_CASE)

    /**
     * Pure (unit tested): picks the CPU temperature from (zone type, raw value) pairs. Values above
     * 1000 are millidegrees (45000 → 45.0), above 200 tenths (450 → 45.0); implausible readings
     * (≤ 0 or > 150 °C) are dropped. CPU-like zones win; otherwise the hottest zone.
     */
    fun pickTemperature(readings: List<Pair<String, String>>): Double? {
        val parsed = readings.mapNotNull { (type, raw) ->
            val v = raw.toDoubleOrNull() ?: return@mapNotNull null
            val c = when {
                v > 1000 -> v / 1000.0
                v > 200 -> v / 10.0
                else -> v
            }
            if (c <= 0.0 || c > 150.0) null else type to c
        }
        if (parsed.isEmpty()) return null
        val cpu = parsed.filter { CPU_TYPES.containsMatchIn(it.first) }
        val best = (cpu.ifEmpty { parsed }).maxOf { it.second }
        return String.format(Locale.US, "%.1f", best).toDouble()
    }
}
