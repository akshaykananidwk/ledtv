package com.hotelcast.tv

import android.content.Context
import android.util.Base64
import android.util.Log
import okhttp3.OkHttpClient
import java.io.ByteArrayInputStream
import java.security.KeyStore
import java.security.cert.CertPathValidator
import java.security.cert.CertificateException
import java.security.cert.CertificateExpiredException
import java.security.cert.CertificateFactory
import java.security.cert.CertificateNotYetValidException
import java.security.cert.PKIXParameters
import java.security.cert.TrustAnchor
import java.security.cert.X509Certificate
import java.util.Date
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManagerFactory
import javax.net.ssl.X509TrustManager

/**
 * HTTPS on old / cheap Android TVs (2.1.1). Two common reasons a TV "is offline" although Wi-Fi works:
 *
 *  1. **Old root certificates.** Android 7.0 and older do not trust ISRG Root X1 (Let's Encrypt), and
 *     some TV firmware ships an outdated CA list. The app therefore also trusts a small bundle of
 *     current public roots (res/raw/hc_extra_roots.pem) — only *in addition* to the system list.
 *  2. **Wrong TV date** (e.g. 2017 after a power cut, no NTP). Every certificate then looks
 *     "not yet valid". When the network time is known ([NetworkTime]) and differs from the TV
 *     clock, the chain is validated again *at the network time* (same roots, same hostname check).
 *
 * Hostname verification stays OkHttp's / HttpsURLConnection's default.
 */
object TlsCompat {
    private const val TAG = "TlsCompat"

    @Volatile private var trustManager: X509TrustManager? = null
    @Volatile private var sslContext: SSLContext? = null

    /** Call once from Application.onCreate (before the first HTTPS request). */
    @Synchronized
    fun init(context: Context) {
        if (trustManager != null) return
        try {
            val system = defaultTrustManager(null)
            val extraStore = extraRootsKeyStore(context)
            val extra = extraStore?.let { defaultTrustManager(it) }
            val anchors = HashSet<TrustAnchor>()
            systemAnchors().forEach { anchors.add(TrustAnchor(it, null)) }
            extraStore?.let { ks ->
                ks.aliases().toList().forEach { a -> (ks.getCertificate(a) as? X509Certificate)?.let { anchors.add(TrustAnchor(it, null)) } }
            }
            val tm = CompatTrustManager(system, extra, anchors) { NetworkTime.offsetMs }
            val ctx = SSLContext.getInstance("TLS")
            ctx.init(null, arrayOf(tm), null)
            trustManager = tm
            sslContext = ctx
            // ExoPlayer / Glide / AppUpdater use HttpsURLConnection.
            HttpsURLConnection.setDefaultSSLSocketFactory(ctx.socketFactory)
            Log.i(TAG, "TLS compat ready: ${anchors.size} trust anchors")
        } catch (e: Exception) {
            Log.e(TAG, "TLS compat init failed, using system defaults", e)
        }
    }

    /** Applies the compat trust manager to an OkHttp builder (no-op when [init] failed). */
    fun apply(builder: OkHttpClient.Builder): OkHttpClient.Builder {
        val tm = trustManager
        val ctx = sslContext
        if (tm != null && ctx != null) builder.sslSocketFactory(ctx.socketFactory, tm)
        return builder
    }

