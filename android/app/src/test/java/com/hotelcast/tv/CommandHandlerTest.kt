package com.hotelcast.tv

import com.google.gson.JsonObject
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class CommandHandlerTest {

    private class FakeActions : CommandActions {
        val calls = mutableListOf<String>()
        override suspend fun refetchContent() { calls += "refetch" }
        override suspend fun clearCache() { calls += "clear" }
        override fun setScreenOn(on: Boolean) { calls += if (on) "on" else "off" }
        override fun reload() { calls += "reload" }
        override fun reboot() { calls += "reboot" }
        override suspend fun updateApp(
            url: String, sha256: String?, versionCode: Int?, versionName: String?,
            beforeInstall: suspend (String) -> Unit,
        ): AppUpdater.Result {
            calls += "update:$url:$versionCode"
            beforeInstall("Installing $versionName")
            return AppUpdater.Result(true, "started")
        }
    }

    private fun cmd(id: Long, name: String, payload: JsonObject = JsonObject()) = Command(id, name, payload)

    @Test fun dedupeExecutesOnceButAcksEveryDelivery() = runTest {
        val actions = FakeActions()
        val acks = mutableListOf<AckRequest>()
        var persisted = ""
        val handler = CommandHandler(CommandDeduper(), { persisted = it }, { acks += it }, actions)

        handler.handle(cmd(10, "SCREEN_OFF"))
        handler.handle(cmd(10, "SCREEN_OFF")) // redelivered (ack lost)
        handler.handle(cmd(11, "SCREEN_ON"))

        assertEquals(listOf("off", "on"), actions.calls)
        assertEquals(listOf(10L, 10L, 11L), acks.map { it.commandId })
        assertTrue(acks.all { it.status == CommandHandler.STATUS_ACKED })
        assertEquals("10,11", persisted)
    }

    @Test fun dedupeSurvivesRestartViaSerialization() = runTest {
        val first = CommandDeduper()
        first.markHandled(551)
        first.markHandled(552)
        val restored = CommandDeduper.deserialize(first.serialize())
        val actions = FakeActions()
        val acks = mutableListOf<AckRequest>()
        val handler = CommandHandler(restored, {}, { acks += it }, actions)
        handler.handle(cmd(551, "REBOOT"))
        assertTrue("reboot must not run twice", actions.calls.isEmpty())
        assertEquals(1, acks.size)
    }

    @Test fun rebootIsAckedBeforeRebooting() = runTest {
        val order = mutableListOf<String>()
        val actions = object : CommandActions by FakeActions() {
            override fun reboot() { order += "reboot" }
        }
        val handler = CommandHandler(CommandDeduper(), {}, { order += "ack:${it.status}" }, actions)
        handler.handle(cmd(1, "REBOOT"))
        assertEquals(listOf("ack:acked", "reboot"), order)
    }

    @Test fun updateAppAcksOnceBeforeInstall() = runTest {
        val actions = FakeActions()
        val acks = mutableListOf<AckRequest>()
        val handler = CommandHandler(CommandDeduper(), {}, { acks += it }, actions)
        val payload = JsonObject().apply {
            addProperty("url", "https://h/api/device/apk/7")
            addProperty("version_code", 7)
            addProperty("version_name", "1.2.0")
            addProperty("sha256", "abc")
        }
        handler.handle(cmd(5, "update_app", payload))
        assertEquals(listOf("update:https://h/api/device/apk/7:7"), actions.calls)
        assertEquals(1, acks.size)
        assertEquals("Installing 1.2.0", acks[0].message)
    }

    @Test fun unknownAndInvalidCommandsFail() = runTest {
        val acks = mutableListOf<AckRequest>()
        val handler = CommandHandler(CommandDeduper(), {}, { acks += it }, FakeActions())
        handler.handle(cmd(1, "SELF_DESTRUCT"))
        handler.handle(cmd(2, "UPDATE_APP")) // no url
        handler.handle(Command(null, "PING")) // no id: ignored
        assertEquals(listOf(CommandHandler.STATUS_FAILED, CommandHandler.STATUS_FAILED), acks.map { it.status })
    }

    @Test fun ackFailureDoesNotBreakHandling() = runTest {
        val actions = FakeActions()
        val handler = CommandHandler(CommandDeduper(), {}, { throw java.io.IOException("offline") }, actions)
        handler.handle(cmd(1, "CLEAR_CACHE"))
        handler.handle(cmd(2, "SHOW_CONTENT"))
        assertEquals(listOf("clear", "refetch"), actions.calls)
    }

    @Test fun handleAllRunsInIdOrder() = runTest {
        val actions = FakeActions()
        val handler = CommandHandler(CommandDeduper(), {}, {}, actions)
        handler.handleAll(listOf(cmd(3, "SCREEN_ON"), cmd(2, "SCREEN_OFF")))
        assertEquals(listOf("off", "on"), actions.calls)
    }

    @Test fun deduperIsBounded() {
        val d = CommandDeduper(capacity = 3)
        (1L..5L).forEach { d.markHandled(it) }
        assertEquals(3, d.size())
        assertFalse(d.isHandled(1))
        assertTrue(d.isHandled(5))
        assertEquals("3,4,5", d.serialize())
    }
}
