package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioTrack
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import android.util.Log
import com.google.android.exoplayer2.ExoPlayer
import com.google.android.exoplayer2.MediaItem
import com.google.android.exoplayer2.PlaybackException
import com.google.android.exoplayer2.Player
import kotlinx.coroutines.CancellableContinuation
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.util.Locale
import java.util.WeakHashMap
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicInteger
import kotlin.math.PI
import kotlin.math.exp
import kotlin.math.sin

/** SPEAK payload, validated ([AnnounceSpec.speak]). */
data class SpeakRequest(
    val text: String,
    /** gu | hi | en | auto */
    val lang: String,
    val rate: Float,
    val repeat: Int,
    /** 0–100 relative to the media volume, null = full. */
    val volume: Int?,
    val chimeBefore: Boolean,
)

/** PLAY_SOUND payload, validated ([AnnounceSpec.sound]). */
data class SoundRequest(val url: String, val volume: Int, val repeat: Int)

/** Pure parsing / validation of the 2.4 announcement commands (unit tested in AnnouncerTest). */
object AnnounceSpec {
    const val MAX_TEXT = 1000
    const val MAX_SOUND_MS = 120_000L
    const val DUCK_FACTOR = 0.15f
    val LANGS = setOf("gu", "hi", "en", "auto")

    fun speak(cmd: Command): SpeakRequest {
        val text = cmd.payloadString("text")?.trim().orEmpty()
        if (text.isEmpty()) throw CommandFailedException("Missing text")
        val lang = cmd.payloadString("lang")?.trim()?.lowercase(Locale.ROOT).orEmpty().ifEmpty { "auto" }
        if (lang !in LANGS) throw CommandFailedException("Invalid lang '$lang' (use gu, hi, en or auto)")
        val rate = (cmd.payloadDouble("rate") ?: 1.0).toFloat().takeIf { !it.isNaN() } ?: 1f
        val volume = cmd.payloadDouble("volume")?.toInt()?.coerceIn(0, 100)
        return SpeakRequest(
            text = text.take(MAX_TEXT),
            lang = lang,
            rate = rate.coerceIn(0.5f, 2f),
            repeat = (cmd.payloadDouble("repeat")?.toInt() ?: 1).coerceIn(1, 3),
            volume = volume,
            chimeBefore = cmd.payloadBool("chime_before") ?: false,
        )
    }

    fun sound(cmd: Command): SoundRequest {
        val url = cmd.payloadString("url")?.trim().orEmpty()
        val scheme = url.substringBefore("://", "").lowercase(Locale.ROOT)
        if (url.isEmpty() || (scheme != "http" && scheme != "https") || url.length > 2048) {
            throw CommandFailedException("Missing or invalid url (http/https)")
        }
        return SoundRequest(
            url = url,
            volume = (cmd.payloadDouble("volume")?.toInt() ?: 100).coerceIn(0, 100),
            repeat = (cmd.payloadDouble("repeat")?.toInt() ?: 1).coerceIn(1, 10),
        )
    }

    /**
     * 2.4.1 SHOW_MESSAGE `sound` {url, repeat 1–5, volume 0–100}: the sound played when the message appears,
     * or null (none, or not a valid http(s) url — the message is still shown).
     */
    fun messageSound(cmd: Command): SoundRequest? {
        val o = try {
            cmd.payload?.get("sound")?.takeIf { it.isJsonObject }?.asJsonObject
        } catch (e: Exception) {
            null
        } ?: return null
        val inner = Command(cmd.id, "PLAY_SOUND", o)
        val req = try { sound(inner) } catch (e: CommandFailedException) { return null }
        return req.copy(
            repeat = (inner.payloadDouble("repeat")?.toInt() ?: 1).coerceIn(1, 5),
            volume = (inner.payloadDouble("volume")?.toInt() ?: 80).coerceIn(0, 100),
        )
    }

    /** "auto": Gujarati script → gu, Devanagari → hi, otherwise en. */
    fun detectLang(text: String): String {
        var gu = 0
        var hi = 0
        var latin = 0
        for (ch in text) {
            when (ch) {
                in '઀'..'૿' -> gu++
                in 'ऀ'..'ॿ' -> hi++
                in 'A'..'Z', in 'a'..'z' -> latin++
            }
        }
        return when {
            gu > 0 && gu >= hi -> "gu"
            hi > 0 -> "hi"
            else -> "en"
        }
    }

