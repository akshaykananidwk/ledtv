package com.hotelcast.tv

import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.ensureActive
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import java.util.Locale

/**
 * QR setup (2.1): the TV asks the server for a short code (`POST provision/start`), shows it as a QR
 * code + 6 characters, and polls `GET provision/status` until a staff member has picked the room in
 * the admin panel. Everything in this file is plain Kotlin (no Android types) so it runs in JVM
 * unit tests; [QrSetupActivity] / [QrSetupViewModel] only draw it.
 */

/** One code issued by provision/start. Times are milliseconds of the injected clock. */
data class QrSession(
    val code: String,
    val secret: String,
    val claimUrl: String,
    val expiresAtMs: Long,
    val pollIntervalSec: Int,
)

/** Values the admin panel assigned to this TV, already validated and normalised. */
data class ClaimedConfig(
    /** Normalised site root, e.g. "https://ledtv.akdwk.in/" (stored as Prefs.serverUrl). */
    val serverUrl: String,
    /** Normalised API base, e.g. "https://ledtv.akdwk.in/api/" (stored as Prefs.apiBase). */
    val apiBase: String,
    val room: String,
    val key: String,
    val hotelName: String?,
) {
    /** Same shape as the bulk-tool extras, so the existing provisioning/registration code is reused. */
    fun toProvisioningRequest(): Provisioning.Request =
        Provisioning.Request(serverUrl, apiBase, room, key, autoRegister = true, force = false)
}

/** Result of interpreting one provision/status answer. */
sealed class StatusResult {
    object Pending : StatusResult()
    object Expired : StatusResult()
    object Used : StatusResult()
    data class Claimed(val config: ClaimedConfig) : StatusResult()
    /** Claimed, but the values are unusable (e.g. no registration key) — start a new code. */
    data class Invalid(val reason: String) : StatusResult()
}

/** Everything the QR screen can show. [server] is the server the flow is talking to. */
sealed class QrSetupState {
    abstract val server: String

    /** Asking the server for a code. */
    data class Starting(override val server: String) : QrSetupState()

    /** QR + code on screen, waiting for a staff member. */
    data class Waiting(override val server: String, val session: QrSession) : QrSetupState()

    /**
     * Cannot reach the server (network = true: no internet / DNS / timeout) or the server answered with
     * an error (network = false). [session] stays on screen while it is still valid.
     */
    data class Offline(
        override val server: String,
        val session: QrSession?,
        val error: String,
        val retryAtMs: Long,
        val network: Boolean,
    ) : QrSetupState()

    /** 429 RATE_LIMITED: wait for Retry-After. */
    data class RateLimited(override val server: String, val session: QrSession?, val retryAtMs: Long) : QrSetupState()

    /** The server has no provision/ endpoints (old version / wrong address). Retried slowly. */
    data class Unsupported(override val server: String, val error: String, val retryAtMs: Long) : QrSetupState()

    /** The configured server URL cannot be parsed. */
    data class InvalidServer(override val server: String) : QrSetupState()

    /** Room assigned in the admin panel; registering now. */
    data class Claimed(override val server: String, val config: ClaimedConfig) : QrSetupState()

    data class Registered(override val server: String, val room: String) : QrSetupState()

    data class RegisterFailed(
        override val server: String,
        val config: ClaimedConfig,
        val errorCode: String?,
        val message: String,
    ) : QrSetupState()

    /** Another path (bulk tool extras / ProvisionReceiver / manual form) registered the TV meanwhile. */
    data class AlreadyRegistered(override val server: String) : QrSetupState()

    /** The QR (session) to draw, if any. */
    val visibleSession: QrSession?
        get() = when (this) {
            is Waiting -> session
            is Offline -> session
            is RateLimited -> session
            else -> null
        }
}

object QrSetupLogic {
    const val DEFAULT_POLL_SEC = 3
    const val DEFAULT_EXPIRES_SEC = 600
    const val UNSUPPORTED_RETRY_SEC = 60
    const val DEFAULT_RETRY_AFTER_SEC = 30

    /** Server to use: the configured one when it is valid, otherwise the built-in default. */
    fun effectiveServer(configured: String?, builtInDefault: String): String {
        val c = configured?.trim().orEmpty()
        return if (c.isNotEmpty() && ServerUrl.normalize(c) != null) c else builtInDefault.trim()
    }

    /** "ab3k9z" → "AB3 K9Z" (groups of 3 for reading across a room). */
    fun displayCode(code: String?): String {
        val clean = code.orEmpty().filter { it.isLetterOrDigit() }.uppercase(Locale.ROOT)
        return clean.chunked(3).joinToString(" ")
    }

    /** Remaining time as m:ss (never negative). */
    fun formatCountdown(remainingMs: Long): String {
        val total = ((remainingMs.coerceAtLeast(0) + 999) / 1000)
        return String.format(Locale.ROOT, "%d:%02d", total / 60, total % 60)
    }

