package com.hotelcast.tv

import com.google.gson.JsonObject
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/** 2.4 device features: USB playlist, announcements, live view, health and CEC (pure parts). */
class DeviceFeatures24Test {

    // ------------------------------------------------------------------ USB file ordering

    @Test fun naturalOrderSortsNumbersAsNumbersAndIgnoresCase() {
        val names = listOf("10 offer.jpg", "2 menu.png", "1 welcome.JPG", "b.mp4", "A.mp4", "01 intro.jpg", "notes.txt", ".hidden.jpg", "._1 welcome.JPG")
        assertEquals(
            listOf("1 welcome.JPG", "01 intro.jpg", "2 menu.png", "10 offer.jpg", "A.mp4", "b.mp4"),
            UsbPlaylist.ordered(names),
        )
    }

    @Test fun typeDetection() {
        assertEquals(ContentItem.TYPE_IMAGE, UsbPlaylist.typeOf("x.JPEG"))
        assertEquals(ContentItem.TYPE_VIDEO, UsbPlaylist.typeOf("film.MKV"))
        assertNull(UsbPlaylist.typeOf("playlist.txt"))
        assertNull(UsbPlaylist.typeOf("._x.jpg"))
        assertNull(UsbPlaylist.typeOf("noext"))
    }

    // ------------------------------------------------------------------ playlist.txt

    @Test fun playlistParsingFormats() {
        val txt = "﻿# lobby loop\n" +
            "01 welcome.jpg, 8\n" +
            "offer.png 15\n" +
            "hotel-film.mp4\n" +
            "\n" +
            "menu.jpg;20s   # dinner\n" +
            "My film 2.mp4\n" +
            "x.jpg | 5\n" +
            "\"quoted name.png\"=12\n" +
            "readme.txt, 4\n" +
            "dir/sub/deep.jpg\t7\n" +
            "bad.jpg, abc\n"
        assertEquals(
            listOf(
                UsbPlaylist.Entry("01 welcome.jpg", 8),
                UsbPlaylist.Entry("offer.png", 15),
                UsbPlaylist.Entry("hotel-film.mp4", null),
                UsbPlaylist.Entry("menu.jpg", 20),
                UsbPlaylist.Entry("My film 2.mp4", null),
                UsbPlaylist.Entry("x.jpg", 5),
                UsbPlaylist.Entry("quoted name.png", 12),
                UsbPlaylist.Entry("deep.jpg", 7),
                UsbPlaylist.Entry("bad.jpg", null),
            ),
            UsbPlaylist.parsePlaylist(txt),
        )
        assertTrue(UsbPlaylist.parsePlaylist("").isEmpty())
        assertEquals(86_400, UsbPlaylist.parsePlaylist("a.jpg, 999999").single().durationSec)
    }

    private fun files(vararg names: String) = names.map { UsbPlaylist.MediaFile(it, "file:///storage/USB/KrishnaCloud/$it", 100) }

    @Test fun buildWithoutPlaylistUsesNameOrderAndDefaults() {
        val items = UsbPlaylist.build(files("10.jpg", "2.mp4", "1.png", "x.txt"), null)
        assertEquals(listOf("1.png", "2.mp4", "10.jpg"), items.map { it.title })
        assertEquals(listOf(10, 0, 10), items.map { it.duration })
        assertEquals(listOf("image", "video", "image"), items.map { it.type })
        assertTrue(items[1].loop == true)
        assertTrue(items.all { it.isPlayable() && it.id == null })
        assertEquals("file:///storage/USB/KrishnaCloud/1.png", items[0].url)
    }

