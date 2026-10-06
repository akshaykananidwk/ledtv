package com.hotelcast.tv

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Ticker contract (`content.overlay.ticker`) parsing, normalisation and bar / content-area geometry. */
class TickerLayoutTest {

    private fun parse(tickerJson: String): Ticker? =
        ApiClient.gson.fromJson("""{ "hash": "h", "overlay": { "ticker": $tickerJson } }""", Content::class.java).overlay?.ticker

    // ------------------------------------------------------------------ Gson

    @Test fun parsesFullContract() {
        val t = parse(
            """{ "text": "msg1   ✦   msg2", "messages": ["msg1","msg2"], "speed": 7,
                 "bg_color": "#112233", "text_color": "#FFD700", "font_size": 40, "height": 90,
                 "position": "top", "reserve_space": false }"""
        )!!
        assertEquals("msg1   ✦   msg2", t.text)
        assertEquals(listOf("msg1", "msg2"), t.messages)
        assertEquals(7, t.speed)
        assertEquals("#112233", t.bgColor)
        assertEquals("#FFD700", t.textColor)
        assertEquals(40.0, t.fontSize!!, 0.0)
        assertEquals(90.0, t.height!!, 0.0)
        assertEquals("top", t.position)
        assertEquals(false, t.reserveSpace)

        val s = TickerSpec.from(t)!!
        assertEquals("msg1   ✦   msg2", s.text)
        assertEquals(7, s.speed)
        assertEquals(40f, s.fontSizeSp, 0f)
        assertEquals(90f, s.heightDp, 0f)
        assertTrue(s.atTop)
        assertFalse(s.reserveSpace)
    }

    @Test fun oldServerTickerGetsDefaultsAndReservesSpace() {
        val t = parse("""{ "text": "મંગળા આરતી સવારે 6:30", "speed": 5, "bg_color": "#000000", "text_color": "#FFD700" }""")!!
        assertNull(t.fontSize)
        assertNull(t.height)
        assertNull(t.position)
        assertNull(t.reserveSpace)
        val s = TickerSpec.from(t)!!
        assertEquals("મંગળા આરતી સવારે 6:30", s.text)
        assertEquals(26f, s.fontSizeSp, 0f)
        assertEquals(56f, s.heightDp, 0f)
        assertFalse(s.atTop)
        assertTrue("old servers: the bar must still reserve space", s.reserveSpace)
        assertEquals("#000000", s.bgColor)
        assertEquals("#FFD700", s.textColor)
    }

    @Test fun lenientValuesFromPhpNeverBreakParsing() {
        assertEquals(true, parse("""{ "text": "a", "reserve_space": 1 }""")!!.reserveSpace)
        assertEquals(false, parse("""{ "text": "a", "reserve_space": 0 }""")!!.reserveSpace)
        assertEquals(false, parse("""{ "text": "a", "reserve_space": "0" }""")!!.reserveSpace)
        assertEquals(true, parse("""{ "text": "a", "reserve_space": "true" }""")!!.reserveSpace)
        assertNull(parse("""{ "text": "a", "reserve_space": null }""")!!.reserveSpace)
        assertNull(parse("""{ "text": "a", "reserve_space": {} }""")!!.reserveSpace)
        val t = parse("""{ "text": "a", "font_size": 30.5, "height": "64" }""")!!
        assertEquals(30.5, t.fontSize!!, 0.0)
        assertEquals(64.0, t.height!!, 0.0)
        assertTrue(TickerSpec.from(parse("""{ "text": "a", "reserve_space": null }"""))!!.reserveSpace)
    }

    @Test fun nullOrBlankTickerMeansNoBar() {
        assertNull(ApiClient.gson.fromJson("""{ "overlay": { "ticker": null } }""", Content::class.java).overlay?.ticker)
        assertNull(TickerSpec.from(null))
        assertNull(TickerSpec.from(Ticker(text = "   ")))
        assertNull(TickerSpec.from(Ticker(text = "", messages = listOf(" ", null))))
    }

