package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 2.6.1 live video sync: settings parsing and the pure watchdog decisions (LiveSync.kt). */
class LiveSyncTest {

    private val cfg = LiveSyncConfig.from(null)

    private fun decide(
        offset: Long? = 20_000,
        buffering: Long = 0,
        stalled: Long = 0,
        since: Long = 0,
        every: Long = cfg.refreshEveryMs,
        seekable: Boolean = true,
        live: Boolean = true,
        target: Long? = 18_000,
        maxLag: Long = cfg.maxLagMs,
    ) = LiveSync.decide(offset, maxLag, buffering, stalled, since, every, seekable, live, target)

    @Test fun defaultsWhenMissing() {
        assertEquals(LiveSyncConfig(5 * 60_000L, 60_000L), cfg)
        assertEquals(LiveSyncConfig(5 * 60_000L, 60_000L), LiveSyncConfig.from(LiveSyncData()))
        assertEquals(LiveSyncConfig(0L, 90_000L), LiveSyncConfig.from(LiveSyncData(refreshMin = 0, maxLagS = 90)))
        assertEquals(LiveSyncConfig(2 * 60_000L, 30_000L), LiveSyncConfig.from(LiveSyncData(refreshMin = 2, maxLagS = 30)))
    }

    @Test fun maxLagNeverAboveTwoMinutes() {
        assertEquals(120_000L, LiveSyncConfig.from(LiveSyncData(maxLagS = 600)).maxLagMs)
        assertEquals(15_000L, LiveSyncConfig.from(LiveSyncData(maxLagS = 1)).maxLagMs)
        assertEquals(0L, LiveSyncConfig.from(LiveSyncData(refreshMin = -3)).refreshEveryMs)
        // Even a bad value passed straight to decide() is capped at 120 s.
        assertEquals(LiveAction.SEEK_TO_LIVE, decide(offset = 125_000, maxLag = 10 * 60_000L).action)
    }

    @Test fun parsesFromContentJson() {
        val c = ApiClient.gson.fromJson("""{"hash":"x","items":[],"live_sync":{"refresh_min":10,"max_lag_s":90}}""", Content::class.java)
        assertEquals(LiveSyncData(10, 90), c.liveSync)
        assertEquals(LiveSyncConfig(600_000L, 90_000L), LiveSyncConfig.from(c.liveSync))
        val old = ApiClient.gson.fromJson("""{"hash":"x","items":[]}""", Content::class.java)
        assertNull(old.liveSync)
        assertEquals(cfg, LiveSyncConfig.from(old.liveSync))
    }

    @Test fun inSyncDoesNothing() {
        val d = decide()
        assertEquals(LiveDecision(LiveAction.NONE, LiveReason.OK), d)
        assertFalse(d.restartsPeriodic)
        assertEquals(LiveAction.NONE, decide(offset = null).action)
        assertEquals(LiveAction.NONE, decide(offset = 60_000).action) // exactly at the limit
    }

    @Test fun behindLiveSeeksWhenSeekableElseReloads() {
        assertEquals(LiveDecision(LiveAction.SEEK_TO_LIVE, LiveReason.BEHIND), decide(offset = 61_000))
        assertEquals(LiveDecision(LiveAction.SEEK_TO_LIVE, LiveReason.BEHIND), decide(offset = 5 * 60_000L))
        assertEquals(LiveDecision(LiveAction.RELOAD, LiveReason.BEHIND), decide(offset = 90_000, seekable = false))
        assertTrue(decide(offset = 61_000).restartsPeriodic)
        // 30 s setting
        assertEquals(LiveAction.SEEK_TO_LIVE, decide(offset = 31_000, maxLag = 30_000, target = 6_000).action)
    }

    @Test fun streamWhoseOwnTargetIsBeyondTheLimitDoesNotSeekInALoop() {
        // Provider holds back 3 x 20 s segments = 60 s: the default position is already 60 s behind.
        assertEquals(LiveAction.NONE, decide(offset = 65_000, maxLag = 30_000, target = 60_000).action)
        assertEquals(LiveAction.SEEK_TO_LIVE, decide(offset = 75_000, maxLag = 30_000, target = 60_000).action)
    }