    private fun defaultTrustManager(ks: KeyStore?): X509TrustManager {
        val tmf = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm())
        tmf.init(ks)
        return tmf.trustManagers.filterIsInstance<X509TrustManager>().first()
    }

    private fun systemAnchors(): List<X509Certificate> = try {
        val ks = KeyStore.getInstance("AndroidCAStore")
        ks.load(null)
        ks.aliases().toList().mapNotNull { ks.getCertificate(it) as? X509Certificate }
    } catch (e: Exception) {
        emptyList()
    }

    private fun extraRootsKeyStore(context: Context): KeyStore? = try {
        val pem = context.resources.openRawResource(R.raw.hc_extra_roots).bufferedReader().use { it.readText() }
        val certs = parsePem(pem) { Base64.decode(it, Base64.DEFAULT) }
        val ks = KeyStore.getInstance(KeyStore.getDefaultType())
        ks.load(null, null)
        certs.forEachIndexed { i, c -> ks.setCertificateEntry("hc_root_$i", c) }
        if (certs.isEmpty()) null else ks
    } catch (e: Exception) {
        Log.w(TAG, "Bundled roots not loaded: ${e.message}")
        null
    }

    /** All CERTIFICATE blocks of a PEM text (comment lines are ignored). */
    fun parsePem(pem: String, b64: (String) -> ByteArray): List<X509Certificate> {
        val cf = CertificateFactory.getInstance("X.509")
        val re = Regex("-----BEGIN CERTIFICATE-----([A-Za-z0-9+/=\\s]+?)-----END CERTIFICATE-----")
        return re.findAll(pem).mapNotNull { m ->
            try {
                cf.generateCertificate(ByteArrayInputStream(b64(m.groupValues[1].replace(Regex("\\s"), "")))) as? X509Certificate
            } catch (e: Exception) {
                null
            }
        }.toList()
    }

    /** True when the failure is only about the certificate dates (typical for a wrong TV clock). */
    fun isDateProblem(e: Throwable?): Boolean {
        var t: Throwable? = e
        var depth = 0
        while (t != null && depth < 10) {
            if (t is CertificateExpiredException || t is CertificateNotYetValidException) return true
            val m = t.message.orEmpty().lowercase()
            if ("not yet valid" in m || "expired" in m || "validity" in m) return true
            t = t.cause
            depth++
        }
        return false
    }
}

/**
 * System roots first, then the bundled roots, then (only for date errors and a known clock offset)
 * PKIX validation at the network time.
 */
class CompatTrustManager(
    private val system: X509TrustManager,
    private val extra: X509TrustManager?,
    private val anchors: Set<TrustAnchor>,
    private val clockOffsetMs: () -> Long?,
) : X509TrustManager {

    override fun checkClientTrusted(chain: Array<out X509Certificate>?, authType: String?) =
        system.checkClientTrusted(chain, authType)

    override fun checkServerTrusted(chain: Array<out X509Certificate>?, authType: String?) {
        // Broken TV firmware may throw RuntimeExceptions here too: treat them as "not trusted".
        val first: CertificateException = try {
            system.checkServerTrusted(chain, authType)
            return
        } catch (e: CertificateException) {
            e
        } catch (e: RuntimeException) {
            CertificateException(e.message, e)
        }
        var last: CertificateException = first
        if (extra != null) {
            try {
                extra.checkServerTrusted(chain, authType)
                return
            } catch (e: CertificateException) {
                last = e
            } catch (e: RuntimeException) {
                last = CertificateException(e.message, e)
            }
        }
        val offset = clockOffsetMs()
        if (chain != null && chain.isNotEmpty() && offset != null && kotlin.math.abs(offset) > MIN_OFFSET_MS &&
            (TlsCompat.isDateProblem(first) || TlsCompat.isDateProblem(last))
        ) {
            if (validateAt(chain, Date(System.currentTimeMillis() + offset))) return
        }
        throw first
    }

    override fun getAcceptedIssuers(): Array<X509Certificate> =
        (system.acceptedIssuers.toList() + (extra?.acceptedIssuers?.toList() ?: emptyList())).toTypedArray()

    private fun validateAt(chain: Array<out X509Certificate>, at: Date): Boolean = try {
        if (anchors.isEmpty()) {
            false
        } else {
            // Leaf must be valid at the network time; the path is checked by PKIX with that date.
            chain[0].checkValidity(at)
            val certs = chain.toMutableList()
            val root = certs.last()
            if (certs.size > 1 && root.subjectX500Principal == root.issuerX500Principal) certs.removeAt(certs.lastIndex)
            val path = CertificateFactory.getInstance("X.509").generateCertPath(certs)
            val params = PKIXParameters(anchors).apply {
                isRevocationEnabled = false
                date = at
            }
            CertPathValidator.getInstance("PKIX").validate(path, params)
            Log.w("TlsCompat", "TV clock is wrong; certificate accepted at network time $at")
            true
        }
    } catch (e: Exception) {
        Log.w("TlsCompat", "Validation at network time failed: ${e.message}")
        false
    }

    private companion object {
        const val MIN_OFFSET_MS = 10 * 60_000L
    }
}
