package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class LayoutSpecTest {

    private val sample = """
    {
      "hash": "abc", "mode": "assigned",
      "items": [
        { "id": 12, "type": "layout", "title": "Lobby split", "duration": 60,
          "layout": { "bg_color": "#101010",
            "zones": [
              { "id": "z1", "x": 0, "y": 0, "w": 70, "h": 100, "loop": true, "transition": "fade", "scale": "fill", "mute": false,
                "items": [
                  { "id": 1, "type": "video", "title": "Promo", "duration": 0, "url": "https://x/api/media/1.mp4" },
                  { "id": 2, "type": "image", "duration": 8, "url": "https://x/api/media/2.jpg" }
                ] },
              { "id": "z2", "x": 70, "y": 0, "w": 30, "h": 50.5, "transition": "none", "scale": "zoom", "mute": "1",
                "items": [ { "id": 3, "type": "clock", "style": "analog" } ] },
              { "id": "z3", "x": 70.0, "y": 50.5, "w": 30, "h": 49.5, "loop": 0,
                "items": [
                  { "id": 4, "type": "url", "url": "https://x/apps/token/1" },
                  { "id": 5, "type": "layout", "layout": { "zones": [ { "items": [ { "id": 6, "type": "clock" } ] } ] } },
                  { "id": 7, "type": "hologram" }
                ] }
            ] } },
        { "id": 13, "type": "image", "duration": 10, "url": "https://x/api/media/13.jpg" }
      ]
    }
    """.trimIndent()

    private fun zone(id: String? = "z", x: Double? = 0.0, y: Double? = 0.0, w: Double? = 50.0, h: Double? = 50.0, items: List<ContentItem?>? = listOf(clock())) =
        LayoutZoneData(id = id, x = x, y = y, w = w, h = h, items = items)

    private fun clock(id: Long = 1) = ContentItem(id = id, type = ContentItem.TYPE_CLOCK)
    private fun video(id: Long, title: String? = null) = ContentItem(id = id, type = ContentItem.TYPE_VIDEO, url = "https://x/$id.mp4", title = title)
    private fun image(id: Long) = ContentItem(id = id, type = ContentItem.TYPE_IMAGE, url = "https://x/$id.jpg")

    @Test fun parsesSampleJson() {
        val c = ApiClient.gson.fromJson(sample, Content::class.java)
        val items = c.playableItems()
        assertEquals(listOf(12L, 13L), items.map { it.id })
        val layoutItem = items[0]
        assertEquals(ContentItem.TYPE_LAYOUT, layoutItem.type)
        assertEquals(60, layoutItem.durationSec)
        val spec = LayoutSpec.from(layoutItem.layout)!!
        assertEquals("#101010", spec.bgColor)
        assertEquals(listOf("z1", "z2", "z3"), spec.zones.map { it.id })

        val z1 = spec.zones[0]
        assertEquals(70f, z1.w, 0.001f)
        assertEquals(ScaleMode.FILL, z1.scale)
        assertEquals("fade", z1.transition)
        assertFalse(z1.mute)
        assertTrue(z1.loop)
        assertEquals(listOf(1L, 2L), z1.items.map { it.id })

        val z2 = spec.zones[1]
        assertEquals(50.5f, z2.h, 0.001f)
        assertEquals(ScaleMode.ZOOM, z2.scale)
        assertEquals("none", z2.transition)
        assertTrue("lenient \"1\" boolean", z2.mute)

        val z3 = spec.zones[2]
        assertFalse("lenient 0 boolean", z3.loop)
        assertEquals("nested layout and unknown types dropped", listOf(4L), z3.items.map { it.id })
        assertEquals(ScaleMode.FIT, z3.scale)
    }

    @Test fun cacheableUrlsIncludeZoneMedia() {
        val c = ApiClient.gson.fromJson(sample, Content::class.java)
        val urls = ContentCache.mediaUrls(c)
        assertTrue(urls.contains("https://x/api/media/1.mp4"))
        assertTrue(urls.contains("https://x/api/media/2.jpg"))
        assertTrue(urls.contains("https://x/api/media/13.jpg"))
    }

    @Test fun layoutWithoutUsableZonesIsNotPlayable() {
        assertFalse(ContentItem(id = 1, type = ContentItem.TYPE_LAYOUT).isPlayable())
        assertFalse(ContentItem(id = 1, type = ContentItem.TYPE_LAYOUT, layout = LayoutData(zones = emptyList())).isPlayable())
        assertFalse(ContentItem(id = 1, type = ContentItem.TYPE_LAYOUT, layout = LayoutData(zones = listOf(zone(items = listOf(ContentItem(type = "x")))))).isPlayable())
        assertTrue(ContentItem(id = 1, type = ContentItem.TYPE_LAYOUT, layout = LayoutData(zones = listOf(zone()))).isPlayable())
        assertNull(LayoutSpec.from(null))
    }

    @Test fun clampsToStage() {
        val spec = LayoutSpec.from(LayoutData(zones = listOf(zone(x = -10.0, y = 90.0, w = 500.0, h = 30.0))))!!
        val z = spec.zones.single()
        assertEquals(0f, z.x, 0f)
        assertEquals(90f, z.y, 0f)
        assertEquals(100f, z.w, 0f)
        assertEquals("never past the bottom edge", 10f, z.h, 0.0001f)
    }

    @Test fun missingGeometryMeansFullStage() {
        val z = LayoutSpec.from(LayoutData(zones = listOf(zone(x = null, y = null, w = null, h = null))))!!.zones.single()
        assertEquals(listOf(0f, 0f, 100f, 100f), listOf(z.x, z.y, z.w, z.h))
    }

    @Test fun dropsZeroSizeAndEmptyZones() {
        val spec = LayoutSpec.from(
            LayoutData(
                zones = listOf(
                    zone(id = "a", w = 0.0),
                    zone(id = "b", h = -5.0),
                    zone(id = "c", x = 100.0),
                    zone(id = "d", items = emptyList()),
                    zone(id = "e", items = listOf(null, ContentItem(type = ContentItem.TYPE_IMAGE))),
                    null,
                    zone(id = "ok"),
                ),
            ),
        )!!
        assertEquals(listOf("ok"), spec.zones.map { it.id })
    }

    @Test fun capsAtSixZones() {
        val zones = (1..9).map { zone(id = "z$it", x = (it - 1) * 10.0, w = 10.0) }
        val spec = LayoutSpec.from(LayoutData(zones = zones))!!
        assertEquals(LayoutSpec.MAX_ZONES, spec.zones.size)
        assertEquals("z1", spec.zones.first().id)
        assertEquals("z6", spec.zones.last().id)
    }

    @Test fun uniqueIdsAndDefaults() {
        val spec = LayoutSpec.from(LayoutData(zones = listOf(zone(id = null), zone(id = "dup"), zone(id = "dup"), zone(id = " "))))!!
        val ids = spec.zones.map { it.id }
        assertEquals(ids.size, ids.toSet().size)
        assertEquals("z1", ids[0])
        val z = spec.zones[0]
        assertTrue(z.loop)
        assertFalse(z.mute)
        assertEquals("fade", z.transition)
        assertEquals(ScaleMode.FIT, z.scale)
    }

    @Test fun scaleParsing() {
        assertEquals(ScaleMode.FIT, LayoutSpec.parseScale(null))
        assertEquals(ScaleMode.FIT, LayoutSpec.parseScale("fit"))
        assertEquals(ScaleMode.FILL, LayoutSpec.parseScale(" FILL "))
        assertEquals(ScaleMode.ZOOM, LayoutSpec.parseScale("zoom"))
        assertEquals(ScaleMode.FIT, LayoutSpec.parseScale("bogus"))
    }

    @Test fun pixelRectsTouchWithoutGaps() {
        val left = ZoneSpec("a", 0f, 0f, 33.333f, 100f, listOf(clock()))
        val right = ZoneSpec("b", 33.333f, 0f, 66.667f, 100f, listOf(clock()))
        val l = left.pixelRect(1919, 1003)
        val r = right.pixelRect(1919, 1003)
        assertEquals(l.right, r.left)
        assertEquals(0, l.left)
        assertEquals(1919, r.right)
        assertEquals(1003, r.height)
    }

    @Test fun pixelRectFollowsStageSize() {
        val z = ZoneSpec("a", 70f, 50f, 30f, 50f, listOf(clock()))
        assertEquals(PxRect(1344, 540, 1920, 1080), z.pixelRect(1920, 1080))
        // ticker reserved 56 px at the bottom
        assertEquals(PxRect(1344, 512, 1920, 1024), z.pixelRect(1920, 1024))
        assertEquals(PxRect(0, 0, 0, 0), z.pixelRect(0, 0).let { PxRect(0, 0, it.width, it.height) })
    }

    // ------------------------------------------------------------------ decoder planner

    private fun zs(id: String, vararg items: ContentItem, mute: Boolean = false) =
        ZoneSpec(id, 0f, 0f, 10f, 10f, items.toList(), mute = mute)

    @Test fun planLimitsVideoZonesAndPrefersSoundZone() {
        val zones = listOf(
            zs("a", video(1), mute = true),
            zs("b", image(2)),
            zs("c", video(3)),
            zs("d", ContentItem(id = 4, type = ContentItem.TYPE_STREAM, url = "rtsp://cam")),
        )
        val p = DecoderPlanner.plan(zones, 2)
        assertEquals(2, p.audioZone)
        assertEquals(setOf(2, 0), p.videoZones)
        assertTrue(p.mutes(0))
        assertTrue(p.mutes(1))
        assertFalse(p.mutes(2))
        assertTrue(p.mutes(3))

        val one = DecoderPlanner.plan(zones, 1)
        assertEquals(setOf(2), one.videoZones)
    }

    @Test fun planWithoutSoundUsesFirstVideoZones() {
        val zones = listOf(zs("a", image(1)), zs("b", video(2), mute = true), zs("c", video(3), mute = true), zs("d", video(4), mute = true))
        val p = DecoderPlanner.plan(zones)
        assertNull(p.audioZone)
        assertEquals(setOf(1, 2), p.videoZones)
        assertTrue((0..3).all { p.mutes(it) })
    }

    @Test fun planCountsYouTubeAndClampsLimit() {
        val yt = ContentItem(id = 9, type = ContentItem.TYPE_YOUTUBE, embedUrl = "https://www.youtube.com/embed/abcdefghijk")
        val zones = listOf(zs("a", yt), zs("b", video(2)))
        assertEquals(setOf(0), DecoderPlanner.plan(zones, 0).videoZones)
        assertEquals(setOf(0, 1), DecoderPlanner.plan(zones, 99).videoZones)
        assertEquals(0, DecoderPlanner.plan(zones).audioZone)
    }

    @Test fun soundGoesToWebZoneWhenNoVideo() {
        val zones = listOf(zs("a", image(1)), zs("b", ContentItem(id = 2, type = ContentItem.TYPE_URL, url = "https://x/app")))
        assertEquals(1, DecoderPlanner.plan(zones).audioZone)
        assertNull(DecoderPlanner.plan(listOf(zs("a", image(1)))).audioZone)
    }

    @Test fun zoneWithoutDecoderPlaysOtherItemsOrPlaceholder() {
        val mixed = zs("a", video(1, "Promo"), image(2))
        assertEquals(listOf(2L), DecoderPlanner.zoneItems(mixed, allowVideo = false).map { it.id })
        assertEquals(listOf(1L, 2L), DecoderPlanner.zoneItems(mixed, allowVideo = true).map { it.id })
        val onlyVideo = zs("b", video(3, "  Aarti live "))
        assertTrue(DecoderPlanner.zoneItems(onlyVideo, allowVideo = false).isEmpty())
        assertEquals("Aarti live", DecoderPlanner.placeholderTitle(onlyVideo))
        assertEquals("", DecoderPlanner.placeholderTitle(zs("c", video(4))))
    }

    @Test fun decoderErrorCodes() {
        @Suppress("DEPRECATION")
        val codes = com.google.android.exoplayer2.PlaybackException::class.java
        assertTrue(DecoderPlanner.isDecoderError(codes.getField("ERROR_CODE_DECODER_INIT_FAILED").getInt(null)))
        assertTrue(DecoderPlanner.isDecoderError(codes.getField("ERROR_CODE_DECODING_FORMAT_EXCEEDS_CAPABILITIES").getInt(null)))
        assertFalse(DecoderPlanner.isDecoderError(codes.getField("ERROR_CODE_IO_NETWORK_CONNECTION_FAILED").getInt(null)))
        assertFalse(DecoderPlanner.isDecoderError(codes.getField("ERROR_CODE_DECODING_FORMAT_UNSUPPORTED").getInt(null)))
        assertEquals(1, DecoderPlanner.lowerLimit(2))
        assertEquals(2, DecoderPlanner.lowerLimit(3))
        assertEquals(1, DecoderPlanner.lowerLimit(1))
    }

    @Test fun webRetryBackoff() {
        assertEquals(5_000L, WebRetryPolicy.delayMs(0))
        assertEquals(10_000L, WebRetryPolicy.delayMs(1))
        assertEquals(80_000L, WebRetryPolicy.delayMs(4))
        assertEquals(120_000L, WebRetryPolicy.delayMs(5))
        assertEquals(120_000L, WebRetryPolicy.delayMs(50))
        assertEquals(5_000L, WebRetryPolicy.delayMs(-1))
    }

    @Test fun nonLayoutItemsUnchanged() {
        assertNotNull(image(1).cacheableUrls().singleOrNull())
        assertTrue(clock().cacheableUrls().isEmpty())
    }
}
