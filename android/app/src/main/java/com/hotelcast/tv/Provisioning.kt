package com.hotelcast.tv

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull

/**
 * Bulk-setup provisioning (V2 §7). The PC setup tool passes the server URL, room and registration key
 * as intent extras, either to the setup screen
 *
 *   adb shell am start -n com.hotelcast.tv/.SettingsActivity \
 *       --es hc_server https://hotel.com/hotelcast --es hc_room 101 --es hc_key KEY --ez hc_autoregister true
 *
 * or as a broadcast (works without UI, also while the player is in lock task)
 *
 *   adb shell am broadcast -a com.hotelcast.tv.PROVISION -n com.hotelcast.tv/.ProvisionReceiver \
 *       --es hc_server … --es hc_room 101 --es hc_key KEY --ez hc_autoregister true [--ez hc_force true]
 *
 * Both components are exported but protected by `android.permission.DUMP` (signature|privileged|
 * development): only the adb shell, the system and HotelCast itself can start them — never another
 * app or a guest. On top of that, values are only accepted while the TV is not registered, unless
 * `hc_force=true`. The outcome is logged with tag [SETUP_TAG] so the tool can read it with
 * `adb logcat -d -s HotelCastSetup`: `REGISTERED room=101`, `SAVED room=101` or `FAILED <reason>`.
 */
object Provisioning {
    const val SETUP_TAG = "HotelCastSetup"
    const val ACTION_PROVISION = "com.hotelcast.tv.PROVISION"
    const val EXTRA_SERVER = "hc_server"
    const val EXTRA_ROOM = "hc_room"
    const val EXTRA_KEY = "hc_key"
    const val EXTRA_AUTOREGISTER = "hc_autoregister"
    const val EXTRA_FORCE = "hc_force"

    private val ROOM_RE = Regex("^[\\p{L}\\p{N} ._/-]{1,32}$")

    data class Request(
        val serverUrl: String,
        val apiBase: String,
        val room: String,
        val key: String,
        val autoRegister: Boolean,
        val force: Boolean,
    )

    sealed class Parsed {
        /** No provisioning extras at all (normal launch). */
        object None : Parsed()
        data class Invalid(val reason: String) : Parsed()
        data class Valid(val request: Request) : Parsed()
    }

    /** Accepts real booleans and the strings "true"/"1"/"yes" (`--es hc_autoregister true`). */
    fun bool(v: Any?): Boolean = when (v) {
        is Boolean -> v
        is Number -> v.toInt() != 0
        is String -> v.trim().lowercase() in setOf("true", "1", "yes", "y", "on")
        else -> false
    }

    /** Validates the extras; [get] looks up one extra (Bundle.get or a test map). Pure. */
    fun parse(get: (String) -> Any?): Parsed {
        val server = get(EXTRA_SERVER)?.toString()?.trim().orEmpty()
        val room = get(EXTRA_ROOM)?.toString()?.trim().orEmpty()
        val key = get(EXTRA_KEY)?.toString()?.trim().orEmpty()
        if (server.isEmpty() && room.isEmpty() && key.isEmpty()) return Parsed.None
        val base = ServerUrl.normalize(server) ?: return Parsed.Invalid("invalid hc_server '$server'")
        if (room.isEmpty()) return Parsed.Invalid("missing hc_room")
        if (!ROOM_RE.matches(room)) return Parsed.Invalid("invalid hc_room '$room'")
        if (key.isEmpty()) return Parsed.Invalid("missing hc_key")
        if (key.length > 128 || key.any { it.isWhitespace() || it.isISOControl() }) return Parsed.Invalid("invalid hc_key")
        return Parsed.Valid(Request(server, base, room, key, bool(get(EXTRA_AUTOREGISTER)), bool(get(EXTRA_FORCE))))
    }

    @Suppress("DEPRECATION")
    fun parse(extras: Bundle?): Parsed = if (extras == null) Parsed.None else parse { k -> try { extras.get(k) } catch (_: Exception) { null } }

    /** Null = accepted; otherwise the reason it was refused. Pure. */
    fun refusal(request: Request, isRegistered: Boolean): String? =
        if (isRegistered && !request.force) "already registered (room ${Prefs.roomNumberOrNull() ?: "?"}); add --ez hc_force true to re-provision" else null

    fun logResult(line: String) {
        if (line.startsWith("FAILED")) Log.e(SETUP_TAG, line) else Log.i(SETUP_TAG, line)
    }

    /** Stores the values without registering (fields are pre-filled on the setup screen). */
    fun saveFields(r: Request) {
        Prefs.serverUrl = r.serverUrl
        Prefs.roomNumber = r.room
        Prefs.registrationKey = r.key
    }
}

