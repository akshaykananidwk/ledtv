package com.hotelcast.tv

import com.google.gson.annotations.SerializedName
import java.util.concurrent.atomic.AtomicInteger

/**
 * 2.6.1 live video sync: `content.live_sync = {refresh_min, max_lag_s}` (customer setting, server
 * core/LiveSync.php). Missing (older server) = defaults 5 min / 60 s. Old apps ignore the field.
 */
data class LiveSyncData(
    @SerializedName("refresh_min") val refreshMin: Int? = null,
    @SerializedName("max_lag_s") val maxLagS: Int? = null,
)

/** Effective live sync settings: [refreshEveryMs] 0 = no periodic re-sync; [maxLagMs] 15..120 s. */
data class LiveSyncConfig(val refreshEveryMs: Long, val maxLagMs: Long) {
    companion object {
        const val DEFAULT_REFRESH_MIN = 5
        const val DEFAULT_MAX_LAG_S = 60
        const val MIN_LAG_S = 15
        /** Never more than 2 minutes behind live (owner's requirement). */
        const val MAX_LAG_S = 120
        const val MAX_REFRESH_MIN = 24 * 60

        fun from(d: LiveSyncData?): LiveSyncConfig {
            val refresh = (d?.refreshMin ?: DEFAULT_REFRESH_MIN).coerceIn(0, MAX_REFRESH_MIN)
            val lag = (d?.maxLagS ?: DEFAULT_MAX_LAG_S).coerceIn(MIN_LAG_S, MAX_LAG_S)
            return LiveSyncConfig(refresh * 60_000L, lag * 1000L)
        }
    }
}

enum class LiveAction { NONE, SEEK_TO_LIVE, RELOAD }

enum class LiveReason { OK, BUFFERING, STALLED, BEHIND, PERIODIC, PERIODIC_IN_SYNC }

data class LiveDecision(val action: LiveAction, val reason: LiveReason) {
    /** The periodic re-sync clock restarts after any re-sync, and when a due re-sync was not needed. */
    val restartsPeriodic: Boolean get() = action != LiveAction.NONE || reason == LiveReason.PERIODIC_IN_SYNC
}

/**
 * Pure decisions of the live-stream watchdog (unit tested in LiveSyncTest). ContentPlayer calls
 * [decide] every [CHECK_MS] while a stream item plays:
 *
 *  1. buffering ≥ 20 s or position frozen ≥ 30 s → RELOAD (full re-prepare of the URL);
 *  2. not a live stream (a VOD file in a "stream" item) → nothing else;
 *  3. more than the max lag behind live (only known for seekable live HLS/DASH) → SEEK_TO_LIVE
 *     (`seekToDefaultPosition()`, no black screen), or RELOAD when the stream cannot seek;
 *  4. periodic re-sync due → SEEK_TO_LIVE for seekable live streams (skipped when already within
 *     the stream's own target offset + 10 s), RELOAD for streams that cannot seek (progressive
 *     HTTP "live" MP3/MP4/TS, RTSP).
 */
object LiveSync {
    const val CHECK_MS = 15_000L
    const val BUFFERING_RELOAD_MS = 20_000L
    const val STALLED_RELOAD_MS = 30_000L
    /** "Already live" margin above the stream's own target offset. */
    const val NEAR_LIVE_SLACK_MS = 10_000L
    /** Gentle catch-up / slow-down range of ExoPlayer's live speed control. */
    const val MAX_PLAYBACK_SPEED = 1.04f
    const val MIN_PLAYBACK_SPEED = 0.98f
    /** WebView (YouTube) live-edge check. */
    const val WEB_CHECK_MS = 30_000L

    /** Re-syncs (seeks + reloads) since the app started; sent as health.live_resyncs. */
    val resyncs = AtomicInteger(0)

    /**
     * @param offsetMs how far behind live (ExoPlayer currentLiveOffset), null when unknown
     * @param seekable the stream can seek (live window), so a jump to live needs no reload
     * @param live a live / unbounded stream (live HLS/DASH, unknown length progressive, RTSP)
     * @param targetOffsetMs the stream's own target live offset, null when unknown
     */
    fun decide(
        offsetMs: Long?,
        maxLagMs: Long,
        bufferingMs: Long,
        stalledMs: Long,
        sinceLastRefreshMs: Long,
        refreshEveryMs: Long,
        seekable: Boolean,
        live: Boolean = true,
        targetOffsetMs: Long? = null,
        bufferingLimitMs: Long = BUFFERING_RELOAD_MS,
        stalledLimitMs: Long = STALLED_RELOAD_MS,
    ): LiveDecision {
        if (bufferingMs >= bufferingLimitMs) return LiveDecision(LiveAction.RELOAD, LiveReason.BUFFERING)
        if (stalledMs >= stalledLimitMs) return LiveDecision(LiveAction.RELOAD, LiveReason.STALLED)
        if (!live) return LiveDecision(LiveAction.NONE, LiveReason.OK)
        val nearLive = (targetOffsetMs ?: 0L).coerceAtLeast(0L) + NEAR_LIVE_SLACK_MS
        // A stream whose own default position is beyond the limit would otherwise seek in a loop.
        val limit = maxOf(maxLagMs.coerceIn(LiveSyncConfig.MIN_LAG_S * 1000L, LiveSyncConfig.MAX_LAG_S * 1000L), nearLive)
        if (offsetMs != null && offsetMs > limit) {
            return LiveDecision(if (seekable) LiveAction.SEEK_TO_LIVE else LiveAction.RELOAD, LiveReason.BEHIND)
        }
        if (refreshEveryMs > 0 && sinceLastRefreshMs >= refreshEveryMs) {
            if (!seekable) return LiveDecision(LiveAction.RELOAD, LiveReason.PERIODIC)
            if (offsetMs != null && offsetMs <= nearLive) return LiveDecision(LiveAction.NONE, LiveReason.PERIODIC_IN_SYNC)
            return LiveDecision(LiveAction.SEEK_TO_LIVE, LiveReason.PERIODIC)
        }
        return LiveDecision(LiveAction.NONE, LiveReason.OK)
    }

