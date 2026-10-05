package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class VolumePolicyTest {
    private val cfg = VolumeConfig(defaultLevel = 30, max = 80, nightMax = 25, nightFrom = "22:00", nightTo = "06:00")
    private fun hm(h: Int, m: Int = 0) = h * 60 + m

    @Test fun parsesTimes() {
        assertEquals(1320, VolumePolicy.parseHm("22:00"))
        assertEquals(360, VolumePolicy.parseHm("6:00"))
        assertEquals(0, VolumePolicy.parseHm("24:00"))
        assertEquals(61, VolumePolicy.parseHm("01:01:59"))
        assertNull(VolumePolicy.parseHm("25:00"))
        assertNull(VolumePolicy.parseHm("10pm"))
        assertNull(VolumePolicy.parseHm(null))
    }

    @Test fun nightWindowWrapsMidnight() {
        assertTrue(VolumePolicy.isNight(cfg, hm(22)))
        assertTrue(VolumePolicy.isNight(cfg, hm(23, 59)))
        assertTrue(VolumePolicy.isNight(cfg, hm(0)))
        assertTrue(VolumePolicy.isNight(cfg, hm(5, 59)))
        assertFalse(VolumePolicy.isNight(cfg, hm(6)))
        assertFalse(VolumePolicy.isNight(cfg, hm(21, 59)))
        assertFalse(VolumePolicy.isNight(cfg, hm(12)))
    }

    @Test fun nightWindowSameDay() {
        val c = cfg.copy(nightFrom = "13:00", nightTo = "15:00")
        assertTrue(VolumePolicy.isNight(c, hm(13)))
        assertTrue(VolumePolicy.isNight(c, hm(14, 59)))
        assertFalse(VolumePolicy.isNight(c, hm(15)))
        assertFalse(VolumePolicy.isNight(c, hm(12, 59)))
    }

    @Test fun noNightWithoutCompleteConfig() {
        assertFalse(VolumePolicy.isNight(cfg.copy(nightMax = null), hm(23)))
        assertFalse(VolumePolicy.isNight(cfg.copy(nightFrom = "bad"), hm(23)))
        assertFalse(VolumePolicy.isNight(cfg.copy(nightFrom = "06:00"), hm(6))) // from == to
        assertFalse(VolumePolicy.isNight(null, hm(23)))
    }

    @Test fun clampsToMaxAndNightMax() {
        assertEquals(80, VolumePolicy.clamp(100, cfg, hm(12)))
        assertEquals(50, VolumePolicy.clamp(50, cfg, hm(12)))
        assertEquals(25, VolumePolicy.clamp(50, cfg, hm(23)))
        assertEquals(10, VolumePolicy.clamp(10, cfg, hm(23)))
        assertEquals(0, VolumePolicy.clamp(-5, cfg, hm(12)))
        assertEquals(100, VolumePolicy.clamp(150, null, hm(12)))
        // night max higher than max never raises the limit
        assertEquals(20, VolumePolicy.clamp(90, cfg.copy(max = 20, nightMax = 60), hm(23)))
    }

    @Test fun indexConversionNeverExceedsLimit() {
        assertEquals(15, VolumePolicy.percentToIndex(100, 15))
        assertEquals(0, VolumePolicy.percentToIndex(0, 15))
        assertEquals(8, VolumePolicy.percentToIndex(50, 15)) // 7.5 rounds up for SET_VOLUME
        assertEquals(3, VolumePolicy.maxIndexFor(25, 15))   // 3.75 floors for clamping: 3/15 = 20 % ≤ 25 %
        assertEquals(100, VolumePolicy.indexToPercent(15, 15))
        assertEquals(0, VolumePolicy.percentToIndex(50, 0))
    }

    @Test fun stayKeyAppliesDefaultOncePerStay() {
        val base = Content(volume = cfg)
        assertEquals("first", VolumePolicy.stayKey(base))
        assertEquals("w:stay-1", VolumePolicy.stayKey(base.copy(welcome = Welcome(id = "stay-1"), guest = Guest(checkinAt = "x"))))
        assertEquals("c:2026-10-05T14:00:00+05:30", VolumePolicy.stayKey(base.copy(guest = Guest(checkinAt = "2026-10-05T14:00:00+05:30"))))
        assertNull(VolumePolicy.stayKey(Content(volume = VolumeConfig(max = 50))))
        assertNull(VolumePolicy.stayKey(null))
    }
}
