package com.hotelcast.tv

import android.graphics.Color
import java.io.File
import java.io.InputStream
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import kotlin.math.abs

object Utils {

    fun sha256Hex(text: String): String = sha256Hex(text.toByteArray(Charsets.UTF_8))

    fun sha256Hex(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(bytes).toHex()

    fun sha256Hex(file: File): String = file.inputStream().use { sha256Hex(it) }

    fun sha256Hex(input: InputStream): String {
        val md = MessageDigest.getInstance("SHA-256")
        val buf = ByteArray(64 * 1024)
        while (true) {
            val n = input.read(buf)
            if (n < 0) break
            md.update(buf, 0, n)
        }
        return md.digest().toHex()
    }

    private fun ByteArray.toHex(): String {
        val sb = StringBuilder(size * 2)
        for (b in this) sb.append(String.format(Locale.US, "%02x", b))
        return sb.toString()
    }

    /** True when [pin] matches the server's settings_pin_hash (or the default PIN when no hash is known). */
    fun verifyPin(pin: String, pinHash: String?, defaultPin: String = DEFAULT_PIN): Boolean {
        val p = pin.trim()
        if (p.isEmpty()) return false
        return if (pinHash.isNullOrBlank()) p == defaultPin
        else sha256Hex(p).equals(pinHash.trim(), ignoreCase = true)
    }

    /**
     * ISO-8601 timestamp with numeric offset (e.g. 2026-10-05T18:00:00+05:30). Built by hand because
     * SimpleDateFormat's "XXX" pattern only exists on API 24+.
     */
    fun isoTime(millis: Long, tz: TimeZone = TimeZone.getDefault()): String {
        val fmt = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", Locale.US)
        fmt.timeZone = tz
        val offsetMin = tz.getOffset(millis) / 60000
        val sign = if (offsetMin >= 0) '+' else '-'
        val o = abs(offsetMin)
        return fmt.format(Date(millis)) + String.format(Locale.US, "%c%02d:%02d", sign, o / 60, o % 60)
    }

    fun parseColor(value: String?, fallback: Int): Int {
        if (value.isNullOrBlank()) return fallback
        return try {
            Color.parseColor(value.trim())
        } catch (e: Exception) {
            fallback
        }
    }

    const val DEFAULT_PIN = "1234"
}
