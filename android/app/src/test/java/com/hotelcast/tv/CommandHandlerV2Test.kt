package com.hotelcast.tv

import com.google.gson.JsonObject
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class CommandHandlerV2Test {

    private class FakeActions : CommandActions {
        val calls = mutableListOf<String>()
        var failScreenshot = false
        override suspend fun refetchContent() { calls += "refetch" }
        override suspend fun clearCache() { calls += "clear" }
        override suspend fun setScreenOn(on: Boolean): String = "ok"
        override fun reload() {}
        override fun reboot() {}
        override suspend fun updateApp(url: String, sha256: String?, versionCode: Int?, versionName: String?, beforeInstall: suspend (String) -> Unit) =
            AppUpdater.Result(true, "x")
        override suspend fun setVolume(level: Int): String { calls += "volume:$level"; return "Volume $level%" }
        override suspend fun setMuted(muted: Boolean): String { calls += "mute:$muted"; return if (muted) "Muted" else "Unmuted" }
        override suspend fun takeScreenshot(): String {
            calls += "screenshot"
            if (failScreenshot) throw CommandFailedException("Player screen is not open")
            return "Screenshot uploaded (120 KB)"
        }
        override suspend fun uploadLogs(): String { calls += "logs"; return "Logs uploaded (40 KB)" }
        override suspend fun openInput(input: String): String { calls += "input:$input"; return "Opened $input" }
        override suspend fun showWelcome(): String { calls += "welcome"; return "Welcome shown" }
        override suspend fun showMessage(title: String?, message: String?, durationSec: Int): String {
            calls += "message:$title|$message|$durationSec"; return "Message shown"
        }
    }

    private fun payload(vararg kv: Pair<String, Any>) = JsonObject().apply {
        kv.forEach { (k, v) -> if (v is Number) addProperty(k, v) else addProperty(k, v.toString()) }
    }

    private fun run(vararg cmds: Command): Pair<FakeActions, List<AckRequest>> {
        val actions = FakeActions()
        val acks = mutableListOf<AckRequest>()
        val h = CommandHandler(CommandDeduper(), {}, { acks += it }, actions)
        runTest { cmds.forEach { h.handle(it) } }
        return actions to acks
    }

    @Test fun volumeCommands() {
        val (a, acks) = run(
            Command(1, "SET_VOLUME", payload("level" to 40)),
            Command(2, "SET_VOLUME", payload("level" to 250)),
            Command(3, "SET_VOLUME", JsonObject()),
            Command(4, "MUTE", JsonObject()),
            Command(5, "unmute", null),
        )
        assertEquals(listOf("volume:40", "volume:100", "mute:true", "mute:false"), a.calls)
        assertEquals(listOf("acked", "acked", "failed", "acked", "acked"), acks.map { it.status })
        assertEquals("Volume 40%", acks[0].message)
        assertTrue(acks[2].message!!.contains("level"))
    }

    @Test fun screenshotAndLogs() {
        val (a, acks) = run(Command(1, "SCREENSHOT", JsonObject()), Command(2, "UPLOAD_LOGS", JsonObject()))
        assertEquals(listOf("screenshot", "logs"), a.calls)
        assertEquals("Screenshot uploaded (120 KB)", acks[0].message)
        assertEquals("acked", acks[1].status)
    }

    @Test fun failuresAckedWithReason() {
        val actions = FakeActions().apply { failScreenshot = true }
        val acks = mutableListOf<AckRequest>()
        runTest { CommandHandler(CommandDeduper(), {}, { acks += it }, actions).handle(Command(9, "SCREENSHOT", JsonObject())) }
        assertEquals("failed", acks.single().status)
        assertEquals("Player screen is not open", acks.single().message)
    }

    @Test fun openInputValidatesPayload() {
        val (a, acks) = run(
            Command(1, "OPEN_INPUT", payload("input" to "hdmi2")),
            Command(2, "OPEN_INPUT", payload("input" to "live_tv")),
            Command(3, "OPEN_INPUT", payload("input" to "usb")),
            Command(4, "OPEN_INPUT", JsonObject()),
        )
        assertEquals(listOf("input:hdmi2", "input:live_tv"), a.calls)
        assertEquals(listOf("acked", "acked", "failed", "failed"), acks.map { it.status })
    }

    @Test fun welcomeAndMessage() {
        val (a, acks) = run(
            Command(1, "SHOW_WELCOME", JsonObject()),
            Command(2, "SHOW_MESSAGE", payload("title" to "Room service", "message" to "Your food is on the way", "duration_sec" to 20)),
            Command(3, "SHOW_MESSAGE", payload("message" to "No title")),
            Command(4, "SHOW_MESSAGE", JsonObject()),
        )
        assertEquals(
            listOf("welcome", "message:Room service|Your food is on the way|20", "message:null|No title|${CommandHandler.DEFAULT_MESSAGE_SEC}"),
            a.calls,
        )
        assertEquals(listOf("acked", "acked", "acked", "failed"), acks.map { it.status })
    }

    @Test fun oldActionImplementationFailsNewCommandsGracefully() {
        val legacy = object : CommandActions {
            override suspend fun refetchContent() {}
            override suspend fun clearCache() {}
            override suspend fun setScreenOn(on: Boolean) = "ok"
            override fun reload() {}
            override fun reboot() {}
            override suspend fun updateApp(url: String, sha256: String?, versionCode: Int?, versionName: String?, beforeInstall: suspend (String) -> Unit) =
                AppUpdater.Result(true, "x")
        }
        val acks = mutableListOf<AckRequest>()
        runTest { CommandHandler(CommandDeduper(), {}, { acks += it }, legacy).handle(Command(1, "SCREENSHOT", JsonObject())) }
        assertEquals("failed", acks.single().status)
        assertTrue(acks.single().message!!.contains("not supported"))
    }
}