    @Test fun messagesUsedWhenTextMissing() {
        val s = TickerSpec.from(Ticker(messages = listOf("Breakfast 7-10", null, " ", "Pool\nopen")))!!
        assertEquals("Breakfast 7-10" + TickerSpec.MESSAGE_SEPARATOR + "Pool open", s.text)
        assertEquals("a b", TickerSpec.from(Ticker(text = "a\r\nb", messages = listOf("x")))!!.text)
    }

    @Test fun valuesAreClamped() {
        val lo = TickerSpec.from(Ticker(text = "a", speed = -3, fontSize = 2.0, height = 1.0, position = "TOP"))!!
        assertEquals(1, lo.speed)
        assertEquals(14f, lo.fontSizeSp, 0f)
        assertEquals(32f, lo.heightDp, 0f)
        assertTrue(lo.atTop)
        val hi = TickerSpec.from(Ticker(text = "a", speed = 99, fontSize = 500.0, height = 9999.0, position = "middle"))!!
        assertEquals(10, hi.speed)
        assertEquals(72f, hi.fontSizeSp, 0f)
        assertEquals(200f, hi.heightDp, 0f)
        assertFalse(hi.atTop)
        val nan = TickerSpec.from(Ticker(text = "a", fontSize = Double.NaN, height = Double.POSITIVE_INFINITY))!!
        assertEquals(26f, nan.fontSizeSp, 0f)
        assertEquals(56f, nan.heightDp, 0f)
    }

    // ------------------------------------------------------------------ geometry

    @Test fun defaultBarAtBottomReservesItsHeight() {
        // 1080p Android TV: xhdpi (2.0)
        val l = TickerLayout.compute(TickerSpec("hello"), density = 2f, scaledDensity = 2f, screenHeightPx = 1080)
        assertTrue(l.visible)
        assertFalse(l.atTop)
        assertEquals(112, l.heightPx)
        assertEquals(52f, l.textSizePx, 0.01f)
        assertEquals(0, l.stageInsetTopPx)
        assertEquals(112, l.stageInsetBottomPx)
        assertEquals(0, l.overlayInsetTopPx)
        assertEquals(112, l.overlayInsetBottomPx)
    }

    @Test fun barAtTopMovesStageAndOverlayDown() {
        // 720p TV: tvdpi (1.33)
        val l = TickerLayout.compute(TickerSpec("hello", heightDp = 60f, atTop = true), density = 1.33125f, scaledDensity = 1.33125f, screenHeightPx = 720)
        assertTrue(l.atTop)
        assertEquals(80, l.heightPx) // ceil(60 × 1.33125)
        assertEquals(80, l.stageInsetTopPx)
        assertEquals(0, l.stageInsetBottomPx)
        assertEquals(80, l.overlayInsetTopPx)
        assertEquals(0, l.overlayInsetBottomPx)
    }

    @Test fun noReserveOverlaysContentButOverlayStillAvoidsBar() {
        val bottom = TickerLayout.compute(TickerSpec("x", reserveSpace = false), 1f)
        assertEquals(56, bottom.heightPx)
        assertEquals(0, bottom.stageInsetTopPx)
        assertEquals(0, bottom.stageInsetBottomPx)
        assertEquals(56, bottom.overlayInsetBottomPx)
        val top = TickerLayout.compute(TickerSpec("x", reserveSpace = false, atTop = true), 1f)
        assertEquals(0, top.stageInsetTopPx)
        assertEquals(56, top.overlayInsetTopPx)
    }

