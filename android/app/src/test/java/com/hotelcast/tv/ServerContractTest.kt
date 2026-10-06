package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Parses Content objects produced by the REAL server (exported by the PHP test
 * hotelcast/tests/Integration/ContractFixtureTest.php) with the app's own models, so any
 * field-name drift between server and TV app fails the build.
 */
class ServerContractTest {

    private fun load(name: String): Content {
        val stream = javaClass.classLoader!!.getResourceAsStream(name)
            ?: throw AssertionError("Missing test resource $name — run the PHP ContractFixtureTest")
        val json = stream.bufferedReader(Charsets.UTF_8).use { it.readText() }
        return ApiClient.gson.fromJson(json, Content::class.java)
    }

    @Test fun fullGuestRoomParsesAndEverythingIsUsable() {
        val c = load("server_content_v2.json")
        assertFalse(c.isOff)
        assertFalse(c.isEmergency)
        assertEquals("gu", c.guest?.language)
        val w = c.welcome
        assertNotNull(w)
        assertEquals(true, w!!.show)
        assertTrue(w.id!!.startsWith("stay-"))
        assertEquals("Hotel-Guest", w.wifi?.ssid)
        assertEquals(true, c.checkoutReminder?.show)
        assertEquals(true, c.services?.enabled)
        assertNotNull(c.branding)

        val menu = c.guestMenu.orEmpty()
        assertEquals("services", menu.first().id)
        val ids = menu.map { it.id }
        listOf("services", "requests", "feedback", "guide", "live_tv", "hdmi1", "cast").forEach {
            assertTrue("guest menu has $it", it in ids)
        }
        menu.forEach { assertTrue("menu item ${it.id} (${it.type}) usable on the TV", it.isUsable()) }

        assertEquals(30, c.volume?.defaultLevel)
        assertEquals(25, c.volume?.nightMax)

        val items = c.playableItems()
        assertTrue("server items are all playable", items.size == c.items.orEmpty().size)
        assertTrue("sponsor ad carries ad_campaign_id", items.any { it.adCampaignId != null })
    }

    @Test fun vacantRoomIsOff() {
        val c = load("server_content_vacant.json")
        assertTrue(c.isOff)
        assertEquals("vacant", c.offReason)
    }
}
