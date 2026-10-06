package com.hotelcast.tv

import android.content.Context
import android.os.Build
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.net.HttpURLConnection
import java.net.InetAddress
import java.net.URL
import java.util.concurrent.TimeUnit

/**
 * Network time from plain-HTTP `Date` headers (our own server first, then Android's connectivity-check
 * hosts). Used to detect a wrong TV clock, which breaks every HTTPS certificate check.
 */
object NetworkTime {
    /** networkTime − TV clock in ms, or null when unknown. */
    @Volatile var offsetMs: Long? = null
        private set

    private const val WRONG_CLOCK_MS = 10 * 60_000L

    /** 2026-01-01 UTC: a TV clock before this is certainly wrong (this app did not exist yet). */
    const val MIN_PLAUSIBLE_TIME_MS = 1_767_225_600_000L

    /**
     * At app start: when the TV clock is obviously wrong, learn the network time (so HTTPS works,
     * see [TlsCompat]) and fix the clock if the app is device owner. Runs on a background thread.
     */
    fun checkAtStartup(context: Context) {
        if (System.currentTimeMillis() >= MIN_PLAUSIBLE_TIME_MS) return
        val app = context.applicationContext
        Thread {
            val server = Prefs.serverUrl.ifBlank { BuildConfig.DEFAULT_SERVER_URL }
            if (fetch(server) != null) fixClockIfPossible(app)
        }.apply { isDaemon = true }.start()
    }

    val clockWrong: Boolean get() = offsetMs?.let { kotlin.math.abs(it) > WRONG_CLOCK_MS } ?: false

    /** Blocking. Returns the offset (also stored in [offsetMs]) or null. */
    fun fetch(serverRoot: String?): Long? {
        val urls = mutableListOf<String>()
        ServerUrl.normalize(serverRoot)?.toHttpUrlOrNull()?.let { u ->
            urls += u.newBuilder().scheme("http").port(if (u.port == 443) 80 else u.port).build().toString() + "health"
        }
        urls += "http://connectivitycheck.gstatic.com/generate_204"
        urls += "http://clients3.google.com/generate_204"
        for (raw in urls) {
            try {
                val c = URL(raw).openConnection() as HttpURLConnection
                c.connectTimeout = 6000
                c.readTimeout = 6000
                c.instanceFollowRedirects = false
                c.useCaches = false
                val before = System.currentTimeMillis()
                c.responseCode
                val date = c.date
                c.disconnect()
                if (date > 0) {
                    val off = date - (before + System.currentTimeMillis()) / 2
                    offsetMs = off
                    return off
                }
            } catch (e: Exception) {
                Log.d("NetworkTime", "$raw: ${e.message}")
            }
        }
        return null
    }

    /** Device owner on Android 9+: fix the TV clock directly. Returns true when the time was set. */
    fun fixClockIfPossible(context: Context): Boolean {
        val off = offsetMs ?: return false
        if (!clockWrong || Build.VERSION.SDK_INT < 28 || !KioskHelper.isDeviceOwner(context)) return false
        return try {
            val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as android.app.admin.DevicePolicyManager
            val ok = dpm.setTime(KioskHelper.adminComponent(context), System.currentTimeMillis() + off)
            if (ok) offsetMs = 0L
            ok
        } catch (e: Exception) {
            Log.w("NetworkTime", "setTime failed: ${e.message}")
            false
        }
    }
}

/** What works and what doesn't between this TV and the server (shown on the setup screen). */
data class NetReport(
    val host: String,
    val dnsOk: Boolean,
    val dnsError: String?,
    val httpsOk: Boolean,
    val httpsError: String?,
    /** The same server answers over plain HTTP (only checked when HTTPS fails). */
    val httpOk: Boolean,
    val clockWrong: Boolean,
    /** Network time as epoch ms when known. */
    val networkTimeMs: Long?,
    /** http:// version of the server address (offered when HTTPS fails but HTTP works). */
    val httpServer: String?,
) {
    val sslProblem: Boolean get() = dnsOk && !httpsOk && (httpsError?.contains("SSL", ignoreCase = true) == true || clockWrong)
    val offerHttp: Boolean get() = !httpsOk && httpOk && httpServer != null
}

object NetDiagnostics {
    private const val TAG = "NetDiagnostics"

    private val plainHttp: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(8, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .followRedirects(false)
            .build()
    }

    /** "https://h.com/x/" → "http://h.com/x/" (null when already http or unparsable). */
    fun httpVersion(server: String): String? {
        val api = ServerUrl.normalize(server) ?: return null
        val u = api.toHttpUrlOrNull() ?: return null
        if (u.scheme != "https") return null
        return ServerUrl.root(u.newBuilder().scheme("http").port(if (u.port == 443) 80 else u.port).build().toString())
    }

    suspend fun run(context: Context, server: String): NetReport = withContext(Dispatchers.IO) {
        val apiBase = ServerUrl.normalize(server)
        val url = apiBase?.toHttpUrlOrNull()
        val host = url?.host.orEmpty()

        var dnsOk = false
        var dnsError: String? = null
        try {
            dnsOk = InetAddress.getAllByName(host).isNotEmpty()
        } catch (e: Exception) {
            dnsError = ApiClient.describe(e)
        }

        // Clock first: with the network time known, the HTTPS check below can succeed on a wrong clock.
        val off = NetworkTime.fetch(server)
        if (NetworkTime.clockWrong) NetworkTime.fixClockIfPossible(context)

        var httpsOk = false
        var httpsError: String? = null
        if (apiBase != null && dnsOk) {
            try {
                ApiClient.http.newCall(Request.Builder().url(apiBase + "health").build()).execute().use { r ->
                    httpsOk = r.isSuccessful
                    if (!httpsOk) httpsError = "HTTP ${r.code}"
                }
            } catch (e: Exception) {
                httpsError = ApiClient.describe(e)
            }
        }

        var httpOk = false
        val httpServer = if (url?.scheme == "https") httpVersion(server) else null
        if (!httpsOk && httpServer != null && dnsOk) {
            try {
                plainHttp.newCall(Request.Builder().url(ServerUrl.normalize(httpServer) + "health").build()).execute().use { r ->
                    val body = r.body?.string().orEmpty()
                    httpOk = r.isSuccessful && body.contains("\"ok\":true")
                }
            } catch (e: Exception) {
                Log.d(TAG, "HTTP check: ${e.message}")
            }
        }
        NetReport(
            host = host,
            dnsOk = dnsOk,
            dnsError = dnsError,
            httpsOk = httpsOk,
            httpsError = httpsError,
            httpOk = httpOk,
            clockWrong = NetworkTime.clockWrong,
            networkTimeMs = off?.let { System.currentTimeMillis() + it },
            httpServer = httpServer,
        ).also { Log.i(TAG, it.toString()) }
    }
}