    @Test fun heightGrowsWhenFontDoesNotFit() {
        // 72 sp in a 40 dp bar at density 1: needs 72 × 1.5 + 8 = 116 px
        val l = TickerLayout.compute(TickerSpec("x", fontSizeSp = 72f, heightDp = 40f), density = 1f)
        assertEquals(116, l.heightPx)
        assertEquals(72f, l.textSizePx, 0.01f)
        assertEquals(116, l.stageInsetBottomPx)
        // Larger system font scale (sp > dp) also grows the bar.
        val scaled = TickerLayout.compute(TickerSpec("x"), density = 1f, scaledDensity = 1.5f)
        assertEquals(67, scaled.heightPx) // 26 × 1.5 × 1.5 + 8 = 66.5 → 67
        assertTextFits(scaled)
    }

    @Test fun barNeverTakesMoreThanAThirdOfTheScreenAndTextStillFits() {
        val l = TickerLayout.compute(TickerSpec("x", fontSizeSp = 72f, heightDp = 200f), density = 2f, scaledDensity = 2f, screenHeightPx = 480)
        assertEquals(163, l.heightPx) // floor(480 × 0.34)
        assertTextFits(l)
        assertTrue(l.textSizePx < 144f)
    }

    @Test fun textAlwaysFitsForAllClampedValues() {
        for (font in listOf(14f, 26f, 40f, 72f)) for (h in listOf(32f, 56f, 120f, 200f)) for (d in listOf(1f, 1.33125f, 2f)) {
            val l = TickerLayout.compute(TickerSpec("x", fontSizeSp = font, heightDp = h), d, d, screenHeightPx = (1080 * d / 2f).toInt())
            assertTextFits(l)
            assertTrue(l.heightPx >= 1)
        }
    }

    @Test fun hiddenWhenNoSpec() {
        val l = TickerLayout.compute(null, 2f)
        assertFalse(l.visible)
        assertEquals(TickerLayout.HIDDEN, l)
        assertEquals(0, l.stageInsetBottomPx + l.stageInsetTopPx + l.overlayInsetBottomPx + l.overlayInsetTopPx)
    }

    @Test fun sameSpecGivesEqualLayout() {
        // MainActivity / MarqueeView rely on value equality to skip re-applying an identical config.
        val a = TickerSpec.from(Ticker(text = "a", speed = 3, fontSize = 30.0))
        val b = TickerSpec.from(Ticker(text = "a", speed = 3, fontSize = 30.0))
        assertEquals(a, b)
        assertEquals(TickerLayout.compute(a, 2f, 2f, 1080), TickerLayout.compute(b, 2f, 2f, 1080))
    }

    // ------------------------------------------------------------------ long text chunks

    @Test fun longTextSplitsAtSpacesWithoutLosingCharacters() {
        val word = "મંગળા આરતી सुबह आरती Checkout "
        val text = word.repeat(200).trim()
        val chunks = TickerSpec.splitChunks(text)
        assertTrue(chunks.size > 10)
        assertEquals(text, chunks.joinToString(""))
        chunks.dropLast(1).forEach { assertTrue("chunk ends at a space", it.endsWith(" ")) }
        assertEquals(listOf("short"), TickerSpec.splitChunks("short"))
        assertEquals(emptyList<String>(), TickerSpec.splitChunks(""))
        val noSpaces = "x".repeat(500)
        assertEquals(listOf(noSpaces), TickerSpec.splitChunks(noSpaces))
    }

    @Test fun serverFixtureTickerParses() {
        val stream = javaClass.classLoader!!.getResourceAsStream("server_content_v2.json")
        assertNotNull(stream)
        val c = ApiClient.gson.fromJson(stream!!.bufferedReader(Charsets.UTF_8).use { it.readText() }, Content::class.java)
        val s = TickerSpec.from(c.overlay?.ticker)
        assertNotNull("fixture has a ticker", s)
        assertTrue(s!!.speed in 1..10)
        assertTrue(s.fontSizeSp in 14f..72f)
        assertTrue(s.heightDp in 32f..200f)
    }

    private fun assertTextFits(l: TickerLayout) {
        val needed = l.textSizePx * TickerLayout.LINE_FACTOR
        assertTrue("text ${l.textSizePx}px fits in ${l.heightPx}px", needed <= l.heightPx + 0.01f)
    }
}