    /** Exponential backoff for network errors: 2, 4, 8, 16, 32, 60, 60 … seconds. */
    fun backoffSec(failures: Int): Int {
        if (failures <= 0) return 0
        val exp = (failures - 1).coerceAtMost(5)
        return (2 shl exp).coerceAtMost(60)
    }

    /** Session from provision/start, or null when the answer is unusable. */
    fun session(data: ProvisionStartResponse?, nowMs: Long): QrSession? {
        val code = data?.code?.trim().orEmpty()
        val secret = data?.secret?.trim().orEmpty()
        val url = data?.claimUrl?.trim().orEmpty()
        if (code.isEmpty() || secret.isEmpty() || url.isEmpty()) return null
        val expires = (data?.expiresIn?.takeIf { it > 0 } ?: DEFAULT_EXPIRES_SEC).coerceIn(30, 24 * 3600)
        val poll = (data?.pollInterval?.takeIf { it > 0 } ?: DEFAULT_POLL_SEC).coerceIn(1, 30)
        return QrSession(code, secret, url, nowMs + expires * 1000L, poll)
    }

    /**
     * Interprets provision/status. A `claimed` answer is mapped through the bulk-tool validation
     * ([Provisioning.parse]) so the QR path accepts exactly what the provisioning extras accept;
     * a missing server_url means "the server you are talking to".
     */
    fun interpret(data: ProvisionStatusResponse?, currentServer: String): StatusResult =
        when (data?.status?.trim()?.lowercase(Locale.ROOT)) {
            "pending" -> StatusResult.Pending
            "expired" -> StatusResult.Expired
            "used" -> StatusResult.Used
            "claimed" -> claim(data, currentServer)
            null, "" -> StatusResult.Invalid("missing status")
            else -> StatusResult.Invalid("unknown status '${data.status}'")
        }

    /**
     * The server reports its https:// address, but a TV that could only reach it over http:// (old TV
     * without working HTTPS, chosen on the setup screen) must keep using http:// for the same host.
     */
    fun keepHttp(claimed: String, currentServer: String): String {
        val cur = ServerUrl.normalize(currentServer)?.toHttpUrlOrNull() ?: return claimed
        val cl = ServerUrl.normalize(claimed)?.toHttpUrlOrNull() ?: return claimed
        if (cur.scheme != "http" || cl.scheme != "https" || !cur.host.equals(cl.host, ignoreCase = true)) return claimed
        return ServerUrl.root(cl.newBuilder().scheme("http").port(cur.port).build().toString())
    }

    fun claim(data: ProvisionStatusResponse, currentServer: String): StatusResult {
        val server = keepHttp(data.serverUrl?.trim()?.takeIf { it.isNotEmpty() } ?: currentServer, currentServer)
        val values = mapOf(
            Provisioning.EXTRA_SERVER to server,
            Provisioning.EXTRA_ROOM to data.roomNumber,
            Provisioning.EXTRA_KEY to data.registrationKey,
            Provisioning.EXTRA_AUTOREGISTER to true,
        )
        return when (val p = Provisioning.parse { k -> values[k] }) {
            is Provisioning.Parsed.Valid -> StatusResult.Claimed(
                ClaimedConfig(
                    serverUrl = ServerUrl.root(p.request.apiBase),
                    apiBase = p.request.apiBase,
                    room = p.request.room,
                    key = p.request.key,
                    hotelName = data.hotelName?.trim()?.takeIf { it.isNotEmpty() },
                )
            )
            is Provisioning.Parsed.Invalid -> StatusResult.Invalid(p.reason)
            Provisioning.Parsed.None -> StatusResult.Invalid("claimed without values")
        }
    }
}

/** Network side of the flow (real implementation: [RetrofitProvisionBackend]; tests use a fake). */
interface ProvisionBackend {
    /** Throws [ApiException] for server errors and IOException for network failures. */
    suspend fun start(apiBase: String, body: ProvisionStartRequest): ProvisionStartResponse?
    suspend fun status(apiBase: String, code: String, secret: String): ProvisionStatusResponse?
}

/**
 * The polling state machine. [run] returns the claimed configuration, or null when the TV got
 * registered by another path meanwhile. It never returns otherwise (cancel the coroutine to stop).
 */
