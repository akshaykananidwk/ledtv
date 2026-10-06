package com.hotelcast.tv

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.async
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.IOException

@OptIn(ExperimentalCoroutinesApi::class)
class QrSetupTest {

    // ------------------------------------------------------------------ JSON parsing

    private val gson = Gson()

    private fun statusEnvelope(json: String): ApiEnvelope<ProvisionStatusResponse> =
        gson.fromJson(json, object : TypeToken<ApiEnvelope<ProvisionStatusResponse>>() {}.type)

    private fun status(json: String, server: String = "https://ledtv.akdwk.in/"): StatusResult {
        val env = statusEnvelope(json)
        assertTrue(env.ok)
        return QrSetupLogic.interpret(env.data, server)
    }

    @Test fun parsesPending() {
        assertEquals(StatusResult.Pending, status("""{"ok":true,"data":{"status":"pending"}}"""))
    }

    @Test fun parsesExpiredAndUsed() {
        assertEquals(StatusResult.Expired, status("""{"ok":true,"data":{"status":"expired"}}"""))
        assertEquals(StatusResult.Used, status("""{"ok":true,"data":{"status":"used"}}"""))
        assertEquals(StatusResult.Pending, status("""{"ok":true,"data":{"status":" PENDING "}}"""))
    }

    @Test fun parsesClaimed() {
        val r = status(
            """{"ok":true,"data":{"status":"claimed","server_url":"https://hotel.example.com/hotelcast",
               "room_number":"101","registration_key":"KEY-abc","hotel_name":"Hotel Sunrise"}}"""
        ) as StatusResult.Claimed
        assertEquals("https://hotel.example.com/hotelcast/", r.config.serverUrl)
        assertEquals("https://hotel.example.com/hotelcast/api/", r.config.apiBase)
        assertEquals("101", r.config.room)
        assertEquals("KEY-abc", r.config.key)
        assertEquals("Hotel Sunrise", r.config.hotelName)
    }

    @Test fun unknownOrMissingStatusIsInvalid() {
        assertTrue(status("""{"ok":true,"data":{"status":"weird"}}""") is StatusResult.Invalid)
        assertTrue(status("""{"ok":true,"data":{}}""") is StatusResult.Invalid)
        assertTrue(QrSetupLogic.interpret(null, "https://x.com/") is StatusResult.Invalid)
    }

    @Test fun parsesStartResponse() {
        val env: ApiEnvelope<ProvisionStartResponse> = gson.fromJson(
            """{"ok":true,"data":{"code":"AB3K9Z","secret":"s3cr3t","claim_url":"https://ledtv.akdwk.in/admin/claim?code=AB3K9Z",
               "expires_in":600,"poll_interval":3}}""",
            object : TypeToken<ApiEnvelope<ProvisionStartResponse>>() {}.type,
        )
        val s = QrSetupLogic.session(env.data, nowMs = 1_000)!!
        assertEquals("AB3K9Z", s.code)
        assertEquals("s3cr3t", s.secret)
        assertEquals("https://ledtv.akdwk.in/admin/claim?code=AB3K9Z", s.claimUrl)
        assertEquals(601_000, s.expiresAtMs)
        assertEquals(3, s.pollIntervalSec)
    }

    @Test fun startResponseDefaultsAndRejects() {
        val s = QrSetupLogic.session(ProvisionStartResponse("C", "S", "u", null, null), 0)!!
        assertEquals(QrSetupLogic.DEFAULT_EXPIRES_SEC * 1000L, s.expiresAtMs)
        assertEquals(QrSetupLogic.DEFAULT_POLL_SEC, s.pollIntervalSec)
        assertEquals(30, QrSetupLogic.session(ProvisionStartResponse("C", "S", "u", 600, 999), 0)!!.pollIntervalSec)
        assertNull(QrSetupLogic.session(ProvisionStartResponse("C", null, "u"), 0))
        assertNull(QrSetupLogic.session(ProvisionStartResponse("", "S", "u"), 0))
        assertNull(QrSetupLogic.session(ProvisionStartResponse("C", "S", " "), 0))
        assertNull(QrSetupLogic.session(null, 0))
    }

    // ------------------------------------------------------------------ claim → prefs mapping

