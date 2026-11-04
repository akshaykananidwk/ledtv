package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test

/** 2.6.2 direct media links: format detection, item normalisation, aspect guard (MediaUrls.kt). */
class MediaUrlsTest {

    @Test fun detectsFormats() {
        assertEquals(MediaUrls.Format.HLS, MediaUrls.formatOf("https://cdn.example.com/live/darshan.m3u8"))
        assertEquals(MediaUrls.Format.HLS, MediaUrls.formatOf("https://cdn.example.com/live/index.M3U8?token=abc"))
        assertEquals(MediaUrls.Format.HLS, MediaUrls.formatOf("https://cdn.example.com/hls/stream1"))
        assertEquals(MediaUrls.Format.HLS, MediaUrls.formatOf("https://cdn.example.com/play?format=m3u8"))
        assertEquals(MediaUrls.Format.DASH, MediaUrls.formatOf("https://cdn.example.com/live/manifest.mpd"))
        assertEquals(MediaUrls.Format.RTSP, MediaUrls.formatOf("rtsp://192.168.1.10:554/live/ch0"))
        assertEquals(MediaUrls.Format.RTSP, MediaUrls.formatOf("RTSPS://cam.example.com/live"))
        assertEquals(MediaUrls.Format.RTMP, MediaUrls.formatOf("rtmp://live.example.com/app/stream"))
        assertEquals(MediaUrls.Format.PROGRESSIVE, MediaUrls.formatOf("https://example.com/aarti.mp4"))
        assertEquals(MediaUrls.Format.PROGRESSIVE, MediaUrls.formatOf("http://10.0.0.5:8080/stream.ts"))
        assertEquals(MediaUrls.Format.AUTO, MediaUrls.formatOf("https://example.com/live/darshan"))
        assertEquals(MediaUrls.Format.AUTO, MediaUrls.formatOf("https://www.dwarkadhish.org/"))
        assertEquals(MediaUrls.Format.AUTO, MediaUrls.formatOf(null))
        assertEquals(MediaUrls.Format.AUTO, MediaUrls.formatOf("  "))
    }

    @Test fun streamAndDirectMedia() {
        assertTrue(MediaUrls.isStreamUrl("https://x.com/a.m3u8"))
        assertTrue(MediaUrls.isStreamUrl("rtsp://1.2.3.4/x"))
        assertFalse(MediaUrls.isStreamUrl("https://x.com/a.mp4"))
        assertTrue(MediaUrls.isDirectMedia("https://x.com/a.mp4"))
        assertFalse(MediaUrls.isDirectMedia("https://x.com/page.html"))
        assertFalse(MediaUrls.isDirectMedia("https://www.youtube.com/watch?v=abc&format=m3u8"))
    }

    @Test fun webPageWithStreamLinkPlaysNatively() {
        val web = ContentItem(id = 7, type = ContentItem.TYPE_URL, title = "Live Darshan", url = " https://cdn.example.com/live/darshan.m3u8 ")
        val n = MediaUrls.normalize(web)
        assertEquals(ContentItem.TYPE_STREAM, n.type)
        assertEquals("https://cdn.example.com/live/darshan.m3u8", n.url)
        assertEquals(7L, n.id)
        assertEquals("Live Darshan", n.title)
        // Deterministic: the same item normalises to an equal item (keeps the playing item across polls).
        assertEquals(n, MediaUrls.normalize(web))
    }

    @Test fun webPageWithVideoFileAndRtmp() {
        assertEquals(ContentItem.TYPE_STREAM, MediaUrls.normalize(ContentItem(type = ContentItem.TYPE_URL, url = "https://x.com/a.mp4")).type)
        assertEquals(ContentItem.TYPE_STREAM, MediaUrls.normalize(ContentItem(type = ContentItem.TYPE_URL, url = "rtmp://x.com/live/k")).type)
    }

    @Test fun videoItemWithLiveLinkBecomesStream() {
        val v = ContentItem(type = ContentItem.TYPE_VIDEO, url = "https://x.com/live.m3u8", loop = true)
        assertEquals(ContentItem.TYPE_STREAM, MediaUrls.normalize(v).type)
        // A real video file stays a cached video.
        val f = ContentItem(type = ContentItem.TYPE_VIDEO, url = "https://x.com/a.mp4")
        assertSame(f, MediaUrls.normalize(f))
    }

