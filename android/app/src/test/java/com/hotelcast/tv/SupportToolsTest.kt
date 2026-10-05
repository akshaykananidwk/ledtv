package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SupportToolsTest {

    @Test fun logsUnderLimitUnchanged() {
        val s = "line 1\nline 2\n"
        assertEquals(s, LogCollector.capTail(s, 1024))
    }

    @Test fun logsCappedToTailBytes() {
        val lines = (1..50_000).joinToString("\n") { "10-05 18:00:00.000  123  456 I Tag: message number $it" }
        val capped = LogCollector.capTail(lines)
        assertTrue(LogCollector.utf8Len(capped) <= LogCollector.MAX_LOG_BYTES)
        assertTrue(capped.endsWith("message number 50000"))
        assertTrue(capped.startsWith("[…truncated…]\n10-05"))
    }

    @Test fun multiByteTextNeverExceedsCap() {
        val gu = "મંગળા આરતી સવારે ૬:૦૦ 🙏\n".repeat(30_000)
        val capped = LogCollector.capTail(gu, 100_000)
        assertTrue(LogCollector.utf8Len(capped) <= 100_000)
        assertTrue(capped.endsWith("🙏\n"))
    }

    @Test fun screenshotScaling() {
        assertEquals(1280 to 720, ScreenshotEncoder.targetSize(1920, 1080))
        assertEquals(1280 to 720, ScreenshotEncoder.targetSize(3840, 2160))
        assertEquals(1024 to 600, ScreenshotEncoder.targetSize(1024, 600))
        assertEquals(0 to 0, ScreenshotEncoder.targetSize(0, 100))
    }
}