    @Test fun claimNormalisesServerUrl() {
        fun cfg(url: String?) = (QrSetupLogic.claim(ProvisionStatusResponse("claimed", url, " 205 ", " K1 "), "https://ledtv.akdwk.in/") as StatusResult.Claimed).config
        assertEquals("https://ledtv.akdwk.in/api/", cfg("ledtv.akdwk.in").apiBase)
        assertEquals("https://ledtv.akdwk.in/", cfg("ledtv.akdwk.in").serverUrl)
        assertEquals("https://ledtv.akdwk.in/api/", cfg("https://ledtv.akdwk.in/api/").apiBase)
        assertEquals("https://ledtv.akdwk.in/", cfg("https://ledtv.akdwk.in/admin").serverUrl)
        assertEquals("http://192.168.1.10/hotelcast/api/", cfg("192.168.1.10/hotelcast/index.php").apiBase)
        assertEquals("205", cfg(null).room)
        assertEquals("K1", cfg(null).key)
    }

    @Test fun claimWithoutServerUrlUsesCurrentServer() {
        val c = (QrSetupLogic.claim(ProvisionStatusResponse("claimed", null, "101", "KEY"), "https://own.hotel.com/tv") as StatusResult.Claimed).config
        assertEquals("https://own.hotel.com/tv/api/", c.apiBase)
        assertEquals("https://own.hotel.com/tv/", c.serverUrl)
        assertNull(c.hotelName)
        val r = c.toProvisioningRequest()
        assertEquals(c.serverUrl, r.serverUrl)
        assertEquals(c.apiBase, r.apiBase)
        assertEquals("101", r.room)
        assertEquals("KEY", r.key)
        assertTrue(r.autoRegister)
        assertFalse(r.force)
        // The stored site root normalises back to the same API base.
        assertEquals(c.apiBase, ServerUrl.normalize(c.serverUrl))
    }

    @Test fun claimWithBadValuesIsInvalid() {
        assertTrue(QrSetupLogic.claim(ProvisionStatusResponse("claimed", null, "101", null), "https://x.com/") is StatusResult.Invalid)
        assertTrue(QrSetupLogic.claim(ProvisionStatusResponse("claimed", null, null, "K"), "https://x.com/") is StatusResult.Invalid)
        assertTrue(QrSetupLogic.claim(ProvisionStatusResponse("claimed", "ftp://x", "101", "K"), "https://x.com/") is StatusResult.Invalid)
        assertTrue(QrSetupLogic.claim(ProvisionStatusResponse("claimed", null, "101;DROP", "K"), "https://x.com/") is StatusResult.Invalid)
        assertTrue(QrSetupLogic.claim(ProvisionStatusResponse("claimed", null, "101", "has space"), "https://x.com/") is StatusResult.Invalid)
    }

    @Test fun serverRootHelper() {
        assertEquals("https://h.com/hotelcast/", ServerUrl.root("https://h.com/hotelcast/api/"))
        assertEquals("https://h.com/", ServerUrl.root("https://h.com/api/"))
        assertEquals("https://h.com/x/", ServerUrl.root("https://h.com/x/"))
    }

    // ------------------------------------------------------------------ default server / formatting

    @Test fun defaultServerSelection() {
        val def = "https://ledtv.akdwk.in/"
        assertEquals(def, QrSetupLogic.effectiveServer("", def))
        assertEquals(def, QrSetupLogic.effectiveServer(null, def))
        assertEquals(def, QrSetupLogic.effectiveServer("   ", def))
        assertEquals(def, QrSetupLogic.effectiveServer("ftp://bad", def))
        assertEquals("https://own.hotel.com/tv", QrSetupLogic.effectiveServer(" https://own.hotel.com/tv ", def))
        assertEquals("192.168.1.10/hotelcast", QrSetupLogic.effectiveServer("192.168.1.10/hotelcast", def))
    }

    @Test fun builtInDefaultServerIsValid() {
        assertTrue(BuildConfig.DEFAULT_SERVER_URL.isNotBlank())
        assertNotNull(ServerUrl.normalize(BuildConfig.DEFAULT_SERVER_URL))
    }

    @Test fun codeFormatting() {
        assertEquals("AB3 K9Z", QrSetupLogic.displayCode("ab3k9z"))
        assertEquals("AB3 K9Z", QrSetupLogic.displayCode(" AB3-K9Z "))
        assertEquals("ABCD", QrSetupLogic.displayCode("abcd").replace(" ", ""))
        assertEquals("ABC D", QrSetupLogic.displayCode("abcd"))
        assertEquals("", QrSetupLogic.displayCode(null))
    }

