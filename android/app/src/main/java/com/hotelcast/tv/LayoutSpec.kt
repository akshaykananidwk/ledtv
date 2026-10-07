package com.hotelcast.tv

import kotlin.math.roundToInt

/**
 * Normalised split-screen layout (`item.layout` of a type "layout" item). Pure Kotlin so it can be
 * unit tested on the JVM; the Android side is [LayoutPlayer].
 *
 * Normalisation: positions and sizes are clamped to 0..100 percent (a zone never sticks out of
 * the stage), zero-size zones and zones without a playable item are dropped, nested layouts are
 * dropped, at most [MAX_ZONES] zones are kept, and every zone gets a unique id.
 */
data class LayoutSpec(
    val bgColor: String?,
    val zones: List<ZoneSpec>,
) {
    companion object {
        const val MAX_ZONES = 6

        /** Null when nothing in the layout can be shown. */
        fun from(data: LayoutData?): LayoutSpec? {
            if (data == null) return null
            val zones = ArrayList<ZoneSpec>()
            val usedIds = HashSet<String>()
            for (raw in data.zones.orEmpty()) {
                if (zones.size >= MAX_ZONES) break
                val z = raw ?: continue
                val x = pct(z.x, 0f)
                val y = pct(z.y, 0f)
                val w = pct(z.w, 100f).coerceAtMost(100f - x)
                val h = pct(z.h, 100f).coerceAtMost(100f - y)
                if (w <= 0f || h <= 0f) continue
                val items = z.items.orEmpty().filterNotNull()
                    .filter { it.type != ContentItem.TYPE_LAYOUT && it.isPlayable() }
                if (items.isEmpty()) continue
                var id = z.id?.trim()?.takeIf { it.isNotEmpty() } ?: "z${zones.size + 1}"
                if (!usedIds.add(id)) {
                    var n = zones.size + 1
                    while (!usedIds.add("$id-$n")) n++
                    id = "$id-$n"
                }
                zones.add(
                    ZoneSpec(
                        id = id,
                        x = x, y = y, w = w, h = h,
                        items = items,
                        loop = z.loop ?: true,
                        transition = if (z.transition?.trim()?.lowercase() in setOf("none", "cut")) "none" else "fade",
                        scale = parseScale(z.scale),
                        mute = z.mute ?: false,
                    ),
                )
            }
            if (zones.isEmpty()) return null
            return LayoutSpec(data.bgColor?.trim()?.takeIf { it.isNotEmpty() }, zones)
        }

        /** Zone scale: "fit" is the default here (aspect kept), unlike the ticker's FILL default. */
        fun parseScale(v: String?): ScaleMode = when (v?.trim()?.lowercase()) {
            "fill", "stretch" -> ScaleMode.FILL
            "zoom", "crop" -> ScaleMode.ZOOM
            else -> ScaleMode.FIT
        }

        private fun pct(v: Double?, default: Float): Float {
            val d = v ?: return default
            if (d.isNaN()) return default
            return d.toFloat().coerceIn(0f, 100f)
        }
    }
}

/** One zone, in percent of the stage. [items] never contains a layout. */
data class ZoneSpec(
    val id: String,
    val x: Float,
    val y: Float,
    val w: Float,
    val h: Float,
    val items: List<ContentItem>,
    val loop: Boolean = true,
    /** "fade" or "none". */
    val transition: String = "fade",
    val scale: ScaleMode = ScaleMode.FIT,
    val mute: Boolean = false,
) {
    /**
     * Pixel rectangle inside a [width] x [height] stage. Edges are rounded independently, so two
     * zones that touch in percent also touch in pixels (no 1 px gaps or overlaps).
     */
    fun pixelRect(width: Int, height: Int): PxRect {
        val l = (x * width / 100f).roundToInt()
        val t = (y * height / 100f).roundToInt()
        val r = ((x + w) * width / 100f).roundToInt().coerceAtMost(width)
        val b = ((y + h) * height / 100f).roundToInt().coerceAtMost(height)
        return PxRect(l, t, r.coerceAtLeast(l), b.coerceAtLeast(t))
    }
}

data class PxRect(val left: Int, val top: Int, val right: Int, val bottom: Int) {
    val width: Int get() = right - left
    val height: Int get() = bottom - top
}

/**
 * Which zones may use a hardware video decoder, and which zone plays sound.
 *
 * Many cheap TV boxes can run only one or two video decoders at once, so at most `maxDecoders`
 * zones get video (video, stream and YouTube items all count). The zone with sound wins, then the
 * other video zones in order. A zone without a decoder plays only its other items; if it has
 * none it shows a black placeholder with the item title.
 */
object DecoderPlanner {
    const val DEFAULT_MAX_DECODERS = 2

    data class Plan(
        /** Index of the only zone allowed to play sound, or null (all silent). */
        val audioZone: Int?,
        /** Zones allowed to decode video. */
        val videoZones: Set<Int>,
    ) {
        fun mutes(zoneIndex: Int): Boolean = zoneIndex != audioZone
        fun allowsVideo(zoneIndex: Int): Boolean = zoneIndex in videoZones
    }

    fun usesDecoder(item: ContentItem): Boolean = when (item.type) {
        ContentItem.TYPE_VIDEO, ContentItem.TYPE_STREAM, ContentItem.TYPE_YOUTUBE -> true
        else -> false
    }

    private fun mayPlaySound(item: ContentItem): Boolean = when (item.type) {
        ContentItem.TYPE_URL, ContentItem.TYPE_HTML, ContentItem.TYPE_TIMETABLE -> true
        else -> usesDecoder(item)
    }

    fun hasVideo(zone: ZoneSpec): Boolean = zone.items.any { usesDecoder(it) }

    /** First unmuted zone with video; otherwise the first unmuted zone with a web page (chimes). */
    fun audioZone(zones: List<ZoneSpec>): Int? {
        zones.forEachIndexed { i, z -> if (!z.mute && hasVideo(z)) return i }
        zones.forEachIndexed { i, z -> if (!z.mute && z.items.any { mayPlaySound(it) }) return i }
        return null
    }

    fun plan(zones: List<ZoneSpec>, maxDecoders: Int = DEFAULT_MAX_DECODERS): Plan {
        val audio = audioZone(zones)
        val limit = maxDecoders.coerceIn(1, LayoutSpec.MAX_ZONES)
        val candidates = zones.indices.filter { hasVideo(zones[it]) }
        val ordered = if (audio != null && audio in candidates) listOf(audio) + (candidates - audio) else candidates
        return Plan(audio, ordered.take(limit).toSet())
    }

    /** What a zone actually plays: everything, or without the video items when it got no decoder. */
    fun zoneItems(zone: ZoneSpec, allowVideo: Boolean): List<ContentItem> =
        if (allowVideo) zone.items else zone.items.filterNot { usesDecoder(it) }

    /** Text for the black placeholder of a zone that has nothing left to play. */
    fun placeholderTitle(zone: ZoneSpec): String =
        zone.items.firstNotNullOfOrNull { it.title?.trim()?.takeIf { t -> t.isNotEmpty() } } ?: ""

    /**
     * ExoPlayer error codes that mean "no decoder available" (PlaybackException 4001..4004:
     * init failed, query failed, decoding failed, format exceeds capabilities).
     */
    fun isDecoderError(errorCode: Int): Boolean = errorCode in 4001..4004

    /** New device limit after a decoder failure while [activeVideoZones] were decoding (never below 1). */
    fun lowerLimit(activeVideoZones: Int): Int = (activeVideoZones - 1).coerceAtLeast(1)
}
