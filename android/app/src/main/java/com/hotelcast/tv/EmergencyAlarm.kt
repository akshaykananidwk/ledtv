package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.media.AudioManager
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import com.google.android.exoplayer2.C
import com.google.android.exoplayer2.ExoPlayer
import com.google.android.exoplayer2.MediaItem
import com.google.android.exoplayer2.PlaybackException
import com.google.android.exoplayer2.Player
import com.google.android.exoplayer2.audio.AudioAttributes

/**
 * Emergency alarm (2.4.1, docs/modules/emergency_alarm.md): while the emergency layer is up and
 * `content.emergency.alarm` is set, a dedicated ExoPlayer plays the alarm (looping, or `repeat` times).
 *
 *  - Volume: STREAM_MUSIC is raised to at least `alarm.volume` % of its maximum (and unmuted); when the alarm
 *    stops, the previous level (and mute) is restored unless someone changed the volume meanwhile. The state
 *    is persisted ([Prefs.alarmVolumeRestore]) so a restart in the middle of an alarm still restores it. The
 *    volume policy (max / night max, [VolumeController.enforce]) does not lower the volume while it holds.
 *  - Content audio: the content player is stopped while an emergency is shown (MainActivity.render), so only
 *    the alarm is heard.
 *  - Driven by every content update ([SyncManager.applyContent]) and every render of [MainActivity]: the same
 *    alarm re-sent by a sync never restarts it; a new sound / loop mode restarts it; no alarm (stopped or
 *    silenced) stops it at once ([AlarmPlan.decide]).
 *  - Screen off: the alarm does not depend on the activity, so it sounds even if the TV could not be woken.
 *    When the player activity pauses while the screen is on (the guest left for HDMI / settings), the alarm
 *    stops; it starts again when the player comes back, or at once for a different alarm.
 *  - Offline: the file is played from [ContentCache] when cached (prefetched with the content); otherwise it
 *    streams, and a failed load is retried (from the cache once the download has finished).
 *
 * All state lives on the main thread.
 */
@SuppressLint("StaticFieldLeak") // application context only
object EmergencyAlarm {
    private const val TAG = "EmergencyAlarm"
    private const val STREAM = AudioManager.STREAM_MUSIC
    private const val RETRY_MS = 5_000L
    private const val MAX_RETRIES = 60

    private val main = Handler(Looper.getMainLooper())
    private lateinit var app: Context
    private var cache: ContentCache? = null
    private var player: ExoPlayer? = null
    private var current: AlarmSpec? = null
    private var plays = 0
    private var retries = 0
    /** Key of the alarm stopped because the player activity was left; it does not restart until resumed. */
    private var suspendedKey: String? = null

    /** True while the alarm holds STREAM_MUSIC raised (the volume policy must not clamp it). */
    @Volatile var holdsVolume: Boolean = false
        private set

    /** True while the alarm player exists (playing or buffering). */
    val isPlaying: Boolean get() = player != null

    fun init(context: Context, contentCache: ContentCache) {
        if (::app.isInitialized) return
        app = context.applicationContext
        cache = contentCache
    }

    /** New content (any thread). */
    fun update(content: Content?) {
        if (!::app.isInitialized) return
        val next = AlarmPlan.from(content)
        onMain { apply(next) }
    }

    /** The player activity paused. With the screen still on the guest left the app: stop the alarm. */
    fun onHostPaused(screenInteractive: Boolean) {
        if (!::app.isInitialized || !screenInteractive) return
        onMain {
            val c = current ?: return@onMain
            suspendedKey = AlarmPlan.key(c)
            stop("player left")
        }
    }

    /** The player activity is back in front: the alarm of the current content plays again. */
    fun onHostResumed(content: Content?) {
        if (!::app.isInitialized) return
        val next = AlarmPlan.from(content)
        onMain {
            suspendedKey = null
            apply(next)
        }
    }

    private fun onMain(block: () -> Unit) {
        if (Looper.myLooper() == Looper.getMainLooper()) block() else main.post(block)
    }

    private fun apply(next: AlarmSpec?) {
        if (next == null) suspendedKey = null
        if (next != null && suspendedKey == AlarmPlan.key(next)) return
        when (AlarmPlan.decide(current, next)) {
            AlarmPlan.Action.NONE -> if (next == null && Prefs.alarmVolumeRestore.isNotEmpty()) restoreVolume() // leftover of a restart
            AlarmPlan.Action.START, AlarmPlan.Action.RESTART -> start(next!!)
            AlarmPlan.Action.ADJUST_VOLUME -> {
                current = next
                if (player != null) raiseVolume(next!!.volume)
            }
            AlarmPlan.Action.STOP -> stop("emergency stopped / alarm silenced")
        }
    }

    private fun start(spec: AlarmSpec) {
        releasePlayer()
        current = spec
        plays = 0
        retries = 0
        raiseVolume(spec.volume)
        Log.i(TAG, "Alarm ${if (spec.loop) "looping" else "${spec.repeat}×"} at ≥${spec.volume}%: ${spec.url}")
        play(spec)
    }