    @Test fun countdownAndBackoff() {
        assertEquals("10:00", QrSetupLogic.formatCountdown(600_000))
        assertEquals("0:59", QrSetupLogic.formatCountdown(58_001))
        assertEquals("0:00", QrSetupLogic.formatCountdown(-5))
        assertEquals(listOf(0, 2, 4, 8, 16, 32, 60, 60), (0..7).map { QrSetupLogic.backoffSec(it) })
    }

    // ------------------------------------------------------------------ state machine

    private class FakeBackend : ProvisionBackend {
        var starts = 0
        var statusCalls = 0
        val startAnswers = ArrayDeque<() -> ProvisionStartResponse?>()
        val statusAnswers = ArrayDeque<() -> ProvisionStatusResponse?>()
        var lastBase: String? = null
        var lastCode: String? = null

        override suspend fun start(apiBase: String, body: ProvisionStartRequest): ProvisionStartResponse? {
            starts++
            lastBase = apiBase
            val a = startAnswers.removeFirstOrNull()
            return if (a != null) a() else ProvisionStartResponse("CODE$starts", "sec$starts", "https://x/claim?c=$starts", 600, 3)
        }

        override suspend fun status(apiBase: String, code: String, secret: String): ProvisionStatusResponse? {
            statusCalls++
            lastCode = code
            val a = statusAnswers.removeFirstOrNull()
            return if (a != null) a() else ProvisionStatusResponse("pending")
        }
    }

    private fun TestScope.flow(backend: FakeBackend, registered: () -> Boolean = { false }) = QrSetupFlow(
        backend = backend,
        startBody = { ProvisionStartRequest("dev-1", "TV", "2.1.0") },
        clock = { testScheduler.currentTime },
        isRegistered = registered,
    )

    @Test fun pendingThenClaimedReturnsConfig() = runTest {
        val b = FakeBackend()
        b.statusAnswers.add { ProvisionStatusResponse("pending") }
        b.statusAnswers.add { ProvisionStatusResponse("claimed", "https://ledtv.akdwk.in", "101", "KEY") }
        val states = mutableListOf<QrSetupState>()
        val result = async { flow(b).run("https://ledtv.akdwk.in/") { states += it } }
        advanceTimeBy(10_000)
        runCurrent()
        val cfg = result.await()!!
        assertEquals("101", cfg.room)
        assertEquals("https://ledtv.akdwk.in/api/", b.lastBase)
        assertEquals("CODE1", b.lastCode)
        assertEquals(1, b.starts)
        assertTrue(states.first() is QrSetupState.Starting)
        assertTrue(states.any { it is QrSetupState.Waiting })
        assertTrue(states.last() is QrSetupState.Claimed)
    }

    @Test fun expiredOrUsedStatusStartsANewCode() = runTest {
        val b = FakeBackend()
        b.statusAnswers.add { ProvisionStatusResponse("expired") }
        b.statusAnswers.add { ProvisionStatusResponse("used") }
        val states = mutableListOf<QrSetupState>()
        val job = launch { flow(b).run("https://ledtv.akdwk.in/") { states += it } }
        advanceTimeBy(3_000 * 2 + 500)
        runCurrent()
        assertEquals(3, b.starts)
        val codes = states.filterIsInstance<QrSetupState.Waiting>().map { it.session.code }.distinct()
        assertEquals(listOf("CODE1", "CODE2", "CODE3"), codes)
        job.cancel()
    }

    @Test fun localExpiryRestartsEvenWithoutServerSayingSo() = runTest {
        val b = FakeBackend()
        b.startAnswers.add { ProvisionStartResponse("A1", "s", "u", 30, 10) }
        val job = launch { flow(b).run("https://ledtv.akdwk.in/") { } }
        advanceTimeBy(29_000)
        runCurrent()
        assertEquals(1, b.starts)
        assertEquals(2, b.statusCalls) // at 10 s and 20 s
        advanceTimeBy(1_500)
        runCurrent()
        assertEquals(2, b.starts) // expired at 30 s → new code
        job.cancel()
    }

    @Test fun status404StartsAgain() = runTest {
        val b = FakeBackend()
        b.statusAnswers.add { throw ApiException(404, "NOT_FOUND", "bad secret", fromServer = true) }
        val job = launch { flow(b).run("https://ledtv.akdwk.in/") { } }
        advanceTimeBy(3_500)
        runCurrent()
        assertEquals(2, b.starts)
        job.cancel()
    }

