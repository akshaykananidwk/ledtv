package com.hotelcast.tv

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 2.4.1 emergency alarm: content parsing, restart decisions, volume steps, SHOW_MESSAGE sound. */
class AlarmPlanTest {

    private fun content(json: String): Content = ApiClient.gson.fromJson(json, Content::class.java)

    private fun emergency(alarm: String?, id: Long = 9, muted: Boolean = false): Content = content(
        """{"mode":"emergency","hash":"h","emergency":{"id":$id,"title":"Fire","message":"Stairs",
           "bg_color":"#B00020","text_color":"#FFFFFF","alarm":${alarm ?: "null"},"alarm_muted":$muted},"items":[]}"""
    )

    private val beep = """{"url":"https://h.example/assets/sounds/emergency_beep.wav","loop":true,"repeat":3,"volume":80,"name":"Emergency beep"}"""

    @Test fun parsesTheAlarmOfAnEmergency() {
        val s = AlarmPlan.from(emergency(beep))!!
        assertEquals(AlarmSpec("https://h.example/assets/sounds/emergency_beep.wav", true, 3, 80, 9), s)
        assertEquals("Emergency beep", emergency(beep).emergency!!.alarm!!.name)
        // Missing fields: loop, repeat 3, volume 80; out-of-range values are clamped.
        assertEquals(AlarmSpec("http://h/a.wav", true, 3, 80, 9), AlarmPlan.from(emergency("""{"url":"http://h/a.wav"}""")))
        assertEquals(AlarmSpec("http://h/a.wav", false, 10, 100, 9), AlarmPlan.from(emergency("""{"url":"http://h/a.wav","loop":false,"repeat":99,"volume":250}""")))
        assertEquals(1, AlarmPlan.from(emergency("""{"url":"http://h/a.wav","loop":false,"repeat":0,"volume":-5}"""))!!.repeat)
    }

    @Test fun noAlarmWhenNoneSilencedOldServerOrInvalid() {
        assertNull(AlarmPlan.from(null))
        assertNull(AlarmPlan.from(emergency(null)))
        assertNull(AlarmPlan.from(emergency(null, muted = true)))
        assertTrue(emergency(null, muted = true).emergency!!.alarmMuted!!)
        // 2.4.0 server: emergency without the alarm fields.
        val old = content("""{"mode":"emergency","emergency":{"id":3,"title":"Old","message":"","bg_color":"#B00020","text_color":"#FFFFFF"}}""")
        assertTrue(old.isEmergency)
        assertNull(AlarmPlan.from(old))
        assertNull(AlarmPlan.from(emergency("""{"url":"file:///sdcard/x.wav"}""")))
        assertNull(AlarmPlan.from(emergency("""{"url":"javascript:alert(1)"}""")))
        assertNull(AlarmPlan.from(emergency("""{"url":""}""")))
        // Not an emergency (e.g. stale alarm object in normal content) → nothing.
        assertNull(AlarmPlan.from(content("""{"mode":"assigned","emergency":null,"items":[]}""")))
    }

    @Test fun sameAlarmResentDoesNotRestart() {
        val a = AlarmPlan.from(emergency(beep))
        val again = AlarmPlan.from(emergency(beep)) // a sync re-sends the same emergency
        assertEquals(AlarmPlan.Action.NONE, AlarmPlan.decide(a, again))
        // A looping alarm: a new emergency with the same sound keeps playing without a stutter.
        assertEquals(AlarmPlan.Action.NONE, AlarmPlan.decide(a, AlarmPlan.from(emergency(beep, id = 10))))
        // Repeat count is irrelevant while looping.
        assertEquals(AlarmPlan.Action.NONE, AlarmPlan.decide(a, AlarmPlan.from(emergency(beep.replace("\"repeat\":3", "\"repeat\":5")))))
    }

    @Test fun restartOnlyWhenTheSoundOrLoopChanges() {
        val a = AlarmPlan.from(emergency(beep))
        val siren = AlarmPlan.from(emergency(beep.replace("emergency_beep", "emergency_siren")))
        val once = AlarmPlan.from(emergency(beep.replace("\"loop\":true", "\"loop\":false")))
        assertEquals(AlarmPlan.Action.RESTART, AlarmPlan.decide(a, siren))
        assertEquals(AlarmPlan.Action.RESTART, AlarmPlan.decide(a, once))
        assertEquals(AlarmPlan.Action.START, AlarmPlan.decide(null, a))
        assertEquals(AlarmPlan.Action.ADJUST_VOLUME, AlarmPlan.decide(a, a!!.copy(volume = 100)))
        // "Play 3 times": same emergency → never replayed; a new emergency → plays again.
        assertEquals(AlarmPlan.Action.NONE, AlarmPlan.decide(once, once!!.copy()))
        assertEquals(AlarmPlan.Action.RESTART, AlarmPlan.decide(once, once.copy(emergencyId = 11)))
        assertEquals(AlarmPlan.Action.RESTART, AlarmPlan.decide(once, once.copy(repeat = 4)))
    }

    @Test fun stopsOnEmergencyEndOrSilence() {
        val a = AlarmPlan.from(emergency(beep))
        assertEquals(AlarmPlan.Action.STOP, AlarmPlan.decide(a, AlarmPlan.from(emergency(null, muted = true))))
        assertEquals(AlarmPlan.Action.STOP, AlarmPlan.decide(a, AlarmPlan.from(content("""{"mode":"assigned","items":[]}"""))))
        assertEquals(AlarmPlan.Action.STOP, AlarmPlan.decide(a, null))
        assertEquals(AlarmPlan.Action.NONE, AlarmPlan.decide(null, null))
    }

