package com.hotelcast.tv

import android.util.Log
import com.google.gson.Gson
import com.google.gson.GsonBuilder
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Error returned by the server (non-2xx or `ok:false`). */
class ApiException(
    val httpStatus: Int,
    val code: String?,
    override val message: String,
    val retryAfterSec: Int? = null,
    /** true when the body was a genuine HotelCast JSON envelope (not a proxy / captive portal page). */
    val fromServer: Boolean = false,
) : Exception(message) {
    val isInvalidToken: Boolean get() = httpStatus == 401 && fromServer
}

/** Server URL normalisation: "https://hotel.com/hotelcast" → "https://hotel.com/hotelcast/api/". */
object ServerUrl {
    fun normalize(input: String?): String? {
        var s = input?.trim().orEmpty()
        if (s.isEmpty()) return null
        s = s.replace('\\', '/')
        if (!s.contains("://")) {
            val host = s.substringBefore('/').substringBefore(':')
            val isLocal = host == "localhost" || host.matches(Regex("^\\d{1,3}(\\.\\d{1,3}){3}$")) || !host.contains('.')
            s = (if (isLocal) "http://" else "https://") + s
        }
        val parsed: HttpUrl = s.toHttpUrlOrNull() ?: return null
        if (parsed.scheme != "http" && parsed.scheme != "https") return null
        val segments = parsed.pathSegments.filter { it.isNotEmpty() }.toMutableList()
        // Strip things users commonly paste: .../index.php, .../api, .../api/index.php, .../admin
        if (segments.lastOrNull()?.equals("index.php", ignoreCase = true) == true) segments.removeAt(segments.lastIndex)
        if (segments.lastOrNull()?.equals("api", ignoreCase = true) == true) segments.removeAt(segments.lastIndex)
        if (segments.lastOrNull()?.equals("admin", ignoreCase = true) == true) segments.removeAt(segments.lastIndex)
        val builder = parsed.newBuilder().query(null).fragment(null).encodedPath("/")
        segments.forEach { builder.addPathSegment(it) }
        builder.addPathSegment("api").addPathSegment("")
        return builder.build().toString()
    }

    /** Site root for display / storage: "https://hotel.com/hotelcast/api/" → "https://hotel.com/hotelcast/". */
    fun root(apiBase: String): String = if (apiBase.endsWith("/api/")) apiBase.removeSuffix("api/") else apiBase
}

object ApiClient {
    private const val TAG = "ApiClient"

    val gson: Gson = GsonBuilder().disableHtmlEscaping().create()

    private val authInterceptor = Interceptor { chain ->
        val req = chain.request()
        val token = if (Prefs.isInitialized) Prefs.token else null
        val apiHost = if (Prefs.isInitialized) Prefs.apiBase.toHttpUrlOrNull()?.host else null
        // Only send credentials to our own server (never to a CDN hosting media).
        val builder = req.newBuilder()
        if (req.header("Accept") == null) builder.header("Accept", "application/json")
        builder.header("User-Agent", "HotelCastTV/${BuildConfig.VERSION_NAME} (Android ${android.os.Build.VERSION.RELEASE})")
        val path = req.url.encodedPath
        val isPublic = path.endsWith("/device/register") || path.endsWith("/health") || path.contains("/provision/")
        if (!isPublic && Prefs.isInitialized && apiHost != null && req.url.host.equals(apiHost, ignoreCase = true)) {
            builder.header("X-Device-Id", Prefs.deviceId)
            if (!token.isNullOrBlank() && req.header("Authorization") == null) {
                builder.header("Authorization", "Bearer $token")
            }
        }
        chain.proceed(builder.build())
    }

    /** Shared HTTP client (API + media + APK downloads). */
    val http: OkHttpClient by lazy {
        val logging = HttpLoggingInterceptor { Log.d("HTTP", it) }.apply {
            level = if (BuildConfig.DEBUG) HttpLoggingInterceptor.Level.BASIC else HttpLoggingInterceptor.Level.NONE
        }
        OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(40, TimeUnit.SECONDS) // > max long-poll wait (25 s)
            .writeTimeout(30, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .followRedirects(true)
            .followSslRedirects(true)
            .addInterceptor(authInterceptor)
            .addInterceptor(logging)
            .build()
    }

    /** Long-timeout client for big downloads (videos, APKs). */
    val downloadHttp: OkHttpClient by lazy {
        http.newBuilder().readTimeout(120, TimeUnit.SECONDS).build()
    }

    @Volatile private var cachedBase: String? = null
    @Volatile private var cachedService: ApiService? = null

    fun serviceFor(baseUrl: String): ApiService =
        Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(http)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(ApiService::class.java)

    /** Service for the configured server, or null when not configured. */
    @Synchronized
    fun service(): ApiService? {
        val base = Prefs.apiBase
        if (base.isBlank()) return null
        if (base != cachedBase || cachedService == null) {
            cachedService = try {
                serviceFor(base)
            } catch (e: Exception) {
                Log.e(TAG, "Bad base URL $base", e)
                null
            }
            cachedBase = base
        }
        return cachedService
    }

    /**
     * Executes an API call and unwraps the envelope. Throws [ApiException] for server errors and
     * [IOException] for network failures.
     */
    suspend fun <T> call(block: suspend () -> Response<ApiEnvelope<T>>): T? {
        val resp = block()
        val body = resp.body()
        if (resp.isSuccessful && body != null && body.ok) return body.data
        var code: String? = body?.error?.code
        var message: String? = body?.error?.message
        var fromServer = body != null
        if (body == null) {
            val raw = try { resp.errorBody()?.string() } catch (e: IOException) { null }
            if (!raw.isNullOrBlank()) {
                try {
                    val env = gson.fromJson(raw, ApiEnvelope::class.java)
                    if (env != null && env.error != null) {
                        code = env.error.code
                        message = env.error.message
                        fromServer = true
                    }
                } catch (_: Exception) {
                    // not JSON (proxy page, HTML 404...)
                }
            }
        }
        val retryAfter = resp.headers()["Retry-After"]?.trim()?.toIntOrNull()
        throw ApiException(
            httpStatus = resp.code(),
            code = code,
            message = message ?: "HTTP ${resp.code()}",
            retryAfterSec = retryAfter,
            fromServer = fromServer,
        )
    }

    fun describe(e: Throwable): String = when (e) {
        is ApiException -> listOfNotNull(e.code, e.message).joinToString(": ")
        is java.net.UnknownHostException -> "Unknown host (${e.message})"
        is java.net.ConnectException -> "Cannot connect (${e.message})"
        is java.net.SocketTimeoutException -> "Timeout"
        is javax.net.ssl.SSLException -> "SSL error (${e.message})"
        else -> e.message ?: e.javaClass.simpleName
    }
}
