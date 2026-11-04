package com.hotelcast.tv

import java.util.Locale

/**
 * 2.6.2 direct media links (pure, unit tested in MediaUrlsTest).
 *
 * Owners paste a temple camera / live darshan link into whatever content type they find first: "Web page",
 * "Video" or "Live stream". A WebView cannot play an HLS / RTSP / RTMP link (Chrome shows its empty media page:
 * a black screen with one thin bar in the middle), and a "Video" item would try to download the live
 * playlist into the offline cache. [normalize] therefore turns every item whose link is a media link into the
 * type the native player handles, and [mimeFor] / [fallbackMimes] pick the player format, also for links
 * without a file extension (e.g. `https://host/live/darshan` serving HLS).
 */
object MediaUrls {

    /** Container formats ExoPlayer plays; [AUTO] = let ExoPlayer detect from the link / content. */
    enum class Format { AUTO, HLS, DASH, RTSP, RTMP, PROGRESSIVE }

    private val FILE_EXT = setOf("mp4", "m4v", "mkv", "webm", "mov", "ts", "m2ts", "flv", "3gp", "avi", "mp3", "aac", "m4a", "ogg", "opus", "wav")

    private fun scheme(url: String): String = url.substringBefore("://", "").lowercase(Locale.US)

    /** Lower-case path without query / fragment. */
    private fun path(url: String): String {
        val afterScheme = url.substringAfter("://", url)
        val p = afterScheme.substringAfter('/', "")
        return "/" + p.substringBefore('?').substringBefore('#').lowercase(Locale.US)
    }

    private fun ext(url: String): String {
        val last = path(url).substringAfterLast('/')
        return if (last.contains('.')) last.substringAfterLast('.') else ""
    }

    /** `format=m3u8`, `type=hls`, `output=mpd` … in the query. */
    private fun queryHint(url: String): Format? {
        val q = url.substringAfter('?', "").substringBefore('#').lowercase(Locale.US)
        if (q.isEmpty()) return null
        return when {
            Regex("(^|[&=])(m3u8|hls)(&|$)").containsMatchIn(q) || q.contains(".m3u8") -> Format.HLS
            Regex("(^|[&=])(mpd|dash)(&|$)").containsMatchIn(q) || q.contains(".mpd") -> Format.DASH
            else -> null
        }
    }

    /** The player format a link needs; [Format.AUTO] when only the content can tell. */
    fun formatOf(url: String?): Format {
        if (url.isNullOrBlank()) return Format.AUTO
        val u = url.trim()
        when (scheme(u)) {
            "rtsp", "rtsps" -> return Format.RTSP
            "rtmp", "rtmps", "rtmpt", "rtmpe" -> return Format.RTMP
        }
        return when (ext(u)) {
            "m3u8", "m3u" -> Format.HLS
            "mpd" -> Format.DASH
            in FILE_EXT -> Format.PROGRESSIVE
            else -> queryHint(u) ?: if (path(u).contains("/hls/") || path(u).endsWith("/playlist")) Format.HLS else Format.AUTO
        }
    }

    /** A live / adaptive stream link (HLS, DASH, RTSP, RTMP): never cached, played as a stream. */
    fun isStreamUrl(url: String?): Boolean = when (formatOf(url)) {
        Format.HLS, Format.DASH, Format.RTSP, Format.RTMP -> true
        else -> false
    }

    /** A link the native player should play instead of a WebView (stream or a plain media file). */
    fun isDirectMedia(url: String?): Boolean {
        if (url.isNullOrBlank() || YouTubeUrls.isYouTube(url)) return false
        return isStreamUrl(url) || formatOf(url) == Format.PROGRESSIVE
    }

    /**
     * The item as the player should handle it:
     *  - "Web page" / non-YouTube "YouTube" item with a stream link → stream; with a media file → video (not cached);
     *  - "Video" item with a stream link (.m3u8, rtsp:// …) → stream.
     * Everything else is returned unchanged (same instance).
     */
    fun normalize(item: ContentItem): ContentItem {
        val url = item.url?.trim()
        if (url.isNullOrBlank()) return item
        return when (item.type) {
            ContentItem.TYPE_URL, ContentItem.TYPE_YOUTUBE -> when {
                YouTubeUrls.isYouTube(url) -> item
                isStreamUrl(url) -> item.copy(type = ContentItem.TYPE_STREAM, url = url)
                formatOf(url) == Format.PROGRESSIVE -> item.copy(type = ContentItem.TYPE_STREAM, url = url)
                else -> item
            }
            ContentItem.TYPE_VIDEO -> if (isStreamUrl(url)) item.copy(type = ContentItem.TYPE_STREAM, url = url) else item
            else -> item
        }
    }

    /**
     * Formats to try, in order, for a link whose first attempt failed because the player did not recognise
     * the content (extensionless HLS / DASH links). Explicit formats are not second-guessed.
     */
    fun fallbackMimes(url: String?): List<Format> = when (formatOf(url)) {
        Format.AUTO, Format.PROGRESSIVE -> listOf(Format.HLS, Format.DASH)
        else -> emptyList()
    }

    /**
     * Video size the player view should not trust: with such a size "fit" draws the picture as a thin line
     * (broken SPS / sample aspect ratio from some IP cameras and encoders). The view then fills the screen.
     */
    fun isImplausibleAspect(width: Int, height: Int, pixelRatio: Float): Boolean {
        if (width <= 0 || height <= 0) return false
        val ratio = if (pixelRatio > 0f && !pixelRatio.isNaN() && !pixelRatio.isInfinite()) pixelRatio else 1f
        val aspect = width * ratio / height
        return aspect > MAX_ASPECT || aspect < 1f / MAX_ASPECT
    }

    /** Widest believable picture (ultra-wide banners are ~3.6:1). */
    const val MAX_ASPECT = 4.0f
}
