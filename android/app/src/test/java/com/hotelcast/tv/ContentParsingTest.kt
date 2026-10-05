package com.hotelcast.tv

import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ContentParsingTest {

    private val pollJson = """
    {
      "ok": true,
      "data": {
        "server_time": "2026-10-05T18:30:00+05:30",
        "poll_interval": 8,
        "content_hash": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b",
        "content_changed": true,
        "content": {
          "hash": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b",
          "generated_at": "2026-10-05T18:30:00+05:30",
          "mode": "assigned",
          "screen_on": true,
          "room": { "id": 12, "number": "101", "name": "Deluxe 101", "floor": "1" },
          "hotel": { "name": "Hotel Dwarka Palace", "logo_url": "https://x/uploads/branding/logo.png" },
          "playlist": { "id": 3, "name": "Morning loop", "transition": "fade", "loop": true },
          "items": [
            { "id": 1, "type": "image", "title": "Lobby", "duration": 10, "url": "https://x/api/media/1" },
            { "id": 2, "type": "video", "title": "Promo", "duration": 0, "url": "https://x/api/media/2.mp4", "loop": false, "mute": true },
            { "id": 3, "type": "stream", "title": "Aarti live", "duration": 0, "url": "https://x/live.m3u8", "mute": false },
            { "id": 4, "type": "announcement", "title": "A", "duration": 15, "text": "મંગળા આરતી", "subtitle": "6:00 AM", "style": "marquee", "bg_color": "#000000", "text_color": "#FFD700", "font_size": 48 },
            { "id": 5, "type": "clock", "duration": 10, "style": "analog" },
            { "id": 6, "type": "youtube", "duration": 30, "url": "https://youtu.be/abc", "embed_url": "https://www.youtube.com/embed/abc" },
            { "id": 7, "type": "timetable", "duration": 20, "html": "<html><body>સમયપત્રક</body></html>", "refresh_sec": 60 },
            { "id": 8, "type": "hologram", "duration": 5 },
            { "id": 9, "type": "image", "duration": 5 }
          ],
          "overlay": {
            "clock": true, "clock_format": "hh:mm a",
            "weather": { "enabled": true, "city": "Dwarka", "temp_c": 31, "condition": "Clear", "icon": "☀" },
            "ticker": { "text": "Mangla Aarti at 6:00 AM | મંગળા આરતી સવારે ૬:૦૦", "speed": 5, "bg_color": "#000000", "text_color": "#FFD700" },
            "logo": true
          },
          "emergency": null
        },
        "commands": [
          { "id": 551, "command": "REBOOT", "payload": {} },
          { "id": 552, "command": "UPDATE_APP", "payload": { "url": "https://x/api/device/apk/7", "version_code": 7, "version_name": "1.2.0", "sha256": "abc" } }
        ]
      }
    }
    """.trimIndent()

    private fun parsePoll(json: String): ApiEnvelope<PollResponse> =
        ApiClient.gson.fromJson(json, object : TypeToken<ApiEnvelope<PollResponse>>() {}.type)

    @Test fun parsesPollEnvelope() {
        val env = parsePoll(pollJson)
        assertTrue(env.ok)
        val data = env.data!!
        assertEquals(8, data.pollInterval)
        assertEquals(true, data.contentChanged)
        assertEquals(2, data.commands!!.size)
        val upd = data.commands!![1]
        assertEquals("UPDATE_APP", upd.command)
        assertEquals("https://x/api/device/apk/7", upd.payloadString("url"))
        assertEquals(7, upd.payloadInt("version_code"))
        assertEquals("1.2.0", upd.payloadString("version_name"))
        assertNull(upd.payloadString("missing"))
    }

    @Test fun parsesContentObject() {
        val c = parsePoll(pollJson).data!!.content!!
        assertEquals("assigned", c.mode)
        assertEquals(12L, c.room!!.id)
        assertEquals("Hotel Dwarka Palace", c.hotel!!.name)
        assertEquals("fade", c.playlist!!.transition)
        assertEquals(31.0, c.overlay!!.weather!!.tempC!!, 0.001)
        assertEquals("☀", c.overlay!!.weather!!.icon)
        assertTrue(c.overlay!!.ticker!!.text!!.contains("મંગળા"))
        assertNull(c.emergency)
        assertFalse(c.isEmergency)
        assertFalse(c.isOff)
        assertFalse(c.isEmpty)
    }

    @Test fun filtersUnplayableItems() {
        val c = parsePoll(pollJson).data!!.content!!
        assertEquals(9, c.items!!.size)
        val ids = c.playableItems().map { it.id }
        assertEquals(listOf(1L, 2L, 3L, 4L, 5L, 6L, 7L), ids) // unknown type + image without url dropped
        val ann = c.items!!.first { it.id == 4L }
        assertEquals("મંગળા આરતી", ann.text)
        assertEquals(48f, ann.fontSize!!, 0.01f)
    }

    @Test fun mediaUrlsForCache() {
        val c = parsePoll(pollJson).data!!.content!!
        val urls = ContentCache.mediaUrls(c)
        assertEquals(
            listOf("https://x/api/media/1", "https://x/api/media/2.mp4", "https://x/uploads/branding/logo.png"),
            urls
        )
    }

    @Test fun roundTripKeepsUtf8() {
        val c = parsePoll(pollJson).data!!.content!!
        val json = ApiClient.gson.toJson(c)
        assertTrue("Gujarati must not be escaped", json.contains("મંગળા"))
        val back = ApiClient.gson.fromJson(json, Content::class.java)
        assertEquals(c, back)
    }

    @Test fun modesAndEmergency() {
        val off = ApiClient.gson.fromJson("""{"mode":"off","screen_on":false,"items":[]}""", Content::class.java)
        assertTrue(off.isOff)
        val empty = ApiClient.gson.fromJson("""{"mode":"empty","items":[]}""", Content::class.java)
        assertTrue(empty.isEmpty)
        val em = ApiClient.gson.fromJson(
            """{"mode":"emergency","items":[{"id":1,"type":"announcement","text":"FIRE"}],
               "emergency":{"id":9,"title":"Fire","message":"Leave now","bg_color":"#B00020","text_color":"#FFFFFF"}}""",
            Content::class.java
        )
        assertTrue(em.isEmergency)
        assertEquals("Leave now", em.emergency!!.message)
    }

    @Test fun parsesErrorEnvelope() {
        val env: ApiEnvelope<Any> = ApiClient.gson.fromJson(
            """{"ok":false,"error":{"code":"INVALID_TOKEN","message":"Token revoked"}}""",
            object : TypeToken<ApiEnvelope<Any>>() {}.type
        )
        assertFalse(env.ok)
        assertEquals("INVALID_TOKEN", env.error!!.code)
        assertNotNull(env.error!!.message)
    }

    @Test fun registerResponse() {
        val env: ApiEnvelope<RegisterResponse> = ApiClient.gson.fromJson(
            """{"ok":true,"data":{"token":"f3a9","device_id":"2b1c","room":{"id":12,"number":"101"},
               "poll_interval":8,"heartbeat_interval":60,"settings_pin_hash":"03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4"}}""",
            object : TypeToken<ApiEnvelope<RegisterResponse>>() {}.type
        )
        val d = env.data!!
        assertEquals("f3a9", d.token)
        assertEquals(12L, d.room!!.id)
        assertTrue(Utils.verifyPin("1234", d.settingsPinHash))
    }
}
