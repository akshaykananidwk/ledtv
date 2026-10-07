package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class YouTubeUrlsTest {

    private fun params(url: String): Map<String, String> =
        url.substringAfter('?', "").split('&').filter { it.isNotEmpty() }
            .associate { it.substringBefore('=') to java.net.URLDecoder.decode(it.substringAfter('=', ""), "UTF-8") }

    @Test fun serverPlaylistEmbedAutoplaysAndLoopsWholeList() {
        val u = YouTubeUrls.build("https://www.youtube.com/embed/videoseries?list=PLabc123&autoplay=1&mute=0&loop=1", mute = false)
        assertTrue(u.startsWith("https://www.youtube.com/embed/videoseries?"))
        val p = params(u)
        assertEquals("PLabc123", p["list"])
        assertEquals("1", p["autoplay"])
        assertEquals("0", p["mute"])
        assertEquals("1", p["loop"])
        assertEquals("0", p["rel"])
        assertEquals("0", p["controls"])
        assertEquals("1", p["playsinline"])
        assertNull("a list loops as a whole; no playlist= override", p["playlist"])
    }

    @Test fun channelUploadsList() {
        val p = params(YouTubeUrls.build("https://www.youtube.com/embed/videoseries?list=UUxyz&autoplay=1&mute=0&loop=1", mute = false))
        assertEquals("UUxyz", p["list"])
        assertEquals("1", p["loop"])
        assertEquals("1", p["autoplay"])
    }

    @Test fun channelPageBecomesUploadsPlaylist() {
        val u = YouTubeUrls.build("https://www.youtube.com/channel/UCabcdef", mute = false)
        assertTrue(u.startsWith("https://www.youtube.com/embed/videoseries?"))
        assertEquals("UUabcdef", params(u)["list"])
    }

    @Test fun playlistPageAndWatchWithList() {
        assertEquals("PL1", params(YouTubeUrls.build("https://www.youtube.com/playlist?list=PL1", false))["list"])
        val w = YouTubeUrls.build("https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL2", false)
        assertTrue(w.startsWith("https://www.youtube.com/embed/dQw4w9WgXcQ?"))
        assertEquals("PL2", params(w)["list"])
        assertNull(params(w)["playlist"])
    }

    @Test fun singleVideoLoopsItself() {
        val u = YouTubeUrls.build("https://www.youtube.com/embed/dQw4w9WgXcQ", mute = false)
        val p = params(u)
        assertEquals("1", p["autoplay"])
        assertEquals("1", p["loop"])
        assertEquals("dQw4w9WgXcQ", p["playlist"])
        assertEquals("0", p["mute"])
        assertEquals("0", p["rel"])
    }

    @Test fun shortLinksAndWatchLinksBecomeEmbeds() {
        assertTrue(YouTubeUrls.build("https://youtu.be/dQw4w9WgXcQ?si=track", false).startsWith("https://www.youtube.com/embed/dQw4w9WgXcQ?"))
        val w = YouTubeUrls.build("https://m.youtube.com/watch?v=dQw4w9WgXcQ&feature=share", false)
        assertTrue(w.startsWith("https://www.youtube.com/embed/dQw4w9WgXcQ?"))
        assertNull(params(w)["feature"])
        assertNull(params(w)["v"])
    }

    @Test fun muteForcedForSilentZoneOrItem() {
        val p = params(YouTubeUrls.build("https://www.youtube.com/embed/videoseries?list=PL1&autoplay=1&mute=0&loop=1", mute = true))
        assertEquals("1", p["mute"])
        // server's own mute=1 is kept when the item does not force it
        assertEquals("1", params(YouTubeUrls.build("https://www.youtube.com/embed/abcdefghijk?mute=1", mute = false))["mute"])
    }

    @Test fun serverValuesNotDuplicatedAndAutoplayForced() {
        val u = YouTubeUrls.build("https://www.youtube.com/embed/abcdefghijk?autoplay=0&controls=1&loop=0", mute = false)
        assertEquals(1, Regex("autoplay=").findAll(u).count())
        val p = params(u)
        assertEquals("1", p["autoplay"])
        assertEquals("1", p["loop"])
        assertEquals("server choice kept", "1", p["controls"])
    }

    @Test fun nocookieHostKept() {
        assertTrue(YouTubeUrls.build("https://www.youtube-nocookie.com/embed/videoseries?list=PL1", false).startsWith("https://www.youtube-nocookie.com/embed/videoseries?"))
    }

    @Test fun nonYouTubeUnchanged() {
        assertEquals("https://example.com/video?x=1", YouTubeUrls.build("https://example.com/video?x=1", false))
        assertEquals("https://evil.com/embed/x?youtube.com", YouTubeUrls.build("https://evil.com/embed/x?youtube.com", false))
        assertEquals("not a url", YouTubeUrls.build("not a url", false))
        assertFalse(YouTubeUrls.isYouTube("https://example.com/"))
        assertTrue(YouTubeUrls.isYouTube("https://www.youtube.com/embed/abc"))
    }

    @Test fun itemPrefersEmbedUrl() {
        val u = YouTubeUrls.forItem("https://www.youtube.com/embed/videoseries?list=PL9", "https://www.youtube.com/playlist?list=PL9", mute = false)
        assertEquals("PL9", params(u)["list"])
        val fallback = YouTubeUrls.forItem(" ", "https://youtu.be/dQw4w9WgXcQ", mute = true)
        assertEquals("1", params(fallback)["mute"])
    }
}
