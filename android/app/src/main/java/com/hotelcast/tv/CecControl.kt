package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.media.tv.TvInputInfo
import android.media.tv.TvInputManager
import android.os.Build
import android.util.Log
import java.lang.reflect.Proxy
import java.util.Locale

/**
 * 2.4 HDMI-CEC (#50). Normal apps cannot use `HdmiControlManager` (a system API guarded by the
 * signature permission HDMI_CEC), so the app relies on what Android does itself on a **box**
 * (Android TV stick / set-top box driving a TV over HDMI) with CEC enabled in its settings:
 *  - the box going to sleep sends `<Standby>` → the TV switches off;
 *  - the box waking up sends One-Touch-Play (`<Image View On>` + `<Active Source>`) → the TV switches
 *    on and selects the box's HDMI input.
 *
 * "CEC mode" (`cec_mode` in the content, per room: auto | box | tv): on a box, SCREEN_OFF / the
 * "off" schedule always use real device sleep (lockNow as device owner, root KEYCODE_SLEEP) instead
 * of the black-screen mode, so CEC switches the TV off; SCREEN_ON wakes the box (PowerController).
 * A TV with a built-in panel ("tv") keeps the existing power controller unchanged.
 *
 * As a bonus the app tries `HdmiControlManager` via reflection (One Touch Play / standby); this only
 * works on system-signed builds and fails gracefully everywhere else.
 */
@SuppressLint("StaticFieldLeak")
object CecControl {
    private const val TAG = "Cec"
    const val MODE_AUTO = "auto"
    const val MODE_BOX = "box"
    const val MODE_TV = "tv"

    @Volatile private var detected: Boolean? = null

    private val BOX_HINTS = Regex(
        "\\b(box|stick|dongle|chromecast|shield|mibox|mi box|fire ?tv|aft[a-z]+|x96|h96|tx[3-9]|mxq|t95|tanix|a95x|hk1|beelink|ugoos|zidoo|formuler|sei[0-9]+|uip|stb|tata ?play|airtel|jiofiber|set.?top)\\b",
        RegexOption.IGNORE_CASE,
    )
    private val TV_HINTS = Regex(
        "\\b(bravia|smart ?tv|led ?tv|qled|oled|uhd ?tv|android ?tv \\d|tcl|hisense|vu tv|kodak|thomson|nextview|onida|mi tv|redmi tv|oneplus tv|philips tv|panasonic tv|sharp tv|lg tv)\\b",
        RegexOption.IGNORE_CASE,
    )

    /**
     * Pure (unit tested). A device with hardware HDMI *inputs* or a TV tuner has a built-in panel;
     * otherwise model / manufacturer hints decide. Unknown devices count as a TV (the existing power
     * behaviour stays unchanged); the admin sets "box" per room when the detection misses a box.
     */
    fun isLikelyBox(hasHdmiInputs: Boolean, hasTuner: Boolean, model: String, manufacturer: String): Boolean {
        if (hasHdmiInputs || hasTuner) return false
        val name = "$manufacturer $model"
        if (TV_HINTS.containsMatchIn(name)) return false
        return BOX_HINTS.containsMatchIn(name)
    }

    /** Pure: the room setting wins; "auto" (or missing) uses the detection. */
    fun effectiveBox(setting: String?, detectedBox: Boolean): Boolean = when (setting?.lowercase(Locale.ROOT)) {
        MODE_BOX -> true
        MODE_TV -> false
        else -> detectedBox
    }

    /** Detection on the device (cached). */
    fun detectBox(context: Context): Boolean {
        detected?.let { return it }
        var hdmiIn = false
        var tuner = false
        try {
            val pm = context.packageManager
            if (pm.hasSystemFeature(PackageManager.FEATURE_LIVE_TV)) {
                val tim = context.getSystemService(Context.TV_INPUT_SERVICE) as? TvInputManager
                tim?.tvInputList.orEmpty().forEach { info ->
                    val child = Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && info.parentId != null // HDMI-CEC device behind an input
                    if (info.type == TvInputInfo.TYPE_HDMI && !child) hdmiIn = true
                    if (info.type == TvInputInfo.TYPE_TUNER && !info.isPassthroughInput) tuner = true
                }
            }
        } catch (e: Exception) {
            Log.i(TAG, "input list unavailable: ${e.message}")
        }
        val box = isLikelyBox(hdmiIn, tuner, Build.MODEL.orEmpty(), Build.MANUFACTURER.orEmpty())
        detected = box
        Log.i(TAG, "detected ${if (box) "box" else "TV"} (hdmi inputs=$hdmiIn, tuner=$tuner, model=${Build.MODEL})")
        return box
    }

    /** True when the room's CEC mode says this device is a box (setting or detection). */
    fun boxMode(context: Context?, content: Content?): Boolean {
        val setting = content?.cecMode
        if (setting == MODE_BOX) return true
        if (setting == MODE_TV || context == null) return false
        return detectBox(context)
    }

    fun state(context: Context, content: Content?): Map<String, Any?> = linkedMapOf(
        "mode" to (content?.cecMode ?: MODE_AUTO),
        "detected_box" to detectBox(context),
        "box_mode" to boxMode(context, content),
    )

    /**
     * Optional: HdmiControlManager via reflection (system-signed builds only). [standby] true sends
     * `<Standby>` to the TV, false One Touch Play. Returns a short result or null when unavailable.
     */
    @SuppressLint("WrongConstant")
    fun tryHdmiControl(context: Context, standby: Boolean): String? {
        return try {
            val mgr = context.getSystemService("hdmi_control") ?: return null
            val client = mgr.javaClass.getMethod("getPlaybackClient").invoke(mgr) ?: return null
            if (standby) {
                client.javaClass.getMethod("sendStandby").invoke(client)
                "HDMI-CEC standby sent"
            } else {
                val cbClass = Class.forName("android.hardware.hdmi.HdmiPlaybackClient\$OneTouchPlayCallback")
                val cb = Proxy.newProxyInstance(cbClass.classLoader, arrayOf(cbClass)) { _, m, args ->
                    if (m.name == "onComplete") Log.i(TAG, "One Touch Play result ${args?.firstOrNull()}")
                    null
                }
                client.javaClass.getMethod("oneTouchPlay", cbClass).invoke(client, cb)
                "HDMI-CEC One Touch Play sent"
            }
        } catch (e: Throwable) {
            // SecurityException (no HDMI_CEC permission), hidden-API restrictions, missing classes…
            Log.i(TAG, "HdmiControlManager not usable: ${(e.cause ?: e).javaClass.simpleName}")
            null
        }
    }
}