    @Test fun networkErrorsBackOffAndKeepTheCode() = runTest {
        val b = FakeBackend()
        repeat(2) { b.statusAnswers.add { throw IOException("no route") } }
        val states = mutableListOf<QrSetupState>()
        val job = launch { flow(b).run("https://ledtv.akdwk.in/") { states += it } }
        advanceTimeBy(3_100) // first status at 3 s → offline, retry in 2 s
        runCurrent()
        val off = states.last() as QrSetupState.Offline
        assertTrue(off.network)
        assertEquals("CODE1", off.session?.code)
        assertEquals(3_000 + 2_000L, off.retryAtMs)
        advanceTimeBy(5_000) // 5 s: retry → wait 3 s poll → second error at 8 s → backoff 4 s
        runCurrent()
        val off2 = states.last() as QrSetupState.Offline
        assertEquals(8_000 + 4_000L, off2.retryAtMs)
        advanceTimeBy(8_000) // 12 s retry + 3 s poll → pending at 15 s
        runCurrent()
        assertTrue(states.last() is QrSetupState.Waiting)
        assertEquals(1, b.starts)
        job.cancel()
    }

    @Test fun startOfflineThenRecovers() = runTest {
        val b = FakeBackend()
        b.startAnswers.add { throw IOException("Unable to resolve host") }
        val states = mutableListOf<QrSetupState>()
        val job = launch { flow(b).run("https://ledtv.akdwk.in/") { states += it } }
        runCurrent()
        val off = states.last() as QrSetupState.Offline
        assertNull(off.session)
        assertTrue(off.network)
        advanceTimeBy(2_100)
        runCurrent()
        assertTrue(states.last() is QrSetupState.Waiting)
        assertEquals(2, b.starts)
        job.cancel()
    }

    @Test fun rateLimitedHonoursRetryAfter() = runTest {
        val b = FakeBackend()
        b.startAnswers.add { throw ApiException(429, "RATE_LIMITED", "slow down", retryAfterSec = 20, fromServer = true) }
        val states = mutableListOf<QrSetupState>()
        val job = launch { flow(b).run("https://ledtv.akdwk.in/") { states += it } }
        runCurrent()
        assertEquals(20_000L, (states.last() as QrSetupState.RateLimited).retryAtMs)
        advanceTimeBy(19_000)
        runCurrent()
        assertEquals(1, b.starts)
        advanceTimeBy(1_100)
        runCurrent()
        assertEquals(2, b.starts)
        job.cancel()
    }

    @Test fun serverWithoutProvisionEndpointsIsUnsupported() = runTest {
        val b = FakeBackend()
        b.startAnswers.add { throw ApiException(404, null, "HTTP 404", fromServer = false) }
        val states = mutableListOf<QrSetupState>()
        val job = launch { flow(b).run("https://old.hotel.com/") { states += it } }
        runCurrent()
        assertTrue(states.last() is QrSetupState.Unsupported)
        job.cancel()
    }

    @Test fun resumeKeepsAValidSession() = runTest {
        val b = FakeBackend()
        val keep = QrSession("KEEP01", "s", "u", expiresAtMs = 100_000, pollIntervalSec = 3)
        val states = mutableListOf<QrSetupState>()
        val job = launch { flow(b).run("https://ledtv.akdwk.in/", keep) { states += it } }
        advanceTimeBy(3_500)
        runCurrent()
        assertEquals(0, b.starts)
        assertEquals("KEEP01", b.lastCode)
        assertEquals("KEEP01", (states.first() as QrSetupState.Waiting).session.code)
        job.cancel()
    }

    @Test fun registeredElsewhereStops() = runTest {
        val b = FakeBackend()
        val states = mutableListOf<QrSetupState>()
        val r = flow(b, registered = { true }).run("https://ledtv.akdwk.in/") { states += it }
        assertNull(r)
        assertTrue(states.last() is QrSetupState.AlreadyRegistered)
        assertEquals(0, b.starts)
    }

    @Test fun invalidServerIsReported() = runTest {
        val b = FakeBackend()
        val states = mutableListOf<QrSetupState>()
        assertNull(flow(b).run("ftp://nope") { states += it })
        assertTrue(states.single() is QrSetupState.InvalidServer)
    }

    @Test fun visibleSessionOnlyWhileUsable() {
        val s = QrSession("C", "S", "u", 1, 3)
        assertEquals(s, QrSetupState.Waiting("x", s).visibleSession)
        assertEquals(s, QrSetupState.Offline("x", s, "e", 0, true).visibleSession)
        assertNull(QrSetupState.Starting("x").visibleSession)
        assertNull(QrSetupState.Unsupported("x", "e", 0).visibleSession)
    }
}
