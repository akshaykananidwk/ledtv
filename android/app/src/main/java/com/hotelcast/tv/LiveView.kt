package com.hotelcast.tv

import com.google.gson.annotations.SerializedName

/**
 * 2.4 live screen view (#41): `LIVE_VIEW {session, interval, max_sec, max_width, quality}`.
 * The TV captures its window every [LiveViewRequest.intervalSec] seconds (same PixelCopy path as
 * SCREENSHOT), scales it to ≤ [LiveViewRequest.maxWidth] px, JPEG quality ≈ 60, and uploads it to
 * `POST device/screenshot` with the extra multipart field `live=<session>`. The server answers with
 * `live: {continue, interval, stop_in}`: the admin page keeps the session alive while it is open;
 * the TV stops when the server says so, when `stop_in` runs out without a new keepalive, after 5
 * failed uploads in a row, or at the latest 2 minutes after the last answer.
 */
data class LiveViewRequest(
    val session: String,
    val intervalSec: Int,
    val maxSec: Int,
    val maxWidth: Int,
    val quality: Int,
)

data class LiveFrameResponse(
    @SerializedName("id") val id: Long? = null,
    @SerializedName("stored") val stored: Boolean? = null,
    @SerializedName("live") val live: LiveState? = null,
)

data class LiveState(
    @SerializedName("continue") val keepGoing: Boolean? = null,
    @SerializedName("interval") val interval: Int? = null,
    @SerializedName("stop_in") val stopIn: Int? = null,
)

/** Pure rules (unit tested in LiveViewTest). */
object LiveViewSpec {
    const val MIN_INTERVAL = 3
    const val MAX_INTERVAL = 10
    const val DEFAULT_INTERVAL = 4
    const val MAX_SEC = 120
    const val MAX_WIDTH = 960
    const val DEFAULT_QUALITY = 60
    const val MAX_FAILURES = 5
    private val SESSION = Regex("^[A-Za-z0-9_-]{16,64}$")

    fun from(cmd: Command): LiveViewRequest {
        val session = cmd.payloadString("session")?.trim().orEmpty()
        if (!SESSION.matches(session)) throw CommandFailedException("Missing or invalid live view session")
        return LiveViewRequest(
            session = session,
            intervalSec = clampInterval(cmd.payloadInt("interval")),
            maxSec = (cmd.payloadInt("max_sec") ?: MAX_SEC).coerceIn(10, MAX_SEC),
            maxWidth = (cmd.payloadInt("max_width") ?: MAX_WIDTH).coerceIn(320, MAX_WIDTH),
            quality = (cmd.payloadInt("quality") ?: DEFAULT_QUALITY).coerceIn(30, 80),
        )
    }

    fun clampInterval(sec: Int?): Int = (sec ?: DEFAULT_INTERVAL).coerceIn(MIN_INTERVAL, MAX_INTERVAL)

    /**
     * New local deadline after a server answer: `stop_in` seconds from now (≤ 2 min). A missing
     * answer keeps the old deadline, so the TV stops by itself when keepalives stop.
     */
    fun deadlineAfter(nowMs: Long, current: Long, state: LiveState?): Long {
        val stopIn = state?.stopIn ?: return current
        return nowMs + stopIn.coerceIn(0, MAX_SEC) * 1000L
    }

    /** Should the loop continue after this answer? */
    fun keepGoing(nowMs: Long, deadline: Long, state: LiveState?, failures: Int): Boolean =
        state?.keepGoing != false && nowMs < deadline && failures < MAX_FAILURES
}
