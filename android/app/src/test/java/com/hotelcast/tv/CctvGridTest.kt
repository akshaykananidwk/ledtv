package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 2.6.3 CCTV grids: layout `max_decoders` hint (server core/Cctv.php / Layouts.php) and the decoder limit. */
class CctvGridTest {

    private fun grid(n: Int, hint: Int?): LayoutSpec? {
        val zones = (1..n).map { i ->
            LayoutZoneData(id = "z$i", x = ((i - 1) % 2) * 50.0, y = ((i - 1) / 2) * 50.0, w = 50.0, h = 50.0,
                items = listOf(ContentItem(id = i.toLong(), type = "stream", title = "Camera $i", url = "rtsp://admin:pw@192.168.1.108/cam/realmonitor?channel=$i&subtype=1")))
        }
        return LayoutSpec.from(LayoutData(bgColor = "#000000", zones = zones, maxDecoders = hint))
    }

    @Test fun parsesHintFromServerJson() {
        val c = ApiClient.gson.fromJson(
            """{"hash":"h","items":[{"id":9,"type":"layout","layout":{"bg_color":"#000000","max_decoders":4,"zones":[{"id":"z1","x":0,"y":0,"w":50,"h":100,"items":[{"id":1,"type":"stream","url":"rtsp://a/1"}]},{"id":"z2","x":50,"y":0,"w":50,"h":100,"items":[{"id":2,"type":"stream","url":"rtsp://a/2"}]}]}}]}""",
            Content::class.java,
        )
        assertEquals(4, LayoutSpec.from(c.items!![0].layout)!!.maxDecoders)
        assertNull(LayoutSpec.from(LayoutData(zones = grid(2, null)!!.zones.map { LayoutZoneData(id = it.id, w = 50.0, h = 100.0, items = it.items) }))!!.maxDecoders)
        assertEquals(6, grid(4, 99)!!.maxDecoders) // capped at MAX_ZONES
        assertNull(grid(4, 0)!!.maxDecoders)
    }

    @Test fun hintRaisesTheLimitUpToWhatTheTvProved() {
        // No hint: the device limit (default 2) as before.
        assertEquals(2, DecoderPlanner.effectiveLimit(null, 2, 6))
        // CCTV 2×2 grid: all 4 cameras decode.
        assertEquals(4, DecoderPlanner.effectiveLimit(4, 2, 6))
        // After a decoder failure with 4, the TV learned 3 → never more than 3 for hinted layouts.
        assertEquals(3, DecoderPlanner.effectiveLimit(4, 2, 3))
        assertEquals(1, DecoderPlanner.effectiveLimit(4, 2, 0))
        val spec = grid(4, 4)!!
        val plan = DecoderPlanner.plan(spec.zones, DecoderPlanner.effectiveLimit(spec.maxDecoders, 2, 6))
        assertEquals(setOf(0, 1, 2, 3), plan.videoZones)
    }

    @Test fun rtspWithLoginPlaysAsStream() {
        assertEquals(MediaUrls.Format.RTSP, MediaUrls.formatOf("rtsp://admin:p%40ss@192.168.1.108:554/cam/realmonitor?channel=1&subtype=1"))
        assertEquals("rtsp://192.168.1.108:554/cam/realmonitor", Utils.redactUrl("rtsp://admin:secret@192.168.1.108:554/cam/realmonitor?channel=1&subtype=1"))
    }
}
