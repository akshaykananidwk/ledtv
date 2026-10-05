package com.hotelcast.tv

import android.content.Context
import android.content.res.Configuration
import java.text.ParseException
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone

/**
 * Bounded, insertion-ordered set of string ids persisted as one line per id (welcome ids shown,
 * reminder ids dismissed). Pure Kotlin.
 */
class IdSet(private val capacity: Int = 50) {
    private val ids = LinkedHashSet<String>()

    operator fun contains(id: String?): Boolean = id != null && ids.contains(id)

    fun add(id: String?) {
        if (id.isNullOrBlank()) return
        ids.remove(id)
        ids.add(id)
        while (ids.size > capacity) {
            val it = ids.iterator()
            it.next()
            it.remove()
        }
    }

    fun size() = ids.size

    fun serialize(): String = ids.joinToString("\n")

    companion object {
        fun parse(value: String?, capacity: Int = 50): IdSet =
            IdSet(capacity).also { s -> value?.split('\n')?.map { it.trim() }?.filter { it.isNotEmpty() }?.forEach { s.add(it) } }
    }
}

/** Show-once rules for the personal welcome card and the checkout reminder (unit-tested). */
object GuestPrompts {
    const val DAY_MS = 24 * 60 * 60 * 1000L
    const val DEFAULT_WELCOME_SEC = 20

    /**
     * Welcome card is shown when the server asks for it (`show`) and either
     *  - this `welcome.id` was never shown on this TV, or
     *  - the TV was just powered on ([powerOn]) and check-in was less than 24 h ago.
     */
    fun shouldShowWelcome(welcome: Welcome?, guest: Guest?, shown: IdSet, nowMillis: Long, powerOn: Boolean): Boolean {
        if (welcome == null || welcome.show != true) return false
        val id = welcome.id?.takeIf { it.isNotBlank() } ?: return false
        if (id !in shown) return true
        if (!powerOn) return false
        val checkin = parseIso(guest?.checkinAt) ?: return false
        val age = nowMillis - checkin
        return age in 0 until DAY_MS
    }

    fun welcomeDurationSec(welcome: Welcome?): Int =
        (welcome?.durationSec ?: DEFAULT_WELCOME_SEC).let { if (it <= 0) DEFAULT_WELCOME_SEC else it }.coerceIn(5, 600)

    /**
     * Checkout reminder banner: shown while the server sends `show` for an id the guest has not
     * dismissed. Hidden for the rest of the power cycle after it timed out ([hiddenThisSession]);
     * comes back after the next power-on until the guest dismisses it.
     */
    fun shouldShowReminder(reminder: CheckoutReminder?, dismissed: IdSet, hiddenThisSession: Set<String>): Boolean {
        if (reminder == null || reminder.show != true || reminder.text.isNullOrBlank()) return false
        val id = reminder.id?.takeIf { it.isNotBlank() } ?: return false
        return id !in dismissed && id !in hiddenThisSession
    }

    /** ISO-8601 parser that works on API 21 (no java.time): 2026-10-05T18:00:00+05:30 / Z / .123 / no seconds. */
    fun parseIso(value: String?): Long? {
        var s = value?.trim()?.takeIf { it.isNotEmpty() } ?: return null
        s = s.replace(' ', 'T')
        // strip fractional seconds
        s = s.replace(Regex("(T\\d{2}:\\d{2}:\\d{2})\\.\\d+"), "$1")
        var tz: TimeZone = TimeZone.getDefault()
        val m = Regex("(Z|[+-]\\d{2}:?\\d{2})$").find(s)
        if (m != null) {
            val z = m.value
            tz = if (z == "Z") TimeZone.getTimeZone("UTC") else TimeZone.getTimeZone("GMT" + z.substring(0, 3) + ":" + z.takeLast(2))
            s = s.substring(0, m.range.first)
        }
        for (pattern in arrayOf("yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd'T'HH:mm", "yyyy-MM-dd")) {
            try {
                val f = SimpleDateFormat(pattern, Locale.US)
                f.timeZone = tz
                f.isLenient = false
                val pos = java.text.ParsePosition(0)
                val d = f.parse(s, pos) ?: continue
                if (pos.index != s.length) continue
                return d.time
            } catch (_: ParseException) {
            } catch (_: IllegalArgumentException) {
            }
        }
        return null
    }
}

/** Process-wide guest UI state that must survive activity recreation (but not a process restart). */
object GuestSession {
    /** True after boot / screen-on until the next normal render consumed it (welcome re-show rule). */
    @Volatile var powerOnPending: Boolean = true

    /** Reminder ids hidden after their on-screen time this power cycle. */
    val remindersHidden: MutableSet<String> = java.util.Collections.synchronizedSet(HashSet())

    fun onPowerOn() {
        powerOnPending = true
        remindersHidden.clear()
    }
}

/**
 * DPAD_CENTER / ENTER state machine: short press (released before [longPressMs]) opens the guest
 * menu on key UP; holding for [longPressMs] opens settings (fires on a repeat DOWN, or on UP when
 * the remote sends no key repeats). Pure Kotlin, unit-tested.
 */
class CenterKeyTracker(private val longPressMs: Long = 3000L) {
    enum class Action { NONE, SHORT_PRESS, LONG_PRESS, LONG_RELEASED }

    private var downAt = 0L
    private var pressed = false
    private var longFired = false

    val isPressed: Boolean get() = pressed

    fun onDown(repeatCount: Int, now: Long): Action {
        if (repeatCount == 0 || !pressed) {
            // A repeat without a seen first DOWN (focus moved to us mid-press) starts tracking now.
            downAt = now
            pressed = true
            longFired = false
            return Action.NONE
        }
        if (!longFired && now - downAt >= longPressMs) {
            longFired = true
            return Action.LONG_PRESS
        }
        return Action.NONE
    }

    fun onUp(now: Long): Action {
        if (!pressed) return Action.NONE // DOWN happened elsewhere (e.g. in the PIN dialog)
        pressed = false
        if (longFired) return Action.LONG_RELEASED
        return if (now - downAt >= longPressMs) {
            longFired = true
            Action.LONG_PRESS
        } else Action.SHORT_PRESS
    }

    fun reset() {
        pressed = false
        longFired = false
    }
}

/** Guest-facing strings follow `guest.language` (en / gu / hi) instead of the TV's system language. */
object GuestLocale {
    val SUPPORTED = setOf("en", "gu", "hi")

    fun normalize(lang: String?): String? = lang?.trim()?.lowercase(Locale.US)?.take(2)?.takeIf { it in SUPPORTED }

    fun context(base: Context, lang: String?): Context {
        val code = normalize(lang) ?: return base
        return try {
            val cfg = Configuration(base.resources.configuration)
            cfg.setLocale(Locale(code))
            base.createConfigurationContext(cfg)
        } catch (e: Exception) {
            base
        }
    }
}
