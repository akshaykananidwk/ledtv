package com.hotelcast.tv

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName

/*
 * Data model of the HotelCast device API (docs/API.md). Every field is nullable with a
 * default so that Gson (which may bypass Kotlin constructors) never produces a "non-null"
 * property that is actually null, and so that unknown/missing server fields never crash.
 */

data class ApiEnvelope<T>(
    @SerializedName("ok") val ok: Boolean = false,
    @SerializedName("data") val data: T? = null,
    @SerializedName("error") val error: ApiError? = null,
)

data class ApiError(
    @SerializedName("code") val code: String? = null,
    @SerializedName("message") val message: String? = null,
)

data class RegisterRequest(
    @SerializedName("device_id") val deviceId: String,
    @SerializedName("room_number") val roomNumber: String,
    @SerializedName("registration_key") val registrationKey: String,
    @SerializedName("app_version") val appVersion: String? = null,
    @SerializedName("app_version_code") val appVersionCode: Int? = null,
    @SerializedName("android_version") val androidVersion: String? = null,
    @SerializedName("model") val model: String? = null,
    @SerializedName("ip_address") val ipAddress: String? = null,
)

data class Room(
    @SerializedName("id") val id: Long? = null,
    @SerializedName("number") val number: String? = null,
    @SerializedName("name") val name: String? = null,
    @SerializedName("floor") val floor: String? = null,
)

data class RegisterResponse(
    @SerializedName("token") val token: String? = null,
    @SerializedName("device_id") val deviceId: String? = null,
    @SerializedName("room") val room: Room? = null,
    @SerializedName("poll_interval") val pollInterval: Int? = null,
    @SerializedName("heartbeat_interval") val heartbeatInterval: Int? = null,
    @SerializedName("settings_pin_hash") val settingsPinHash: String? = null,
)

data class Command(
    @SerializedName("id") val id: Long? = null,
    @SerializedName("command") val command: String? = null,
    @SerializedName("payload") val payload: JsonObject? = null,
) {
    fun payloadString(key: String): String? = try {
        payload?.get(key)?.takeIf { !it.isJsonNull }?.asString
    } catch (e: Exception) {
        null
    }

    fun payloadInt(key: String): Int? = try {
        payload?.get(key)?.takeIf { !it.isJsonNull }?.asInt
    } catch (e: Exception) {
        null
    }
}

data class PollResponse(
    @SerializedName("server_time") val serverTime: String? = null,
    @SerializedName("poll_interval") val pollInterval: Int? = null,
    @SerializedName("content_hash") val contentHash: String? = null,
    @SerializedName("content_changed") val contentChanged: Boolean? = null,
    @SerializedName("content") val content: Content? = null,
    @SerializedName("commands") val commands: List<Command>? = null,
)

data class AckRequest(
    @SerializedName("command_id") val commandId: Long,
    @SerializedName("status") val status: String,
    @SerializedName("message") val message: String? = null,
)

data class AckResponse(
    @SerializedName("command_id") val commandId: Long? = null,
    @SerializedName("status") val status: String? = null,
)

data class HeartbeatRequest(
    @SerializedName("app_version") val appVersion: String,
    @SerializedName("app_version_code") val appVersionCode: Int,
    @SerializedName("android_version") val androidVersion: String,
    @SerializedName("model") val model: String,
    @SerializedName("ip_address") val ipAddress: String?,
    @SerializedName("battery") val battery: Int,
    @SerializedName("network_type") val networkType: String,
    @SerializedName("wifi_signal") val wifiSignal: Int?,
    @SerializedName("free_storage_mb") val freeStorageMb: Long,
    @SerializedName("current_content_hash") val currentContentHash: String?,
    @SerializedName("current_item_id") val currentItemId: Long?,
    @SerializedName("screen_on") val screenOn: Boolean,
    @SerializedName("uptime_sec") val uptimeSec: Long,
)

data class HeartbeatResponse(
    @SerializedName("server_time") val serverTime: String? = null,
    @SerializedName("poll_interval") val pollInterval: Int? = null,
    @SerializedName("heartbeat_interval") val heartbeatInterval: Int? = null,
    @SerializedName("settings_pin_hash") val settingsPinHash: String? = null,
)

data class PlayedItem(
    @SerializedName("content_id") val contentId: Long,
    @SerializedName("started_at") val startedAt: String,
    @SerializedName("duration_sec") val durationSec: Int,
)

data class PlayedRequest(
    @SerializedName("items") val items: List<PlayedItem>,
)

data class HealthResponse(
    @SerializedName("status") val status: String? = null,
    @SerializedName("version") val version: String? = null,
    @SerializedName("db") val db: Boolean? = null,
    @SerializedName("time") val time: String? = null,
)

// ---------------------------------------------------------------- Content object

