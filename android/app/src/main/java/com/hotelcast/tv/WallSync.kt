@file:Suppress("DEPRECATION") // ExoPlayer 2.x is deprecated in favour of Media3; 2.19.1 is used deliberately.

package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.os.SystemClock
import android.view.Gravity
import android.view.LayoutInflater
import android.view.View
import android.widget.FrameLayout
import com.google.android.exoplayer2.ui.StyledPlayerView

/**
 * 2.4 (#37): the app's server clock. [SyncManager] feeds every poll round trip in; [ContentPlayer]
 * asks [nowMs] for the synced schedule. Monotonic local clock (elapsedRealtime). Before the first
 * poll (cold start from the cached content) the TV's own wall clock is used, which is NTP-synced on
 * most TVs, and the next poll corrects it.
 */
object ServerClock {
    val clock = SyncClock({ SystemClock.elapsedRealtime() })

    fun localNow(): Long = SystemClock.elapsedRealtime()

    /** One poll: [sentAt] = [localNow] before the request; [serverMs] = `server_time_ms` (null on old servers). */
    fun onResponse(sentAt: Long, serverMs: Long?) {
        if (serverMs == null || serverMs <= 0) return
        clock.addSample(sentAt, localNow(), serverMs)
    }

    fun nowMs(): Long = clock.serverNowMs() ?: System.currentTimeMillis()
}

/**
 * 2.4 video wall (#36): applies [WallGeometry] to the item views of [ContentPlayer]. Every view on a
 * wall is laid out at the wall's aspect ratio and scaled / moved with view properties, so the tile shows
 * only its part. That works for every view that draws through the normal view hierarchy (ImageView,
 * WebView, text, clocks) — but not for a SurfaceView, so wall videos use a TextureView-based
 * StyledPlayerView ([playerView]). The normal (non-wall) path is untouched.
 */
object WallLayout {
    /** Lay out [view] (a direct child of [stage]) for this tile. Safe to call again on every stage resize. */
    fun apply(view: View, wall: WallGeometry, stage: View) {
        val dm = view.resources.displayMetrics
        val w = stage.width.takeIf { it > 0 } ?: dm.widthPixels
        val h = stage.height.takeIf { it > 0 } ?: dm.heightPixels
        val t = wall.transform(w, h)
        val lp = (view.layoutParams as? FrameLayout.LayoutParams) ?: FrameLayout.LayoutParams(t.viewW, t.viewH)
        if (lp.width != t.viewW || lp.height != t.viewH || lp.gravity != (Gravity.TOP or Gravity.START)) {
            lp.width = t.viewW
            lp.height = t.viewH
            lp.gravity = Gravity.TOP or Gravity.START
            view.layoutParams = lp
        }
        view.pivotX = 0f
        view.pivotY = 0f
        view.scaleX = t.scale
        view.scaleY = t.scale
        view.translationX = t.translateX
        view.translationY = t.translateY
    }

    /** StyledPlayerView whose video surface is a TextureView (transformable), controller off. */
    @SuppressLint("InflateParams") // added to the stage by ContentPlayer, which sets its layout params
    fun playerView(context: Context): StyledPlayerView =
        LayoutInflater.from(context).inflate(R.layout.hc_wall_player_view, null, false) as StyledPlayerView
}