    @Test fun normalPagesAndYouTubeUnchanged() {
        val page = ContentItem(type = ContentItem.TYPE_URL, url = "https://www.dwarkadhish.org/live")
        assertSame(page, MediaUrls.normalize(page))
        val yt = ContentItem(type = ContentItem.TYPE_YOUTUBE, url = "https://www.youtube.com/live/abc123", embedUrl = "https://www.youtube.com/embed/abc123")
        assertSame(yt, MediaUrls.normalize(yt))
        val ytUrl = ContentItem(type = ContentItem.TYPE_URL, url = "https://youtu.be/abc123")
        assertSame(ytUrl, MediaUrls.normalize(ytUrl))
        val img = ContentItem(type = ContentItem.TYPE_IMAGE, url = "https://x.com/a.m3u8")
        assertSame(img, MediaUrls.normalize(img))
        val empty = ContentItem(type = ContentItem.TYPE_URL, url = null)
        assertSame(empty, MediaUrls.normalize(empty))
    }

    @Test fun playableItemsAreNormalisedAndStreamsNotCached() {
        val c = ApiClient.gson.fromJson(
            """{"hash":"h","items":[{"id":1,"type":"url","url":"https://x.com/live/darshan.m3u8"},{"id":2,"type":"video","url":"https://x.com/live.m3u8"},{"id":3,"type":"video","url":"https://x.com/a.mp4"}]}""",
            Content::class.java,
        )
        val items = c.playableItems()
        assertEquals(listOf("stream", "stream", "video"), items.map { it.type })
        assertNull(items[1].cacheableUrl())
        assertNull(ContentItem(type = ContentItem.TYPE_VIDEO, url = "https://x.com/live.m3u8").cacheableUrl())
        assertEquals("https://x.com/a.mp4", items[2].cacheableUrl())
    }

    @Test fun layoutZonesAreNormalised() {
        val spec = LayoutSpec.from(
            LayoutData(zones = listOf(LayoutZoneData(id = "a", x = 0.0, y = 0.0, w = 100.0, h = 100.0, items = listOf(ContentItem(type = "url", url = "rtsp://1.2.3.4/cam"))))),
        )
        assertEquals(ContentItem.TYPE_STREAM, spec!!.zones[0].items[0].type)
    }

    @Test fun fallbacksOnlyForUnknownLinks() {
        assertEquals(listOf(MediaUrls.Format.HLS, MediaUrls.Format.DASH), MediaUrls.fallbackMimes("https://x.com/live/darshan"))
        assertEquals(listOf(MediaUrls.Format.HLS, MediaUrls.Format.DASH), MediaUrls.fallbackMimes("https://x.com/a.mp4"))
        assertTrue(MediaUrls.fallbackMimes("https://x.com/a.m3u8").isEmpty())
        assertTrue(MediaUrls.fallbackMimes("rtsp://x/a").isEmpty())
    }

    @Test fun implausibleAspect() {
        assertFalse(MediaUrls.isImplausibleAspect(1920, 1080, 1f))
        assertFalse(MediaUrls.isImplausibleAspect(1080, 1920, 1f)) // portrait camera
        assertFalse(MediaUrls.isImplausibleAspect(720, 576, 1.4222f)) // PAL anamorphic 16:9
        assertTrue(MediaUrls.isImplausibleAspect(1920, 8, 1f)) // the "thin line"
        assertTrue(MediaUrls.isImplausibleAspect(1920, 1080, 40f)) // broken sample aspect ratio
        assertTrue(MediaUrls.isImplausibleAspect(16, 1080, 1f))
        assertFalse(MediaUrls.isImplausibleAspect(0, 0, 1f)) // unknown size: leave as is
        assertFalse(MediaUrls.isImplausibleAspect(1920, 1080, Float.NaN))
    }

    @Test fun redactsLinksForLogs() {
        assertEquals("rtsp://192.168.1.10/live", Utils.redactUrl("rtsp://admin:secret@192.168.1.10/live?channel=1"))
        assertEquals("https://x.com/a.m3u8", Utils.redactUrl("https://x.com/a.m3u8?token=abc#t"))
        assertEquals("", Utils.redactUrl(null))
    }
}