data class Content(
    @SerializedName("hash") val hash: String? = null,
    @SerializedName("generated_at") val generatedAt: String? = null,
    @SerializedName("mode") val mode: String? = null,
    @SerializedName("screen_on") val screenOn: Boolean? = null,
    @SerializedName("room") val room: Room? = null,
    @SerializedName("hotel") val hotel: Hotel? = null,
    @SerializedName("playlist") val playlist: Playlist? = null,
    @SerializedName("items") val items: List<ContentItem>? = null,
    @SerializedName("overlay") val overlay: Overlay? = null,
    @SerializedName("emergency") val emergency: Emergency? = null,
) {
    val isOff: Boolean get() = mode == MODE_OFF || screenOn == false
    val isEmergency: Boolean get() = emergency != null || mode == MODE_EMERGENCY
    val isEmpty: Boolean get() = mode == MODE_EMPTY || playableItems().isEmpty()

    /** Items with a known type and the fields needed to render them. */
    fun playableItems(): List<ContentItem> = items.orEmpty().filter { it.isPlayable() }

    companion object {
        const val MODE_EMERGENCY = "emergency"
        const val MODE_OFF = "off"
        const val MODE_EMPTY = "empty"
    }
}

data class Hotel(
    @SerializedName("name") val name: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
)

data class Playlist(
    @SerializedName("id") val id: Long? = null,
    @SerializedName("name") val name: String? = null,
    @SerializedName("transition") val transition: String? = null,
    @SerializedName("loop") val loop: Boolean? = null,
)

data class Overlay(
    @SerializedName("clock") val clock: Boolean? = null,
    @SerializedName("clock_format") val clockFormat: String? = null,
    @SerializedName("weather") val weather: Weather? = null,
    @SerializedName("ticker") val ticker: Ticker? = null,
    @SerializedName("logo") val logo: Boolean? = null,
)

data class Weather(
    @SerializedName("enabled") val enabled: Boolean? = null,
    @SerializedName("city") val city: String? = null,
    @SerializedName("temp_c") val tempC: Double? = null,
    @SerializedName("condition") val condition: String? = null,
    @SerializedName("icon") val icon: String? = null,
)

data class Ticker(
    @SerializedName("text") val text: String? = null,
    @SerializedName("speed") val speed: Int? = null,
    @SerializedName("bg_color") val bgColor: String? = null,
    @SerializedName("text_color") val textColor: String? = null,
)

data class Emergency(
    @SerializedName("id") val id: Long? = null,
    @SerializedName("title") val title: String? = null,
    @SerializedName("message") val message: String? = null,
    @SerializedName("bg_color") val bgColor: String? = null,
    @SerializedName("text_color") val textColor: String? = null,
)

data class ContentItem(
    @SerializedName("id") val id: Long? = null,
    @SerializedName("type") val type: String? = null,
    @SerializedName("title") val title: String? = null,
    @SerializedName("duration") val duration: Int? = null,
    @SerializedName("url") val url: String? = null,
    @SerializedName("loop") val loop: Boolean? = null,
    @SerializedName("mute") val mute: Boolean? = null,
    @SerializedName("html") val html: String? = null,
    @SerializedName("refresh_sec") val refreshSec: Int? = null,
    @SerializedName("text") val text: String? = null,
    @SerializedName("subtitle") val subtitle: String? = null,
    @SerializedName("style") val style: String? = null,
    @SerializedName("bg_color") val bgColor: String? = null,
    @SerializedName("text_color") val textColor: String? = null,
    @SerializedName("font_size") val fontSize: Float? = null,
    @SerializedName("embed_url") val embedUrl: String? = null,
) {
    val durationSec: Int get() = (duration ?: 0).coerceAtLeast(0)

    fun isPlayable(): Boolean = when (type) {
        TYPE_IMAGE, TYPE_VIDEO, TYPE_STREAM, TYPE_URL -> !url.isNullOrBlank()
        TYPE_YOUTUBE -> !embedUrl.isNullOrBlank() || !url.isNullOrBlank()
        TYPE_TIMETABLE, TYPE_HTML -> !html.isNullOrBlank()
        TYPE_ANNOUNCEMENT -> !text.isNullOrBlank() || !subtitle.isNullOrBlank()
        TYPE_CLOCK -> true
        else -> false
    }

    /** URLs worth caching on disk for offline playback. */
    fun cacheableUrl(): String? = if ((type == TYPE_IMAGE || type == TYPE_VIDEO) && !url.isNullOrBlank()) url else null

    companion object {
        const val TYPE_IMAGE = "image"
        const val TYPE_VIDEO = "video"
        const val TYPE_STREAM = "stream"
        const val TYPE_TIMETABLE = "timetable"
        const val TYPE_ANNOUNCEMENT = "announcement"
        const val TYPE_HTML = "html"
        const val TYPE_URL = "url"
        const val TYPE_YOUTUBE = "youtube"
        const val TYPE_CLOCK = "clock"
    }
}
