package com.hotelcast.tv

import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

/** 2.4 synchronized playback: schedule position, boundary steps and drift decisions ([SyncPlan]). */
class SyncPlanTest {

    private val epoch = 1_760_000_000_000L
    /** 30 s video, 6 s image, 10 s image = 46 s. */
    private val plan = SyncPlan.from(SyncData(epoch, 46_000, listOf(0, 30_000, 36_000)), 3)!!

    @Test
    fun positionInsideTheCycle() {
        assertEquals(SyncPlan.Position(0, 0, 30_000, 30_000), plan.positionAt(epoch))
        assertEquals(SyncPlan.Position(0, 12_345, 30_000, 17_655), plan.positionAt(epoch + 12_345))
        assertEquals(SyncPlan.Position(1, 0, 6_000, 6_000), plan.positionAt(epoch + 30_000))
        assertEquals(SyncPlan.Position(1, 5_999, 6_000, 1), plan.positionAt(epoch + 35_999))
        assertEquals(SyncPlan.Position(2, 9_999, 10_000, 1), plan.positionAt(epoch + 45_999))
        // Wraps every cycle, also many days later.
        assertEquals(SyncPlan.Position(0, 0, 30_000, 30_000), plan.positionAt(epoch + 46_000))
        assertEquals(plan.positionAt(epoch + 31_000), plan.positionAt(epoch + 31_000 + 46_000L * 1_000_003))
        // Before the epoch (TV clock estimate slightly early): floor modulo, never negative.
        assertEquals(SyncPlan.Position(2, 9_000, 10_000, 1_000), plan.positionAt(epoch - 1_000))
    }

    @Test
    fun twoTvsWithDifferentClocksAgreeAfterOffsetCorrection() {
        // TV A is 3.2 s behind the server, TV B 7.9 s ahead; both estimated their offset.
        val serverNow = epoch + 1_000_000L
        val localA = serverNow - 3_200
        val localB = serverNow + 7_900
        assertEquals(plan.positionAt(localA + 3_200), plan.positionAt(localB - 7_900))
    }

    @Test
    fun durationsAndOffsets() {
        assertEquals(30_000L, plan.itemDurationMs(0))
        assertEquals(6_000L, plan.itemDurationMs(1))
        assertEquals(10_000L, plan.itemDurationMs(2))
        assertEquals(36_000L, plan.offsetOf(2))
        assertEquals(3, plan.itemCount)
    }

    @Test
    fun nextStepAtBoundaries() {
        // Timer fired on time: show the next item from its start.
        assertEquals(SyncPlan.Step.Show(1, 0), plan.nextStep(0, epoch + 30_000))
        // Timer fired 30 ms early (or the clock estimate moved): wait for the boundary, do not rebuild.
        assertEquals(SyncPlan.Step.Wait(30), plan.nextStep(0, epoch + 29_970))
        assertEquals(SyncPlan.Step.Wait(SyncPlan.MIN_WAIT_MS), plan.nextStep(0, epoch + 29_999))
        // Late timer / a TV that fell behind: jump to what the clock says, with the offset into it.
        assertEquals(SyncPlan.Step.Show(2, 4_000), plan.nextStep(0, epoch + 40_000))
        // Last item → first again.
        assertEquals(SyncPlan.Step.Show(0, 0), plan.nextStep(2, epoch + 46_000))
        // A single item never "waits" for itself: the caller decides (one item is never rebuilt).
        val one = SyncPlan.from(SyncData(0, 12_500, listOf(0)), 1)!!
        assertEquals(SyncPlan.Step.Show(0, 2_500), one.nextStep(0, 15_000))
    }

    @Test
    fun invalidSchedulesAreIgnored() {
        assertNull(SyncPlan.from(null, 3))
        assertNull("count mismatch", SyncPlan.from(SyncData(epoch, 46_000, listOf(0, 30_000)), 3))
        assertNull("not from 0", SyncPlan.from(SyncData(epoch, 46_000, listOf(5, 30_000, 36_000)), 3))
        assertNull("not increasing", SyncPlan.from(SyncData(epoch, 46_000, listOf(0, 30_000, 30_000)), 3))
        assertNull("beyond the cycle", SyncPlan.from(SyncData(epoch, 36_000, listOf(0, 30_000, 36_000)), 3))
        assertNull("no cycle", SyncPlan.from(SyncData(epoch, 0, listOf(0)), 1))
        assertNull("no epoch", SyncPlan.from(SyncData(null, 1000, listOf(0)), 1))
        assertNull("null offset", SyncPlan.from(SyncData(epoch, 1000, listOf(0, null)), 2))
        assertNull("no items", SyncPlan.from(SyncData(epoch, 1000, emptyList()), 0))
        assertNotNull(SyncPlan.from(SyncData(0, 1, listOf(0)), 1))
    }