private fun Prefs.roomNumberOrNull(): String? = if (isInitialized) roomNumber.ifBlank { null } else null

/** Outcome of POST device/register. */
data class RegisterOutcome(val ok: Boolean, val room: String?, val errorCode: String?, val message: String)

/** Registration shared by the setup screen and [ProvisionReceiver]. */
object Registrar {
    private const val TAG = "Registrar"

    suspend fun register(context: Context, serverUrlRaw: String, apiBase: String, room: String, key: String): RegisterOutcome {
        return try {
            val req = RegisterRequest(
                deviceId = Prefs.deviceId,
                roomNumber = room,
                registrationKey = key,
                appVersion = DeviceInfo.appVersion,
                appVersionCode = DeviceInfo.appVersionCode,
                androidVersion = DeviceInfo.androidVersion,
                model = DeviceInfo.model,
                ipAddress = withContext(Dispatchers.IO) { DeviceInfo.ipAddress() },
            )
            val data = withContext(Dispatchers.IO) {
                val api = ApiClient.serviceFor(apiBase)
                ApiClient.call { api.register(req) }
            }
            val token = data?.token
            if (token.isNullOrBlank()) return RegisterOutcome(false, null, null, "Server returned no token")

            SyncManager.stop()
            Prefs.serverUrl = serverUrlRaw.trim()
            Prefs.apiBase = apiBase
            Prefs.registrationKey = key
            Prefs.roomNumber = data.room?.number ?: room
            Prefs.roomName = data.room?.name.orEmpty()
            Prefs.roomId = data.room?.id ?: 0L
            data.hotel?.name?.takeIf { it.isNotBlank() }?.let { Prefs.hotelName = it }
            data.pollInterval?.let { Prefs.pollIntervalSec = it }
            data.heartbeatInterval?.let { Prefs.heartbeatIntervalSec = it }
            Prefs.pinHash = data.settingsPinHash
            Prefs.currentHash = "" // ask for the full content object on the next poll
            Prefs.token = token
            PollService.start(context)
            SyncManager.pollNow()
            RegisterOutcome(true, Prefs.roomNumber, null, "Registered as room ${Prefs.roomNumber}")
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: ApiException) {
            Log.w(TAG, "register failed: ${ApiClient.describe(e)}")
            RegisterOutcome(false, null, e.code, ApiClient.describe(e))
        } catch (e: Exception) {
            Log.w(TAG, "register failed: ${ApiClient.describe(e)}")
            RegisterOutcome(false, null, null, ApiClient.describe(e))
        }
    }
}

/**
 * `am broadcast -a com.hotelcast.tv.PROVISION -n com.hotelcast.tv/.ProvisionReceiver --es hc_server …`
 * Protected by android.permission.DUMP in the manifest (adb shell / system only).
 */
class ProvisionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        Prefs.init(context)
        SyncManager.init(context)
        val parsed = Provisioning.parse(intent.extras)
        val request = when (parsed) {
            Provisioning.Parsed.None -> {
                Provisioning.logResult("FAILED no extras (need hc_server, hc_room, hc_key)")
                return
            }
            is Provisioning.Parsed.Invalid -> {
                Provisioning.logResult("FAILED ${parsed.reason}")
                return
            }
            is Provisioning.Parsed.Valid -> parsed.request
        }
        Provisioning.refusal(request, Prefs.isRegistered)?.let {
            Provisioning.logResult("FAILED $it")
            return
        }
        Provisioning.saveFields(request)
        if (!request.autoRegister) {
            Provisioning.logResult("SAVED room=${request.room} (hc_autoregister=false: open the setup screen to register)")
            return
        }
        val pending = goAsync()
        CoroutineScope(SupervisorJob() + Dispatchers.Main).launch {
            try {
                val outcome = withTimeoutOrNull(50_000) {
                    Registrar.register(context.applicationContext, request.serverUrl, request.apiBase, request.room, request.key)
                } ?: RegisterOutcome(false, null, "TIMEOUT", "Timeout")
                if (outcome.ok) {
                    Provisioning.logResult("REGISTERED room=${outcome.room}")
                    try {
                        context.startActivity(
                            Intent(context, MainActivity::class.java)
                                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
                        )
                    } catch (e: Exception) {
                        Log.w("ProvisionReceiver", "Cannot open player: ${e.message}")
                    }
                } else {
                    Provisioning.logResult("FAILED ${outcome.message}")
                }
            } catch (e: Throwable) {
                Provisioning.logResult("FAILED ${e.message ?: e.javaClass.simpleName}")
            } finally {
                pending.finish()
            }
        }
    }
}
