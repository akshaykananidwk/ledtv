package com.hotelcast.tv

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.tv.TvContract
import android.media.tv.TvInputInfo
import android.media.tv.TvInputManager
import android.net.Uri
import android.provider.Settings
import android.util.Log
import java.util.Locale

/**
 * Leaves the HotelCast player for Live TV (tuner) or an HDMI input.
 *
 * Live TV chain (first one that resolves to an activity wins):
 *  1. the TV's tuner input: ACTION_VIEW on `TvContract.buildChannelsUriForInput(tunerInputId)`
 *  2. ACTION_VIEW on `TvContract.Channels.CONTENT_URI` (Live Channels / TV app)
 *  3. launch intents of known TV apps: Google Live Channels, AOSP Live TV, Google TV home (live tab),
 *     MediaTek "TV center" (Sony/Philips/TCL/Xiaomi boards), Sony, TCL, Xiaomi PatchWall
 *  4. Android settings (inputs list) as the last resort
 *
 * HDMI: TvInputManager.getTvInputList() → hardware inputs of TYPE_HDMI (CEC child devices skipped),
 * matched to the port by label ("HDMI 2"), id ("…hdmi2…" / "…HW2…") or position, then ACTION_VIEW
 * on `TvContract.buildChannelUriForPassthroughInput(inputId)`. Falls back to the inputs list.
 *
 * Kiosk: the resolved package is temporarily added to the lock-task allow list (device owner) or lock
 * task is paused (see [KioskHelper.allowExternalApp]); the guest returns with HOME (HotelCast is HOME).
 */
object InputSwitcher {
    private const val TAG = "InputSwitcher"

    sealed class Target {
        object LiveTv : Target()
        data class Hdmi(val port: Int) : Target()
    }

    data class Result(val ok: Boolean, val message: String, val packageName: String? = null)

    /** Candidate TV input (subset of TvInputInfo, so the matching logic is unit-testable). */
    data class InputCandidate(val id: String, val label: String?, val isHdmi: Boolean, val isTuner: Boolean, val parentId: String?)

    val KNOWN_LIVE_TV_PACKAGES = listOf(
        "com.google.android.tv",              // Google "Live Channels"
        "com.android.tv",                     // AOSP Live TV
        "com.google.android.apps.tv.launcherx", // Google TV home (Live tab)
        "com.mediatek.wwtv.tvcenter",         // MediaTek TV center (Sony/Philips/TCL/Xiaomi…)
        "com.sony.dtv.tvx",                   // Sony Bravia TV
        "com.tcl.tv",                         // TCL
        "com.mitv.tvhome",                    // Xiaomi PatchWall
    )

    /** "live_tv" / "tv" / "tuner" → LiveTv; "hdmi1".."hdmi9" (also "HDMI 2", "hdmi-3") → Hdmi(n). */
    fun parse(input: String?): Target? {
        val s = input?.trim()?.lowercase(Locale.US)?.replace(" ", "")?.replace("-", "")?.replace("_", "") ?: return null
        if (s == "livetv" || s == "tv" || s == "tuner") return Target.LiveTv
        val m = Regex("^hdmi([1-9])$").matchEntire(s) ?: return null
        return Target.Hdmi(m.groupValues[1].toInt())
    }

    /** Picks the HDMI input id for [port] (1-based). Pure. */
    fun pickHdmi(inputs: List<InputCandidate>, port: Int): String? {
        val hdmi = inputs.filter { it.isHdmi && it.parentId == null }
        if (hdmi.isEmpty()) return null
        val digit = port.toString()
        hdmi.firstOrNull { c ->
            val l = c.label?.lowercase(Locale.US)?.replace(" ", "").orEmpty()
            l.contains("hdmi$digit") && !l.contains("hdmi${digit}0")
        }?.let { return it.id }
        hdmi.firstOrNull { c ->
            val id = c.id.lowercase(Locale.US)
            Regex("hdmi[^0-9]?$digit(?![0-9])").containsMatchIn(id)
        }?.let { return it.id }
        val sorted = hdmi.sortedBy { it.id }
        return sorted.getOrNull(port - 1)?.id
    }

