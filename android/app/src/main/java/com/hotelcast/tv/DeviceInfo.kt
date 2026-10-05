package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.wifi.WifiManager
import android.os.BatteryManager
import android.os.Build
import android.os.StatFs
import android.os.SystemClock
import java.net.Inet4Address
import java.net.NetworkInterface

/** Collects device telemetry for registration and heartbeats. Every getter is failure-safe. */
object DeviceInfo {

    val appVersion: String get() = BuildConfig.VERSION_NAME
    val appVersionCode: Int get() = BuildConfig.VERSION_CODE
    val androidVersion: String get() = Build.VERSION.RELEASE ?: Build.VERSION.SDK_INT.toString()
    val model: String
        get() = listOfNotNull(Build.MANUFACTURER?.takeIf { it.isNotBlank() }, Build.MODEL)
            .joinToString(" ").ifBlank { "unknown" }

    /** First non-loopback IPv4 address (prefers ethernet / wifi interfaces). */
    fun ipAddress(): String? = try {
        val candidates = NetworkInterface.getNetworkInterfaces()?.toList().orEmpty()
            .filter { it.isUp && !it.isLoopback }
            .sortedBy { iface ->
                when {
                    iface.name.startsWith("eth") -> 0
                    iface.name.startsWith("wlan") -> 1
                    else -> 2
                }
            }
        candidates.asSequence()
            .flatMap { it.inetAddresses.toList().asSequence() }
            .filterIsInstance<Inet4Address>()
            .firstOrNull { !it.isLoopbackAddress && !it.isLinkLocalAddress }
            ?.hostAddress
    } catch (e: Exception) {
        null
    }

    /** Battery percentage or -1 when the device has no battery (most TVs). */
    fun battery(context: Context): Int = try {
        val intent: Intent? = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
        val present = intent?.getBooleanExtra(BatteryManager.EXTRA_PRESENT, false) ?: false
        val level = intent?.getIntExtra(BatteryManager.EXTRA_LEVEL, -1) ?: -1
        val scale = intent?.getIntExtra(BatteryManager.EXTRA_SCALE, -1) ?: -1
        if (!present || level < 0 || scale <= 0) -1 else (level * 100 / scale)
    } catch (e: Exception) {
        -1
    }

    @Suppress("DEPRECATION")
    fun networkType(context: Context): String = try {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val caps = cm.getNetworkCapabilities(cm.activeNetwork)
            when {
                caps == null -> "none"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ethernet"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"
                else -> "other"
            }
        } else {
            val info = cm.activeNetworkInfo
            when {
                info == null || !info.isConnected -> "none"
                info.type == ConnectivityManager.TYPE_ETHERNET -> "ethernet"
                info.type == ConnectivityManager.TYPE_WIFI -> "wifi"
                info.type == ConnectivityManager.TYPE_MOBILE -> "mobile"
                else -> "other"
            }
        }
    } catch (e: Exception) {
        "unknown"
    }

    /** WiFi RSSI in dBm, or null when not on WiFi. */
    @SuppressLint("MissingPermission")
    @Suppress("DEPRECATION")
    fun wifiSignal(context: Context): Int? = try {
        if (networkType(context) != "wifi") null else {
            val wm = context.applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
            wm.connectionInfo?.rssi?.takeIf { it in -127..0 }
        }
    } catch (e: Exception) {
        null
    }

    fun freeStorageMb(context: Context): Long = try {
        val stat = StatFs(context.filesDir.absolutePath)
        stat.availableBytes / (1024L * 1024L)
    } catch (e: Exception) {
        -1
    }

    fun uptimeSec(): Long = SystemClock.elapsedRealtime() / 1000

    fun isOnline(context: Context): Boolean = networkType(context).let { it != "none" }
}
