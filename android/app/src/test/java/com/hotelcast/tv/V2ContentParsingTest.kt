package com.hotelcast.tv

import com.google.gson.JsonParser
import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Full V2 Content object as in docs/V2_SPEC.md "§ TV contract". */
class V2ContentParsingTest {

    private val v2Json = """
    {
      "hash": "abc123",
      "mode": "assigned",
      "screen_on": true,
      "off_reason": null,
      "room": { "id": 12, "number": "101", "name": "Deluxe 101", "floor": "1" },
      "hotel": { "name": "Hotel Dwarka Palace", "logo_url": "https://x/logo.png" },
      "branding": { "product": "StayCast", "logo_url": "https://x/brand.png", "color": "#7B1FA2", "support": "+91 98765 43210" },
      "guest": { "name": "Mr. Shah", "first_name": "Rajesh", "language": "gu", "checkin_at": "2026-10-05T14:00:00+05:30", "checkout_at": null },
      "welcome": { "show": true, "id": "stay-123", "title": "Welcome Mr. Shah 🙏", "message": "આપનું સ્વાગત છે",
                   "wifi": { "ssid": "Hotel-Guest", "password": "pa;ss:word" }, "duration_sec": 20 },
      "checkout_reminder": { "show": true, "id": "co-123-2026-10-06", "text": "Checkout today at 10:00 AM" },
      "suspended": { "title": "Service paused", "message": "Please contact reception." },
      "services": { "enabled": true, "url": "https://x/g/AbC123", "label": "Scan for Room Service" },
      "guest_menu": [
        { "id": "services", "type": "qr", "title": "Room Service", "icon": "food", "url": "https://x/g/AbC123" },
        { "id": "feedback", "type": "qr", "title": "Feedback", "icon": "star", "url": "https://x/g/AbC123#feedback" },
        { "id": "live_tv", "type": "live_tv", "title": "Live TV", "icon": "tv" },
        { "id": "hdmi1", "type": "input", "title": "HDMI 1", "icon": "hdmi", "input": "hdmi1" },
        { "id": "cast", "type": "cast", "title": "Cast from phone", "icon": "cast", "text": "Connect to Wi-Fi 'Hotel-Guest'…" },
        { "id": "guide", "type": "content", "title": "Local guide", "icon": "map",
          "content": { "id": 77, "type": "html", "title": "Guide", "html": "<html><body>Dwarka</body></html>" } },
        { "id": "future", "type": "hologram", "title": "Unknown future type" },
        { "id": "broken", "type": "qr", "title": "QR without url" }
      ],
      "volume": { "default": 30, "max": 100, "night_max": 25, "night_from": "22:00", "night_to": "06:00" },
      "items": [
        { "id": 1, "type": "image", "duration": 10, "url": "https://x/1.jpg" },
        { "id": 2, "type": "image", "duration": 10, "url": "https://x/ad.jpg", "ad_campaign_id": 12 }
      ],
      "overlay": { "clock": true, "ticker": { "text": "Hello" } },
      "emergency": null,
      "some_future_field": { "nested": [1, 2, 3] }
    }
    """.trimIndent()

    private fun parse(json: String): Content = ApiClient.gson.fromJson(json, Content::class.java)

    @Test fun parsesAllV2Fields() {
        val c = parse(v2Json)
        assertEquals("StayCast", c.branding!!.product)
        assertEquals("#7B1FA2", c.branding!!.color)
        assertEquals("+91 98765 43210", c.branding!!.support)
        assertEquals("gu", c.guest!!.language)
        assertEquals("Rajesh", c.guest!!.firstName)
        assertNull(c.guest!!.checkoutAt)
        assertEquals(true, c.welcome!!.show)
        assertEquals("stay-123", c.welcome!!.id)
        assertEquals("pa;ss:word", c.welcome!!.wifi!!.password)
        assertEquals(20, c.welcome!!.durationSec)
        assertEquals("co-123-2026-10-06", c.checkoutReminder!!.id)
        assertEquals("Service paused", c.suspended!!.title)
        assertEquals(true, c.services!!.enabled)
        assertEquals(30, c.volume!!.defaultLevel)
        assertEquals(25, c.volume!!.nightMax)
        assertEquals("22:00", c.volume!!.nightFrom)
        assertNull(c.offReason)
        assertEquals(12L, c.items!![1].adCampaignId)
        assertNull(c.items!![0].adCampaignId)
        assertFalse(c.isSuspended)
    }

    @Test fun guestMenuKeepsOnlyUsableItems() {
        val c = parse(v2Json)
        assertEquals(8, c.guestMenu!!.size)
        val menu = c.effectiveGuestMenu()
        assertEquals(listOf("services", "feedback", "live_tv", "hdmi1", "cast", "guide"), menu.map { it.id })
        assertEquals("hdmi1", menu[3].input)
        assertEquals(ContentItem.TYPE_HTML, menu[5].content!!.type)
        assertTrue(menu[5].content!!.isPlayable())
    }

