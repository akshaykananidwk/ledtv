package com.hotelcast.tv

import kotlin.math.ceil
import kotlin.math.floor
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * Normalised ticker settings (`content.overlay.ticker`): every value clamped, every missing value
 * defaulted. Pure Kotlin so it can be unit tested on the JVM. Colours stay strings here; the
 * activity parses them with [Utils.parseColor] and its own fallbacks.
 */
data class TickerSpec(
    val text: String,
    val speed: Int = DEFAULT_SPEED,
    val fontSizeSp: Float = DEFAULT_FONT_SP,
    val heightDp: Float = DEFAULT_HEIGHT_DP,
    val atTop: Boolean = false,
    val reserveSpace: Boolean = true,
    val bgColor: String? = null,
    val textColor: String? = null,
    val videoScale: ScaleMode = ScaleMode.FILL,
) {
    companion object {
        const val DEFAULT_SPEED = 5
        const val MIN_SPEED = 1
        const val MAX_SPEED = 10
        const val DEFAULT_FONT_SP = 26f
        const val MIN_FONT_SP = 14f
        const val MAX_FONT_SP = 72f
        const val DEFAULT_HEIGHT_DP = 56f
        const val MIN_HEIGHT_DP = 32f
        const val MAX_HEIGHT_DP = 200f
        /** Separator used when the server only sends `messages`. Same as the server's own `text`. */
        const val MESSAGE_SEPARATOR = "   ✦   "

        /** Null when there is no ticker or nothing to show (blank text and no messages). */
        fun from(t: Ticker?): TickerSpec? {
            if (t == null) return null
            val text = displayText(t) ?: return null
            return TickerSpec(
                text = text,
                speed = (t.speed ?: DEFAULT_SPEED).coerceIn(MIN_SPEED, MAX_SPEED),
                fontSizeSp = clamp(t.fontSize, DEFAULT_FONT_SP, MIN_FONT_SP, MAX_FONT_SP),
                heightDp = clamp(t.height, DEFAULT_HEIGHT_DP, MIN_HEIGHT_DP, MAX_HEIGHT_DP),
                atTop = t.position?.trim()?.equals("top", ignoreCase = true) == true,
                reserveSpace = t.reserveSpace ?: true,
                bgColor = t.bgColor?.trim()?.takeIf { it.isNotEmpty() },
                textColor = t.textColor?.trim()?.takeIf { it.isNotEmpty() },
                videoScale = ScaleMode.parse(t.videoScale),
            )
        }

        /** `text` wins (the server already joined the messages); otherwise the joined `messages`. Single line. */
        fun displayText(t: Ticker): String? {
            val fromText = t.text?.let(::singleLine)?.takeIf { it.isNotEmpty() }
            if (fromText != null) return fromText
            val joined = t.messages.orEmpty()
                .mapNotNull { m -> m?.let(::singleLine)?.takeIf { it.isNotEmpty() } }
                .joinToString(MESSAGE_SEPARATOR)
            return joined.takeIf { it.isNotEmpty() }
        }

        /** Target chunk length; chunks end after a space so shaping never splits a word. */
        const val CHUNK_CHARS = 80

        /** Splits [text] into pieces of roughly [CHUNK_CHARS] chars, breaking only after spaces. */
        fun splitChunks(text: String, target: Int = CHUNK_CHARS): List<String> {
            if (text.length <= target) return if (text.isEmpty()) emptyList() else listOf(text)
            val out = ArrayList<String>(text.length / target + 1)
            var start = 0
            while (start < text.length) {
                if (text.length - start <= target) {
                    out.add(text.substring(start))
                    break
                }
                var cut = text.indexOf(' ', start + target)
                if (cut < 0) {
                    out.add(text.substring(start))
                    break
                }
                cut++ // keep the space with the left chunk
                out.add(text.substring(start, cut))
                start = cut
            }
            return out
        }

        private fun singleLine(s: String) = s.replace(Regex("[\\r\\n\\t]+"), " ").trim()

        private fun clamp(v: Double?, def: Float, lo: Float, hi: Float): Float {
            if (v == null || v.isNaN() || v.isInfinite()) return def
            return v.toFloat().coerceIn(lo, hi)
        }
    }
}

