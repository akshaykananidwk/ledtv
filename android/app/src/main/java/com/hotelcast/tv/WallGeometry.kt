package com.hotelcast.tv

import com.google.gson.annotations.JsonAdapter
import com.google.gson.annotations.SerializedName

/**
 * 2.4 video wall (#36): `content.wall` — this TV is tile ([row], [col]) of a [rows] × [cols] wall
 * (0-based, row 0 = top, col 0 = left as seen from the front). bezel_*_pct = the gap between two
 * pictures in percent of one picture's width / height. Old apps ignore the field.
 */
data class WallData(
    @SerializedName("id") val id: Long? = null,
    @SerializedName("rows") val rows: Int? = null,
    @SerializedName("cols") val cols: Int? = null,
    @SerializedName("row") val row: Int? = null,
    @SerializedName("col") val col: Int? = null,
    @SerializedName("bezel_x_pct") val bezelXPct: Double? = null,
    @SerializedName("bezel_y_pct") val bezelYPct: Double? = null,
    /** This tile plays the sound (default: tile 0,0). */
    @JsonAdapter(LenientBooleanAdapter::class)
    @SerializedName("audio") val audio: Boolean? = null,
)

/** Normalised part of the whole picture (0..1 of its width / height) one tile shows. */
data class CropRect(val left: Double, val top: Double, val right: Double, val bottom: Double)

/**
 * How to draw an item so that this tile shows exactly its part of the wall picture: lay the item out
 * at [viewW] × [viewH] (the wall's aspect ratio, never larger than the screen), scale it uniformly by
 * [scale] around its top-left corner and move it by [translateX] / [translateY] (pixels).
 */
data class WallTransform(val viewW: Int, val viewH: Int, val scale: Float, val translateX: Float, val translateY: Float)

/**
 * Pure geometry of a video wall (unit tested). In units of one screen, the whole picture is
 *   W = cols + (cols - 1) · gapX  wide and  H = rows + (rows - 1) · gapY  high
 * (gap = bezel compensation: the part of the picture hidden behind the frames). Tile (r, c) shows
 * x ∈ [c·(1 + gapX), c·(1 + gapX) + 1], y likewise.
 */
class WallGeometry private constructor(
    val rows: Int,
    val cols: Int,
    val row: Int,
    val col: Int,
    /** Gap as a fraction of one screen (bezel_x_pct / 100). */
    val gapX: Double,
    val gapY: Double,
    val audio: Boolean,
) {
    val wallWidthUnits: Double get() = cols + (cols - 1) * gapX
    val wallHeightUnits: Double get() = rows + (rows - 1) * gapY

    fun cropRect(): CropRect {
        val x0 = col * (1 + gapX)
        val y0 = row * (1 + gapY)
        return CropRect(x0 / wallWidthUnits, y0 / wallHeightUnits, (x0 + 1) / wallWidthUnits, (y0 + 1) / wallHeightUnits)
    }

    /** Transform for a screen (stage) of [stageW] × [stageH] pixels. */
    fun transform(stageW: Int, stageH: Int): WallTransform {
        val w = stageW.coerceAtLeast(1)
        val h = stageH.coerceAtLeast(1)
        val wallW = wallWidthUnits
        val wallH = wallHeightUnits
        val s = maxOf(wallW, wallH)
        return WallTransform(
            viewW = Math.round(wallW * w / s).toInt().coerceAtLeast(1),
            viewH = Math.round(wallH * h / s).toInt().coerceAtLeast(1),
            scale = s.toFloat(),
            translateX = (-col * (1 + gapX) * w).toFloat(),
            translateY = (-row * (1 + gapY) * h).toFloat(),
        )
    }

    companion object {
        const val MAX_SIZE = 4
        /** More than half a screen of "bezel" is a typo, not a wall. */
        const val MAX_BEZEL_PCT = 50.0

        fun of(rows: Int, cols: Int, row: Int, col: Int, bezelXPct: Double = 0.0, bezelYPct: Double = 0.0, audio: Boolean = row == 0 && col == 0): WallGeometry? {
            if (rows !in 1..MAX_SIZE || cols !in 1..MAX_SIZE || rows * cols < 2) return null
            if (row !in 0 until rows || col !in 0 until cols) return null
            val bx = bezelXPct.takeIf { it.isFinite() }?.coerceIn(0.0, MAX_BEZEL_PCT) ?: 0.0
            val by = bezelYPct.takeIf { it.isFinite() }?.coerceIn(0.0, MAX_BEZEL_PCT) ?: 0.0
            return WallGeometry(rows, cols, row, col, bx / 100.0, by / 100.0, audio)
        }

        /** From the server object; null when absent or invalid (then the TV plays normally). */
        fun from(data: WallData?): WallGeometry? {
            data ?: return null
            val rows = data.rows ?: return null
            val cols = data.cols ?: return null
            val row = data.row ?: return null
            val col = data.col ?: return null
            return of(rows, cols, row, col, data.bezelXPct ?: 0.0, data.bezelYPct ?: 0.0, data.audio ?: (row == 0 && col == 0))
        }
    }
}