    fun resolveLang(req: SpeakRequest): String = if (req.lang == "auto") detectLang(req.text) else req.lang

    /** BCP-47 tag of the voice to use. */
    fun localeTag(lang: String): String = when (lang) {
        "gu" -> "gu-IN"
        "hi" -> "hi-IN"
        else -> "en-IN"
    }

    /** Two-tone "ding-dong" chime (mono PCM 16 bit), with a soft decay. */
    fun chimeSamples(sampleRate: Int = 22_050): ShortArray {
        val toneMs = 420
        val n = sampleRate * toneMs / 1000
        val out = ShortArray(n * 2)
        for ((t, freq) in listOf(880.0, 659.25).withIndex()) {
            for (i in 0 until n) {
                val x = i.toDouble() / sampleRate
                val env = exp(-3.5 * x / (toneMs / 1000.0)) * minOf(1.0, i / (sampleRate * 0.01))
                val v = (sin(2 * PI * freq * x) * 0.6 + sin(4 * PI * freq * x) * 0.15) * env
                out[t * n + i] = (v * Short.MAX_VALUE * 0.8).toInt().coerceIn(Short.MIN_VALUE.toInt(), Short.MAX_VALUE.toInt()).toShort()
            }
        }
        return out
    }

    /** Max time one utterance may take before we give up waiting (TTS engines can hang). */
    fun utteranceTimeoutMs(text: String, rate: Float): Long =
        (10_000L + (text.length * 180L / rate.coerceAtLeast(0.5f)).toLong()).coerceAtMost(300_000L)
}

fun Command.payloadDouble(key: String): Double? = try {
    payload?.get(key)?.takeIf { !it.isJsonNull }?.asDouble
} catch (e: Exception) {
    null
}

fun Command.payloadBool(key: String): Boolean? = try {
    payload?.get(key)?.takeIf { !it.isJsonNull }?.let { el ->
        val p = el.asJsonPrimitive
        when {
            p.isBoolean -> p.asBoolean
            p.isNumber -> p.asInt != 0
            else -> p.asString.trim().lowercase(Locale.ROOT) in setOf("1", "true", "yes", "on")
        }
    }
} catch (e: Exception) {
    null
}

/**
 * Lowers the volume of every content ExoPlayer (videos, streams, layout zones) while an announcement
 * or sound plays, then restores it. Web pages / YouTube are not ducked (their audio is inside the
 * WebView). All calls are posted to the main thread (ExoPlayer's application thread).
 */
object AudioDuck {
    private val main = Handler(Looper.getMainLooper())
    private val players = WeakHashMap<ExoPlayer, Float>()
    @Volatile var factor: Float = 1f
        private set

    /** Register a content player; its current volume is the base (0 = muted item / zone). Main thread. */
    fun track(player: ExoPlayer) {
        val base = player.volume
        synchronized(players) { players[player] = base }
        if (factor != 1f) runCatching { player.volume = base * factor }
    }

    fun duck(to: Float = AnnounceSpec.DUCK_FACTOR) = apply(to)
    fun restore() = apply(1f)

    private fun apply(f: Float) {
        factor = f
        main.post {
            val snapshot = synchronized(players) { players.entries.map { it.key to it.value } }
            for ((p, base) in snapshot) runCatching { p.volume = base * f }
        }
    }
}

/**
 * Plays SPEAK (TextToSpeech) and PLAY_SOUND (separate ExoPlayer) one after another — never
 * overlapping — and ducks the content audio while the queue is busy.
 */
@SuppressLint("StaticFieldLeak") // application context only
object Announcer {
    private const val TAG = "Announcer"
    private const val MAX_QUEUE = 10
    private const val TTS_IDLE_RELEASE_MS = 10 * 60_000L

    private sealed class Task {
        data class Speak(val req: SpeakRequest, val locale: Locale?) : Task()
        data class Sound(val req: SoundRequest) : Task()
    }

    private lateinit var app: Context
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    private val queue = Channel<Task>(Channel.UNLIMITED)
    private val pending = AtomicInteger(0)
    private var tts: TextToSpeech? = null
    private var ttsReady = false
    private var ttsFailed = false
    private val waiting = ConcurrentHashMap<String, CancellableContinuation<Boolean>>()
    private val ttsLock = kotlinx.coroutines.sync.Mutex()
    @Volatile private var lastUse = 0L

