package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 2.4 video wall: crop rect and view transform of each tile ([WallGeometry]). */
class WallGeometryTest {

    private val eps = 1e-9

    private fun crop(rows: Int, cols: Int, row: Int, col: Int, bx: Double = 0.0, by: Double = 0.0) =
        WallGeometry.of(rows, cols, row, col, bx, by)!!.cropRect()

    private fun assertRect(expected: List<Double>, r: CropRect) {
        assertEquals("left", expected[0], r.left, 1e-6)
        assertEquals("top", expected[1], r.top, 1e-6)
        assertEquals("right", expected[2], r.right, 1e-6)
        assertEquals("bottom", expected[3], r.bottom, 1e-6)
    }

    @Test
    fun twoByTwoWithoutBezel() {
        assertRect(listOf(0.0, 0.0, 0.5, 0.5), crop(2, 2, 0, 0))
        assertRect(listOf(0.5, 0.0, 1.0, 0.5), crop(2, 2, 0, 1))
        assertRect(listOf(0.0, 0.5, 0.5, 1.0), crop(2, 2, 1, 0))
        assertRect(listOf(0.5, 0.5, 1.0, 1.0), crop(2, 2, 1, 1))
    }

    @Test
    fun twoByTwoWithBezel() {
        // Gap 5 % of a screen horizontally, 8 % vertically: the wall is 2.05 × 2.08 screens.
        val w = 2.05
        val h = 2.08
        assertRect(listOf(0.0, 0.0, 1 / w, 1 / h), crop(2, 2, 0, 0, 5.0, 8.0))
        assertRect(listOf(1.05 / w, 1.08 / h, 1.0, 1.0), crop(2, 2, 1, 1, 5.0, 8.0))
        // The part between the tiles is hidden behind the frames.
        val a = crop(2, 2, 0, 0, 5.0, 8.0)
        val b = crop(2, 2, 0, 1, 5.0, 8.0)
        assertEquals(0.05 / w, b.left - a.right, 1e-9)
    }

    @Test
    fun threeByThree() {
        val third = 1.0 / 3
        assertRect(listOf(third, third, 2 * third, 2 * third), crop(3, 3, 1, 1))
        assertRect(listOf(2 * third, 0.0, 1.0, third), crop(3, 3, 0, 2))
        // With a 2 % bezel the middle tile sits exactly in the middle.
        val m = crop(3, 3, 1, 1, 2.0, 2.0)
        assertEquals(1 - m.right, m.left, eps)
        assertEquals(1.02 / 3.04, m.left, eps)
        assertEquals(2.02 / 3.04, m.right, eps)
    }

    @Test
    fun oneByFour() {
        for (c in 0 until 4) assertRect(listOf(c / 4.0, 0.0, (c + 1) / 4.0, 1.0), crop(1, 4, 0, c))
        val withBezel = crop(1, 4, 0, 3, 3.0, 3.0)
        assertRect(listOf(3 * 1.03 / 4.09, 0.0, 1.0, 1.0), withBezel)
    }

    @Test
    fun tilesCoverTheWholePictureWithoutOverlap() {
        for ((rows, cols) in listOf(2 to 2, 3 to 3, 1 to 4, 4 to 4, 2 to 3)) {
            var area = 0.0
            for (r in 0 until rows) for (c in 0 until cols) {
                val x = crop(rows, cols, r, c)
                area += (x.right - x.left) * (x.bottom - x.top)
            }
            assertEquals("$rows×$cols", 1.0, area, 1e-9)
        }
    }

    @Test
    fun transformShowsExactlyTheCrop() {
        // For each tile: the stage [0, W] × [0, H] maps back to the crop rect of the laid-out view.
        for ((rows, cols, bezel) in listOf(Triple(2, 2, 0.0), Triple(3, 3, 2.5), Triple(1, 4, 3.0), Triple(4, 4, 1.0))) {
            for (r in 0 until rows) for (c in 0 until cols) {
                val g = WallGeometry.of(rows, cols, r, c, bezel, bezel)!!
                val t = g.transform(1920, 1080)
                assertTrue(t.viewW <= 1920 && t.viewH <= 1080)
                val left = (0 - t.translateX) / t.scale / t.viewW
                val right = (1920 - t.translateX) / t.scale / t.viewW
                val top = (0 - t.translateY) / t.scale / t.viewH
                val bottom = (1080 - t.translateY) / t.scale / t.viewH
                val cr = g.cropRect()
                val tol = 2e-3 // view sizes are whole pixels
                assertEquals("$rows×$cols ($r,$c) left", cr.left, left.toDouble(), tol)
                assertEquals("$rows×$cols ($r,$c) right", cr.right, right.toDouble(), tol)
                assertEquals("$rows×$cols ($r,$c) top", cr.top, top.toDouble(), tol)
                assertEquals("$rows×$cols ($r,$c) bottom", cr.bottom, bottom.toDouble(), tol)
            }
        }
    }

    @Test
    fun transformNumbers() {
        // 2×2 of 1080p: lay out at 1920×1080, scale 2, tile (1,1) moves by one screen.
        assertEquals(WallTransform(1920, 1080, 2f, -1920f, -1080f), WallGeometry.of(2, 2, 1, 1)!!.transform(1920, 1080))
        // 1×4: the view has the wall's 64:9 shape (1920×270) and is scaled 4×.
        assertEquals(WallTransform(1920, 270, 4f, -3840f, 0f), WallGeometry.of(1, 4, 0, 2)!!.transform(1920, 1080))
        // Bezel 5 %: tile (0,1) starts 1.05 screens in.
        val t = WallGeometry.of(2, 2, 0, 1, 5.0, 5.0)!!.transform(1000, 500)
        assertEquals(-1050f, t.translateX, 0.001f)
        assertEquals(2.05f, t.scale, 0.0001f)
        assertEquals(1000, t.viewW)
        assertEquals(500, t.viewH)
    }

    @Test
    fun validationAndAudio() {
        assertNull(WallGeometry.of(5, 1, 0, 0))
        assertNull(WallGeometry.of(1, 1, 0, 0))
        assertNull(WallGeometry.of(2, 2, 2, 0))
        assertNull(WallGeometry.of(2, 2, 0, -1))
        assertNull(WallGeometry.from(null))
        assertNull(WallGeometry.from(WallData(rows = 2, cols = 2, row = 0)))
        assertEquals(0.5, WallGeometry.of(2, 2, 0, 0, 500.0, Double.NaN)!!.gapX, eps) // clamped to 50 %
        assertEquals(0.0, WallGeometry.of(2, 2, 0, 0, -3.0, Double.NaN)!!.gapY, eps)
        assertTrue(WallGeometry.from(WallData(rows = 2, cols = 2, row = 0, col = 0))!!.audio)
        assertFalse(WallGeometry.from(WallData(rows = 2, cols = 2, row = 0, col = 1))!!.audio)
        assertTrue(WallGeometry.from(WallData(rows = 2, cols = 2, row = 1, col = 1, audio = true))!!.audio)
        assertNotNull(WallGeometry.from(WallData(rows = 1, cols = 2, row = 0, col = 1, bezelXPct = 2.0)))
    }
}
