package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.TimeZone

class UtilsTest {

    @Test fun sha256() =
        assertEquals("03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4", Utils.sha256Hex("1234"))

    @Test fun defaultPinBeforeRegistration() {
        assertTrue(Utils.verifyPin("1234", null))
        assertTrue(Utils.verifyPin("1234", ""))
        assertFalse(Utils.verifyPin("0000", null))
        assertFalse(Utils.verifyPin("", null))
    }

    @Test fun serverPinHash() {
        val hash = Utils.sha256Hex("4321")
        assertTrue(Utils.verifyPin("4321", hash))
        assertTrue(Utils.verifyPin("4321", hash.uppercase()))
        assertFalse("default PIN must stop working once the server sent a hash", Utils.verifyPin("1234", hash))
    }

    @Test fun isoTimeWithOffset() {
        val ist = TimeZone.getTimeZone("Asia/Kolkata")
        val millis = java.time.Instant.parse("2026-10-05T12:30:00Z").toEpochMilli()
        assertEquals("2026-10-05T18:00:00+05:30", Utils.isoTime(millis, ist))
        assertEquals("1970-01-01T00:00:00+00:00", Utils.isoTime(0, TimeZone.getTimeZone("UTC")))
        assertEquals("1969-12-31T19:00:00-05:00", Utils.isoTime(0, TimeZone.getTimeZone("GMT-5")))
    }
}
