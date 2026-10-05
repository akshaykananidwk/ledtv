package com.hotelcast.tv

import com.hotelcast.tv.CenterKeyTracker.Action
import org.junit.Assert.assertEquals
import org.junit.Test

/** OK / D-pad centre: short press → guest menu (on UP), 3 s hold → settings. */
class CenterKeyTrackerTest {

    @Test fun shortPressFiresOnUp() {
        val t = CenterKeyTracker(3000)
        assertEquals(Action.NONE, t.onDown(0, 1000))
        assertEquals(Action.SHORT_PRESS, t.onUp(1150))
    }

    @Test fun longPressWithRepeatsFiresOnceAndUpDoesNotOpenMenu() {
        val t = CenterKeyTracker(3000)
        t.onDown(0, 0)
        var longs = 0
        var ts = 500L
        var repeat = 1
        while (ts <= 4500) {
            if (t.onDown(repeat++, ts) == Action.LONG_PRESS) longs++
            ts += 50
        }
        assertEquals(1, longs)
        assertEquals(Action.LONG_RELEASED, t.onUp(4600))
    }

    @Test fun longPressWithoutRepeatsDetectedOnUp() {
        val t = CenterKeyTracker(3000)
        t.onDown(0, 0)
        assertEquals(Action.LONG_PRESS, t.onUp(3200))
    }

    @Test fun holdJustUnderThresholdIsShort() {
        val t = CenterKeyTracker(3000)
        t.onDown(0, 0)
        assertEquals(Action.NONE, t.onDown(1, 2999))
        assertEquals(Action.SHORT_PRESS, t.onUp(2999))
    }

    @Test fun upWithoutDownIsIgnored() {
        // e.g. OK pressed in the PIN dialog, released after it closed
        val t = CenterKeyTracker(3000)
        assertEquals(Action.NONE, t.onUp(100))
        t.onDown(0, 0)
        t.reset()
        assertEquals(Action.NONE, t.onUp(50))
    }

    @Test fun repeatWithoutFirstDownStartsTracking() {
        val t = CenterKeyTracker(3000)
        assertEquals(Action.NONE, t.onDown(5, 1000))
        assertEquals(Action.SHORT_PRESS, t.onUp(1200))
    }

    @Test fun consecutivePressesAreIndependent() {
        val t = CenterKeyTracker(3000)
        t.onDown(0, 0)
        t.onDown(1, 3100)
        t.onUp(3200)
        t.onDown(0, 5000)
        assertEquals(Action.SHORT_PRESS, t.onUp(5100))
    }
}
