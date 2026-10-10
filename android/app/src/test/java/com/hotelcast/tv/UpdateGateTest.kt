package com.hotelcast.tv

import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 2.6.0 forced update on app start: the pure decision logic (UpdateGate) and the server JSON contract. */
class UpdateGateTest {

    private fun info(code: Int, required: Int = code, req: Boolean = true, url: String? = "https://x/api/device/apk/7") =
        AppUpdateInfo(versionCode = code, versionName = "v$code", sha256 = "ab".repeat(32), size = 1000, url = url, required = req, requiredVersionCode = if (req) required else 0)

    @Test
    fun blocksOnlyWhenARequiredNewerVersionExists() {
        assertTrue(UpdateGate.mustUpdate(13, info(14)))
        assertFalse("same version", UpdateGate.mustUpdate(14, info(14)))
        assertFalse("lower version", UpdateGate.mustUpdate(15, info(14)))
        assertFalse("optional update", UpdateGate.mustUpdate(13, info(14, req = false)))
        assertFalse("no url", UpdateGate.mustUpdate(13, info(14, url = "")))
        assertFalse("nothing offered", UpdateGate.mustUpdate(13, null))
        // Offered 16 (optional), required 14: a TV on 13 must update (installs 16), one on 14 may play.
        assertTrue(UpdateGate.mustUpdate(13, info(16, required = 14)))
        assertFalse(UpdateGate.mustUpdate(14, info(16, required = 14)))
    }

    @Test
    fun serverAnswerWinsOverCache() {
        val d = UpdateGate.decide(13, serverReached = true, server = info(14), cached = null)
        assertTrue(d.block)
        assertEquals(UpdateGate.Source.SERVER, d.source)
        assertEquals(14, d.info?.versionCode)
        // The server withdrew the release (update null): start normally even if the cache said "required".
        val w = UpdateGate.decide(13, serverReached = true, server = null, cached = info(14))
        assertFalse(w.block)
        assertNull(UpdateGate.cacheAfter(true, null, info(14)))
        // Server lists a version that is not newer: never block.
        assertFalse(UpdateGate.decide(14, true, info(14), info(15)).block)
    }

    @Test
    fun offlineUsesTheCacheAndStartsNormallyWithoutIt() {
        val c = UpdateGate.decide(13, serverReached = false, server = null, cached = info(14))
        assertTrue(c.block)
        assertEquals(UpdateGate.Source.CACHE, c.source)
        assertEquals(info(14), UpdateGate.cacheAfter(false, null, info(14)))
        val none = UpdateGate.decide(13, serverReached = false, server = null, cached = null)
        assertFalse("offline without any info: keep showing cached content", none.block)
        assertEquals(UpdateGate.Source.NONE, none.source)
        // Updated meanwhile (cache still names the version now installed): no block, no loop.
        assertFalse(UpdateGate.decide(14, false, null, info(14)).block)
    }

    @Test
    fun retryPolicyStopsAutoInstallAfterThreeFailures() {
        assertTrue(UpdateGate.shouldAutoRetry(UpdateGate.Failure.DOWNLOAD, 99))
        assertTrue(UpdateGate.shouldAutoRetry(UpdateGate.Failure.CHECKSUM, 5))
        assertTrue(UpdateGate.shouldAutoRetry(UpdateGate.Failure.INSTALL_FAILED, 2))
        assertFalse(UpdateGate.shouldAutoRetry(UpdateGate.Failure.INSTALL_FAILED, 3))
        assertFalse(UpdateGate.shouldAutoRetry(UpdateGate.Failure.CANCELLED, 3))
        assertFalse("signature mismatch needs a new upload", UpdateGate.shouldAutoRetry(UpdateGate.Failure.SIGNATURE, 0))
        assertFalse(UpdateGate.shouldAutoRetry(UpdateGate.Failure.WRONG_APK, 0))
        assertTrue(UpdateGate.countsAsInstallAttempt(UpdateGate.Failure.SIGNATURE))
        assertFalse(UpdateGate.countsAsInstallAttempt(UpdateGate.Failure.DOWNLOAD))
        assertEquals(30_000L, UpdateGate.RETRY_DELAY_MS)
    }

    @Test
    fun installResultsAreClassified() {
        assertNull(UpdateGate.classifyInstall(UpdateGate.STATUS_SUCCESS, null))
        assertNull(UpdateGate.classifyInstall(UpdateGate.STATUS_PENDING_USER_ACTION, null))
        assertEquals(UpdateGate.Failure.SIGNATURE, UpdateGate.classifyInstall(UpdateGate.STATUS_FAILURE, "INSTALL_FAILED_UPDATE_INCOMPATIBLE: Package com.hotelcast.tv signatures do not match"))
        assertEquals(UpdateGate.Failure.SIGNATURE, UpdateGate.classifyInstall(UpdateGate.STATUS_FAILURE_CONFLICT, null))
        assertEquals(UpdateGate.Failure.CANCELLED, UpdateGate.classifyInstall(UpdateGate.STATUS_FAILURE_ABORTED, "User rejected"))
        assertEquals(UpdateGate.Failure.INSTALL_BLOCKED, UpdateGate.classifyInstall(UpdateGate.STATUS_FAILURE_BLOCKED, null))
        assertEquals(UpdateGate.Failure.NO_SPACE, UpdateGate.classifyInstall(UpdateGate.STATUS_FAILURE_STORAGE, null))
        assertEquals(UpdateGate.Failure.WRONG_APK, UpdateGate.classifyInstall(UpdateGate.STATUS_FAILURE_INVALID, null))
        assertEquals(UpdateGate.Failure.INSTALL_FAILED, UpdateGate.classifyInstall(UpdateGate.STATUS_FAILURE, "boom"))
    }

    @Test
    fun spaceAndProgress() {
        val mb = 1024L * 1024
        assertTrue(UpdateGate.hasSpace(100 * mb, 10 * mb))
        assertFalse(UpdateGate.hasSpace(25 * mb, 10 * mb))
        assertTrue("a resumed download needs less", UpdateGate.hasSpace(31 * mb, 10 * mb, alreadyDownloaded = 10 * mb))
        assertTrue("unknown size", UpdateGate.hasSpace(0, 0))
        assertEquals(50, UpdateGate.percent(5, 10))
        assertEquals(100, UpdateGate.percent(12, 10))
        assertEquals(-1, UpdateGate.percent(5, 0))
    }

    @Test
    fun parsesTheServerContract() {
        // GET api/device/app-version (server 2.7, core/AppReleases::forDevice) and the poll field app_update.
        val json = """{"installed_version_code":13,"update":{"version_code":14,"version_name":"2.6.0","sha256":"${"c".repeat(64)}",
            "size":7340032,"url":"https://tv.example/api/device/apk/5","required":true,"required_version_code":14,"notes":"","source":"platform"}}"""
        val r = Gson().fromJson(json, AppVersionResponse::class.java)
        assertEquals(13, r.installedVersionCode)
        val u = r.update!!
        assertEquals(14, u.versionCode)
        assertEquals("2.6.0", u.label)
        assertTrue(u.required)
        assertEquals(14, u.requiredVersionCode)
        assertEquals(7340032L, u.size)
        assertTrue(UpdateGate.mustUpdate(13, u))
        val poll = Gson().fromJson("""{"content_hash":"x","commands":[],"app_update":null}""", PollResponse::class.java)
        assertNull(poll.appUpdate)
        val none = Gson().fromJson("""{"installed_version_code":14,"update":null}""", AppVersionResponse::class.java)
        assertNull(none.update)
    }
}
