package com.hotelcast.tv

/**
 * 2.4 synchronized playback (#37): estimates the offset between this TV's clock and the server clock
 * from poll round trips, NTP style. Pure Kotlin (unit tested); the app instance is [ServerClock].
 *
 * One sample = local time when the request was sent (t0), local time when the response arrived (t1)
 * and the server's `server_time_ms` (ts). Assuming the server stamped the response half way:
 *   offset = ts - (t0 + t1) / 2, error ≤ RTT / 2.
 * The sample with the smallest round trip among the recent ones is the most precise, so that one wins
 * (jitter, Wi-Fi retries and long polls only make RTT bigger). Old samples expire because the TV's
 * oscillator drifts.
 *
 * [localClock] should be monotonic (SystemClock.elapsedRealtime) so that a wall-clock change on the
 * TV (NTP, time zone, user) never moves the estimate.
 */
class SyncClock(
    private val localClock: () -> Long,
    private val maxSamples: Int = 16,
    private val maxAgeMs: Long = 30 * 60_000L,
    private val maxRttMs: Long = 10_000L,
) {
    data class Sample(val sentAt: Long, val receivedAt: Long, val serverMs: Long) {
        val rttMs: Long get() = receivedAt - sentAt
        /** server - local, at the midpoint of the request. */
        val offsetMs: Long get() = serverMs - (sentAt + receivedAt) / 2
    }

    private val samples = ArrayDeque<Sample>()

    /** Adds a round trip. Returns false (ignored) for impossible or uselessly slow samples. */
    @Synchronized
    fun addSample(sentAt: Long, receivedAt: Long, serverMs: Long): Boolean {
        val s = Sample(sentAt, receivedAt, serverMs)
        if (serverMs <= 0 || s.rttMs < 0 || s.rttMs > maxRttMs) return false
        samples.addLast(s)
        while (samples.size > maxSamples) samples.removeFirst()
        return true
    }

    /** The best (minimum RTT) recent sample, or null before the first good poll. */
    @Synchronized
    fun bestSample(): Sample? {
        val now = localClock()
        while (samples.isNotEmpty() && now - samples.first().receivedAt > maxAgeMs) samples.removeFirst()
        return samples.minByOrNull { it.rttMs }
    }

    /** server time - local time, ms (null = no estimate yet). */
    fun offsetMs(): Long? = bestSample()?.offsetMs

    /** Estimated server time now (null = no estimate yet). */
    fun serverNowMs(): Long? = offsetMs()?.let { localClock() + it }

    /** Half the best round trip: the worst-case error of the estimate (ms), null without samples. */
    fun uncertaintyMs(): Long? = bestSample()?.let { it.rttMs / 2 }

    @Synchronized
    fun reset() = samples.clear()

    val sampleCount: Int @Synchronized get() = samples.size
}