    @Synchronized
    fun init(context: Context) {
        if (::app.isInitialized) return
        app = context.applicationContext
        scope.launch { worker() }
        scope.launch { idleReleaser() }
    }

    val queued: Int get() = pending.get()

    /** Queues a SPEAK; returns the ack message (voice used / fallback). Throws CommandFailedException. */
    suspend fun speak(req: SpeakRequest): String {
        if (pending.get() >= MAX_QUEUE) throw CommandFailedException("Announcement queue full ($MAX_QUEUE)")
        val engine = engine() ?: throw CommandFailedException("No text-to-speech engine on this TV")
        val lang = AnnounceSpec.resolveLang(req)
        val tag = AnnounceSpec.localeTag(lang)
        val wanted = Locale.forLanguageTag(tag)
        val avail = try { engine.isLanguageAvailable(wanted) } catch (_: Exception) { TextToSpeech.LANG_NOT_SUPPORTED }
        val useLocale: Locale?
        val voiceNote: String
        if (avail >= TextToSpeech.LANG_AVAILABLE) {
            useLocale = wanted
            voiceNote = "voice $tag"
        } else {
            useLocale = null
            val def = try { engine.defaultVoice?.locale?.toLanguageTag() } catch (_: Exception) { null } ?: Locale.getDefault().toLanguageTag()
            voiceNote = "voice $tag not installed – default voice ($def) used"
        }
        val ahead = enqueue(Task.Speak(req, useLocale))
        val parts = mutableListOf(if (ahead > 0) "Queued ($ahead ahead)" else "Speaking", voiceNote)
        if (req.repeat > 1) parts.add("${req.repeat}×")
        if (req.chimeBefore) parts.add("chime")
        return parts.joinToString(", ")
    }

    /** Queues a PLAY_SOUND; returns the ack message. */
    fun playSound(req: SoundRequest): String {
        if (pending.get() >= MAX_QUEUE) throw CommandFailedException("Announcement queue full ($MAX_QUEUE)")
        val ahead = enqueue(Task.Sound(req))
        return (if (ahead > 0) "Queued ($ahead ahead)" else "Playing") +
            " sound, volume ${req.volume}%" + (if (req.repeat > 1) ", ${req.repeat}×" else "") + ", max 2 min"
    }

    private fun enqueue(t: Task): Int {
        val ahead = pending.getAndIncrement()
        queue.trySend(t)
        return ahead
    }

    private suspend fun worker() {
        for (task in queue) {
            AudioDuck.duck()
            try {
                when (task) {
                    is Task.Speak -> runSpeak(task)
                    is Task.Sound -> runSound(task.req)
                }
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                Log.w(TAG, "announcement failed: ${e.message}")
                ErrorLog.add("announce", e.message)
            } finally {
                lastUse = System.currentTimeMillis()
                if (pending.decrementAndGet() <= 0) {
                    delay(300)
                    if (pending.get() <= 0) AudioDuck.restore()
                }
            }
        }
    }

    // ------------------------------------------------------------------ TTS