    fun tvInputs(context: Context): List<InputCandidate> = try {
        val tim = context.getSystemService(Context.TV_INPUT_SERVICE) as? TvInputManager
        tim?.tvInputList.orEmpty().map { info ->
            InputCandidate(
                id = info.id,
                label = try { info.loadLabel(context)?.toString() } catch (_: Exception) { null },
                isHdmi = info.type == TvInputInfo.TYPE_HDMI,
                isTuner = info.type == TvInputInfo.TYPE_TUNER,
                parentId = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
                    try { info.parentId } catch (_: Exception) { null }
                } else null,
            )
        }
    } catch (e: SecurityException) {
        Log.w(TAG, "TV input list not permitted: ${e.message}")
        emptyList()
    } catch (e: Exception) {
        Log.w(TAG, "TV input list unavailable: ${e.message}")
        emptyList()
    }

    /** Ordered candidate intents for [target]. */
    fun candidateIntents(context: Context, target: Target): List<Intent> {
        val list = mutableListOf<Intent>()
        val inputs = tvInputs(context)
        when (target) {
            is Target.Hdmi -> {
                pickHdmi(inputs, target.port)?.let { id ->
                    list += Intent(Intent.ACTION_VIEW, TvContract.buildChannelUriForPassthroughInput(id))
                }
            }
            Target.LiveTv -> {
                inputs.firstOrNull { it.isTuner && it.parentId == null }?.let { tuner ->
                    list += Intent(Intent.ACTION_VIEW, TvContract.buildChannelsUriForInput(tuner.id))
                }
                list += Intent(Intent.ACTION_VIEW, TvContract.Channels.CONTENT_URI)
                list += Intent(Intent.ACTION_VIEW).setDataAndType(TvContract.Channels.CONTENT_URI, "vnd.android.cursor.dir/channel")
                val pm = context.packageManager
                for (pkg in KNOWN_LIVE_TV_PACKAGES) {
                    val launch = try {
                        pm.getLeanbackLaunchIntentForPackage(pkg) ?: pm.getLaunchIntentForPackage(pkg)
                    } catch (_: Exception) {
                        null
                    }
                    if (launch != null) list += launch
                }
            }
        }
        // Last resort: the TV's input list / settings so the guest can pick the source by hand.
        list += Intent("android.settings.TV_INPUT_SETTINGS")
        list += Intent(Settings.ACTION_SETTINGS)
        return list
    }

    private fun resolvePackage(context: Context, intent: Intent): String? = try {
        @Suppress("DEPRECATION")
        context.packageManager.resolveActivity(intent, PackageManager.MATCH_DEFAULT_ONLY)?.activityInfo?.packageName
            ?.takeIf { it != "android" } // "android" = the chooser / ResolverActivity
            ?: context.packageManager.queryIntentActivities(intent, 0).firstOrNull()?.activityInfo?.packageName
    } catch (e: Exception) {
        null
    }

    /** Tries each candidate; must be called on the main thread with the player activity. */
    fun open(activity: Activity, target: Target, label: String): Result {
        for (intent in candidateIntents(activity, target)) {
            val pkg = resolvePackage(activity, intent) ?: continue
            if (pkg == activity.packageName) continue
            try {
                KioskHelper.allowExternalApp(activity, pkg)
                // Pin the resolved package so a chooser never appears (and it matches the lock-task allow list).
                if (intent.component == null) intent.setPackage(pkg)
                activity.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                val how = intent.data?.let { describeUri(it) } ?: intent.action ?: "launch"
                Log.i(TAG, "Opened $label via $pkg ($how)")
                return Result(true, "Opened $label via $pkg ($how)", pkg)
            } catch (e: ActivityNotFoundException) {
                Log.i(TAG, "$pkg cannot handle ${intent.data}: ${e.message}")
            } catch (e: SecurityException) {
                Log.w(TAG, "$pkg refused: ${e.message}")
            } catch (e: Exception) {
                Log.w(TAG, "open $pkg failed", e)
            }
        }
        KioskHelper.restoreKioskPackages(activity)
        return Result(false, "$label is not available on this TV")
    }

    private fun describeUri(u: Uri): String = if (TvContract.isChannelUriForPassthroughInput(u)) "passthrough" else u.toString()
}
