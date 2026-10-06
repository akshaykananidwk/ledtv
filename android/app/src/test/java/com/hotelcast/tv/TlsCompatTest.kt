package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.security.cert.CertificateException
import java.security.cert.CertificateExpiredException
import java.security.cert.CertificateNotYetValidException
import java.util.Base64

class TlsCompatTest {

    private fun bundledPem(): String {
        val f = listOf("src/main/res/raw/hc_extra_roots.pem", "app/src/main/res/raw/hc_extra_roots.pem").map(::File).first { it.exists() }
        return f.readText()
    }

    @Test
    fun bundledRootsParseAndIncludeLetsEncrypt() {
        val certs = TlsCompat.parsePem(bundledPem()) { Base64.getDecoder().decode(it) }
        assertEquals(15, certs.size)
        val names = certs.map { it.subjectX500Principal.name }
        assertTrue(names.any { "ISRG Root X1" in it })
        assertTrue(names.any { "ISRG Root X2" in it })
        assertTrue(names.any { "USERTrust RSA" in it })
        // Every bundled root is a CA and valid for years.
        val in2028 = java.util.Date(1_830_297_600_000L)
        certs.forEach { c ->
            assertTrue(c.basicConstraints >= 0)
            c.checkValidity(in2028)
        }
    }

    @Test
    fun pemCommentsAndGarbageAreIgnored() {
        val one = bundledPem().substringAfter("# ISRG_Root_X1\n").substringBefore("# ISRG_Root_X2")
        val certs = TlsCompat.parsePem("junk\n# comment\n$one\n-----BEGIN CERTIFICATE-----\n!!!\n-----END CERTIFICATE-----") {
            Base64.getDecoder().decode(it)
        }
        assertEquals(1, certs.size)
    }

    @Test
    fun dateProblemsAreRecognised() {
        assertTrue(TlsCompat.isDateProblem(CertificateException(CertificateNotYetValidException("x"))))
        assertTrue(TlsCompat.isDateProblem(CertificateExpiredException("x")))
        assertTrue(TlsCompat.isDateProblem(CertificateException("Certificate not yet valid: 2017")))
        assertFalse(TlsCompat.isDateProblem(CertificateException("Trust anchor for certification path not found.")))
        assertFalse(TlsCompat.isDateProblem(null))
    }

    @Test
    fun httpVersionOfServer() {
        assertEquals("http://ledtv.akdwk.in/", NetDiagnostics.httpVersion("https://ledtv.akdwk.in/"))
        assertEquals("http://h.com/hotelcast/", NetDiagnostics.httpVersion("h.com/hotelcast"))
        assertEquals("http://h.com:8443/x/", NetDiagnostics.httpVersion("https://h.com:8443/x/api/"))
        assertNull(NetDiagnostics.httpVersion("http://192.168.1.10/hotelcast/"))
        assertNull(NetDiagnostics.httpVersion(""))
    }

    @Test
    fun claimKeepsHttpChosenOnTheTv() {
        // TV uses http:// (old TV without working HTTPS); server reports its https:// address.
        assertEquals("http://ledtv.akdwk.in/", QrSetupLogic.keepHttp("https://ledtv.akdwk.in/", "http://ledtv.akdwk.in/"))
        // Normal case: https stays https.
        assertEquals("https://ledtv.akdwk.in/", QrSetupLogic.keepHttp("https://ledtv.akdwk.in/", "https://ledtv.akdwk.in/"))
        // Another host is never rewritten.
        assertEquals("https://other.com/", QrSetupLogic.keepHttp("https://other.com/", "http://ledtv.akdwk.in/"))

        val r = QrSetupLogic.interpret(
            ProvisionStatusResponse(status = "claimed", serverUrl = "https://ledtv.akdwk.in/", roomNumber = "101", registrationKey = "KEY123456", hotelName = "H"),
            "http://ledtv.akdwk.in/",
        )
        assertTrue(r is StatusResult.Claimed)
        assertEquals("http://ledtv.akdwk.in/api/", (r as StatusResult.Claimed).config.apiBase)
    }
}

/** Wrong TV clock: a chain that is only valid "in the future" is accepted when the network time says so. */
class CompatTrustManagerTest {
    private fun pem(name: String): java.security.cert.X509Certificate {
        val text = javaClass.classLoader!!.getResource("tls/$name")!!.readText()
        return TlsCompat.parsePem(text) { Base64.getDecoder().decode(it) }.single()
    }

    private fun tm(ks: java.security.KeyStore?): javax.net.ssl.X509TrustManager {
        val f = javax.net.ssl.TrustManagerFactory.getInstance(javax.net.ssl.TrustManagerFactory.getDefaultAlgorithm())
        f.init(ks)
        return f.trustManagers.filterIsInstance<javax.net.ssl.X509TrustManager>().first()
    }

    private fun storeWith(vararg certs: java.security.cert.X509Certificate): java.security.KeyStore =
        java.security.KeyStore.getInstance(java.security.KeyStore.getDefaultType()).apply {
            load(null, null)
            certs.forEachIndexed { i, c -> setCertificateEntry("c$i", c) }
        }

    private val ca by lazy { pem("ca.pem") }
    private val leaf by lazy { pem("leaf.pem") } // valid 2030-2039, issued by ca

    /** Any unrelated root (the first bundled one). */
    private val otherRoot by lazy {
        val f = listOf("src/main/res/raw/hc_extra_roots.pem", "app/src/main/res/raw/hc_extra_roots.pem").map(::File).first { it.exists() }
        TlsCompat.parsePem(f.readText()) { Base64.getDecoder().decode(it) }.first()
    }

    private fun manager(offset: Long?) = CompatTrustManager(
        system = tm(storeWith(otherRoot)), // "old TV": does not know the CA
        extra = tm(storeWith(ca)), // bundled roots
        anchors = setOf(java.security.cert.TrustAnchor(ca, null)),
        clockOffsetMs = { offset },
    )

    private val to2031 get() = 1_924_992_000_000L - System.currentTimeMillis() // 2031-01-01

    @Test
    fun acceptedAtNetworkTime() {
        manager(to2031).checkServerTrusted(arrayOf(leaf, ca), "RSA")
        manager(to2031).checkServerTrusted(arrayOf(leaf), "RSA")
    }

    @Test(expected = java.security.cert.CertificateException::class)
    fun rejectedWithoutNetworkTime() {
        manager(null).checkServerTrusted(arrayOf(leaf), "RSA")
    }

    @Test(expected = java.security.cert.CertificateException::class)
    fun unknownRootStillRejectedAtNetworkTime() {
        CompatTrustManager(tm(storeWith(otherRoot)), null, setOf(java.security.cert.TrustAnchor(otherRoot, null))) { to2031 }.checkServerTrusted(arrayOf(leaf, ca), "RSA")
    }

    @Test(expected = java.security.cert.CertificateException::class)
    fun networkTimeOutsideValidityRejected() {
        // 2045: leaf expired in 2039.
        manager(2_366_841_600_000L - System.currentTimeMillis()).checkServerTrusted(arrayOf(leaf), "RSA")
    }
}