    private fun play(spec: AlarmSpec) {
        val file = cache?.cachedFile(spec.url)
        val uri = if (file != null) Uri.fromFile(file) else Uri.parse(spec.url)
        try {
            val p = ExoPlayer.Builder(app).build()
            player = p
            p.setAudioAttributes(
                AudioAttributes.Builder().setUsage(C.USAGE_MEDIA).setContentType(C.AUDIO_CONTENT_TYPE_SONIFICATION).build(),
                false, // no audio focus handling: nothing else may lower or pause the alarm
            )
            p.setWakeMode(C.WAKE_MODE_NETWORK) // keeps playing with the screen off
            p.repeatMode = if (spec.loop) Player.REPEAT_MODE_ONE else Player.REPEAT_MODE_OFF
            p.volume = 1f
            p.addListener(object : Player.Listener {
                override fun onPlaybackStateChanged(state: Int) {
                    if (player !== p || state != Player.STATE_ENDED || spec.loop) return
                    plays++
                    if (plays < spec.repeat) {
                        p.seekTo(0)
                        p.play()
                    } else {
                        Log.i(TAG, "Alarm played ${spec.repeat}×")
                        releasePlayer()
                        restoreVolume() // `current` stays: the same alarm is not played again
                    }
                }

                override fun onPlayerError(error: PlaybackException) {
                    if (player !== p) return
                    Log.w(TAG, "Alarm failed: ${error.errorCodeName} (${if (file != null) "cache" else "network"})")
                    ErrorLog.add("alarm", error.errorCodeName)
                    releasePlayer()
                    if (retries++ < MAX_RETRIES) {
                        main.postDelayed({ if (current === spec && player == null) play(spec) }, RETRY_MS)
                    }
                }
            })
            p.setMediaItem(MediaItem.fromUri(uri))
            p.prepare()
            p.play()
        } catch (e: Exception) {
            Log.w(TAG, "Alarm player failed: ${e.message}")
            ErrorLog.add("alarm", e.message)
            releasePlayer()
        }
    }

    private fun stop(reason: String) {
        if (current != null || player != null) Log.i(TAG, "Alarm stopped: $reason")
        current = null // a pending retry sees this and does nothing
        releasePlayer()
        restoreVolume()
    }

    private fun releasePlayer() {
        val p = player ?: return
        player = null
        runCatching { p.stop() }
        runCatching { p.release() }
    }

    private fun am(): AudioManager? = app.getSystemService(Context.AUDIO_SERVICE) as? AudioManager

    private fun isMuted(am: AudioManager): Boolean =
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && runCatching { am.isStreamMute(STREAM) }.getOrDefault(false)

    @Suppress("DEPRECATION")
    private fun setMute(am: AudioManager, muted: Boolean) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            am.adjustStreamVolume(STREAM, if (muted) AudioManager.ADJUST_MUTE else AudioManager.ADJUST_UNMUTE, 0)
        } else {
            am.setStreamMute(STREAM, muted)
        }
    }

    /** STREAM_MUSIC to at least [percent] % (unmuted); remembers the previous state once per alarm. */
    private fun raiseVolume(percent: Int) {
        val am = am() ?: return
        try {
            if (runCatching { am.isVolumeFixed }.getOrDefault(false)) {
                Log.i(TAG, "Volume is fixed on this TV: alarm plays at the TV's own volume")
                return
            }
            val max = am.getStreamMaxVolume(STREAM)
            val cur = am.getStreamVolume(STREAM)
            val saved = AlarmPlan.VolumeRestore.parse(Prefs.alarmVolumeRestore)
            val muted = isMuted(am)
            // Base for "at least": the guest's own level (not a level this alarm raised it to).
            val base = if (saved != null && cur == saved.raisedIndex) saved.previousIndex else cur
            val target = AlarmPlan.raisedIndex(base, max, percent)
            if (muted) setMute(am, false)
            if (target != cur) am.setStreamVolume(STREAM, target, 0)
            Prefs.alarmVolumeRestore = AlarmPlan.VolumeRestore(
                previousIndex = saved?.previousIndex ?: cur,
                raisedIndex = target,
                wasMuted = saved?.wasMuted ?: muted,
            ).serialize()
            holdsVolume = true
            Log.i(TAG, "Volume $cur → $target of $max (alarm ≥$percent%)" + if (muted) ", unmuted" else "")
        } catch (e: Exception) {
            Log.w(TAG, "Volume not raised: ${e.message}")
        }
    }

    private fun restoreVolume() {
        holdsVolume = false
        val saved = AlarmPlan.VolumeRestore.parse(Prefs.alarmVolumeRestore)
        Prefs.alarmVolumeRestore = ""
        saved ?: return
        val am = am() ?: return
        try {
            val now = am.getStreamVolume(STREAM)
            AlarmPlan.restoreIndex(saved, now)?.let { am.setStreamVolume(STREAM, it, 0) }
            if (saved.wasMuted) setMute(am, true)
            Log.i(TAG, "Volume restored: $now → ${AlarmPlan.restoreIndex(saved, now) ?: now}" + if (saved.wasMuted) ", muted again" else "")
        } catch (e: Exception) {
            Log.w(TAG, "Volume not restored: ${e.message}")
        }
    }
}
