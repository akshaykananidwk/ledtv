package com.hotelcast.tv

import android.content.Context
import android.graphics.Color
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.TextView

/**
 * Plays a split-screen layout item: one [ContentPlayer] per zone (in "zone mode"), each looping
 * its own items with its own transition and scale. Created by the stage's [ContentPlayer], which
 * keeps reporting the layout item itself to analytics; zones never report.
 *
 * Video decoders are budgeted by [DecoderPlanner]: zones beyond the device limit play only their
 * non-video items (or a black placeholder with the title). If a second decoder still fails at
 * runtime, the limit for this TV is lowered and that zone drops its video, without a crash.
 *
 * [release] stops every zone player (ExoPlayers, WebViews, handlers). Main thread only.
 */
class LayoutPlayer(
    private val context: Context,
    private val cache: ContentCache,
    private val overlay: Overlay?,
    private val spec: LayoutSpec,
) {
    val view: ZoneLayout = ZoneLayout(context).apply {
        setBackgroundColor(Utils.parseColor(spec.bgColor, Color.BLACK))
    }

    private val handler = Handler(Looper.getMainLooper())
    private val players = arrayOfNulls<ContentPlayer>(spec.zones.size)
    private val stages = arrayOfNulls<FrameLayout>(spec.zones.size)
    private val placeholders = arrayOfNulls<TextView>(spec.zones.size)
    private var plan = DecoderPlanner.plan(spec.zones, currentLimit())
    private var released = false

    fun start() {
        spec.zones.forEachIndexed { i, zone ->
            val root = FrameLayout(context)
            val stage = FrameLayout(context)
            root.addView(stage, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
            val ph = TextView(context).apply {
                setBackgroundColor(Color.BLACK)
                setTextColor(Color.parseColor("#9E9E9E"))
                setTextSize(TypedValue.COMPLEX_UNIT_SP, 22f)
                gravity = Gravity.CENTER
                text = DecoderPlanner.placeholderTitle(zone)
                visibility = View.GONE
            }
            root.addView(ph, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
            stages[i] = stage
            placeholders[i] = ph
            view.addView(root, ZoneLayout.LayoutParams(zone))
            startZone(i)
        }
        Log.i(TAG, "Layout: ${spec.zones.size} zones, video in ${plan.videoZones}, sound in ${plan.audioZone}, decoder limit ${currentLimit()}")
    }

    private fun startZone(i: Int) {
        if (released) return
        val zone = spec.zones[i]
        val stage = stages[i] ?: return
        val items = DecoderPlanner.zoneItems(zone, plan.allowsVideo(i)).let { list ->
            // A single looping item (typically a video) repeats instead of freezing on its last frame.
            if (zone.loop && list.size == 1 && list[0].loop == null) listOf(list[0].copy(loop = true)) else list
        }
        if (items.isEmpty()) {
            showPlaceholder(i, true)
            return
        }
        val ph = placeholders[i]
        val p = ContentPlayer(context, stage, cache, object : ContentPlayer.Listener {
            override fun onItemStarted(item: ContentItem) {
                ph?.visibility = View.GONE
            }

            override fun onItemFinished(item: ContentItem, startedAtMillis: Long, durationSec: Int) = Unit

            override fun onNothingToPlay() {
                ph?.visibility = View.VISIBLE
            }
        }, zoneMode = true)
        p.scaleMode = zone.scale
        p.forceMute = plan.mutes(i) || zone.mute
        p.decoderErrorHandler = { onDecoderError(i) }
        players[i] = p
        try {
            p.setContent(
                Content(
                    hash = null,
                    mode = "layout_zone",
                    items = items,
                    playlist = Playlist(transition = zone.transition, loop = zone.loop),
                    overlay = overlay,
                ),
                force = true,
            )
        } catch (e: Throwable) {
            Log.e(TAG, "zone ${zone.id} failed to start", e)
            showPlaceholder(i, true)
        }
    }

    private fun showPlaceholder(i: Int, show: Boolean) {
        placeholders[i]?.visibility = if (show) View.VISIBLE else View.GONE
    }

    /**
     * A zone's ExoPlayer could not get a decoder. With other video zones running this is the device
     * limit: remember a lower limit and replay this zone without video. Returns false (normal error
     * handling: skip / retry) when this was the only video zone.
     */
    private fun onDecoderError(i: Int): Boolean {
        if (released || !plan.allowsVideo(i) || plan.videoZones.size <= 1) return false
        val newLimit = DecoderPlanner.lowerLimit(plan.videoZones.size)
        Log.w(TAG, "Decoder failure in zone ${spec.zones[i].id} with ${plan.videoZones.size} video zones; limit -> $newLimit")
        try {
            if (Prefs.isInitialized && Prefs.maxVideoDecoders > newLimit) Prefs.maxVideoDecoders = newLimit
        } catch (e: Exception) {
            Log.w(TAG, "cannot save decoder limit", e)
        }
        plan = plan.copy(videoZones = plan.videoZones - i)
        handler.post {
            if (released) return@post
            players[i]?.release()
            players[i] = null
            startZone(i)
        }
        return true
    }

    fun release() {
        if (released) return
        released = true
        handler.removeCallbacksAndMessages(null)
        for (i in players.indices) {
            try {
                players[i]?.release()
            } catch (e: Throwable) {
                Log.w(TAG, "zone release failed", e)
            }
            players[i] = null
        }
        view.removeAllViews()
    }

    private fun currentLimit(): Int = try {
        if (Prefs.isInitialized) Prefs.maxVideoDecoders else DecoderPlanner.DEFAULT_MAX_DECODERS
    } catch (e: Exception) {
        DecoderPlanner.DEFAULT_MAX_DECODERS
    }

    companion object {
        private const val TAG = "LayoutPlayer"
    }
}

/**
 * Positions each child by its zone's percent rectangle ([ZoneSpec.pixelRect]). Re-measured on every
 * size change of the stage (e.g. when the ticker reserves space), so zones always fill it exactly.
 */
class ZoneLayout(context: Context) : ViewGroup(context) {

    class LayoutParams(val zone: ZoneSpec?) : ViewGroup.LayoutParams(MATCH_PARENT, MATCH_PARENT)

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val w = MeasureSpec.getSize(widthMeasureSpec)
        val h = MeasureSpec.getSize(heightMeasureSpec)
        setMeasuredDimension(w, h)
        for (i in 0 until childCount) {
            val c = getChildAt(i)
            val r = rectOf(c, w, h)
            c.measure(
                MeasureSpec.makeMeasureSpec(r.width, MeasureSpec.EXACTLY),
                MeasureSpec.makeMeasureSpec(r.height, MeasureSpec.EXACTLY),
            )
        }
    }

    override fun onLayout(changed: Boolean, l: Int, t: Int, r: Int, b: Int) {
        val w = r - l
        val h = b - t
        for (i in 0 until childCount) {
            val c = getChildAt(i)
            val rect = rectOf(c, w, h)
            c.layout(rect.left, rect.top, rect.right, rect.bottom)
        }
    }

    private fun rectOf(c: View, w: Int, h: Int): PxRect =
        (c.layoutParams as? LayoutParams)?.zone?.pixelRect(w, h) ?: PxRect(0, 0, w, h)

    override fun generateDefaultLayoutParams(): ViewGroup.LayoutParams = LayoutParams(null)
    override fun checkLayoutParams(p: ViewGroup.LayoutParams?): Boolean = p is LayoutParams
    override fun generateLayoutParams(p: ViewGroup.LayoutParams?): ViewGroup.LayoutParams = LayoutParams(null)
    override fun shouldDelayChildPressedState(): Boolean = false
}