    @Test
    fun expectedMediaPosition() {
        assertEquals(12_000L, SyncPlan.expectedMediaPositionMs(12_000, 29_500, loop = false))
        assertEquals(29_500L, SyncPlan.expectedMediaPositionMs(30_000, 29_500, loop = false), "holds the last frame")
        assertEquals(500L, SyncPlan.expectedMediaPositionMs(30_000, 29_500, loop = true), "looped once")
        assertEquals(7_000L, SyncPlan.expectedMediaPositionMs(7_000, -1, loop = true), "duration unknown yet")
        assertEquals(0L, SyncPlan.expectedMediaPositionMs(-50, 10_000, loop = true))
    }

    private fun assertEquals(expected: Long, actual: Long, message: String) = org.junit.Assert.assertEquals(message, expected, actual)

    @Test
    fun driftDecisions() {
        // At a boundary / right after start: > 300 ms is corrected.
        assertEquals(DriftAction.NONE, SyncPlan.driftAction(10_000, 10_300, atBoundary = true))
        assertEquals(DriftAction.SEEK, SyncPlan.driftAction(10_000, 10_301, atBoundary = true))
        assertEquals(DriftAction.SEEK, SyncPlan.driftAction(10_000, 9_650, atBoundary = true))
        // Mid-item: only beyond 1 s.
        assertEquals(DriftAction.NONE, SyncPlan.driftAction(10_000, 10_301, atBoundary = false))
        assertEquals(DriftAction.NONE, SyncPlan.driftAction(10_000, 9_000, atBoundary = false))
        assertEquals(DriftAction.SEEK, SyncPlan.driftAction(10_000, 8_999, atBoundary = false))
        assertEquals(DriftAction.SEEK, SyncPlan.driftAction(10_000, 11_500, atBoundary = false))
    }

    @Test
    fun seekLeadLearnsAndIsBounded() {
        var lead = 0L
        lead = SyncPlan.adjustLead(lead, 400) // landed 400 ms late
        assertEquals(200L, lead)
        lead = SyncPlan.adjustLead(lead, 200)
        assertEquals(300L, lead)
        lead = SyncPlan.adjustLead(lead, -1000) // overshot
        assertEquals(0L, lead)
        assertEquals(SyncPlan.MAX_SEEK_LEAD_MS, SyncPlan.adjustLead(1400, 5000))
    }

    @Test
    fun parsesTheServerContract() {
        val json = """
        {"ok":true,"data":{"server_time":"2026-10-08T10:00:00+05:30","server_time_ms":1760000012345,"content_hash":"h","content_changed":true,
         "content":{"hash":"h","mode":"wall","items":[
            {"id":1,"type":"video","duration":30,"url":"https://x/v.mp4","loop":true},
            {"id":2,"type":"image","duration":6,"url":"https://x/1.jpg"},
            {"id":3,"type":"image","duration":10,"url":"https://x/2.jpg"}],
          "sync":{"epoch_ms":1760000000000,"cycle_ms":46000,"item_offsets_ms":[0,30000,36000]},
          "wall":{"id":4,"rows":2,"cols":2,"row":1,"col":0,"bezel_x_pct":1,"bezel_y_pct":1.786,"audio":false},
          "unknown_future_field":{"x":1}}}}
        """.trimIndent()
        val env: ApiEnvelope<PollResponse> = ApiClient.gson.fromJson(json, object : TypeToken<ApiEnvelope<PollResponse>>() {}.type)
        val data = env.data!!
        assertEquals(1_760_000_012_345L, data.serverTimeMs)
        val c = data.content!!
        val p = SyncPlan.from(c.sync, c.playableItems().size)!!
        assertEquals(46_000L, p.cycleMs)
        assertEquals(1_760_000_000_000L, p.epochMs)
        val w = WallGeometry.from(c.wall)!!
        assertEquals(1, w.row)
        assertEquals(0, w.col)
        assertEquals(false, w.audio)
        // Without the new fields (old server) nothing changes.
        val old = ApiClient.gson.fromJson("""{"mode":"assigned","items":[{"id":1,"type":"image","url":"u"}]}""", Content::class.java)
        assertNull(SyncPlan.from(old.sync, 1))
        assertNull(WallGeometry.from(old.wall))
    }
}
