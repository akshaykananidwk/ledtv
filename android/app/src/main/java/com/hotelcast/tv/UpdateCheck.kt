package com.hotelcast.tv

import android.content.Context
import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull

/**
 * 2.6.0 forced update: talks to the server for [UpdateGate] (pure decision logic).
 *  - [checkOnStart]: cold start of the player — asks GET device/app-version (≤ [UpdateGate.CHECK_TIMEOUT_MS]),
 *    falls back to the cached answer when the server cannot be reached.
 *  - [onServerInfo]: every poll response carries `app_update`; it is cached (a running TV keeps playing) and a
 *    required update is downloaded in the background so the next start can install it even offline.
 */
object UpdateCheck {
    private const val TAG = "UpdateCheck"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    /** versionCode prefetched (or being prefetched) in this process, to download / hash only once. */
    @Volatile private var prefetched = 0

    val installed: Int get() = BuildConfig.VERSION_CODE

    /** Result of a server request: reached (answered, even with "no update") and the info. */
    data class ServerAnswer(val reached: Boolean, val info: AppUpdateInfo?)

    suspend fun fetch(timeoutMs: Long = UpdateGate.CHECK_TIMEOUT_MS): ServerAnswer {
        val service = ApiClient.service() ?: return ServerAnswer(false, null)
        return withTimeoutOrNull(timeoutMs) {
            try {
                val r = ApiClient.call { service.appVersion() }
                ServerAnswer(true, r?.update)
            } catch (e: ApiException) {
                // A server older than 2.7 has no such endpoint: nothing is enforced (and the cache is cleared).
                if (e.fromServer && e.httpStatus == 404) ServerAnswer(true, null) else ServerAnswer(false, null)
            } catch (e: Exception) {
                Log.w(TAG, "app-version check failed: ${ApiClient.describe(e)}")
                ServerAnswer(false, null)
            }
        } ?: ServerAnswer(false, null)
    }

    /** Cold start: server (with timeout) → cache → start normally. */
    suspend fun checkOnStart(context: Context): UpdateGate.Decision {
        val cached = Prefs.appUpdate
        val answer = fetch()
        if (answer.reached) onServerInfo(context, answer.info)
        val d = UpdateGate.decide(installed, answer.reached, answer.info, cached)
        Log.i(TAG, "start gate: installed=$installed block=${d.block} source=${d.source} offered=${d.info?.versionCode} required=${d.info?.requiredVersionCode}")
        return d
    }

    /** Re-start of the player (activity came back): decided from the cache kept fresh by the poll. */
    fun decideFromCache(): UpdateGate.Decision = UpdateGate.decide(installed, false, null, Prefs.appUpdate)

    /** Server answer from a poll / check: cache it (only when changed), prefetch a required update. */
    fun onServerInfo(context: Context, info: AppUpdateInfo?) {
        val json = info?.let { ApiClient.gson.toJson(it) } ?: ""
        if (json != Prefs.appUpdateJson) Prefs.appUpdateJson = json
        if (info != null && UpdateGate.mustUpdate(installed, info) && prefetched != info.versionCode) {
            prefetched = info.versionCode
            val app = context.applicationContext
            scope.launch {
                try {
                    AppUpdater(app).prefetch(info)
                } finally {
                    if (!java.io.File(app.cacheDir, "updates/hotelcast-${info.versionCode}.apk").isFile &&
                        !java.io.File(app.externalCacheDir, "updates/hotelcast-${info.versionCode}.apk").isFile
                    ) prefetched = 0 // failed: try again on a later poll
                }
            }
        }
    }
}

/** In-process results of PackageInstaller sessions (InstallResultReceiver → UpdateActivity). */
object UpdateStatus {
    data class InstallEvent(val failure: UpdateGate.Failure, val message: String, val seq: Long)

    private val _events = MutableStateFlow<InstallEvent?>(null)
    val events: StateFlow<InstallEvent?> = _events
    private var seq = 0L

    @Synchronized
    fun installFailed(failure: UpdateGate.Failure, message: String) {
        _events.value = InstallEvent(failure, message, ++seq)
    }

    fun clear() {
        _events.value = null
    }
}