/**
 * Pixel geometry of the ticker bar for one screen: bar height, text size, and how far the content
 * area (stage, welcome screen, guest layers) and the logo/clock overlay must be inset so nothing
 * hides behind the bar.
 *
 * - `reserveSpace = true`: the stage gets a margin equal to the bar height on the bar's side, so
 *   the video is laid out (aspect-fit) in the remaining area and is never covered.
 * - `reserveSpace = false`: the bar is drawn over the content (stage insets 0).
 * - The logo / clock / weather overlay is always kept clear of the bar.
 */
data class TickerLayout(
    val visible: Boolean,
    val atTop: Boolean,
    val heightPx: Int,
    val textSizePx: Float,
    val stageInsetTopPx: Int,
    val stageInsetBottomPx: Int,
    val overlayInsetTopPx: Int,
    val overlayInsetBottomPx: Int,
) {
    companion object {
        /** Line height needed per px of font size; generous for Gujarati / Devanagari ascenders and matras. */
        const val LINE_FACTOR = 1.5f
        /** Vertical padding (dp, total) around the text inside the bar. */
        const val PADDING_DP = 8f
        /** The bar never takes more than this share of the screen height. */
        const val MAX_SCREEN_SHARE = 0.34f

        val HIDDEN = TickerLayout(false, false, 0, 0f, 0, 0, 0, 0)

        /**
         * @param density display density (px per dp)
         * @param scaledDensity px per sp (density × font scale)
         * @param screenHeightPx height of the root view, or 0 if unknown (no screen-share cap)
         */
        fun compute(spec: TickerSpec?, density: Float, scaledDensity: Float = density, screenHeightPx: Int = 0): TickerLayout {
            if (spec == null || density <= 0f) return HIDDEN
            val sd = if (scaledDensity > 0f) scaledDensity else density
            val padPx = PADDING_DP * density
            val fontPx = spec.fontSizeSp * sd
            val requested = spec.heightDp * density
            val neededForFont = fontPx * LINE_FACTOR + padPx
            var height = ceil(max(requested, neededForFont)).toInt()
            if (screenHeightPx > 0) height = min(height, floor(screenHeightPx * MAX_SCREEN_SHARE).toInt())
            height = max(height, 1)
            // If the cap made the bar too small for the font, shrink the font rather than clip the text.
            val maxFontForHeight = max(1f, (height - padPx) / LINE_FACTOR)
            val textPx = min(fontPx, maxFontForHeight)
            val stage = if (spec.reserveSpace) height else 0
            return TickerLayout(
                visible = true,
                atTop = spec.atTop,
                heightPx = height,
                textSizePx = (textPx * 100f).roundToInt() / 100f,
                stageInsetTopPx = if (spec.atTop) stage else 0,
                stageInsetBottomPx = if (spec.atTop) 0 else stage,
                overlayInsetTopPx = if (spec.atTop) height else 0,
                overlayInsetBottomPx = if (spec.atTop) 0 else height,
            )
        }
    }
}

/** How content fills the stage (see `overlay.ticker.video_scale`). */
enum class ScaleMode {
    /** Aspect ratio kept, black bars where it does not match (normal full-screen behaviour). */
    FIT,
    /** Stretched to the whole area: no black bars, nothing cut (slightly squeezed next to a ticker). */
    FILL,
    /** Aspect ratio kept, fills the area, edges cropped. */
    ZOOM;

    companion object {
        /** Default FILL: a reserved ticker must not leave black bars at the sides of the video. */
        fun parse(v: String?): ScaleMode = when (v?.trim()?.lowercase()) {
            "fit" -> FIT
            "zoom", "crop" -> ZOOM
            else -> FILL
        }
    }
}
