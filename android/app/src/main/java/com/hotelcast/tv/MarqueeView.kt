package com.hotelcast.tv

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.os.SystemClock
import android.text.TextPaint
import android.util.AttributeSet
import android.util.TypedValue
import android.view.View

/**
 * Smooth horizontally scrolling text (news-ticker). Unlike TextView's marquee it needs no focus,
 * supports a configurable speed and shapes complex scripts (Gujarati) via Canvas.drawText.
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
    private var textWidth = 0f
    private var offset = Float.NaN
    private var lastFrame = 0L
    private var pxPerSec = dp(120f)
    private var running = false

    /** @param speed 1 (slow) .. 10 (fast), as configured on the server. */
    fun configure(text: String, textColor: Int, bgColor: Int, speed: Int, textSizeSp: Float = 26f) {
        val newText = text.replace('\n', ' ').trim()
        val changed = newText != this.text
        this.text = newText
        paint.color = textColor
        paint.textSize = sp(textSizeSp)
        setBackgroundColor(bgColor)
        pxPerSec = dp(30f + speed.coerceIn(1, 10) * 25f)
        textWidth = paint.measureText(this.text)
        if (changed) offset = Float.NaN
        start()
    }

    fun start() {
        running = true
        lastFrame = 0L
        postInvalidateOnAnimation()
    }

    fun stop() {
        running = false
    }

    override fun onDetachedFromWindow() {
        super.onDetachedFromWindow()
        running = false
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        if (text.isNotEmpty()) start()
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        if (text.isEmpty() || width == 0) return
        val now = SystemClock.uptimeMillis()
        if (offset.isNaN()) offset = width.toFloat()
        if (lastFrame != 0L && running) {
            val dt = (now - lastFrame).coerceAtMost(100) / 1000f
            offset -= pxPerSec * dt
            if (offset < -textWidth) offset = width.toFloat()
        }
        lastFrame = now
        val y = height / 2f - (paint.descent() + paint.ascent()) / 2f
        canvas.drawText(text, offset, y, paint)
        if (running && visibility == VISIBLE) postInvalidateOnAnimation()
    }

    private fun sp(v: Float) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_SP, v, resources.displayMetrics)
    private fun dp(v: Float) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, resources.displayMetrics)
}