    @Test fun bufferingOrFrozenReloads() {
        assertEquals(LiveDecision(LiveAction.RELOAD, LiveReason.BUFFERING), decide(buffering = 20_000))
        assertEquals(LiveAction.NONE, decide(buffering = 19_999).action)
        assertEquals(LiveDecision(LiveAction.RELOAD, LiveReason.STALLED), decide(stalled = 30_000))
        assertEquals(LiveAction.NONE, decide(stalled = 29_999).action)
        // Stall rules apply to every stream, even a VOD file in a stream item or a non-seekable one.
        assertEquals(LiveAction.RELOAD, decide(stalled = 45_000, live = false, offset = null).action)
        assertEquals(LiveAction.RELOAD, decide(buffering = 25_000, seekable = false, offset = null).action)
    }

    @Test fun periodicResyncSeeksSeekableLive() {
        val every = 5 * 60_000L
        assertEquals(LiveAction.NONE, decide(offset = 40_000, since = every - 1).action)
        val d = decide(offset = 40_000, since = every)
        assertEquals(LiveDecision(LiveAction.SEEK_TO_LIVE, LiveReason.PERIODIC), d)
        assertTrue(d.restartsPeriodic)
        // Already at the stream's own live position: nothing to do, but the clock restarts.
        val near = decide(offset = 25_000, since = every, target = 18_000)
        assertEquals(LiveDecision(LiveAction.NONE, LiveReason.PERIODIC_IN_SYNC), near)
        assertTrue(near.restartsPeriodic)
        // Offset unknown on a seekable live stream: jump anyway.
        assertEquals(LiveAction.SEEK_TO_LIVE, decide(offset = null, since = every).action)
    }

    @Test fun periodicResyncReloadsStreamsThatCannotSeek() {
        // Progressive HTTP "live" (MP3 / TS) or RTSP: no live offset, not seekable.
        assertEquals(LiveDecision(LiveAction.RELOAD, LiveReason.PERIODIC), decide(offset = null, seekable = false, since = 5 * 60_000L))
        assertEquals(LiveAction.NONE, decide(offset = null, seekable = false, since = 60_000).action)
    }

    @Test fun periodicOffOrVodNeverResyncs() {
        assertEquals(LiveAction.NONE, decide(offset = 40_000, since = 3_600_000, every = 0).action)
        assertEquals(LiveAction.NONE, decide(offset = null, seekable = false, since = 3_600_000, every = 0).action)
        // Off still keeps the max delay.
        assertEquals(LiveAction.SEEK_TO_LIVE, decide(offset = 70_000, every = 0).action)
        // VOD (finite file, seekable, not live) in a stream item: only the stall rules.
        assertEquals(LiveAction.NONE, decide(offset = null, live = false, since = 3_600_000).action)
    }

    @Test fun youTubeLiveLinks() {
        assertTrue(LiveSync.isYouTubeLiveUrl("https://www.youtube.com/live/abcDEF12345"))
        assertTrue(LiveSync.isYouTubeLiveUrl("https://www.youtube.com/live/abcDEF12345?si=x"))
        assertTrue(LiveSync.isYouTubeLiveUrl("https://youtube.com/@dwarkadhish/live"))
        assertTrue(LiveSync.isYouTubeLiveUrl("https://www.youtube.com/channel/UCabc/live"))
        assertTrue(LiveSync.isYouTubeLiveUrl("https://www.youtube.com/embed/live_stream?channel=UCabc"))
        assertFalse(LiveSync.isYouTubeLiveUrl("https://www.youtube.com/watch?v=abcDEF12345"))
        assertFalse(LiveSync.isYouTubeLiveUrl("https://www.youtube.com/embed/abcDEF12345"))
        assertFalse(LiveSync.isYouTubeLiveUrl("https://example.com/live/stream"))
        assertFalse(LiveSync.isYouTubeLiveUrl(null))
        assertFalse(LiveSync.isYouTubeLiveUrl(""))
    }

    @Test fun youTubeScriptAndResult() {
        val js = LiveSync.youTubeLiveEdgeJs(60)
        assertTrue(js.contains("seekTo(p.getDuration(), true)"))
        assertTrue(js.trimEnd().endsWith("})(60)"))
        assertEquals("seek:75", LiveSync.parseWebResult("\"seek:75\""))
        assertEquals("live:4", LiveSync.parseWebResult("\"live:4\""))
        assertEquals("", LiveSync.parseWebResult(null))
    }
}
