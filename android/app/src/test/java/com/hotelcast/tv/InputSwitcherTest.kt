package com.hotelcast.tv

import com.hotelcast.tv.InputSwitcher.InputCandidate
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class InputSwitcherTest {

    @Test fun parsesInputs() {
        assertEquals(InputSwitcher.Target.LiveTv, InputSwitcher.parse("live_tv"))
        assertEquals(InputSwitcher.Target.LiveTv, InputSwitcher.parse("TV"))
        assertEquals(InputSwitcher.Target.Hdmi(1), InputSwitcher.parse("hdmi1"))
        assertEquals(InputSwitcher.Target.Hdmi(3), InputSwitcher.parse("HDMI 3"))
        assertEquals(InputSwitcher.Target.Hdmi(2), InputSwitcher.parse("hdmi-2"))
        assertNull(InputSwitcher.parse("hdmi0"))
        assertNull(InputSwitcher.parse("usb"))
        assertNull(InputSwitcher.parse(null))
    }

    private fun hdmi(id: String, label: String?, parent: String? = null) = InputCandidate(id, label, isHdmi = true, isTuner = false, parentId = parent)

    @Test fun picksByLabel() {
        val inputs = listOf(
            hdmi("com.mediatek.tvinput/.hdmi.HDMIInputService/HW5", "HDMI 2"),
            hdmi("com.mediatek.tvinput/.hdmi.HDMIInputService/HW4", "HDMI 1"),
            hdmi("com.mediatek.tvinput/.hdmi.HDMIInputService/HDMI1-cec", "Blu-ray", parent = "x"),
            InputCandidate("tuner", "TV", isHdmi = false, isTuner = true, parentId = null),
        )
        assertEquals("com.mediatek.tvinput/.hdmi.HDMIInputService/HW4", InputSwitcher.pickHdmi(inputs, 1))
        assertEquals("com.mediatek.tvinput/.hdmi.HDMIInputService/HW5", InputSwitcher.pickHdmi(inputs, 2))
    }

    @Test fun picksByIdThenPosition() {
        val inputs = listOf(
            hdmi("com.example/.Hdmi/hdmi2", null),
            hdmi("com.example/.Hdmi/hdmi1", null),
        )
        assertEquals("com.example/.Hdmi/hdmi2", InputSwitcher.pickHdmi(inputs, 2))
        val noHints = listOf(hdmi("b-input", "Game"), hdmi("a-input", "PC"))
        assertEquals("a-input", InputSwitcher.pickHdmi(noHints, 1))
        assertEquals("b-input", InputSwitcher.pickHdmi(noHints, 2))
        assertNull(InputSwitcher.pickHdmi(noHints, 3))
        assertNull(InputSwitcher.pickHdmi(emptyList(), 1))
    }

    @Test fun hdmi1DoesNotMatchHdmi10() {
        val inputs = listOf(hdmi("x/HW10", "HDMI 10"), hdmi("x/HW1", "HDMI 1"))
        assertEquals("x/HW1", InputSwitcher.pickHdmi(inputs, 1))
    }
}
