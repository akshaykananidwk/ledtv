package com.hotelcast.tv

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.util.AttributeSet
import android.view.View
import java.util.Calendar
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.sin

/** Simple self-updating analog clock (the framework AnalogClock is deprecated and not stylable). */
class AnalogClockView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
) : View(context, attrs) {

    private val facePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE }
    private val tickPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { strokeCap = Paint.Cap.ROUND }
    private val handPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { strokeCap = Paint.Cap.ROUND }
    private val secondPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { strokeCap = Paint.Cap.ROUND; color = Color.RED }

    var color: Int = Color.WHITE
        set(value) {
            field = value
            facePaint.color = value
            tickPaint.color = value
            handPaint.color = value
            invalidate()
        }

    private val ticker = object : Runnable {
        override fun run() {
            invalidate()
            postDelayed(this, 1000 - System.currentTimeMillis() % 1000)
        }
    }

    init {
        color = Color.WHITE
    }

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        post(ticker)
    }

    override fun onDetachedFromWindow() {
        removeCallbacks(ticker)
        super.onDetachedFromWindow()
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val cx = width / 2f
        val cy = height / 2f
        val r = min(cx, cy) * 0.85f
        if (r <= 0) return
        facePaint.strokeWidth = r * 0.03f
        canvas.drawCircle(cx, cy, r, facePaint)
        for (i in 0 until 60) {
            val a = Math.toRadians(i * 6.0)
            val major = i % 5 == 0
            tickPaint.strokeWidth = if (major) r * 0.03f else r * 0.012f
            val inner = if (major) r * 0.85f else r * 0.92f
            canvas.drawLine(
                cx + (inner * sin(a)).toFloat(), cy - (inner * cos(a)).toFloat(),
                cx + (r * 0.97f * sin(a)).toFloat(), cy - (r * 0.97f * cos(a)).toFloat(), tickPaint
            )
        }
        val cal = Calendar.getInstance()
        val s = cal.get(Calendar.SECOND)
        val m = cal.get(Calendar.MINUTE) + s / 60f
        val h = cal.get(Calendar.HOUR) + m / 60f
        hand(canvas, cx, cy, h * 30f, r * 0.5f, r * 0.06f, handPaint)
        hand(canvas, cx, cy, m * 6f, r * 0.75f, r * 0.04f, handPaint)
        hand(canvas, cx, cy, s * 6f, r * 0.85f, r * 0.015f, secondPaint)
        canvas.drawCircle(cx, cy, r * 0.04f, secondPaint)
    }

    private fun hand(c: Canvas, cx: Float, cy: Float, deg: Float, len: Float, w: Float, p: Paint) {
        val a = Math.toRadians(deg.toDouble())
        p.strokeWidth = w
        c.drawLine(cx, cy, cx + (len * sin(a)).toFloat(), cy - (len * cos(a)).toFloat(), p)
    }
}