    @Test fun servicesOnlySynthesisesQrMenu() {
        val c = parse("""{"mode":"assigned","services":{"enabled":true,"url":"https://x/g/T","label":"Room service"}}""")
        val menu = c.effectiveGuestMenu()
        assertEquals(1, menu.size)
        assertEquals(GuestMenuItem.TYPE_QR, menu[0].type)
        assertEquals("https://x/g/T", menu[0].url)
        val disabled = parse("""{"services":{"enabled":false,"url":"https://x/g/T"}}""")
        assertTrue(disabled.effectiveGuestMenu().isEmpty())
    }

    @Test fun oldServerContentStillWorks() {
        val c = parse("""{"hash":"h","mode":"assigned","items":[{"id":1,"type":"image","url":"https://x/1.jpg"}]}""")
        assertNull(c.branding)
        assertNull(c.guest)
        assertNull(c.welcome)
        assertNull(c.volume)
        assertTrue(c.effectiveGuestMenu().isEmpty())
        assertFalse(c.isSuspended)
        assertFalse(c.isEmpty)
    }

    @Test fun suspendedMode() {
        val c = parse("""{"mode":"suspended","items":[],"suspended":{"title":"Paused","message":"Call us"}}""")
        assertTrue(c.isSuspended)
        assertFalse(c.isOff)
        assertFalse(c.isEmergency)
    }

    @Test fun offReason() {
        val c = parse("""{"mode":"off","screen_on":false,"off_reason":"vacant"}""")
        assertTrue(c.isOff)
        assertEquals("vacant", c.offReason)
    }

    @Test fun roundTripKeepsV2Fields() {
        val c = parse(v2Json)
        val back = parse(ApiClient.gson.toJson(c))
        assertEquals(c, back)
        assertTrue(ApiClient.gson.toJson(c).contains("આપનું સ્વાગત છે"))
    }

    @Test fun cacheIncludesBrandingLogo() {
        val urls = ContentCache.mediaUrls(parse(v2Json))
        assertEquals(listOf("https://x/1.jpg", "https://x/ad.jpg", "https://x/logo.png", "https://x/brand.png"), urls)
    }

    @Test fun playedItemSerialisesAdCampaignOnlyWhenSet() {
        val json = ApiClient.gson.toJson(PlayedRequest(listOf(
            PlayedItem(1, "2026-10-05T18:00:00+05:30", 10),
            PlayedItem(2, "2026-10-05T18:00:10+05:30", 10, adCampaignId = 12),
        )))
        val items = JsonParser.parseString(json).asJsonObject.getAsJsonArray("items")
        assertFalse(items[0].asJsonObject.has("ad_campaign_id"))
        assertEquals(12, items[1].asJsonObject.get("ad_campaign_id").asInt)
        assertEquals(2, items[1].asJsonObject.get("content_id").asInt)
    }

    @Test fun registerResponseWithHotel() {
        val env: ApiEnvelope<RegisterResponse> = ApiClient.gson.fromJson(
            """{"ok":true,"data":{"token":"t","room":{"id":1,"number":"101"},"hotel":{"id":7,"name":"Hotel Dwarka"}}}""",
            object : TypeToken<ApiEnvelope<RegisterResponse>>() {}.type
        )
        assertEquals(7L, env.data!!.hotel!!.id)
        assertEquals("Hotel Dwarka", env.data!!.hotel!!.name)
    }

    @Test fun registerErrorCodesParse() {
        for (code in listOf("HOTEL_SUSPENDED", "LICENSE_LIMIT")) {
            val env: ApiEnvelope<Any> = ApiClient.gson.fromJson(
                """{"ok":false,"error":{"code":"$code","message":"m"}}""",
                object : TypeToken<ApiEnvelope<Any>>() {}.type
            )
            assertEquals(code, env.error!!.code)
        }
    }

    @Test fun eventAndLogsRequestsSerialise() {
        val data = ApiClient.gson.toJsonTree(mapOf("input" to "hdmi1", "ok" to true)).asJsonObject
        val json = ApiClient.gson.toJson(EventRequest("input_switch", data))
        assertEquals("""{"type":"input_switch","data":{"input":"hdmi1","ok":true}}""", json)
        val crash = ApiClient.gson.toJson(CrashRequest("stack", "2.0.0 (4)", "2026-10-05T18:00:00+05:30"))
        assertTrue(crash.contains("\"happened_at\""))
        assertTrue(crash.contains("\"app_version\""))
    }
}
