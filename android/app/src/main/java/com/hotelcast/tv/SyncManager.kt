package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.util.Log
import com.google.gson.JsonObject
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.lang.ref.WeakReference
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
import kotlinx.coroutines.flow.combine
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

/** What the visible player can do for server commands (implemented by MainActivity, main thread only). */
interface PlayerUi {
    /** SHOW_WELCOME: returns the ack message or throws CommandFailedException. */
    fun showWelcomeNow(): String
    fun showMessage(title: String?, message: String?, durationSec: Int): String
    fun openInput(input: String): String
    /** Captures the player window; callback receives null on failure. */
    fun captureScreen(callback: (Bitmap?) -> Unit)
}

/** Weak link from the process-wide [SyncManager] to the player activity currently started. */
object UiBridge {
    @Volatile private var ref: WeakReference<PlayerUi>? = null

    var player: PlayerUi?
        get() = ref?.get()
        set(v) {
            ref = v?.let { WeakReference(it) }
        }

    fun clear(p: PlayerUi) {
        if (ref?.get() === p) ref = null
    }
}

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
    private const val VOLUME_CHECK_MS = 30_000L

    private lateinit var app: Context
    private lateinit var cache: ContentCache
    private lateinit var commandHandler: CommandHandler

    private val errorHandler = CoroutineExceptionHandler { _, e -> Log.e(TAG, "Uncaught in sync scope", e) }
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO + errorHandler)
    private var pollJob: Job? = null
    private var heartbeatJob: Job? = null
    private var prefetchJob: Job? = null
    private var volumeJob: Job? = null
    private val wake = Channel<Unit>(Channel.CONFLATED)
    private val pollMutex = Mutex()

    /** Server content (or the cached copy of it). */
    private val _content = MutableStateFlow<Content?>(null)
    /** 2.4: what the player shows — the server content, or the USB folder (UsbPlaylist.effective). */
    private val _effective = MutableStateFlow<Content?>(null)
    val content: StateFlow<Content?> = _effective
    private var liveJob: Job? = null

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
        // 2.4: USB / offline mode, announcements.
        _effective.value = _content.value
        Announcer.init(app)
        UsbMedia.init(app)
        scope.launch {
            combine(_content, UsbMedia.items) { c, usb -> c to usb }.collect { (c, usb) ->
                UsbMedia.mayNeedUsb = c == null || c.usbMode == true
                _effective.value = UsbPlaylist.effective(c, usb, UsbMedia.hash)
            }
        }
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
        if (volumeJob?.isActive != true) volumeJob = scope.launch { volumeLoop() }
        // Re-download any media missing from the cache (e.g. after a cold start while offline)
        _content.value?.let { prefetch(it) }
    }

    @Synchronized
    fun stop() {
        pollJob?.cancel()
        heartbeatJob?.cancel()
        prefetchJob?.cancel()
        volumeJob?.cancel()
        pollJob = null
        heartbeatJob = null
        volumeJob = null
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

    /**
     * Volume policy: volume.default once per stay, and every 30 s clamp STREAM_MUSIC to volume.max /
     * the night max while the screen is on (also while the guest is in Live TV / HDMI).
     */
    private suspend fun volumeLoop() {
        delay(5_000)
        _content.value?.let { VolumeController.applyDefaultIfNewStay(app, it) }
        while (currentCoroutineContext().isActive) {
            try {
                val c = _content.value
                if (c?.volume != null && PowerController.isScreenInteractive()) VolumeController.enforce(app, c.volume)
            } catch (e: Exception) {
                Log.w(TAG, "volume check failed: ${e.message}")
            }
            delay(VOLUME_CHECK_MS)
        }
    }

    /** Clamp right away (e.g. after the guest pressed VOLUME_UP). */
    fun enforceVolumeSoon() {
        if (!::app.isInitialized) return
        val cfg = _content.value?.volume ?: return
        scope.launch { VolumeController.enforce(app, cfg) }
    }

    private suspend fun heartbeatLoop() {
        delay(2_000)
        try {
            CrashReporter.uploadPending(app) { req -> api()?.let { s -> ApiClient.call { s.crash(req) } } }
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Exception) {
            Log.w(TAG, "crash upload failed: ${e.message}")
        }
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
        val sentAt = ServerClock.localNow()
        val data = ApiClient.call { service.poll(Prefs.deviceId, Prefs.currentHash) } ?: return@withLock
        ServerClock.onResponse(sentAt, data.serverTimeMs) // 2.4 synchronized playback (#37)
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
            health = try {
                DeviceHealth.collect(app, mapOf(
                    "usb" to UsbMedia.state(UsbPlaylist.decide(c, UsbMedia.items.value)),
                    "cec" to CecControl.state(app, c),
                    "live_view" to (liveJob?.isActive == true),
                ))
            } catch (e: Exception) {
                null
            },
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
        val wasEmergency = _content.value?.isEmergency == true
        cache.saveContent(normalized)
        Prefs.currentHash = hash
        _content.value = normalized
        normalized.branding?.product?.takeIf { it.isNotBlank() }?.let { if (Prefs.brandProduct != it) Prefs.brandProduct = it }
        normalized.branding?.color?.takeIf { it.isNotBlank() }?.let { if (Prefs.brandColor != it) Prefs.brandColor = it }
        normalized.hotel?.name?.takeIf { it.isNotBlank() }?.let { if (Prefs.hotelName != it) Prefs.hotelName = it }
        PowerController.evaluate(normalized)
        if (normalized.isEmergency && !wasEmergency) bringPlayerToFront() // e.g. guest is in Live TV / HDMI
        VolumeController.applyDefaultIfNewStay(app, normalized)
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

    fun reportPlayed(contentId: Long?, startedAtMillis: Long, durationSec: Int, adCampaignId: Long? = null) {
        if (contentId == null || durationSec <= 0) return
        synchronized(played) {
            played.add(PlayedItem(contentId, Utils.isoTime(startedAtMillis), durationSec, adCampaignId))
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

    /** Fire-and-forget analytics event (POST device/event); old servers' 404 is ignored. */
    fun reportEvent(type: String, data: Map<String, Any?> = emptyMap()) {
        if (!::app.isInitialized || !Prefs.isRegistered) return
        scope.launch {
            try {
                val service = api() ?: return@launch
                val json = ApiClient.gson.toJsonTree(data).takeIf { it.isJsonObject }?.asJsonObject ?: JsonObject()
                ApiClient.call { service.event(EventRequest(type, json)) }
            } catch (e: Exception) {
                Log.i(TAG, "event $type not sent: ${ApiClient.describe(e)}")
            }
        }
    }

    private fun bringPlayerToFront() {
        try {
            app.startActivity(
                Intent(app, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
            )
        } catch (e: Exception) {
            Log.w(TAG, "Cannot bring player to front: ${e.message}")
        }
    }

    private fun markError(e: Exception, online: Boolean) {
        Log.w(TAG, "Poll failed: ${ApiClient.describe(e)}")
        ErrorLog.add("poll", ApiClient.describe(e))
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

    override suspend fun setScreenOn(on: Boolean): String {
        Prefs.forcedScreenOff = !on
        _events.tryEmit(SyncEvent.ScreenStateChanged)
        val result = PowerController.force(on, _content.value)
        heartbeatSoon()
        return result
    }

    /** Screen switched on (power-on from standby): welcome re-show rule + checkout reminder come back. */
    fun notifyPowerOn() {
        GuestSession.onPowerOn()
        _events.tryEmit(SyncEvent.ScreenStateChanged)
    }

    /** Send a heartbeat right away (e.g. after the screen turned on/off) so the admin panel is current. */
    fun heartbeatSoon() {
        if (!::app.isInitialized || !Prefs.isRegistered) return
        scope.launch {
            delay(1_500)
            runCatching { heartbeatOnce() }
        }
    }

    override fun reload() {
        // 2.4: also "back to the player" when Live TV / HDMI / another app is in front (PlayerFront).
        when (PlayerFront.reloadAction(UiBridge.player != null, KioskHelper.externalAppActive)) {
            PlayerFront.ReloadAction.RESTART_PLAYER -> _events.tryEmit(SyncEvent.Reload)
            PlayerFront.ReloadAction.BRING_TO_FRONT -> {
                if (KioskHelper.isDeviceOwner(app)) {
                    try {
                        KioskHelper.restoreKioskPackages(app)
                    } catch (e: Exception) {
                        Log.w(TAG, "restore kiosk packages failed: ${e.message}")
                    }
                }
                bringPlayerToFront() // MainActivity.onResume re-enters lock task (enterKioskIfPossible)
            }
        }
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

    // ---- V2 commands

    private fun now() = VolumePolicy.minutesOfDay()

    override suspend fun setVolume(level: Int): String {
        val cfg = _content.value?.volume
        val allowed = VolumePolicy.clamp(level, cfg, now())
        val msg = VolumeController.setPercent(app, allowed)
        return if (allowed < level) "$msg (requested $level%, limited by ${if (VolumePolicy.isNight(cfg, now())) "night max" else "max"})" else msg
    }

    override suspend fun setMuted(muted: Boolean): String = VolumeController.setMuted(app, muted)

    private suspend fun <T> onPlayer(block: (PlayerUi) -> T): T = withContext(Dispatchers.Main) {
        val p = UiBridge.player ?: throw CommandFailedException("Player screen is not open (TV in standby, settings or another app)")
        block(p)
    }

    override suspend fun showWelcome(): String = onPlayer { it.showWelcomeNow() }

    override suspend fun showMessage(title: String?, message: String?, durationSec: Int): String =
        onPlayer { it.showMessage(title, message, durationSec) }

    override suspend fun openInput(input: String): String = onPlayer { it.openInput(input) }

    override suspend fun takeScreenshot(): String {
        val jpeg = captureJpeg(ScreenshotEncoder.MAX_WIDTH, ScreenshotEncoder.QUALITY)
        val service = api() ?: throw CommandFailedException("Server not configured")
        val part = MultipartBody.Part.createFormData(
            "image", "screenshot-${System.currentTimeMillis()}.jpg", jpeg.toRequestBody("image/jpeg".toMediaType())
        )
        ApiClient.call { service.screenshot(part) }
        return "Screenshot uploaded (${jpeg.size / 1024} KB)"
    }

    /** Captures the player window as JPEG (PixelCopy / View.draw, see MainActivity.captureScreen). */
    private suspend fun captureJpeg(maxWidth: Int, quality: Int): ByteArray {
        val bitmap = withContext(Dispatchers.Main) {
            val p = UiBridge.player ?: throw CommandFailedException("Player screen is not open (TV in standby, settings or another app)")
            kotlinx.coroutines.suspendCancellableCoroutine<Bitmap?> { cont ->
                try {
                    p.captureScreen { bmp -> if (cont.isActive) cont.resumeWith(Result.success(bmp)) }
                } catch (e: Exception) {
                    if (cont.isActive) cont.resumeWith(Result.success(null))
                }
            }
        } ?: throw CommandFailedException("Screen capture failed")
        return try {
            withContext(Dispatchers.Default) { ScreenshotEncoder.toJpeg(bitmap, maxWidth, quality) }
        } finally {
            bitmap.recycle()
        }
    }

    // ---- 2.4 commands

    override suspend fun speak(req: SpeakRequest): String = Announcer.speak(req)

    override suspend fun playSound(req: SoundRequest): String = Announcer.playSound(req)

    override suspend fun startLiveView(req: LiveViewRequest): String {
        api() ?: throw CommandFailedException("Server not configured")
        synchronized(this) {
            liveJob?.cancel()
            liveJob = scope.launch { liveViewLoop(req) }
        }
        val note = if (UiBridge.player == null) " – player not on screen yet (standby, settings or another app)" else ""
        return "Live view started (every ${req.intervalSec} s, ≤ ${req.maxWidth} px)$note"
    }

    /** Live view: capture + upload until the server stops it or keepalives stop (LiveViewSpec). */
    private suspend fun liveViewLoop(req: LiveViewRequest) {
        var interval = req.intervalSec
        var deadline = System.currentTimeMillis() + req.maxSec * 1000L
        var failures = 0
        var frames = 0
        val session = req.session.toRequestBody("text/plain".toMediaType())
        Log.i(TAG, "Live view ${req.session.take(6)}… started")
        while (currentCoroutineContext().isActive) {
            val started = System.currentTimeMillis()
            var state: LiveState? = null
            try {
                val jpeg = captureJpeg(req.maxWidth, req.quality)
                val service = api() ?: break
                val part = MultipartBody.Part.createFormData("image", "live-$started.jpg", jpeg.toRequestBody("image/jpeg".toMediaType()))
                state = ApiClient.call { service.liveFrame(part, session) }?.live
                failures = 0
                frames++
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: CommandFailedException) {
                // Player not on screen / capture failed: keep trying until the deadline.
                failures = 0
            } catch (e: ApiException) {
                if (e.isInvalidToken || e.httpStatus == 404 || e.httpStatus == 409) break
                if (e.httpStatus == 429) delay((e.retryAfterSec ?: interval).coerceIn(1, 30) * 1000L) else failures++
            } catch (e: Exception) {
                failures++
            }
            val now = System.currentTimeMillis()
            deadline = LiveViewSpec.deadlineAfter(now, deadline, state)
            state?.interval?.let { interval = LiveViewSpec.clampInterval(it) }
            if (!LiveViewSpec.keepGoing(now, deadline, state, failures)) break
            delay((interval * 1000L - (now - started)).coerceAtLeast(1_000L))
        }
        Log.i(TAG, "Live view stopped after $frames frame(s)")
    }

    override suspend fun uploadLogs(): String {
        val service = api() ?: throw CommandFailedException("Server not configured")
        val logs = withContext(Dispatchers.IO) { LogCollector.capTail(LogCollector.readLogcat()) }
        val state = withContext(Dispatchers.IO) { LogCollector.state(app) }
        ApiClient.call { service.logs(LogsRequest(logs, state)) }
        return "Logs uploaded (${LogCollector.utf8Len(logs) / 1024} KB)"
    }

    /** Local "Clear Cache" from settings. */
    fun clearCacheLocal() {
        scope.launch { clearCache() }
    }
}
