package com.google.android.exoplayer2.source.rtsp

import org.junit.Assert.assertEquals
import org.junit.Test

/** Krishna Cloud TV 2.6.4: Dahua / CP Plus SETUP URL (patched RtspMediaTrack.resolveControl / joinAfterQuery). */
class RtspDahuaTest {
    @Test fun controlGoesAfterTheQueryLikeVlc() {
        assertEquals(
            "rtsp://192.168.10.2:554/cam/realmonitor?channel=1&subtype=0/trackID=0",
            RtspMediaTrack.joinAfterQuery("rtsp://192.168.10.2:554/cam/realmonitor?channel=1&subtype=0/", "trackID=0"),
        )
        assertEquals(
            "rtsp://192.168.10.2/cam/realmonitor?channel=2&subtype=1/trackID=1",
            RtspMediaTrack.joinAfterQuery("rtsp://192.168.10.2/cam/realmonitor?channel=2&subtype=1", "trackID=1"),
        )
    }

    @Test fun passwordWithAtSignStaysOutOfTheHost() {
        // The customer's real case: password "H0me@#2oz4" → URL-encoded by the server.
        assertEquals("192.168.10.2", RtspMessageUtil.authorityWithoutUserInfo("admin:H0me%40%232oz4@192.168.10.2"))
        assertEquals("192.168.10.2:8554", RtspMessageUtil.authorityWithoutUserInfo("admin:a@b@192.168.10.2:8554"))
        assertEquals("cam.example.com", RtspMessageUtil.authorityWithoutUserInfo("cam.example.com"))
    }
}