    @Test fun volumeIsRaisedToAtLeastTheAlarmLevel() {
        assertEquals(12, AlarmPlan.minIndexFor(80, 15)) // 80 % of 15 = 12
        assertEquals(13, AlarmPlan.minIndexFor(81, 15)) // rounded up, never below the request
        assertEquals(15, AlarmPlan.minIndexFor(100, 15))
        assertEquals(0, AlarmPlan.minIndexFor(0, 15))
        assertEquals(0, AlarmPlan.minIndexFor(80, 0))
        assertEquals(12, AlarmPlan.raisedIndex(3, 15, 80)) // quiet TV → raised
        assertEquals(14, AlarmPlan.raisedIndex(14, 15, 80)) // already louder → unchanged (never lowered)
        assertEquals(15, AlarmPlan.raisedIndex(99, 15, 80))
        assertEquals(80, AlarmPlan.raisedIndex(10, 100, 80))
    }

    @Test fun volumeIsRestoredUnlessChangedMeanwhile() {
        val r = AlarmPlan.VolumeRestore(previousIndex = 4, raisedIndex = 12, wasMuted = true)
        assertEquals(r, AlarmPlan.VolumeRestore.parse(r.serialize()))
        assertEquals("4|12|1", r.serialize())
        assertEquals(4, AlarmPlan.restoreIndex(r, 12))
        assertNull(AlarmPlan.restoreIndex(r, 7)) // staff / guest changed it during the alarm: keep
        assertNull(AlarmPlan.restoreIndex(AlarmPlan.VolumeRestore(13, 13, false), 13)) // nothing was raised
        assertNull(AlarmPlan.VolumeRestore.parse(""))
        assertNull(AlarmPlan.VolumeRestore.parse("x|1|0"))
        assertNull(AlarmPlan.VolumeRestore.parse("1|2"))
        assertNull(AlarmPlan.VolumeRestore.parse(null))
    }

    @Test fun alarmFileIsCachedWithTheContent() {
        val urls = ContentCache.mediaUrls(emergency(beep))
        assertTrue(urls.contains("https://h.example/assets/sounds/emergency_beep.wav"))
        assertFalse(ContentCache.mediaUrls(emergency(null, muted = true)).any { it.endsWith(".wav") })
    }

    // ------------------------------------------------------------------ SHOW_MESSAGE sound

    private class Actions : CommandActions {
        val calls = mutableListOf<String>()
        var soundFails = false
        override suspend fun refetchContent() {}
        override suspend fun clearCache() {}
        override suspend fun setScreenOn(on: Boolean): String = "ok"
        override fun reload() {}
        override fun reboot() {}
        override suspend fun updateApp(url: String, sha256: String?, versionCode: Int?, versionName: String?, beforeInstall: suspend (String) -> Unit) =
            AppUpdater.Result(true, "x")
        override suspend fun showMessage(title: String?, message: String?, durationSec: Int): String {
            calls += "message:$title"
            return "Message shown for $durationSec s"
        }
        override suspend fun playSound(req: SoundRequest): String {
            calls += "sound:${req.url}|${req.repeat}|${req.volume}"
            if (soundFails) throw CommandFailedException("Announcement queue full (10)")
            return "Playing sound"
        }
    }

    private fun cmd(id: Long, json: String) = Command(id, "SHOW_MESSAGE", JsonParser.parseString(json).asJsonObject)

    @Test fun showMessagePlaysItsSound() {
        val a = Actions()
        val acks = mutableListOf<AckRequest>()
        val h = CommandHandler(CommandDeduper(), {}, { acks += it }, a)
        runTest {
            h.handle(cmd(1, """{"title":"Tea","message":"","duration_sec":15,"sound":{"url":"https://h/assets/sounds/notice_chime.wav","repeat":2,"volume":70}}"""))
            h.handle(cmd(2, """{"title":"Plain"}"""))
            h.handle(cmd(3, """{"title":"Bad","sound":{"url":"ftp://h/x.wav"}}"""))
            h.handle(cmd(4, """{"title":"Clamp","sound":{"url":"https://h/x.wav","repeat":9,"volume":300}}"""))
            h.handle(cmd(5, """{"title":"Odd","sound":"b:notice_chime"}"""))
        }
        assertEquals(
            listOf(
                "message:Tea", "sound:https://h/assets/sounds/notice_chime.wav|2|70",
                "message:Plain", "message:Bad", "message:Clamp", "sound:https://h/x.wav|5|100", "message:Odd",
            ),
            a.calls,
        )
        assertEquals(listOf("acked", "acked", "acked", "acked", "acked"), acks.map { it.status })
        assertEquals("Message shown for 15 s; Playing sound", acks[0].message)
        assertEquals("Message shown for 15 s", acks[1].message)
        // Defaults: once, volume 80.
        assertEquals(SoundRequest("https://h/a.wav", 80, 1), AnnounceSpec.messageSound(cmd(9, """{"sound":{"url":"https://h/a.wav"}}""")))
        assertNull(AnnounceSpec.messageSound(Command(9, "SHOW_MESSAGE", JsonObject())))
    }

    @Test fun messageIsShownEvenWhenTheSoundCannotPlay() {
        val a = Actions().apply { soundFails = true }
        val acks = mutableListOf<AckRequest>()
        val h = CommandHandler(CommandDeduper(), {}, { acks += it }, a)
        runTest { h.handle(cmd(1, """{"title":"Tea","sound":{"url":"https://h/c.wav"}}""")) }
        assertEquals("acked", acks.single().status)
        assertNotNull(acks.single().message)
        assertTrue(acks.single().message!!.contains("sound not played: Announcement queue full"))
    }
}
