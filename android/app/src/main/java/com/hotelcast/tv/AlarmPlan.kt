package com.hotelcast.tv

import java.util.Locale
import kotlin.math.ceil

/** Validated `content.emergency.alarm` (2.4.1). */
data class AlarmSpec(
    val url: String,
    val loop: Boolean,
    /** Plays when [loop] is false (1–10). */
    val repeat: Int,
    /** 0–100 % of STREAM_MUSIC's maximum: the minimum volume while the alarm plays. */
    val volume: Int,
    val emergencyId: Long?,
)

/**
 * Pure decisions of the emergency alarm ([EmergencyAlarm]), unit tested in AlarmPlanTest:
 * which alarm the content asks for, whether a new content object restarts it, and the volume steps.
 */
object AlarmPlan {
    const val DEFAULT_VOLUME = 80
    const val DEFAULT_REPEAT = 3
    const val MAX_REPEAT = 10

    enum class Action {
        /** Nothing to do (same alarm still playing / finished, or no alarm at all). */
        NONE,
        START,
        /** Sound or loop mode changed: stop the current one, start the new one. */
        RESTART,
        /** Same sound, only the minimum volume changed: no restart, no stutter. */
        ADJUST_VOLUME,
        STOP,
    }

    /** The alarm of an emergency content object, or null (no emergency, no / silenced alarm, bad url). */
    fun from(content: Content?): AlarmSpec? {
        if (content?.isEmergency != true) return null
        val em = content.emergency ?: return null
        val a = em.alarm ?: return null
        val url = a.url?.trim().orEmpty()
        val scheme = url.substringBefore("://", "").lowercase(Locale.ROOT)
        if ((scheme != "http" && scheme != "https") || url.length > 2048) return null
        return AlarmSpec(
            url = url,
            loop = a.loop ?: true,
            repeat = (a.repeat ?: DEFAULT_REPEAT).coerceIn(1, MAX_REPEAT),
            volume = (a.volume ?: DEFAULT_VOLUME).coerceIn(0, 100),
            emergencyId = em.id,
        )
    }

    /**
     * Restart key. A sync that re-sends the same emergency gives the same key, so a running alarm is never
     * restarted. A "play N times" alarm also restarts for a different emergency (it may have finished).
     */
    fun key(s: AlarmSpec): String = s.url + "|" + if (s.loop) "loop" else "x${s.repeat}|${s.emergencyId ?: 0}"

    fun decide(current: AlarmSpec?, next: AlarmSpec?): Action = when {
        next == null -> if (current != null) Action.STOP else Action.NONE
        current == null -> Action.START
        key(current) != key(next) -> Action.RESTART
        current.volume != next.volume -> Action.ADJUST_VOLUME
        else -> Action.NONE
    }

    /** Lowest stream index that is at least [percent] % of [maxIndex] (rounded up: never below the request). */
    fun minIndexFor(percent: Int, maxIndex: Int): Int =
        if (maxIndex <= 0) 0 else ceil(percent.coerceIn(0, 100) * maxIndex / 100.0).toInt().coerceIn(0, maxIndex)

    /** Index to set while the alarm plays: the current one if already loud enough ("at least", never lower). */
    fun raisedIndex(currentIndex: Int, maxIndex: Int, percent: Int): Int =
        maxOf(currentIndex.coerceIn(0, maxOf(0, maxIndex)), minIndexFor(percent, maxIndex))

    /** What the alarm changed, to be undone when it stops. */
    data class VolumeRestore(val previousIndex: Int, val raisedIndex: Int, val wasMuted: Boolean) {
        fun serialize(): String = "$previousIndex|$raisedIndex|${if (wasMuted) 1 else 0}"

        companion object {
            fun parse(s: String?): VolumeRestore? {
                val p = s?.split('|') ?: return null
                if (p.size != 3) return null
                val prev = p[0].toIntOrNull() ?: return null
                val raised = p[1].toIntOrNull() ?: return null
                if (prev < 0 || raised < 0) return null
                return VolumeRestore(prev, raised, p[2] == "1")
            }
        }
    }

    /**
     * Index to restore after the alarm: the previous one, unless someone changed the volume meanwhile
     * (SET_VOLUME from the admin panel, the remote) — then their choice is kept (null).
     */
    fun restoreIndex(r: VolumeRestore, nowIndex: Int): Int? =
        if (nowIndex == r.raisedIndex && r.previousIndex != r.raisedIndex) r.previousIndex else null
}