    @Test fun buildWithPlaylistKeepsOrderDurationsAndOnlyListedFiles() {
        val pl = UsbPlaylist.parsePlaylist("B.JPG, 5\nmissing.jpg, 9\nfilm.mp4, 30\na.jpg 0\n")
        val items = UsbPlaylist.build(files("a.jpg", "b.jpg", "c.jpg", "film.mp4"), pl)
        assertEquals(listOf("b.jpg", "film.mp4", "a.jpg"), items.map { it.title })
        assertEquals(listOf(5, 30, 10), items.map { it.duration }) // image 0 s → default
        // A playlist naming no existing file falls back to all files in name order.
        assertEquals(listOf("a.jpg", "b.jpg"), UsbPlaylist.build(files("b.jpg", "a.jpg"), UsbPlaylist.parsePlaylist("zzz.jpg, 3")).map { it.title })
    }

    @Test fun hashChangesWithFiles() {
        val a = UsbPlaylist.build(files("1.jpg", "2.jpg"), null)
        val b = UsbPlaylist.build(files("1.jpg", "3.jpg"), null)
        assertEquals(UsbPlaylist.hash(a), UsbPlaylist.hash(a))
        assertNotEquals(UsbPlaylist.hash(a), UsbPlaylist.hash(b))
        assertTrue(UsbPlaylist.hash(a).startsWith("usb-"))
    }

    // ------------------------------------------------------------------ USB vs server decision

    @Test fun cacheWinsUsbIsFallbackOrForced() {
        val usb = UsbPlaylist.build(files("1.jpg"), null)
        val server = Content(hash = "h1", mode = "playlist", items = listOf(ContentItem(id = 5, type = "image", url = "http://x/a.jpg", duration = 5)))
        assertEquals(UsbPlaylist.Source.SERVER, UsbPlaylist.decide(server, usb))
        assertEquals(UsbPlaylist.Source.USB, UsbPlaylist.decide(null, usb))
        assertEquals(UsbPlaylist.Source.SERVER, UsbPlaylist.decide(null, emptyList()))
        assertEquals(UsbPlaylist.Source.USB, UsbPlaylist.decide(server.copy(usbMode = true), usb))
        assertEquals(UsbPlaylist.Source.SERVER, UsbPlaylist.decide(server.copy(usbMode = true), emptyList()))

        assertSame(server, UsbPlaylist.effective(server, usb, "usb-1"))
        val offline = UsbPlaylist.effective(null, usb, "usb-1")!!
        assertEquals("usb-1", offline.hash)
        assertEquals(1, offline.playableItems().size)
        assertFalse(offline.isEmpty)

        val forced = UsbPlaylist.effective(server.copy(usbMode = true, overlay = Overlay(clock = true)), usb, "usb-1")!!
        assertEquals("h1+usb-1", forced.hash)
        assertEquals(usb, forced.items)
        assertEquals(true, forced.overlay?.clock) // overlay / ticker / volume of the room stay
        // Emergency / off are never replaced by USB.
        val em = server.copy(usbMode = true, mode = Content.MODE_EMERGENCY)
        assertSame(em, UsbPlaylist.effective(em, usb, "usb-1"))
        val off = server.copy(usbMode = true, mode = Content.MODE_OFF)
        assertSame(off, UsbPlaylist.effective(off, usb, "usb-1"))
    }

    @Test fun contentParsesUsbAndCecFlags() {
        val c = ApiClient.gson.fromJson("""{"hash":"x","mode":"playlist","items":[],"usb_mode":true,"cec_mode":"box"}""", Content::class.java)
        assertEquals(true, c.usbMode)
        assertEquals("box", c.cecMode)
        val old = ApiClient.gson.fromJson("""{"hash":"x","mode":"playlist","items":[]}""", Content::class.java)
        assertNull(old.usbMode)
        assertNull(old.cecMode)
    }

    // ------------------------------------------------------------------ SPEAK / PLAY_SOUND

    private fun payload(vararg kv: Pair<String, Any>) = JsonObject().apply {
        kv.forEach { (k, v) ->
            when (v) {
                is Number -> addProperty(k, v)
                is Boolean -> addProperty(k, v)
                else -> addProperty(k, v.toString())
            }
        }
    }

