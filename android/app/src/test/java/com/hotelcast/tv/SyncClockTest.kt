package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlin.math.abs
import kotlin.random.Random

/** 2.4 synchronized playback: offset estimation from poll round trips ([SyncClock]). */
class SyncClockTest {

    private var local = 1_000_000L
    private fun clock() = SyncClock({ local })

    @Test
    fun noSamplesNoEstimate() {
        val c = clock()
        assertNull(c.offsetMs())
        assertNull(c.serverNowMs())
        assertNull(c.uncertaintyMs())
    }

    @Test
    fun midpointOfASymmetricRoundTrip() {
        val c = clock()
        // Server is 5 000 ms ahead; request 40 ms each way, stamped half way.
        assertTrue(c.addSample(sentAt = 1000, receivedAt = 1080, serverMs = 1040 + 5000))
        assertEquals(5000L, c.offsetMs())
        assertEquals(40L, c.uncertaintyMs())
        local = 2000
        assertEquals(7000L, c.serverNowMs())
    }

    @Test
    fun minimumRttSampleWinsOverJitter() {
        val c = clock()
        val trueOffset = -123_456L
        val rnd = Random(42)
        var best = Long.MAX_VALUE
        var t = 10_000L
        repeat(15) {
            // Asymmetric jitter: up to 400 ms extra on the way back (Wi-Fi retries, busy server).
            val up = 5L + rnd.nextLong(0, 60)
            val down = 5L + rnd.nextLong(0, 400)
            val server = t + up + trueOffset
            c.addSample(t, t + up + down, server)
            best = minOf(best, up + down)
            t += 8000
        }
        // A precise sample: 6 ms round trip.
        c.addSample(t, t + 6, t + 3 + trueOffset)
        assertEquals(6L, c.bestSample()!!.rttMs)
        assertTrue("error ${c.offsetMs()!! - trueOffset}", abs(c.offsetMs()!! - trueOffset) <= 3)
    }

    @Test
    fun jitteryEstimateStaysWithinHalfTheBestRtt() {
        val rnd = Random(7)
        repeat(50) { round ->
            val c = SyncClock({ 0L }, maxAgeMs = Long.MAX_VALUE)
            val trueOffset = rnd.nextLong(-3_600_000, 3_600_000)
            var t = 1_760_000_000_000L
            repeat(12) {
                val up = rnd.nextLong(2, 300)
                val down = rnd.nextLong(2, 300)
                c.addSample(t, t + up + down, t + up + trueOffset)
                t += 5000
            }
            val err = abs(c.offsetMs()!! - trueOffset)
            assertTrue("round $round: error $err > rtt/2 ${c.uncertaintyMs()}", err <= c.uncertaintyMs()!! + 1)
        }
    }

    @Test
    fun rejectsImpossibleAndUselessSamples() {
        val c = clock()
        assertFalse("negative rtt", c.addSample(100, 50, 1000))
        assertFalse("rtt above 10 s (long poll)", c.addSample(0, 25_000, 1000))
        assertFalse("no server time", c.addSample(0, 10, 0))
        assertEquals(0, c.sampleCount)
        assertTrue(c.addSample(0, 10, 1000))
        assertEquals(1, c.sampleCount)
    }

    @Test
    fun oldSamplesExpireAndWindowIsBounded() {
        val c = SyncClock({ local }, maxSamples = 4, maxAgeMs = 60_000)
        local = 0
        c.addSample(0, 2, 101) // great sample: rtt 2, offset 100
        for (i in 1..3) c.addSample(i * 1000L, i * 1000L + 50, i * 1000L + 25 + 200) // offset 200, rtt 50
        assertEquals(100L, c.offsetMs())
        // The precise one is pushed out of the window by newer samples …
        c.addSample(5000, 5050, 5025 + 200)
        assertEquals(4, c.sampleCount)
        assertEquals(200L, c.offsetMs())
        // … and everything expires after maxAge.
        local = 5050 + 60_001
        assertNull(c.offsetMs())
        c.addSample(local, local + 10, local + 5 + 300)
        assertEquals(300L, c.offsetMs())
        c.reset()
        assertNull(c.offsetMs())
    }
}