    private suspend fun engine(): TextToSpeech? {
        ttsLock.withLock {
            lastUse = System.currentTimeMillis()
            if (tts != null && ttsReady) return tts
            if (tts == null || ttsFailed) {
                ttsFailed = false
                ttsReady = false
                val ready = kotlinx.coroutines.CompletableDeferred<Boolean>()
                tts = withContext(Dispatchers.Main) {
                    TextToSpeech(app) { status -> ready.complete(status == TextToSpeech.SUCCESS) }
                }
                val ok = withTimeoutOrNull(10_000) { ready.await() } ?: false
                if (!ok) {
                    ttsFailed = true
                    runCatching { tts?.shutdown() }
                    tts = null
                    return null
                }
                tts?.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
                    override fun onStart(utteranceId: String?) {}
                    override fun onDone(utteranceId: String?) = finish(utteranceId, true)
                    @Deprecated("Deprecated in Java")
                    override fun onError(utteranceId: String?) = finish(utteranceId, false)
                    override fun onError(utteranceId: String?, errorCode: Int) = finish(utteranceId, false)
                    override fun onStop(utteranceId: String?, interrupted: Boolean) = finish(utteranceId, false)
                })
                ttsReady = true
            }
            return tts
        }
    }

    private fun finish(id: String?, ok: Boolean) {
        val c = id?.let { waiting.remove(it) } ?: return
        if (c.isActive) c.resumeWith(Result.success(ok))
    }

    private suspend fun runSpeak(task: Task.Speak) {
        val req = task.req
        val engine = engine() ?: return
        if (req.chimeBefore) playChime(req.volume)
        try {
            if (task.locale != null) engine.setLanguage(task.locale) else engine.defaultVoice?.let { engine.voice = it }
        } catch (e: Exception) {
            Log.w(TAG, "setLanguage failed: ${e.message}")
        }
        engine.setSpeechRate(req.rate)
        repeat(req.repeat) { n ->
            val id = "hc-" + System.nanoTime()
            val params = Bundle().apply {
                putInt(TextToSpeech.Engine.KEY_PARAM_STREAM, AudioManager.STREAM_MUSIC)
                req.volume?.let { putFloat(TextToSpeech.Engine.KEY_PARAM_VOLUME, it / 100f) }
            }
            val done = withTimeoutOrNull(AnnounceSpec.utteranceTimeoutMs(req.text, req.rate)) {
                suspendCancellableCoroutine<Boolean> { cont ->
                    waiting[id] = cont
                    cont.invokeOnCancellation { waiting.remove(id) }
                    if (engine.speak(req.text, TextToSpeech.QUEUE_ADD, params, id) != TextToSpeech.SUCCESS) finish(id, false)
                }
            }
            if (done == null) {
                waiting.remove(id)
                runCatching { engine.stop() }
            }
            if (n < req.repeat - 1) delay(1_200)
        }
    }

    @Suppress("DEPRECATION")
    private suspend fun playChime(volume: Int?) = withContext(Dispatchers.IO) {
        val rate = 22_050
        val pcm = AnnounceSpec.chimeSamples(rate)
        var track: AudioTrack? = null
        try {
            track = AudioTrack(AudioManager.STREAM_MUSIC, rate, AudioFormat.CHANNEL_OUT_MONO, AudioFormat.ENCODING_PCM_16BIT, pcm.size * 2, AudioTrack.MODE_STATIC)
            track.write(pcm, 0, pcm.size)
            val v = (volume ?: 100) / 100f
            track.setStereoVolume(v, v)
            track.play()
            delay(pcm.size * 1000L / rate + 150)
        } catch (e: Exception) {
            Log.w(TAG, "chime failed: ${e.message}")
        } finally {
            runCatching { track?.stop() }
            runCatching { track?.release() }
        }
    }

    private suspend fun idleReleaser() {
        while (true) {
            delay(60_000)
            if (pending.get() == 0 && tts != null && System.currentTimeMillis() - lastUse > TTS_IDLE_RELEASE_MS) {
                ttsLock.withLock {
                    runCatching { tts?.shutdown() }
                    tts = null
                    ttsReady = false
                }
            }
        }
    }

    // ------------------------------------------------------------------ PLAY_SOUND

    private suspend fun runSound(req: SoundRequest) {
        var exo: ExoPlayer? = null
        try {
            withTimeoutOrNull(AnnounceSpec.MAX_SOUND_MS) {
                suspendCancellableCoroutine<Unit> { cont ->
                    Handler(Looper.getMainLooper()).post {
                        try {
                            val p = ExoPlayer.Builder(app).build()
                            exo = p
                            var plays = 0
                            p.volume = req.volume / 100f
                            p.addListener(object : Player.Listener {
                                override fun onPlaybackStateChanged(state: Int) {
                                    if (state != Player.STATE_ENDED) return
                                    plays++
                                    if (plays < req.repeat) {
                                        p.seekTo(0)
                                        p.play()
                                    } else if (cont.isActive) cont.resumeWith(Result.success(Unit))
                                }

                                override fun onPlayerError(error: PlaybackException) {
                                    Log.w(TAG, "sound failed: ${error.errorCodeName} ${req.url}")
                                    ErrorLog.add("play_sound", error.errorCodeName)
                                    if (cont.isActive) cont.resumeWith(Result.success(Unit))
                                }
                            })
                            p.setMediaItem(MediaItem.fromUri(Uri.parse(req.url)))
                            p.prepare()
                            p.play()
                        } catch (e: Exception) {
                            Log.w(TAG, "sound player failed: ${e.message}")
                            if (cont.isActive) cont.resumeWith(Result.success(Unit))
                        }
                    }
                }
            }
        } finally {
            withContext(Dispatchers.Main) {
                runCatching { exo?.stop() }
                runCatching { exo?.release() }
            }
        }
    }
}
