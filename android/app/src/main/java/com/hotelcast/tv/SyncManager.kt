package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.util.Log
import kotlinx.coroutines.CoroutineExceptionHandler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withTimeoutOrNull
import java.io.IOException

data class SyncStatus(
    val online: Boolean = false,
    val lastPollTime: Long = 0L,
    val lastError: String? = null,
    val registered: Boolean = false,
)

sealed class SyncEvent {
    /** Screen on/off state changed (SCREEN_OFF / SCREEN_ON). */
    object ScreenStateChanged : SyncEvent()
    /** RELOAD command: restart the main activity. */
    object Reload : SyncEvent()
    /** Token revoked (401 INVALID_TOKEN): go back to setup. */
    object Unregistered : SyncEvent()
}

/**
 * Process-wide sync engine: the fast poll loop (every poll_interval seconds), the heartbeat loop,
 * played-history batching, media prefetch and command execution. Hosted by [PollService]
 * (foreground service) and started idempotently from MainActivity and [PollWorker].
 */
@SuppressLint("StaticFieldLeak") // holds the application context only
object SyncManager : CommandActions {
    private const val TAG = "SyncManager"

    private lateinit var app: Context
    private lateinit var cache: ContentCache
    private lateinit var commandHandler: CommandHandler

    private val errorHandler = CoroutineExceptionHandler { _, e -> Log.e(TAG, "Uncaught in sync scope", e) }
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO + errorHandler)
    private var pollJob: Job? = null
    private var heartbeatJob: Job? = null
    private var prefetchJob: Job? = null
    private val wake = Channel<Unit>(Channel.CONFLATED)
    private val pollMutex = Mutex()

    private val _content = MutableStateFlow<Content?>(null)
    val content: StateFlow<Content?> = _content

    private val _status = MutableStateFlow(SyncStatus())
    val status: StateFlow<SyncStatus> = _status

    private val _events = MutableSharedFlow<SyncEvent>(extraBufferCapacity = 16)
    val events: SharedFlow<SyncEvent> = _events

    /** Id of the item currently on screen (reported in heartbeats). */
    @Volatile var currentItemId: Long? = null

    private val played = mutableListOf<PlayedItem>()

    @Synchronized
    fun init(context: Context) {
        if (::app.isInitialized) return
        app = context.applicationContext
        Prefs.init(app)
        PowerController.init(app)
        cache = ContentCache(app)
        commandHandler = CommandHandler(
            deduper = CommandDeduper.deserialize(Prefs.handledCommands),
            persist = { Prefs.handledCommands = it },
            ack = { req -> api()?.let { s -> ApiClient.call { s.ack(req) } } },
            actions = this,
        )
        // Cold start: show the last known content immediately (works offline).
        cache.loadContent()?.let { cached ->
            _content.value = cached
            if (Prefs.currentHash.isBlank()) Prefs.currentHash = cached.hash.orEmpty()
        }
        _status.value = _status.value.copy(registered = Prefs.isRegistered, lastPollTime = Prefs.lastPollTime)
    }

    val contentCache: ContentCache get() = cache

    private fun api(): ApiService? = ApiClient.service()

    /** Starts the loops if registered and not already running. Safe to call repeatedly. */
    @Synchronized
    fun start(context: Context) {
        init(context)
        _status.value = _status.value.copy(registered = Prefs.isRegistered)
        if (!Prefs.isRegistered) return
        if (pollJob?.isActive != true) pollJob = scope.launch { pollLoop() }
        if (heartbeatJob?.isActive != true) heartbeatJob = scope.launch { heartbeatLoop() }
        // Re-download any media missing from the cache (e.g. after a cold start while offline)
        _content.value?.let { prefetch(it) }
    }

    @Synchronized
    fun stop() {
        pollJob?.cancel()
        heartbeatJob?.cancel()
        prefetchJob?.cancel()
        pollJob = null
        heartbeatJob = null
    }

    fun restart(context: Context) {
        stop()
        start(context)
    }

    val isRunning: Boolean get() = pollJob?.isActive == true

    /** Wake the poll loop now (e.g. after network reconnect or a settings change). */
    fun pollNow() {
        wake.trySend(Unit)
    }

    // ------------------------------------------------------------------ loops

    private suspend fun pollLoop() {
        var failures = 0
        while (currentCoroutineContext().isActive && Prefs.isRegistered) {
            var waitSec = Prefs.pollIntervalSec.toLong()
            try {
                pollOnce()
                failures = 0
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: ApiException) {
                if (e.isInvalidToken) {
                    onInvalidToken()
                    return
                }
                failures++
                markError(e, online = e.httpStatus in 400..499)
                waitSec = e.retryAfterSec?.toLong() ?: backoff(failures)
            } catch (e: Exception) {
                failures++
                markError(e, online = false)
                waitSec = backoff(failures)
            }
            withTimeoutOrNull(waitSec.coerceAtLeast(1) * 1000) { wake.receive() }
        }
    }

    private fun backoff(failures: Int): Long {
        val base = Prefs.pollIntervalSec.toLong()
        return (base * (1L shl (failures - 1).coerceIn(0, 3))).coerceAtMost(60)
    }

    private suspend fun heartbeatLoop() {
        delay(2_000)
        while (currentCoroutineContext().isActive && Prefs.isRegistered) {
            try {
                heartbeatOnce()
                flushPlayed()
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: ApiException) {
                if (e.isInvalidToken) {
                    onInvalidToken()
                    return
                }
                Log.w(TAG, "Heartbeat failed: ${ApiClient.describe(e)}")
            } catch (e: Exception) {
                Log.w(TAG, "Heartbeat failed: ${ApiClient.describe(e)}")
            }
            delay(Prefs.heartbeatIntervalSec * 1000L)
        }
    }

    // ------------------------------------------------------------------ single operations

    /** One poll of GET /device/command/{id}?hash=… — applies content and executes commands. */
    suspend fun pollOnce() = pollMutex.withLock {
        val service = api() ?: throw IOException("Server not configured")
        val data = ApiClient.call { service.poll(Prefs.deviceId, Prefs.currentHash) } ?: return@withLock
        val now = System.currentTimeMillis()
        Prefs.lastPollTime = now
        _status.value = _status.value.copy(online = true, lastPollTime = now, lastError = null, registered = true)
        data.pollInterval?.takeIf { it > 0 }?.let { Prefs.pollIntervalSec = it }
        val newContent = data.content
        if (newContent != null && (data.contentChanged != false)) {
            applyContent(newContent, data.contentHash)
        }
        commandHandler.handleAll(data.commands)
    }

    suspend fun heartbeatOnce() {
        val service = api() ?: return
        val c = _content.value
        val screenOn = PowerController.isScreenInteractive() &&
            (!PowerController.desiredOff(c) || PowerController.isLocallyOverridden())
        val body = HeartbeatRequest(
            appVersion = DeviceInfo.appVersion,
            appVersionCode = DeviceInfo.appVersionCode,
            androidVersion = DeviceInfo.androidVersion,
            model = DeviceInfo.model,
            ipAddress = DeviceInfo.ipAddress(),
            battery = DeviceInfo.battery(app),
            networkType = DeviceInfo.networkType(app),
            wifiSignal = DeviceInfo.wifiSignal(app),
            freeStorageMb = DeviceInfo.freeStorageMb(app),
            currentContentHash = Prefs.currentHash.ifBlank { null },
            currentItemId = currentItemId,
            screenOn = screenOn,
            uptimeSec = DeviceInfo.uptimeSec(),
        )
        val data = ApiClient.call { service.heartbeat(body) }
        _status.value = _status.value.copy(online = true, lastError = null)
        data?.pollInterval?.takeIf { it > 0 }?.let { Prefs.pollIntervalSec = it }
        data?.heartbeatInterval?.takeIf { it > 0 }?.let { Prefs.heartbeatIntervalSec = it }
        data?.settingsPinHash?.takeIf { it.isNotBlank() }?.let { Prefs.pinHash = it }
    }

    private fun applyContent(content: Content, contentHash: String?) {
        val hash = contentHash?.takeIf { it.isNotBlank() } ?: content.hash.orEmpty()
        val normalized = if (content.hash.isNullOrBlank() && hash.isNotBlank()) content.copy(hash = hash) else content
        cache.saveContent(normalized)
        Prefs.currentHash = hash
        _content.value = normalized
        PowerController.evaluate(normalized)
        Log.i(TAG, "New content hash=$hash mode=${normalized.mode} items=${normalized.items?.size ?: 0}")
        prefetch(normalized)
    }

    private fun prefetch(content: Content) {
        prefetchJob?.cancel()
        prefetchJob = scope.launch {
            val urls = ContentCache.mediaUrls(content)
            for (u in urls) {
                if (!isActive) break
                cache.download(u)
            }
            // Only prune after a full successful set so offline fallbacks are never deleted early.
            if (urls.all { cache.cachedFile(it) != null }) cache.prune(urls)
        }
    }

    // ------------------------------------------------------------------ played history

    fun reportPlayed(contentId: Long?, startedAtMillis: Long, durationSec: Int) {
        if (contentId == null || durationSec <= 0) return
        synchronized(played) {
            played.add(PlayedItem(contentId, Utils.isoTime(startedAtMillis), durationSec))
            if (played.size > 500) played.subList(0, played.size - 500).clear()
            if (played.size >= 20) scope.launch { runCatching { flushPlayed() } }
        }
    }

    private suspend fun flushPlayed() {
        val service = api() ?: return
        val batch = synchronized(played) {
            if (played.isEmpty()) return
            played.toList().also { played.clear() }
        }
        try {
            ApiClient.call { service.played(PlayedRequest(batch)) }
        } catch (e: Exception) {
            // Put them back for the next attempt (bounded).
            synchronized(played) {
                played.addAll(0, batch)
                if (played.size > 500) played.subList(0, played.size - 500).clear()
            }
            if (e is ApiException && e.isInvalidToken) throw e
        }
    }

    // ------------------------------------------------------------------ status helpers

    private fun markError(e: Exception, online: Boolean) {
        Log.w(TAG, "Poll failed: ${ApiClient.describe(e)}")
        _status.value = _status.value.copy(online = online, lastError = ApiClient.describe(e))
    }

    private fun onInvalidToken() {
        Log.w(TAG, "Token rejected by server – returning to setup")
        Prefs.clearRegistration()
        _status.value = _status.value.copy(online = true, registered = false, lastError = "INVALID_TOKEN")
        stop()
        _events.tryEmit(SyncEvent.Unregistered)
    }

    // ------------------------------------------------------------------ CommandActions

    override suspend fun refetchContent() {
        val roomId = Prefs.roomId
        val service = api()
        if (roomId > 0 && service != null) {
            try {
                val c = ApiClient.call { service.content(roomId) }
                if (c != null) {
                    applyContent(c, c.hash)
                    return
                }
            } catch (e: ApiException) {
                if (e.isInvalidToken) throw e
                Log.w(TAG, "content/$roomId failed: ${e.message}")
            }
        }
        // Fall back to forcing a full content response on the next poll.
        Prefs.currentHash = ""
        pollNow()
    }

    override suspend fun clearCache() {
        prefetchJob?.cancel()
        cache.clearAll()
        Prefs.currentHash = ""
        pollNow()
    }

    override fun setScreenOn(on: Boolean) {
        Prefs.forcedScreenOff = !on
        PowerController.evaluate(_content.value)
        _events.tryEmit(SyncEvent.ScreenStateChanged)
    }

    override fun reload() {
        _events.tryEmit(SyncEvent.Reload)
    }

    override fun reboot() {
        KioskHelper.reboot(app)
    }

    override suspend fun updateApp(
        url: String,
        sha256: String?,
        versionCode: Int?,
        versionName: String?,
        beforeInstall: suspend (String) -> Unit,
    ): AppUpdater.Result = AppUpdater(app).update(url, sha256, versionCode, versionName, beforeInstall)

    /** Local "Clear Cache" from settings. */
    fun clearCacheLocal() {
        scope.launch { clearCache() }
    }
}