    /** 2.6.2: first start of a stream on a slow line may buffer this long before a reload. */
    const val FIRST_START_LIMIT_MS = 60_000L
    /** Longest wait between reloads of a stream that keeps buffering (slow internet). */
    const val MAX_RELOAD_LIMIT_MS = 120_000L
    /** After this many jumps to live that did not reduce the delay, the delay figure is not trusted. */
    const val MAX_FUTILE_SEEKS = 2

    /**
     * 2.6.2: how long a stream may buffer / freeze before it is reloaded. 2.6.1 reloaded after a fixed 20 s, so a
     * stream that needs longer to start on a slow temple line was reloaded for ever and never showed a picture.
     * Before the first picture: [FIRST_START_LIMIT_MS]; then 20 s, doubling with every reload in a row that
     * did not get the stream playing again, capped at [MAX_RELOAD_LIMIT_MS].
     */
    fun reloadLimitMs(base: Long, reloadsInRow: Int, everReady: Boolean): Long {
        if (!everReady) return maxOf(base, FIRST_START_LIMIT_MS) shl reloadsInRow.coerceIn(0, 1)
        return (base shl reloadsInRow.coerceIn(0, 3)).coerceAtMost(MAX_RELOAD_LIMIT_MS)
    }

    /**
     * 2.6.2: the delay ExoPlayer reports comes from the stream's own clock (PROGRAM-DATE-TIME) and the TV clock.
     * When either is wrong (TV date after a power cut, camera clock), the stream looks minutes behind for ever
     * and 2.6.1 jumped to live every 15 s — the picture never settled. A jump that does not bring the delay
     * down by at least a quarter counts as futile; after [MAX_FUTILE_SEEKS] of them the delay is ignored.
     */
    fun seekWasFutile(offsetBeforeMs: Long?, offsetAfterMs: Long?): Boolean =
        offsetBeforeMs != null && offsetAfterMs != null && offsetAfterMs > offsetBeforeMs * 3 / 4

    /** YouTube live links: `/live/ID`, `/@channel/live`, `/channel/UC…/live`, `embed/live_stream?channel=`. */
    fun isYouTubeLiveUrl(url: String?): Boolean {
        if (url.isNullOrBlank() || !YouTubeUrls.isYouTube(url)) return false
        val path = url.substringAfter("://").substringAfter('/', "").substringBefore('#')
        val p = path.substringBefore('?').lowercase()
        return p.startsWith("live/") || p.endsWith("/live") || p.contains("/live/") || p.contains("live_stream") ||
            path.contains("live_stream")
    }

    /**
     * JavaScript run inside a YouTube embed page (WebView): when the video is live and more than
     * [maxLagS] behind the live edge, jumps to it (player API `seekTo(getDuration())`, else the
     * `<video>` element's seekable end). Returns "vod", "none", "live:<lag>" or "seek:<lag>".
     */
    fun youTubeLiveEdgeJs(maxLagS: Int): String = """
        (function (maxLag) {
          try {
            var v = document.querySelector('video');
            var p = document.getElementById('movie_player');
            if (!v && !p) return 'none';
            var live = false;
            try { var d = p && p.getVideoData ? p.getVideoData() : null; live = !!(d && d.isLive); } catch (e) {}
            if (v && v.duration === Infinity) live = true;
            if (!live) return 'vod';
            var edge = -1, lag = -1;
            if (v && v.seekable && v.seekable.length) edge = v.seekable.end(v.seekable.length - 1);
            if (edge > 0) lag = edge - v.currentTime;
            if (lag > maxLag) {
              if (p && p.seekTo && p.getDuration) p.seekTo(p.getDuration(), true);
              else v.currentTime = Math.max(0, edge - 2);
              return 'seek:' + Math.round(lag);
            }
            return 'live:' + Math.round(lag);
          } catch (e) { return 'none'; }
        })($maxLagS)
    """.trimIndent()

    /** Result string of [youTubeLiveEdgeJs] (evaluateJavascript wraps it in JSON quotes). */
    fun parseWebResult(raw: String?): String = raw?.trim()?.removeSurrounding("\"").orEmpty()
}