    @Test fun speakPayloadIsValidatedAndClamped() {
        val r = AnnounceSpec.speak(Command(1, "SPEAK", payload("text" to "  Breakfast is ready  ", "lang" to "EN", "rate" to 5, "repeat" to 9, "volume" to 150, "chime_before" to true)))
        assertEquals(SpeakRequest("Breakfast is ready", "en", 2f, 3, 100, true), r)
        val d = AnnounceSpec.speak(Command(2, "SPEAK", payload("text" to "નમસ્તે")))
        assertEquals(SpeakRequest("નમસ્તે", "auto", 1f, 1, null, false), d)
        assertEquals(0.5f, AnnounceSpec.speak(Command(3, "SPEAK", payload("text" to "x", "rate" to 0.1))).rate)
        assertEquals(true, AnnounceSpec.speak(Command(4, "SPEAK", payload("text" to "x", "chime_before" to "1"))).chimeBefore)
        for (bad in listOf(payload(), payload("text" to "   "), payload("text" to "x", "lang" to "fr"))) {
            try {
                AnnounceSpec.speak(Command(9, "SPEAK", bad))
                fail("expected failure for $bad")
            } catch (e: CommandFailedException) {
                assertTrue(e.message!!.isNotBlank())
            }
        }
        assertEquals(1000, AnnounceSpec.speak(Command(5, "SPEAK", payload("text" to "a".repeat(5000)))).text.length)
    }

    @Test fun languageDetectionAndLocales() {
        assertEquals("gu", AnnounceSpec.detectLang("નાસ્તો તૈયાર છે"))
        assertEquals("hi", AnnounceSpec.detectLang("नाश्ता तैयार है"))
        assertEquals("en", AnnounceSpec.detectLang("Breakfast is ready"))
        assertEquals("gu", AnnounceSpec.detectLang("Room 101: નાસ્તો"))
        assertEquals("gu-IN", AnnounceSpec.localeTag("gu"))
        assertEquals("hi-IN", AnnounceSpec.localeTag("hi"))
        assertEquals("en-IN", AnnounceSpec.localeTag("en"))
        assertEquals("hi", AnnounceSpec.resolveLang(SpeakRequest("नमस्ते", "auto", 1f, 1, null, false)))
        assertEquals("en", AnnounceSpec.resolveLang(SpeakRequest("नमस्ते", "en", 1f, 1, null, false)))
    }

    @Test fun soundPayload() {
        assertEquals(SoundRequest("https://h/sounds/chime.mp3", 40, 2), AnnounceSpec.sound(Command(1, "PLAY_SOUND", payload("url" to "https://h/sounds/chime.mp3", "volume" to 40, "repeat" to 2))))
        assertEquals(SoundRequest("http://h/a.mp3", 100, 10), AnnounceSpec.sound(Command(1, "PLAY_SOUND", payload("url" to "http://h/a.mp3", "repeat" to 50))))
        for (bad in listOf("", "file:///sdcard/a.mp3", "javascript:alert(1)", "ftp://x/a.mp3")) {
            try {
                AnnounceSpec.sound(Command(1, "PLAY_SOUND", payload("url" to bad)))
                fail("expected failure for '$bad'")
            } catch (_: CommandFailedException) {
            }
        }
    }

    @Test fun chimeAndTimeouts() {
        val pcm = AnnounceSpec.chimeSamples(8000)
        assertTrue(pcm.size in 6000..8000)
        assertTrue(pcm.any { it > 1000 })
        assertTrue(AnnounceSpec.utteranceTimeoutMs("x".repeat(1000), 0.5f) <= 300_000L)
        assertTrue(AnnounceSpec.utteranceTimeoutMs("hello", 1f) >= 10_000L)
    }

    // ------------------------------------------------------------------ command dispatch + acks

