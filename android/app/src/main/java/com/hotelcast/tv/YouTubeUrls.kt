package com.hotelcast.tv

import java.net.URLDecoder
import java.net.URLEncoder

/**
 * Builds the URL a TV WebView loads for a "youtube" item. Pure Kotlin (no android.net.Uri) so it
 * is unit tested on the JVM.
 *
 * Handles single videos (`/embed/ID`), playlists (`/embed/videoseries?list=PL…`) and channel
 * uploads (`list=UU…`). Watch / youtu.be / playlist / channel page links are converted to embeds.
 * The result always autoplays and loops (a single video needs `playlist=ID` for `loop=1` to work;
 * a list loops as a whole), hides controls, and asks for no related videos (`rel=0`: YouTube still
 * shows same-channel videos at the very end of a non-looping list; looping avoids that end screen).
 * Anything that is not a YouTube link is returned unchanged.
 */
object YouTubeUrls {

    private val VIDEO_ID = Regex("^[A-Za-z0-9_-]{6,20}$")
    private val YT_HOSTS = setOf(
        "youtube.com", "www.youtube.com", "m.youtube.com", "youtube-nocookie.com", "www.youtube-nocookie.com",
    )

    fun forItem(embedUrl: String?, url: String?, mute: Boolean): String {
        val base = embedUrl?.trim()?.takeIf { it.isNotEmpty() } ?: url?.trim().orEmpty()
        return build(base, mute)
    }

    fun build(base: String, mute: Boolean): String {
        val embed = toEmbed(base) ?: return base
        val q = parseQuery(embed.substringAfter('?', ""))
        val path = embed.substringBefore('?')
        val videoId = path.substringAfterLast("/embed/", "").takeIf { it.isNotEmpty() && it != "videoseries" && VIDEO_ID.matches(it) }
        val isList = !q["list"].isNullOrBlank()

        q["autoplay"] = "1"
        if (mute) q["mute"] = "1" else if (q["mute"].isNullOrBlank()) q["mute"] = "0"
        q["loop"] = "1"
        // loop=1 only loops a single video when it is also its own one-entry playlist.
        if (!isList && videoId != null && q["playlist"].isNullOrBlank()) q["playlist"] = videoId
        q.setDefault("controls", "0")
        q["rel"] = "0"
        q.setDefault("playsinline", "1")
        q.setDefault("iv_load_policy", "3")
        q.setDefault("modestbranding", "1")
        q.setDefault("disablekb", "1")
        q.setDefault("fs", "0")
        return path + "?" + q.entries.joinToString("&") { (k, v) -> "${enc(k)}=${enc(v)}" }
    }

    fun isYouTube(url: String?): Boolean = url != null && hostOf(url) in YT_HOSTS + setOf("youtu.be", "www.youtu.be")

    /** Embed URL for a YouTube link, or null when [url] is not one we understand. */
    fun toEmbed(url: String): String? {
        val host = hostOf(url) ?: return null
        val afterHost = url.substringAfter("://").substringAfter('/', "")
        val path = "/" + afterHost.substringBefore('?').substringBefore('#')
        val query = parseQuery(afterHost.substringAfter('?', "").substringBefore('#'))
        val origin = "https://" + (if (host.endsWith("youtube-nocookie.com")) "www.youtube-nocookie.com" else "www.youtube.com")
        fun withQuery(p: String, extra: Map<String, String>): String {
            val m = LinkedHashMap(query)
            m.remove("v"); m.remove("si"); m.remove("feature")
            m.putAll(extra)
            return if (m.isEmpty()) origin + p else origin + p + "?" + m.entries.joinToString("&") { (k, v) -> "${enc(k)}=${enc(v)}" }
        }
        if (host == "youtu.be" || host == "www.youtu.be") {
            val id = path.trim('/').substringBefore('/')
            return if (VIDEO_ID.matches(id)) withQuery("/embed/$id", emptyMap()) else null
        }
        if (host !in YT_HOSTS) return null
        return when {
            path.startsWith("/embed/") -> withQuery(path.trimEnd('/'), emptyMap())
            path == "/watch" -> {
                val id = query["v"]
                val list = query["list"]
                when {
                    id != null && VIDEO_ID.matches(id) -> withQuery("/embed/$id", emptyMap())
                    !list.isNullOrBlank() -> withQuery("/embed/videoseries", emptyMap())
                    else -> null
                }
            }
            path == "/playlist" && !query["list"].isNullOrBlank() -> withQuery("/embed/videoseries", emptyMap())
            path.startsWith("/shorts/") || path.startsWith("/live/") -> {
                val id = path.split('/').getOrNull(2).orEmpty()
                if (VIDEO_ID.matches(id)) withQuery("/embed/$id", emptyMap()) else null
            }
            path.startsWith("/channel/UC") -> {
                // A channel's uploads playlist is its id with UC → UU.
                val ch = path.split('/').getOrNull(2).orEmpty()
                if (ch.length > 2) withQuery("/embed/videoseries", mapOf("list" to "UU" + ch.substring(2))) else null
            }
            else -> null
        }
    }

    private fun MutableMap<String, String>.setDefault(k: String, v: String) {
        if (this[k].isNullOrEmpty()) this[k] = v
    }

    private fun hostOf(url: String): String? {
        val s = url.trim()
        val scheme = s.substringBefore("://", "").lowercase()
        if (scheme != "http" && scheme != "https") return null
        return s.substringAfter("://").substringBefore('/').substringBefore('?').substringBefore('#')
            .substringAfterLast('@').substringBefore(':').lowercase().takeIf { it.isNotEmpty() }
    }

    private fun parseQuery(q: String): LinkedHashMap<String, String> {
        val m = LinkedHashMap<String, String>()
        if (q.isBlank()) return m
        for (part in q.split('&')) {
            if (part.isEmpty()) continue
            val k = dec(part.substringBefore('='))
            if (k.isEmpty()) continue
            m[k] = dec(part.substringAfter('=', ""))
        }
        return m
    }

    private fun dec(s: String): String = try { URLDecoder.decode(s, "UTF-8") } catch (e: Exception) { s }
    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8").replace("+", "%20")
}
