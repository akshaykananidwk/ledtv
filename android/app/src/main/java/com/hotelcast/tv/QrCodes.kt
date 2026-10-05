package com.hotelcast.tv

import android.graphics.Bitmap
import android.graphics.Color
import android.util.Log
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.common.BitMatrix
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel

/**
 * QR codes generated on the TV with ZXing core (Apache 2.0) — no network, no external images.
 * [WifiQr] builds the standard Wi-Fi join string understood by Android/iOS camera apps.
 */
object QrCodes {
    private const val TAG = "QrCodes"

    /** QR module matrix for [text] (pure JVM, unit-testable). [size] is the requested edge in pixels/modules. */
    fun matrix(text: String, size: Int): BitMatrix {
        val hints = mapOf(
            EncodeHintType.CHARACTER_SET to "UTF-8",
            EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M,
            EncodeHintType.MARGIN to 1,
        )
        return QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, size, size, hints)
    }

    /** Black-on-white QR bitmap, or null if [text] is blank / too long / anything fails. */
    fun bitmap(text: String?, sizePx: Int, dark: Int = Color.BLACK, light: Int = Color.WHITE): Bitmap? {
        if (text.isNullOrBlank()) return null
        return try {
            val size = sizePx.coerceIn(64, 1024)
            val m = matrix(text, size)
            val w = m.width
            val h = m.height
            val pixels = IntArray(w * h)
            for (y in 0 until h) {
                val off = y * w
                for (x in 0 until w) pixels[off + x] = if (m.get(x, y)) dark else light
            }
            Bitmap.createBitmap(w, h, Bitmap.Config.RGB_565).apply { setPixels(pixels, 0, w, 0, 0, w, h) }
        } catch (e: Throwable) {
            Log.w(TAG, "QR generation failed (${text.length} chars)", e)
            null
        }
    }
}

object WifiQr {
    /**
     * `WIFI:T:WPA;S:<ssid>;P:<password>;;` with the special characters `\ ; , : "` escaped by a
     * backslash (ZXing / Android "Wi-Fi network config" format). Open networks use `T:nopass` and no P.
     * Returns null when there is no SSID.
     */
    fun build(ssid: String?, password: String?, security: String = "WPA"): String? {
        if (ssid.isNullOrEmpty()) return null
        val sb = StringBuilder("WIFI:")
        if (password.isNullOrEmpty()) {
            sb.append("T:nopass;S:").append(escape(ssid)).append(';')
        } else {
            sb.append("T:").append(security).append(";S:").append(escape(ssid)).append(";P:").append(escape(password)).append(';')
        }
        sb.append(';')
        return sb.toString()
    }

    fun escape(value: String): String {
        val sb = StringBuilder(value.length + 8)
        for (ch in value) {
            if (ch == '\\' || ch == ';' || ch == ',' || ch == ':' || ch == '"') sb.append('\\')
            sb.append(ch)
        }
        return sb.toString()
    }
}
