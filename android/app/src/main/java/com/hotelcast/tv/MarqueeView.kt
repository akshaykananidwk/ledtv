package com.hotelcast.tv

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.text.TextPaint
import android.util.AttributeSet
import android.util.TypedValue
import android.view.View

/**
 * Smooth horizontally scrolling text (news-ticker). Unlike TextView's marquee it needs no focus,
 * supports a configurable speed and shapes complex scripts (Gujarati, Hindi) via Canvas.drawText.
 *
 * Long texts are split at spaces into chunks ([TickerSpec.splitChunks]) that are measured once; each frame only the chunks
 * that are on screen are drawn, so a very long ticker costs the same per frame as a short one.
 * Re-applying an identical configuration (every sync) is a no-op: the scroll keeps its position.
 */
class MarqueeView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyle: Int = 0,
) : View(context, attrs, defStyle) {

    private val paint = TextPaint(Paint.ANTI_ALIAS_FLAG or Paint.SUBPIXEL_TEXT_FLAG).apply {
        color = Color.WHITE
        textSize = sp(26f)
    }
    private var text: String = ""
    private var chunks: Array<String> = emptyArray()
    private var chunkX: FloatArray = FloatArray(0) // start of each chunk relative to the text start
    private var textWidth = 0f
    private var offset = Float.NaN
    private var lastFrameNanos = 0L
    private var pxPerSec = dp(120f)
    private var running = false

    private var cfgTextColor = Color.WHITE
    private var cfgBgColor = Color.TRANSPARENT
    private var cfgSpeed = -1
    private var cfgTextSizePx = -1f

    /** @param speed 1 (slow) .. 10 (fast), as configured on the server. */
    fun configure(text: String, textColor: Int, bgColor: Int, speed: Int, textSizeSp: Float = 26f) =
        configurePx(text, textColor, bgColor, speed, sp(textSizeSp))

    /**
     * Same as [configure] with the text size in px. Returns true if anything changed. A changed text
     * restarts the scroll from the right edge; colour / speed / size changes keep the position.
     */
    fun configurePx(text: String, textColor: Int, bgColor: Int, speed: Int, textSizePx: Float): Boolean {
        val newText = text.replace('\n', ' ').replace('\r', ' ').trim()
        val s = speed.coerceIn(1, 10)
        val unchanged = newText == this.text && textColor == cfgTextColor && bgColor == cfgBgColor &&
            s == cfgSpeed && textSizePx == cfgTextSizePx
        if (unchanged) {
            if (!running) start()
            return false
        }
        val textChanged = newText != this.text
        if (bgColor != cfgBgColor || cfgSpeed < 0) setBackgroundColor(bgColor)
        cfgTextColor = textColor
        cfgBgColor = bgColor
        cfgSpeed = s
        paint.color = textColor
        pxPerSec = dp(30f + s * 25f)
        val sizeChanged = textSizePx != cfgTextSizePx
        cfgTextSizePx = textSizePx
        paint.textSize = textSizePx
        if (textChanged || sizeChanged) {
            this.text = newText
            measureChunks()
        }
        if (textChanged) offset = Float.NaN
        start()
        invalidate()
        return true
    }

    private fun measureChunks() {
        val parts = TickerSpec.splitChunks(text)
        chunks = parts.toTypedArray()
        chunkX = FloatArray(parts.size)
        var x = 0f
        for (i in parts.indices) {
            chunkX[i] = x
            x += paint.measureText(parts[i])
        }
        textWidth = x
    }

    fun start() {
        if (running) {
            postInvalidateOnAnimation()
            return
        }
        running = true
        lastFrameNanos = 0L
        postInvalidateOnAnimation()
    }

    fun stop() {
        running = false
        lastFrameNanos = 0L
    }

    override fun onDetachedFromWindow() {
        super.onDetachedFromWindow()
        running = false
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        if (text.isNotEmpty()) start()
    }

    override fun onVisibilityChanged(changedView: View, visibility: Int) {
        super.onVisibilityChanged(changedView, visibility)
        // Resume without a jump: the first frame after becoming visible does not advance.
        lastFrameNanos = 0L
        if (visibility == VISIBLE && running) postInvalidateOnAnimation()
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        if (text.isEmpty() || width == 0) return
        val now = System.nanoTime()
        if (offset.isNaN()) offset = width.toFloat()
        if (lastFrameNanos != 0L && running) {
            val dt = ((now - lastFrameNanos) / 1_000_000_000f).coerceIn(0f, 0.1f)
            offset -= pxPerSec * dt
            if (offset < -textWidth) offset = width.toFloat()
        }
        lastFrameNanos = if (running) now else 0L
        val y = height / 2f - (paint.descent() + paint.ascent()) / 2f
        val w = width.toFloat()
        for (i in chunks.indices) {
            val x = offset + chunkX[i]
            if (x > w) break
            val end = if (i + 1 < chunkX.size) offset + chunkX[i + 1] else offset + textWidth
            if (end < 0f) continue
            canvas.drawText(chunks[i], x, y, paint)
        }
        if (running && isShown) postInvalidateOnAnimation()
    }

    private fun sp(v: Float) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_SP, v, resources.displayMetrics)
    private fun dp(v: Float) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, resources.displayMetrics)
}
