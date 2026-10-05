package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.media.AudioManager
import android.os.Build
import android.util.Log
import java.util.Calendar
import kotlin.math.roundToInt

/** Pure volume rules (unit-tested): percent levels, max / night max, night window wrap-around. */
object VolumePolicy {

    /** "22:00" → 1320 minutes after midnight; null when malformed. */
    fun parseHm(value: String?): Int? {
        val v = value?.trim().orEmpty()
        val m = Regex("^(\\d{1,2}):(\\d{2})(?::\\d{2})?$").matchEntire(v) ?: return null
        val h = m.groupValues[1].toInt()
        val min = m.groupValues[2].toInt()
        if (h !in 0..24 || min !in 0..59 || (h == 24 && min != 0)) return null
        return (h * 60 + min) % (24 * 60)
    }

    /** True when [nowMin] lies in [from, to); the window may wrap past midnight (22:00 → 06:00). */
    fun isNight(cfg: VolumeConfig?, nowMin: Int): Boolean {
        if (cfg?.nightMax == null) return false
        val from = parseHm(cfg.nightFrom) ?: return false
        val to = parseHm(cfg.nightTo) ?: return false
        if (from == to) return false
        return if (from < to) nowMin in from until to else nowMin >= from || nowMin < to
    }

    /** Highest allowed level (percent) right now. */
    fun effectiveMax(cfg: VolumeConfig?, nowMin: Int): Int {
        var max = (cfg?.max ?: 100).coerceIn(0, 100)
        if (isNight(cfg, nowMin)) max = minOf(max, cfg!!.nightMax!!.coerceIn(0, 100))
        return max
    }

    fun clamp(level: Int, cfg: VolumeConfig?, nowMin: Int): Int = level.coerceIn(0, 100).coerceAtMost(effectiveMax(cfg, nowMin))

    /**
     * Key identifying a guest stay, used to apply `volume.default` once per stay: the welcome id,
     * else the check-in time, else "first" (apply once on first content from a server without stays).
     */
    fun stayKey(content: Content?): String? {
        if (content?.volume?.defaultLevel == null) return null
        content.welcome?.id?.takeIf { it.isNotBlank() }?.let { return "w:$it" }
        content.guest?.checkinAt?.takeIf { it.isNotBlank() }?.let { return "c:$it" }
        return "first"
    }

    fun percentToIndex(percent: Int, maxIndex: Int): Int =
        if (maxIndex <= 0) 0 else (percent.coerceIn(0, 100) * maxIndex / 100.0).roundToInt().coerceIn(0, maxIndex)

    fun indexToPercent(index: Int, maxIndex: Int): Int =
        if (maxIndex <= 0) 0 else (index.coerceIn(0, maxIndex) * 100.0 / maxIndex).roundToInt()

    /**
     * Highest stream index that is still ≤ [percent]. Used for clamping so that rounding never lets
     * the volume end up above the limit.
     */
    fun maxIndexFor(percent: Int, maxIndex: Int): Int =
        if (maxIndex <= 0) 0 else (percent.coerceIn(0, 100) * maxIndex / 100).coerceIn(0, maxIndex)

    fun minutesOfDay(cal: Calendar = Calendar.getInstance()): Int =
        cal.get(Calendar.HOUR_OF_DAY) * 60 + cal.get(Calendar.MINUTE)
}

/**
 * Applies the volume policy to STREAM_MUSIC. Many TVs route the speaker volume through the same
 * stream; TVs whose firmware handles volume outside Android (isVolumeFixed) are reported as such.
 */
@SuppressLint("StaticFieldLeak")
object VolumeController {
    private const val TAG = "Volume"
    private const val STREAM = AudioManager.STREAM_MUSIC

    private fun am(context: Context): AudioManager? =
        context.applicationContext.getSystemService(Context.AUDIO_SERVICE) as? AudioManager

    fun currentPercent(context: Context): Int? = try {
        val am = am(context) ?: return null
        VolumePolicy.indexToPercent(am.getStreamVolume(STREAM), am.getStreamMaxVolume(STREAM))
    } catch (e: Exception) {
        null
    }

    private fun isFixed(am: AudioManager): Boolean =
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP && try { am.isVolumeFixed } catch (_: Exception) { false }

    /** Sets the level (already clamped by the caller). Returns a short description for acks/logs. */
    fun setPercent(context: Context, percent: Int): String {
        val am = am(context) ?: throw CommandFailedException("No audio service")
        if (isFixed(am)) throw CommandFailedException("Volume is fixed on this TV (controlled by the TV firmware)")
        val max = am.getStreamMaxVolume(STREAM)
        val index = VolumePolicy.percentToIndex(percent, max)
        try {
            am.setStreamVolume(STREAM, index, 0)
        } catch (e: SecurityException) {
            throw CommandFailedException("Volume change not permitted: ${e.message}")
        }
        return "Volume $percent% (step $index/$max)"
    }

    fun setMuted(context: Context, muted: Boolean): String {
        val am = am(context) ?: throw CommandFailedException("No audio service")
        if (isFixed(am)) throw CommandFailedException("Volume is fixed on this TV (controlled by the TV firmware)")
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                am.adjustStreamVolume(STREAM, if (muted) AudioManager.ADJUST_MUTE else AudioManager.ADJUST_UNMUTE, 0)
            } else {
                @Suppress("DEPRECATION")
                am.setStreamMute(STREAM, muted)
            }
        } catch (e: SecurityException) {
            throw CommandFailedException("Mute not permitted: ${e.message}")
        }
        return if (muted) "Muted" else "Unmuted"
    }

    /** Clamps the current volume to the allowed max (night limit). Returns true if it was lowered. */
    fun enforce(context: Context, cfg: VolumeConfig?): Boolean {
        if (cfg == null) return false
        return try {
            val am = am(context) ?: return false
            if (isFixed(am)) return false
            val max = am.getStreamMaxVolume(STREAM)
            val allowed = VolumePolicy.maxIndexFor(VolumePolicy.effectiveMax(cfg, VolumePolicy.minutesOfDay()), max)
            val cur = am.getStreamVolume(STREAM)
            if (cur > allowed) {
                am.setStreamVolume(STREAM, allowed, 0)
                Log.i(TAG, "Volume clamped $cur → $allowed (of $max)")
                true
            } else false
        } catch (e: Exception) {
            Log.w(TAG, "enforce failed: ${e.message}")
            false
        }
    }

    /** Applies `volume.default` once per stay (see [VolumePolicy.stayKey]). */
    fun applyDefaultIfNewStay(context: Context, content: Content?) {
        val key = VolumePolicy.stayKey(content) ?: return
        if (Prefs.volumeStayApplied == key) return
        Prefs.volumeStayApplied = key
        val level = VolumePolicy.clamp(content!!.volume!!.defaultLevel!!, content.volume, VolumePolicy.minutesOfDay())
        try {
            Log.i(TAG, "New stay ($key): " + setPercent(context, level))
        } catch (e: Exception) {
            Log.w(TAG, "default volume not applied: ${e.message}")
        }
    }
}

/** Thrown by command actions to have the command acked as `failed` with this reason. */
class CommandFailedException(message: String) : Exception(message)
