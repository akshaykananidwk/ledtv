package com.hotelcast.tv

import android.content.Context
import android.graphics.Bitmap
import android.os.Build
import android.os.Process
import android.util.Log
import com.google.gson.JsonObject
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.concurrent.TimeUnit

/** Small in-memory ring buffer of recent errors, included in UPLOAD_LOGS state. */
object ErrorLog {
    private const val MAX = 30
    private val entries = ArrayDeque<String>()

    @Synchronized
    fun add(where: String, message: String?) {
        entries.addLast(Utils.isoTime(System.currentTimeMillis()) + " [" + where + "] " + (message ?: "?").take(300))
        while (entries.size > MAX) entries.removeFirst()
    }

    @Synchronized
    fun snapshot(): List<String> = entries.toList()
}

/** UPLOAD_LOGS: own-process logcat + app state as JSON (no secrets). */
object LogCollector {
    private const val TAG = "LogCollector"
    const val MAX_LOG_BYTES = 512 * 1024
    private const val LINES = 2000

    /**
     * Keeps the newest part of [text] so that its UTF-8 size is ≤ [maxBytes] (the server caps the
     * field at 512 KB). Pure, unit-tested.
     */
    fun capTail(text: String, maxBytes: Int = MAX_LOG_BYTES): String {
        if (utf8Len(text) <= maxBytes) return text
        val marker = "[…truncated…]\n"
        val budget = maxBytes - utf8Len(marker)
        var bytes = 0
        var i = text.length
        while (i > 0) {
            val cp = Character.codePointBefore(text, i)
            val len = utf8Len(cp)
            if (bytes + len > budget) break
            bytes += len
            i -= Character.charCount(cp)
        }
        // start at a line boundary when possible
        val nl = text.indexOf('\n', i)
        val start = if (nl in i until text.length - 1 && nl - i < 2000) nl + 1 else i
        return marker + text.substring(start)
    }

    private fun utf8Len(cp: Int): Int = when {
        cp < 0x80 -> 1
        cp < 0x800 -> 2
        cp < 0x10000 -> 3
        else -> 4
    }

    fun utf8Len(s: String): Int {
        var n = 0
        var i = 0
        while (i < s.length) {
            val cp = Character.codePointAt(s, i)
            n += utf8Len(cp)
            i += Character.charCount(cp)
        }
        return n
    }

    /** Blocking — call from a background thread. */
    fun readLogcat(): String {
        val pid = Process.myPid()
        val attempts = listOf(
            arrayOf("logcat", "-d", "-v", "threadtime", "-t", LINES.toString(), "--pid=$pid"),
            arrayOf("logcat", "-d", "-v", "threadtime", "-t", LINES.toString()), // older Android: no --pid (apps only see their own logs anyway)
        )
        for (cmd in attempts) {
            try {
                val p = Runtime.getRuntime().exec(cmd)
                val out = StringBuilder()
                val reader = Thread {
                    try {
                        p.inputStream.bufferedReader().useLines { seq -> seq.forEach { out.append(it).append('\n') } }
                    } catch (_: Exception) {
                    }
                }
                reader.start()
                reader.join(TimeUnit.SECONDS.toMillis(10))
                try { p.destroy() } catch (_: Exception) {}
                reader.join(1000)
                val text = out.toString()
                if (text.isNotBlank() && !text.contains("unknown option", ignoreCase = true) && !text.startsWith("Unrecognized Option")) return text
            } catch (e: Exception) {
                Log.w(TAG, "logcat ${cmd.joinToString(" ")} failed: ${e.message}")
            }
        }
        return "(logcat unavailable)"
    }

