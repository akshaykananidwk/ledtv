package com.hotelcast.tv

import android.util.Log
import kotlinx.coroutines.delay

/** What the device can do in response to server commands (implemented by [SyncManager]). */
interface CommandActions {
    suspend fun refetchContent()
    suspend fun clearCache()
    /** Switch the TV to standby (false) or wake it (true). Returns a short result for the ack. */
    suspend fun setScreenOn(on: Boolean): String
    fun reload()
    fun reboot()
    suspend fun updateApp(
        url: String,
        sha256: String?,
        versionCode: Int?,
        versionName: String?,
        beforeInstall: suspend (String) -> Unit,
    ): AppUpdater.Result

    // ---- V2 commands. Each returns the ack message; throwing (e.g. CommandFailedException) acks
    // the command as failed with the exception message. Defaults keep older implementations compiling.

    /** SET_VOLUME: [level] 0–100, clamped to volume.max / night max by the implementation. */
    suspend fun setVolume(level: Int): String = throw CommandFailedException("SET_VOLUME not supported")
    suspend fun setMuted(muted: Boolean): String = throw CommandFailedException("MUTE not supported")
    suspend fun takeScreenshot(): String = throw CommandFailedException("SCREENSHOT not supported")
    suspend fun uploadLogs(): String = throw CommandFailedException("UPLOAD_LOGS not supported")
    suspend fun openInput(input: String): String = throw CommandFailedException("OPEN_INPUT not supported")
    suspend fun showWelcome(): String = throw CommandFailedException("SHOW_WELCOME not supported")
    suspend fun showMessage(title: String?, message: String?, durationSec: Int): String =
        throw CommandFailedException("SHOW_MESSAGE not supported")
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
                    safeAck(id, STATUS_ACKED, actions.setScreenOn(false))
                }
                "SCREEN_ON" -> {
                    safeAck(id, STATUS_ACKED, actions.setScreenOn(true))
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
                "SET_VOLUME" -> {
                    val level = cmd.payloadInt("level")
                    if (level == null) {
                        safeAck(id, STATUS_FAILED, "Missing level (0-100)")
                        return
                    }
                    safeAck(id, STATUS_ACKED, actions.setVolume(level.coerceIn(0, 100)))
                }
                "MUTE" -> safeAck(id, STATUS_ACKED, actions.setMuted(true))
                "UNMUTE" -> safeAck(id, STATUS_ACKED, actions.setMuted(false))
                "SCREENSHOT" -> safeAck(id, STATUS_ACKED, actions.takeScreenshot())
                "UPLOAD_LOGS" -> safeAck(id, STATUS_ACKED, actions.uploadLogs())
                "OPEN_INPUT" -> {
                    val input = cmd.payloadString("input")?.trim()
                    if (input.isNullOrEmpty() || InputSwitcher.parse(input) == null) {
                        safeAck(id, STATUS_FAILED, "Invalid input '${input.orEmpty()}' (use live_tv or hdmi1..hdmi4)")
                        return
                    }
                    safeAck(id, STATUS_ACKED, actions.openInput(input))
                }
                "SHOW_WELCOME" -> safeAck(id, STATUS_ACKED, actions.showWelcome())
                "SHOW_MESSAGE" -> {
                    val title = cmd.payloadString("title")?.trim()
                    val message = cmd.payloadString("message")?.trim()
                    if (title.isNullOrEmpty() && message.isNullOrEmpty()) {
                        safeAck(id, STATUS_FAILED, "Missing title/message")
                        return
                    }
                    val dur = (cmd.payloadInt("duration_sec") ?: DEFAULT_MESSAGE_SEC).let { if (it <= 0) DEFAULT_MESSAGE_SEC else it }.coerceIn(3, 3600)
                    safeAck(id, STATUS_ACKED, actions.showMessage(title, message, dur))
                }
                else -> safeAck(id, STATUS_FAILED, "Unknown command: $name")
            }
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: CommandFailedException) {
            Log.w(TAG, "Command $id $name failed: ${e.message}")
            safeAck(id, STATUS_FAILED, e.message ?: "failed")
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
        const val DEFAULT_MESSAGE_SEC = 15
    }
}
