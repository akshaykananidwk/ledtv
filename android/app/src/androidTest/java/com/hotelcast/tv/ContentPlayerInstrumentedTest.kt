package com.hotelcast.tv

import android.widget.FrameLayout
import androidx.test.core.app.ActivityScenario
import androidx.test.core.app.ApplicationProvider
import androidx.test.espresso.Espresso.onView
import androidx.test.espresso.assertion.ViewAssertions.matches
import androidx.test.espresso.matcher.ViewMatchers.isDisplayed
import androidx.test.espresso.matcher.ViewMatchers.withId
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Instrumentation tests for the player. Run on a TV/emulator with:
 *   ./gradlew connectedAndroidTest
 */
@RunWith(AndroidJUnit4::class)
class ContentPlayerInstrumentedTest {

    private val context get() = ApplicationProvider.getApplicationContext<android.content.Context>()

    @Test
    fun settingsScreenShowsForms() {
        ActivityScenario.launch(SettingsActivity::class.java).use {
            onView(withId(R.id.input_server)).check(matches(isDisplayed()))
            onView(withId(R.id.btn_register)).check(matches(isDisplayed()))
        }
    }

    @Test
    fun playerRendersAnnouncementAndClockWithoutCrashing() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        instrumentation.runOnMainSync {
            val stage = FrameLayout(context)
            val events = mutableListOf<String>()
            val player = ContentPlayer(context, stage, ContentCache(context), object : ContentPlayer.Listener {
                override fun onItemStarted(item: ContentItem) { events += "start:${item.id}" }
                override fun onItemFinished(item: ContentItem, startedAtMillis: Long, durationSec: Int) { events += "end:${item.id}" }
                override fun onNothingToPlay() { events += "nothing" }
            })
            val content = Content(
                hash = "test",
                mode = "assigned",
                playlist = Playlist(transition = "none", loop = true),
                items = listOf(
                    ContentItem(id = 1, type = ContentItem.TYPE_ANNOUNCEMENT, text = "સ્વાગત છે", style = "fullscreen", duration = 5),
                    ContentItem(id = 2, type = ContentItem.TYPE_CLOCK, style = "analog", duration = 5),
                ),
            )
            player.setContent(content)
            assertEquals(listOf("start:1"), events)
            assertEquals(1, stage.childCount)
            player.stop()
            assertTrue(events.contains("end:1"))
            assertEquals(0, stage.childCount)
        }
    }

    @Test
    fun emptyContentFallsBackToWelcome() {
        InstrumentationRegistry.getInstrumentation().runOnMainSync {
            var nothing = false
            val player = ContentPlayer(context, FrameLayout(context), ContentCache(context), object : ContentPlayer.Listener {
                override fun onItemStarted(item: ContentItem) {}
                override fun onItemFinished(item: ContentItem, startedAtMillis: Long, durationSec: Int) {}
                override fun onNothingToPlay() { nothing = true }
            })
            player.setContent(Content(hash = "e", mode = "empty", items = emptyList()))
            assertTrue(nothing)
            player.release()
        }
    }
}