class QrSetupFlow(
    private val backend: ProvisionBackend,
    private val startBody: () -> ProvisionStartRequest,
    private val clock: () -> Long,
    private val isRegistered: () -> Boolean = { false },
    private val describe: (Throwable) -> String = { it.message ?: it.javaClass.simpleName },
) {
    suspend fun run(server: String, resume: QrSession? = null, emit: (QrSetupState) -> Unit): ClaimedConfig? {
        val apiBase = ServerUrl.normalize(server)
        if (apiBase == null) {
            emit(QrSetupState.InvalidServer(server))
            return null
        }
        var session: QrSession? = resume?.takeIf { clock() < it.expiresAtMs }
        var failures = 0
        session?.let { emit(QrSetupState.Waiting(server, it)) }

        while (true) {
            currentCoroutineContext().ensureActive()
            if (isRegistered()) {
                emit(QrSetupState.AlreadyRegistered(server))
                return null
            }
            val current = session
            if (current == null) {
                emit(QrSetupState.Starting(server))
                val waitSec: Int = try {
                    val s = QrSetupLogic.session(backend.start(apiBase, startBody()), clock())
                    if (s != null) {
                        session = s
                        failures = 0
                        emit(QrSetupState.Waiting(server, s))
                        0
                    } else {
                        failures++
                        val w = QrSetupLogic.backoffSec(failures)
                        emit(QrSetupState.Offline(server, null, "Invalid answer from provision/start", clock() + w * 1000L, network = false))
                        w
                    }
                } catch (e: ApiException) {
                    when {
                        e.httpStatus == 429 -> {
                            val w = e.retryAfterSec?.takeIf { it > 0 } ?: QrSetupLogic.DEFAULT_RETRY_AFTER_SEC
                            emit(QrSetupState.RateLimited(server, null, clock() + w * 1000L))
                            w
                        }
                        e.httpStatus == 404 || e.httpStatus == 405 -> {
                            val w = QrSetupLogic.UNSUPPORTED_RETRY_SEC
                            emit(QrSetupState.Unsupported(server, describe(e), clock() + w * 1000L))
                            w
                        }
                        else -> {
                            failures++
                            val w = QrSetupLogic.backoffSec(failures)
                            emit(QrSetupState.Offline(server, null, describe(e), clock() + w * 1000L, network = false))
                            w
                        }
                    }
                } catch (e: kotlinx.coroutines.CancellationException) {
                    throw e
                } catch (e: Exception) {
                    failures++
                    val w = QrSetupLogic.backoffSec(failures)
                    emit(QrSetupState.Offline(server, null, describe(e), clock() + w * 1000L, network = true))
                    w
                }
                if (waitSec > 0) delay(waitSec * 1000L)
                continue
            }

            // Wait one poll interval, but never past the expiry.
            val untilExpiry = current.expiresAtMs - clock()
            if (untilExpiry <= 0) {
                session = null // expired → new code
                continue
            }
            delay(minOf(current.pollIntervalSec * 1000L, untilExpiry))
            if (clock() >= current.expiresAtMs) {
                session = null
                continue
            }
            if (isRegistered()) continue

            val waitSec: Int = try {
                when (val r = QrSetupLogic.interpret(backend.status(apiBase, current.code, current.secret), server)) {
                    StatusResult.Pending -> {
                        failures = 0
                        emit(QrSetupState.Waiting(server, current))
                        0
                    }
                    StatusResult.Expired, StatusResult.Used -> {
                        session = null
                        0
                    }
                    is StatusResult.Invalid -> {
                        session = null
                        failures++
                        val w = QrSetupLogic.backoffSec(failures)
                        emit(QrSetupState.Offline(server, null, r.reason, clock() + w * 1000L, network = false))
                        w
                    }
                    is StatusResult.Claimed -> {
                        emit(QrSetupState.Claimed(server, r.config))
                        return r.config
                    }
                }
            } catch (e: ApiException) {
                when {
                    e.httpStatus == 404 -> {
                        session = null // unknown code / wrong secret → start again (start() detects a missing endpoint)
                        0
                    }
                    e.httpStatus == 429 -> {
                        val w = e.retryAfterSec?.takeIf { it > 0 } ?: QrSetupLogic.DEFAULT_RETRY_AFTER_SEC
                        emit(QrSetupState.RateLimited(server, current, clock() + w * 1000L))
                        w
                    }
                    else -> {
                        failures++
                        val w = QrSetupLogic.backoffSec(failures)
                        emit(QrSetupState.Offline(server, current, describe(e), clock() + w * 1000L, network = !e.fromServer))
                        w
                    }
                }
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                failures++
                val w = QrSetupLogic.backoffSec(failures)
                emit(QrSetupState.Offline(server, current, describe(e), clock() + w * 1000L, network = true))
                w
            }
            // Retry waits are capped by the expiry; the next loop iteration starts a new code if needed.
            if (waitSec > 0) delay(minOf(waitSec * 1000L, (current.expiresAtMs - clock()).coerceAtLeast(1)))
        }
    }
}

/** Real backend: Retrofit against `{apiBase}provision/…` (no auth headers, see ApiClient). */
class RetrofitProvisionBackend : ProvisionBackend {
    private var cachedBase: String? = null
    private var cachedApi: ApiService? = null

    @Synchronized
    private fun api(apiBase: String): ApiService {
        val existing = cachedApi
        if (existing != null && cachedBase == apiBase) return existing
        return ApiClient.serviceFor(apiBase).also {
            cachedApi = it
            cachedBase = apiBase
        }
    }

    override suspend fun start(apiBase: String, body: ProvisionStartRequest): ProvisionStartResponse? {
        val api = api(apiBase)
        return ApiClient.call { api.provisionStart(body) }
    }

    override suspend fun status(apiBase: String, code: String, secret: String): ProvisionStatusResponse? {
        val api = api(apiBase)
        return ApiClient.call { api.provisionStatus(code, secret) }
    }
}
