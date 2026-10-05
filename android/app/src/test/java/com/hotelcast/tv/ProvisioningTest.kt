package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ProvisioningTest {
    private fun parse(vararg pairs: Pair<String, Any?>) = Provisioning.parse { k -> pairs.toMap()[k] }

    @Test fun validExtras() {
        val p = parse("hc_server" to "https://hotel.com/hotelcast", "hc_room" to "101", "hc_key" to "KEY-123", "hc_autoregister" to true)
        p as Provisioning.Parsed.Valid
        assertEquals("https://hotel.com/hotelcast/api/", p.request.apiBase)
        assertEquals("101", p.request.room)
        assertEquals("KEY-123", p.request.key)
        assertTrue(p.request.autoRegister)
        assertFalse(p.request.force)
    }

    @Test fun stringBooleansFromEsAreAccepted() {
        val p = parse("hc_server" to "192.168.1.10/hotelcast", "hc_room" to " 2A ", "hc_key" to "k", "hc_autoregister" to "true", "hc_force" to "1") as Provisioning.Parsed.Valid
        assertEquals("http://192.168.1.10/hotelcast/api/", p.request.apiBase)
        assertEquals("2A", p.request.room)
        assertTrue(p.request.autoRegister)
        assertTrue(p.request.force)
        assertFalse(Provisioning.bool("no"))
        assertFalse(Provisioning.bool(null))
        assertTrue(Provisioning.bool(1))
    }

    @Test fun noExtrasIsNone() {
        assertEquals(Provisioning.Parsed.None, parse())
        assertEquals(Provisioning.Parsed.None, parse("hc_autoregister" to true))
    }

    @Test fun invalidExtras() {
        assertTrue(parse("hc_server" to "ftp://x", "hc_room" to "1", "hc_key" to "k") is Provisioning.Parsed.Invalid)
        assertTrue(parse("hc_server" to "https://h", "hc_key" to "k") is Provisioning.Parsed.Invalid)
        assertTrue(parse("hc_server" to "https://h", "hc_room" to "1") is Provisioning.Parsed.Invalid)
        assertTrue(parse("hc_server" to "https://h", "hc_room" to "1;rm -rf", "hc_key" to "k") is Provisioning.Parsed.Invalid)
        assertTrue(parse("hc_server" to "https://h", "hc_room" to "1", "hc_key" to "has space") is Provisioning.Parsed.Invalid)
        assertTrue(parse("hc_server" to "https://h", "hc_room" to "x".repeat(40), "hc_key" to "k") is Provisioning.Parsed.Invalid)
        val reason = (parse("hc_room" to "1", "hc_key" to "k") as Provisioning.Parsed.Invalid).reason
        assertTrue(reason.contains("hc_server"))
    }

    @Test fun onlyWhenNotRegisteredUnlessForced() {
        val req = (parse("hc_server" to "https://h", "hc_room" to "101", "hc_key" to "k") as Provisioning.Parsed.Valid).request
        assertNull(Provisioning.refusal(req, isRegistered = false))
        assertNotNull(Provisioning.refusal(req, isRegistered = true))
        assertNull(Provisioning.refusal(req.copy(force = true), isRegistered = true))
    }

    @Test fun gujaratiRoomNamesAllowed() {
        assertTrue(parse("hc_server" to "https://h", "hc_room" to "રૂમ 5", "hc_key" to "k") is Provisioning.Parsed.Valid)
    }
}