    private class Actions : CommandActions {
        val calls = mutableListOf<Any>()
        override suspend fun refetchContent() {}
        override suspend fun clearCache() {}
        override suspend fun setScreenOn(on: Boolean): String = "ok"
        override fun reload() {}
        override fun reboot() {}
        override suspend fun updateApp(url: String, sha256: String?, versionCode: Int?, versionName: String?, beforeInstall: suspend (String) -> Unit) =
            AppUpdater.Result(true, "x")
        override suspend fun speak(req: SpeakRequest): String { calls += req; return "Speaking, voice gu-IN" }
        override suspend fun playSound(req: SoundRequest): String { calls += req; return "Playing sound" }
        override suspend fun startLiveView(req: LiveViewRequest): String { calls += req; return "Live view started" }
    }

    @Test fun newCommandsAreDispatchedAndAcked() {
        val a = Actions()
        val acks = mutableListOf<AckRequest>()
        val h = CommandHandler(CommandDeduper(), {}, { acks += it }, a)
        runTest {
            h.handle(Command(1, "SPEAK", payload("text" to "નમસ્તે", "lang" to "gu", "repeat" to 2)))
            h.handle(Command(2, "speak", payload("lang" to "gu")))
            h.handle(Command(3, "PLAY_SOUND", payload("url" to "https://x/c.mp3", "volume" to 70)))
            h.handle(Command(4, "PLAY_SOUND", JsonObject()))
            h.handle(Command(5, "LIVE_VIEW", payload("session" to "abcdef0123456789abcdef0123456789", "interval" to 1, "max_sec" to 600, "max_width" to 4000)))
            h.handle(Command(6, "LIVE_VIEW", payload("session" to "short")))
        }
        assertEquals(listOf("acked", "failed", "acked", "failed", "acked", "failed"), acks.map { it.status })
        assertEquals("Speaking, voice gu-IN", acks[0].message)
        assertTrue(acks[1].message!!.contains("text"))
        assertEquals(SpeakRequest("નમસ્તે", "gu", 1f, 2, null, false), a.calls[0])
        assertEquals(SoundRequest("https://x/c.mp3", 70, 1), a.calls[1])
        assertEquals(LiveViewRequest("abcdef0123456789abcdef0123456789", 3, 120, 960, 60), a.calls[2])
        assertEquals(3, a.calls.size)
    }

    @Test fun olderActionImplementationsFailCleanly() {
        val acks = mutableListOf<AckRequest>()
        val minimal = object : CommandActions {
            override suspend fun refetchContent() {}
            override suspend fun clearCache() {}
            override suspend fun setScreenOn(on: Boolean): String = "ok"
            override fun reload() {}
            override fun reboot() {}
            override suspend fun updateApp(url: String, sha256: String?, versionCode: Int?, versionName: String?, beforeInstall: suspend (String) -> Unit) =
                AppUpdater.Result(true, "x")
        }
        runTest { CommandHandler(CommandDeduper(), {}, { acks += it }, minimal).handle(Command(1, "SPEAK", payload("text" to "x"))) }
        assertEquals("failed", acks.single().status)
        assertEquals("SPEAK not supported", acks.single().message)
    }

    // ------------------------------------------------------------------ live view

    @Test fun liveViewDeadlinesAndStop() {
        val now = 1_000_000L
        assertEquals(now + 120_000, LiveViewSpec.deadlineAfter(now, now + 5_000, LiveState(true, 4, 500)))
        assertEquals(now + 30_000, LiveViewSpec.deadlineAfter(now, now + 5_000, LiveState(true, 4, 30)))
        assertEquals(now + 5_000, LiveViewSpec.deadlineAfter(now, now + 5_000, null)) // no answer: old deadline
        assertTrue(LiveViewSpec.keepGoing(now, now + 1, LiveState(true, 4, 30), 0))
        assertFalse(LiveViewSpec.keepGoing(now, now + 60_000, LiveState(false, 4, 0), 0))
        assertFalse(LiveViewSpec.keepGoing(now, now, null, 0))
        assertFalse(LiveViewSpec.keepGoing(now, now + 60_000, null, LiveViewSpec.MAX_FAILURES))
        assertEquals(3, LiveViewSpec.clampInterval(0))
        assertEquals(10, LiveViewSpec.clampInterval(60))
        assertEquals(4, LiveViewSpec.clampInterval(null))
        val r = ApiClient.gson.fromJson("""{"stored":true,"live":{"continue":false,"interval":5,"stop_in":0}}""", LiveFrameResponse::class.java)
        assertEquals(false, r.live?.keepGoing)
        assertEquals(5, r.live?.interval)
    }