    fun state(context: Context, extra: Map<String, Any?> = emptyMap()): JsonObject {
        val st = JsonObject()
        val gson = ApiClient.gson
        st.add("prefs", gson.toJsonTree(Prefs.debugSnapshot()))
        st.add("app", gson.toJsonTree(linkedMapOf(
            "version_name" to BuildConfig.VERSION_NAME,
            "version_code" to BuildConfig.VERSION_CODE,
            "android" to Build.VERSION.RELEASE,
            "sdk" to Build.VERSION.SDK_INT,
            "model" to DeviceInfo.model,
            "device" to Build.DEVICE,
            "uptime_sec" to DeviceInfo.uptimeSec(),
            "device_owner" to KioskHelper.isDeviceOwner(context),
            "lock_task" to KioskHelper.isInLockTask(context),
            "external_app_active" to KioskHelper.externalAppActive,
        )))
        val c = SyncManager.content.value
        st.add("power", gson.toJsonTree(linkedMapOf(
            "screen_interactive" to PowerController.isScreenInteractive(),
            "desired_off" to PowerController.desiredOff(c),
            "black_mode" to PowerController.blackMode(c),
            "local_override" to PowerController.isLocallyOverridden(),
        )))
        st.add("network", gson.toJsonTree(linkedMapOf(
            "type" to DeviceInfo.networkType(context),
            "ip" to DeviceInfo.ipAddress(),
            "wifi_signal" to DeviceInfo.wifiSignal(context),
            "free_storage_mb" to DeviceInfo.freeStorageMb(context),
        )))
        val status = SyncManager.status.value
        st.add("sync", gson.toJsonTree(linkedMapOf(
            "online" to status.online,
            "last_poll_time" to status.lastPollTime,
            "last_error" to status.lastError,
            "content_mode" to c?.mode,
            "content_hash" to c?.hash,
            "items" to (c?.items?.size ?: 0),
            "current_item_id" to SyncManager.currentItemId,
            "volume_percent" to VolumeController.currentPercent(context),
        )))
        st.add("last_errors", gson.toJsonTree(ErrorLog.snapshot()))
        extra.forEach { (k, v) -> st.add(k, gson.toJsonTree(v)) }
        return st
    }
}

/**
 * Crash reports: the uncaught-exception handler writes `filesDir/crash/<millis>.json`; on the next
 * start (once registered) every pending report is sent to POST device/crash and deleted.
 */
object CrashReporter {
    private const val TAG = "CrashReporter"
    private const val MAX_FILES = 5
    private const val MAX_STACK = 64 * 1024

    private fun dir(context: Context) = File(context.filesDir, "crash")

    /** Called from the uncaught-exception handler: must be fast and must never throw. */
    fun store(context: Context, thread: Thread, error: Throwable) {
        try {
            val d = dir(context)
            d.mkdirs()
            val now = System.currentTimeMillis()
            val stack = ("Thread: " + thread.name + "\n" + Log.getStackTraceString(error)).take(MAX_STACK)
            val req = CrashRequest(stack = stack, appVersion = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})", happenedAt = Utils.isoTime(now))
            File(d, "$now.json").writeText(ApiClient.gson.toJson(req), Charsets.UTF_8)
            // keep only the newest reports (crash loops)
            d.listFiles()?.sortedByDescending { it.name }?.drop(MAX_FILES)?.forEach { it.delete() }
        } catch (_: Throwable) {
        }
    }

    fun pending(context: Context): List<File> =
        dir(context).listFiles()?.filter { it.name.endsWith(".json") }?.sortedBy { it.name }.orEmpty()

    /** Sends pending reports. Deletes each one after the server accepted it (or rejected it as invalid). */
    suspend fun uploadPending(context: Context, send: suspend (CrashRequest) -> Unit) {
        for (f in pending(context)) {
            val req = try {
                ApiClient.gson.fromJson(f.readText(Charsets.UTF_8), CrashRequest::class.java)
            } catch (e: Exception) {
                null
            }
            if (req == null || req.stack.isNullOrBlank()) {
                f.delete()
                continue
            }
            try {
                send(req)
                f.delete()
                Log.i(TAG, "Crash report ${f.name} sent")
            } catch (e: ApiException) {
                if (e.isInvalidToken) throw e
                if (e.httpStatus in 400..499 && e.httpStatus != 429) f.delete() // e.g. old server: 404
                Log.w(TAG, "Crash report ${f.name} not sent: ${e.message}")
                return
            } catch (e: Exception) {
                Log.w(TAG, "Crash report ${f.name} not sent: ${e.message}")
                return // offline: try next start
            }
        }
    }
}

/** Screenshot encoding: scale to ≤ 1280 px wide, JPEG quality 80. */
object ScreenshotEncoder {
    const val MAX_WIDTH = 1280
    const val QUALITY = 80

    /** Target size keeping the aspect ratio. Pure. */
    fun targetSize(w: Int, h: Int, maxWidth: Int = MAX_WIDTH): Pair<Int, Int> {
        if (w <= 0 || h <= 0) return 0 to 0
        if (w <= maxWidth) return w to h
        return maxWidth to (h.toLong() * maxWidth / w).toInt().coerceAtLeast(1)
    }

    fun toJpeg(src: Bitmap): ByteArray {
        val (tw, th) = targetSize(src.width, src.height)
        val scaled = if (tw != src.width) Bitmap.createScaledBitmap(src, tw, th, true) else src
        val out = ByteArrayOutputStream()
        scaled.compress(Bitmap.CompressFormat.JPEG, QUALITY, out)
        if (scaled !== src) scaled.recycle()
        return out.toByteArray()
    }
}
