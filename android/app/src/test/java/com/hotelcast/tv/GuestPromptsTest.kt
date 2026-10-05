package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class GuestPromptsTest {
    private val checkin = "2026-10-05T14:00:00+05:30"
    private val checkinMs = java.time.OffsetDateTime.parse(checkin).toInstant().toEpochMilli()
    private val welcome = Welcome(show = true, id = "stay-123", title = "Welcome", durationSec = 20)
    private val guest = Guest(name = "Mr. Shah", checkinAt = checkin)

    @Test fun welcomeShownOncePerId() {
        val shown = IdSet()
        val now = checkinMs + 60_000
        assertTrue(GuestPrompts.shouldShowWelcome(welcome, guest, shown, now, powerOn = false))
        shown.add("stay-123")
        assertFalse(GuestPrompts.shouldShowWelcome(welcome, guest, shown, now + 1000, powerOn = false))
        // a new stay id is shown again
        assertTrue(GuestPrompts.shouldShowWelcome(welcome.copy(id = "stay-124"), guest, shown, now, powerOn = false))
    }

    @Test fun welcomeAgainOnPowerOnWithin24h() {
        val shown = IdSet().apply { add("stay-123") }
        assertTrue(GuestPrompts.shouldShowWelcome(welcome, guest, shown, checkinMs + 23 * 3_600_000L, powerOn = true))
        assertFalse(GuestPrompts.shouldShowWelcome(welcome, guest, shown, checkinMs + 25 * 3_600_000L, powerOn = true))
        // no check-in time → only the first time
        assertFalse(GuestPrompts.shouldShowWelcome(welcome, guest.copy(checkinAt = null), shown, checkinMs, powerOn = true))
    }

    @Test fun welcomeNeedsShowAndId() {
        val shown = IdSet()
        assertFalse(GuestPrompts.shouldShowWelcome(welcome.copy(show = false), guest, shown, checkinMs, true))
        assertFalse(GuestPrompts.shouldShowWelcome(welcome.copy(show = null), guest, shown, checkinMs, true))
        assertFalse(GuestPrompts.shouldShowWelcome(welcome.copy(id = " "), guest, shown, checkinMs, true))
        assertFalse(GuestPrompts.shouldShowWelcome(null, guest, shown, checkinMs, true))
    }

    @Test fun welcomeDuration() {
        assertEquals(20, GuestPrompts.welcomeDurationSec(welcome))
        assertEquals(GuestPrompts.DEFAULT_WELCOME_SEC, GuestPrompts.welcomeDurationSec(welcome.copy(durationSec = 0)))
        assertEquals(5, GuestPrompts.welcomeDurationSec(welcome.copy(durationSec = 1)))
        assertEquals(600, GuestPrompts.welcomeDurationSec(welcome.copy(durationSec = 99999)))
    }

    @Test fun reminderUntilDismissedAndHiddenPerSession() {
        val r = CheckoutReminder(show = true, id = "co-1", text = "Checkout today at 10:00 AM")
        val dismissed = IdSet()
        val hidden = mutableSetOf<String>()
        assertTrue(GuestPrompts.shouldShowReminder(r, dismissed, hidden))
        hidden += "co-1" // timed out this power cycle
        assertFalse(GuestPrompts.shouldShowReminder(r, dismissed, hidden))
        hidden.clear() // power-on
        assertTrue(GuestPrompts.shouldShowReminder(r, dismissed, hidden))
        dismissed.add("co-1")
        assertFalse(GuestPrompts.shouldShowReminder(r, dismissed, hidden))
        assertTrue(GuestPrompts.shouldShowReminder(r.copy(id = "co-2"), dismissed, hidden))
        assertFalse(GuestPrompts.shouldShowReminder(r.copy(show = false), IdSet(), hidden))
        assertFalse(GuestPrompts.shouldShowReminder(r.copy(text = ""), IdSet(), hidden))
    }

    @Test fun idSetIsBoundedAndSerialises() {
        val s = IdSet(capacity = 3)
        listOf("a", "b", "c", "d").forEach { s.add(it) }
        assertEquals(3, s.size())
        assertFalse("a" in s)
        val back = IdSet.parse(s.serialize(), 3)
        assertTrue("d" in back)
        assertTrue("b" in back)
        assertEquals(0, IdSet.parse(null).size())
        assertEquals(1, IdSet.parse("x,with,commas\n\n").size())
    }

    @Test fun parsesIsoVariants() {
        assertEquals(checkinMs, GuestPrompts.parseIso(checkin))
        assertEquals(checkinMs, GuestPrompts.parseIso("2026-10-05T08:30:00Z"))
        assertEquals(checkinMs, GuestPrompts.parseIso("2026-10-05T08:30:00.123Z"))
        assertEquals(checkinMs, GuestPrompts.parseIso("2026-10-05T14:00:00+0530"))
        assertEquals(checkinMs, GuestPrompts.parseIso("2026-10-05 08:30:00Z"))
        assertNull(GuestPrompts.parseIso("yesterday"))
        assertNull(GuestPrompts.parseIso(null))
    }

    @Test fun guestLanguageNormalisation() {
        assertEquals("gu", GuestLocale.normalize("GU"))
        assertEquals("hi", GuestLocale.normalize("hi-IN"))
        assertEquals("en", GuestLocale.normalize(" en "))
        assertNull(GuestLocale.normalize("fr"))
        assertNull(GuestLocale.normalize(null))
    }
}
