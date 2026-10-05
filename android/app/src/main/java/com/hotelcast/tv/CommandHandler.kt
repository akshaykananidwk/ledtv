package com.hotelcast.tv

import android.util.Log
import kotlinx.coroutines.delay

/** What the device can do in response to server commands (implemented by [SyncManager]). */
interface CommandActions {
    suspend fun refetchContent()
    suspend fun clearCache()
    fun setScreenOn(on: Boolean)
    fun reload()
    fun reboot()
    suspend fun updateApp(
        url: String,
        sha256: String?,
        versionCode: Int?,
        versionName: String?,
        beforeInstall: suspend (String) -> Unit,
    ): AppUpdater.Result
}

/**
 * Executes server commands exactly once (de-duplicated by command id, persisted across restarts)
 * and acknowledges every delivery, including duplicates (delivery is at-least-once until acked).
 */
class CommandHandler(
    private val deduper: CommandDeduper,
    private val persist: (String) -> Unit,
    private val ack: suspend (AckRequest) -> Unit,
    private val actions: CommandActions,
) {

    suspend fun handleAll(commands: List<Command>?) {
        commands.orEmpty().sortedBy { it.id ?: Long.MAX_VALUE }.forEach { handle(it) }
    }

    suspend fun handle(cmd: Command) {
        val id = cmd.id ?: return
        val name = cmd.command?.uppercase()?.trim().orEmpty()
        if (!deduper.markHandled(id)) {
            Log.i(TAG, "Duplicate command $id $name – re-acking only")
            safeAck(id, STATUS_ACKED, "Already handled")
            return
        }
        persist(deduper.serialize())
        Log.i(TAG, "Executing command $id $name")
        try {
            when (name) {
                "PING" -> safeAck(id, STATUS_ACKED, "pong")
                "SHOW_CONTENT" -> {
                    actions.refetchContent()
                    safeAck(id, STATUS_ACKED, "Content refreshed")
                }
                "CLEAR_CACHE" -> {
                    actions.clearCache()
                    safeAck(id, STATUS_ACKED, "Cache cleared")
                }
                "SCREEN_OFF" -> {
                    actions.setScreenOn(false)
                    safeAck(id, STATUS_ACKED, "Screen off")
                }
                "SCREEN_ON" -> {
                    actions.setScreenOn(true)
                    safeAck(id, STATUS_ACKED, "Screen on")
                }
                "RELOAD" -> {
                    safeAck(id, STATUS_ACKED, "Reloading")
                    actions.reload()
                }
                "REBOOT" -> {
                    safeAck(id, STATUS_ACKED, "Rebooting")
                    delay(1000)
                    actions.reboot()
                }
                "UPDATE_APP" -> {
                    val url = cmd.payloadString("url")
                    if (url.isNullOrBlank()) {
                        safeAck(id, STATUS_FAILED, "Missing url")
                        return
                    }
                    var acked = false
                    val result = actions.updateApp(
                        url = url,
                        sha256 = cmd.payloadString("sha256"),
                        versionCode = cmd.payloadInt("version_code"),
                        versionName = cmd.payloadString("version_name"),
                    ) { msg ->
                        // Ack before installing: a silent self-update kills this process.
                        safeAck(id, STATUS_ACKED, msg)
                        acked = true
                    }
                    if (!result.ok) safeAck(id, STATUS_FAILED, result.message)
                    else if (!acked) safeAck(id, STATUS_ACKED, result.message)
                }
                else -> safeAck(id, STATUS_FAILED, "Unknown command: $name")
            }
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Exception) {
            Log.e(TAG, "Command $id $name failed", e)
            safeAck(id, STATUS_FAILED, e.message ?: e.javaClass.simpleName)
        }
    }

    private suspend fun safeAck(id: Long, status: String, message: String?) {
        try {
            ack(AckRequest(id, status, message?.take(250)))
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Exception) {
            // Not fatal: the server re-delivers un-acked commands and the de-duper re-acks them.
            Log.w(TAG, "Ack $id failed: ${e.message}")
        }
    }

    companion object {
        private const val TAG = "CommandHandler"
        const val STATUS_DELIVERED = "delivered"
        const val STATUS_ACKED = "acked"
        const val STATUS_FAILED = "failed"
    }
}
