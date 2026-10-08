package com.hotelcast.tv

import com.google.gson.annotations.SerializedName
import kotlin.math.abs

/**
 * 2.4 synchronized playback (#37): `content.sync` from the server. All TVs showing the same synced
 * playlist or video wall get the same schedule; with the server clock ([SyncClock]) every TV computes
 *   position = (server_now - epoch_ms) mod cycle_ms → current item + offset into it.
 * Old apps ignore the field.
 */
data class SyncData(
    @SerializedName("epoch_ms") val epochMs: Long? = null,
    @SerializedName("cycle_ms") val cycleMs: Long? = null,
    @SerializedName("item_offsets_ms") val itemOffsetsMs: List<Long?>? = null,
)

enum class DriftAction { NONE, SEEK }

/**
 * Validated schedule + the pure decisions of synced playback (unit tested, no Android).
 * [offsets] start at 0, strictly increase and stay below [cycleMs]; one per playable item.
 */
class SyncPlan private constructor(
    val epochMs: Long,
    val cycleMs: Long,
    private val offsets: LongArray,
) {
    val itemCount: Int get() = offsets.size

    data class Position(
        val index: Int,
        /** ms since the item started. */
        val offsetMs: Long,
        val itemDurationMs: Long,
        /** ms until the next item starts (the next boundary). */
        val untilNextMs: Long,
    )

    sealed class Step {
        /** The right item is on screen: check again after [ms] (the next boundary). */
        data class Wait(val ms: Long) : Step()
        /** Show item [index] now, [offsetMs] into it. */
        data class Show(val index: Int, val offsetMs: Long) : Step()
    }

    fun itemDurationMs(i: Int): Long = (if (i + 1 < offsets.size) offsets[i + 1] else cycleMs) - offsets[i]

    fun offsetOf(i: Int): Long = offsets[i]

    /** Where the schedule is at server time [serverNowMs] (times before the epoch wrap around too). */
    fun positionAt(serverNowMs: Long): Position {
        val t = Math.floorMod(serverNowMs - epochMs, cycleMs)
        // Last item whose offset ≤ t (binary search: offsets are sorted, offsets[0] = 0).
        var lo = 0
        var hi = offsets.size - 1
        while (lo < hi) {
            val mid = (lo + hi + 1) ushr 1
            if (offsets[mid] <= t) lo = mid else hi = mid - 1
        }
        val dur = itemDurationMs(lo)
        val into = t - offsets[lo]
        return Position(lo, into, dur, dur - into)
    }

    /**
     * Called when the advance timer fires (at an item boundary): the item the clock asks for, or — when
     * that is still the item on screen (timer slightly early, clock estimate moved) — wait for the boundary.
     * Re-aligning at every boundary is what keeps images and pages in step.
     */
    fun nextStep(currentIndex: Int, serverNowMs: Long): Step {
        val p = positionAt(serverNowMs)
        return if (p.index == currentIndex && itemCount > 1) Step.Wait(p.untilNextMs.coerceAtLeast(MIN_WAIT_MS)) else Step.Show(p.index, p.offsetMs)
    }

    companion object {
        /** Re-align a video at an item boundary (and right after it started / seeked) when off by more. */
        const val BOUNDARY_DRIFT_MS = 300L
        /** Mid-item, only a large drift is worth a visible seek. */
        const val MID_ITEM_DRIFT_MS = 1000L
        /** The first part of an item counts as "at the boundary". */
        const val BOUNDARY_WINDOW_MS = 1500L
        const val MIN_WAIT_MS = 20L
        /** Seeks land late (decoder restarts at a key frame): learned lead, kept within these bounds. */
        const val MAX_SEEK_LEAD_MS = 1500L

        /** A plan for [itemCount] playable items, or null when the data does not fit (then: normal playback). */
        fun from(data: SyncData?, itemCount: Int): SyncPlan? {
            data ?: return null
            val cycle = data.cycleMs ?: return null
            val epoch = data.epochMs ?: return null
            val raw = data.itemOffsetsMs ?: return null
            if (cycle <= 0 || epoch < 0 || itemCount <= 0 || raw.size != itemCount || raw.any { it == null }) return null
            val offsets = LongArray(raw.size) { raw[it]!! }
            if (offsets[0] != 0L) return null
            for (i in 1 until offsets.size) if (offsets[i] <= offsets[i - 1]) return null
            if (offsets.last() >= cycle) return null
            return SyncPlan(epoch, cycle, offsets)
        }

        /**
         * Where the media should be, [offsetInItemMs] into the item: a looping video repeats every
         * [mediaDurationMs]; a non-looping one holds its last frame. Unknown duration (≤ 0) → the offset.
         */
        fun expectedMediaPositionMs(offsetInItemMs: Long, mediaDurationMs: Long, loop: Boolean): Long {
            val off = offsetInItemMs.coerceAtLeast(0)
            if (mediaDurationMs <= 0) return off
            return if (loop) off % mediaDurationMs else off.coerceAtMost(mediaDurationMs)
        }

        /**
         * Drift rule: at a boundary (or right after starting / seeking: [atBoundary]) re-align when the
         * video is off by more than 300 ms; in the middle of an item only beyond 1 s, so small jitter never
         * causes visible jumps.
         */
        fun driftAction(expectedMs: Long, actualMs: Long, atBoundary: Boolean): DriftAction {
            val limit = if (atBoundary) BOUNDARY_DRIFT_MS else MID_ITEM_DRIFT_MS
            return if (abs(expectedMs - actualMs) > limit) DriftAction.SEEK else DriftAction.NONE
        }

        /** New seek lead after a seek that ended [lateByMs] behind (negative = ahead). Half-step, bounded. */
        fun adjustLead(leadMs: Long, lateByMs: Long): Long = (leadMs + lateByMs / 2).coerceIn(0L, MAX_SEEK_LEAD_MS)
    }
}