    @Test fun screenshotSizeForLiveFrames() {
        assertEquals(960 to 540, ScreenshotEncoder.targetSize(1920, 1080, LiveViewSpec.MAX_WIDTH))
        assertEquals(960 to 540, ScreenshotEncoder.targetSize(3840, 2160, LiveViewSpec.MAX_WIDTH))
        assertEquals(640 to 360, ScreenshotEncoder.targetSize(640, 360, LiveViewSpec.MAX_WIDTH))
    }

    // ------------------------------------------------------------------ health

    @Test fun temperaturePicking() {
        assertEquals(52.3, DeviceHealth.pickTemperature(listOf("battery" to "30000", "cpu-thermal" to "52300", "gpu" to "61000"))!!, 0.001)
        assertEquals(61.0, DeviceHealth.pickTemperature(listOf("zone a" to "45000", "zone b" to "61000"))!!, 0.001)
        assertEquals(45.0, DeviceHealth.pickTemperature(listOf("soc" to "450"))!!, 0.001) // tenths
        assertEquals(48.0, DeviceHealth.pickTemperature(listOf("tsens_tz_sensor0" to "48"))!!, 0.001)
        assertNull(DeviceHealth.pickTemperature(listOf("x" to "-40000", "y" to "abc", "z" to "0", "w" to "900000")))
        assertNull(DeviceHealth.pickTemperature(emptyList()))
        assertEquals("3840x2160", DeviceHealth.resolution(2160, 3840))
        assertNull(DeviceHealth.resolution(0, 1080))
    }

    @Test fun heartbeatCarriesHealthJson() {
        val hb = HeartbeatRequest("2.4.0", 11, "11", "TV", null, -1, "wifi", -60, 900, null, null, true, 100,
            health = mapOf("ram_avail_mb" to 512L, "cpu_temp_c" to 55.5, "device_owner" to true))
        val json = ApiClient.gson.toJson(hb)
        assertTrue(json, json.contains("\"health\":{\"ram_avail_mb\":512,\"cpu_temp_c\":55.5,\"device_owner\":true}"))
    }

    // ------------------------------------------------------------------ CEC

    @Test fun cecBoxDetectionAndSetting() {
        assertFalse(CecControl.isLikelyBox(hasHdmiInputs = true, hasTuner = false, model = "MiBox S", manufacturer = "Xiaomi"))
        assertFalse(CecControl.isLikelyBox(false, true, "Box", "x"))
        assertTrue(CecControl.isLikelyBox(false, false, "MIBOX4", "Xiaomi") || CecControl.isLikelyBox(false, false, "Mi Box S", "Xiaomi"))
        assertTrue(CecControl.isLikelyBox(false, false, "SHIELD Android TV", "NVIDIA"))
        assertTrue(CecControl.isLikelyBox(false, false, "Chromecast", "Google"))
        assertTrue(CecControl.isLikelyBox(false, false, "AFTMM", "Amazon"))
        assertFalse(CecControl.isLikelyBox(false, false, "BRAVIA 4K VH2", "Sony"))
        assertFalse(CecControl.isLikelyBox(false, false, "Unknown model", "Unknown")) // unknown: keep TV behaviour
        assertTrue(CecControl.effectiveBox("box", false))
        assertFalse(CecControl.effectiveBox("TV", true))
        assertTrue(CecControl.effectiveBox("auto", true))
        assertFalse(CecControl.effectiveBox(null, false))
        assertTrue(CecControl.boxMode(null, Content(cecMode = "box")))
        assertFalse(CecControl.boxMode(null, Content(cecMode = "auto")))
    }
}
