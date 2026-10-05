package com.hotelcast.tv

import android.graphics.Color
import android.view.KeyEvent
import android.widget.FrameLayout
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Guest menu UI skeleton tests. Run on a TV/emulator with `./gradlew connectedAndroidTest`.
 * (Key routing logic itself is covered by the JVM test CenterKeyTrackerTest.)
 */
@RunWith(AndroidJUnit4::class)
class GuestMenuInstrumentedTest {

    private val context get() = ApplicationProvider.getApplicationContext<android.content.Context>()

    private val items = listOf(
        GuestMenuItem(id = "services", type = GuestMenuItem.TYPE_QR, title = "Room Service", icon = "food", url = "https://x/g/T"),
        GuestMenuItem(id = "live_tv", type = GuestMenuItem.TYPE_LIVE_TV, title = "Live TV", icon = "tv"),
        GuestMenuItem(id = "hdmi1", type = GuestMenuItem.TYPE_INPUT, title = "HDMI 1", icon = "hdmi", input = "hdmi1"),
    )

    @Test
    fun panelListsItemsAndSelectsWithDpadCenter() {
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            val selected = mutableListOf<String?>()
            val panel = GuestMenuPanel.build(context, "Hotel", "Room 101", "BACK = close", items, Color.YELLOW) { selected += it.id }
            val host = FrameLayout(context).apply { addView(panel) }
            assertNotNull(host)
            val rows = GuestMenuPanel.itemRows(panel)
            assertEquals(3, rows.size)
            assertTrue(rows.all { it.isFocusable })
            // focus wraps around inside the list
            assertEquals(rows[0].id, rows[2].nextFocusDownId)
            assertEquals(rows[2].id, rows[0].nextFocusUpId)
            rows[1].performClick()
            assertEquals(listOf<String?>("live_tv"), selected)
            rows[0].dispatchKeyEvent(KeyEvent(KeyEvent.ACTION_DOWN, KeyEvent.KEYCODE_DPAD_CENTER))
            rows[0].dispatchKeyEvent(KeyEvent(KeyEvent.ACTION_UP, KeyEvent.KEYCODE_DPAD_CENTER))
        }
    }

    @Test
    fun wifiQrBitmapIsGenerated() {
        val bmp = QrCodes.bitmap(WifiQr.build("Hotel-Guest", "pa;ss"), 300)
        assertNotNull(bmp)
        assertTrue(bmp!!.width >= 300)
    }
}
