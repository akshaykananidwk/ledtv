package com.hotelcast.tv

import android.content.Context
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
import android.graphics.drawable.StateListDrawable
import android.os.Handler
import android.os.Looper
import android.text.TextUtils
import android.util.Log
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import com.bumptech.glide.Glide

/**
 * Builds the guest menu side panel (pure view construction, used by [GuestUi] and the
 * instrumentation test). Items are large, focusable rows navigable with the D-pad.
 */
object GuestMenuPanel {

    fun icon(name: String?, type: String?): String = when (name?.lowercase()) {
        "food", "restaurant", "room_service" -> "🍽"   // 🍽
        "star", "feedback" -> "⭐"                           // ⭐
        "tv", "live_tv" -> "📺"                        // 📺
        "hdmi", "input" -> "🔌"                        // 🔌
        "cast" -> "📡"                                 // 📡
        "map", "guide" -> "🗺"                         // 🗺
        "wifi" -> "📶"                                 // 📶
        "phone", "call" -> "📞"                        // 📞
        "info" -> "ℹ"                                       // ℹ
        "bell", "service" -> "🛎"                      // 🛎
        "taxi", "car" -> "🚕"                          // 🚕
        "spa" -> "💆"                                  // 💆
        "temple" -> "🛕"                               // 🛕
        else -> when (type) {
            GuestMenuItem.TYPE_QR -> "📱"                 // 📱
            GuestMenuItem.TYPE_LIVE_TV -> "📺"
            GuestMenuItem.TYPE_INPUT -> "🔌"
            GuestMenuItem.TYPE_CAST -> "📡"
            else -> "▶"                                     // ▶
        }
    }

    fun focusBackground(accent: Int, radiusPx: Float): Drawable {
        fun shape(fill: Int, stroke: Int?) = GradientDrawable().apply {
            cornerRadius = radiusPx
            setColor(fill)
            if (stroke != null) setStroke((radiusPx / 3).toInt().coerceAtLeast(3), stroke)
        }
        return StateListDrawable().apply {
            addState(intArrayOf(android.R.attr.state_pressed), shape(accent, Color.WHITE))
            addState(intArrayOf(android.R.attr.state_focused), shape(accent, Color.WHITE))
            addState(intArrayOf(), shape(Color.parseColor("#33FFFFFF"), null))
        }
    }

    /**
     * @return the panel; each item row has `tag = GuestMenuItem` and is clickable / focusable.
     */
    fun build(
        context: Context,
        title: String,
        subtitle: String?,
        hint: String,
        items: List<GuestMenuItem>,
        accent: Int,
        onSelect: (GuestMenuItem) -> Unit,
    ): LinearLayout {
        val d = context.resources.displayMetrics.density
        fun dp(v: Int) = (v * d).toInt()
        val panel = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(28), dp(28), dp(28), dp(20))
            background = GradientDrawable(GradientDrawable.Orientation.LEFT_RIGHT, intArrayOf(Color.parseColor("#E6101418"), Color.parseColor("#F5101418")))
            isClickable = true
        }
        panel.addView(TextView(context).apply {
            text = title
            setTextColor(Color.WHITE)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 28f)
            setTypeface(typeface, Typeface.BOLD)
            maxLines = 2
            ellipsize = TextUtils.TruncateAt.END
        })
        if (!subtitle.isNullOrBlank()) {
            panel.addView(TextView(context).apply {
                text = subtitle
                setTextColor(accent)
                setTextSize(TypedValue.COMPLEX_UNIT_SP, 18f)
            })
        }
        val list = LinearLayout(context).apply { orientation = LinearLayout.VERTICAL }
        val scroll = ScrollView(context).apply {
            isFillViewport = false
            isVerticalScrollBarEnabled = false
            addView(list)
        }
        panel.addView(scroll, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f).apply { topMargin = dp(18) })
        items.forEachIndexed { i, item ->
            val row = LinearLayout(context).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                isFocusable = true
                isFocusableInTouchMode = true
                isClickable = true
                background = focusBackground(accent, dp(12).toFloat())
                setPadding(dp(18), dp(12), dp(18), dp(12))
                tag = item
                id = View.generateViewId()
                setOnClickListener { onSelect(item) }
            }
            row.addView(TextView(context).apply {
                text = icon(item.icon, item.type)
                setTextSize(TypedValue.COMPLEX_UNIT_SP, 34f)
                gravity = Gravity.CENTER
                setTextColor(Color.WHITE)
            }, LinearLayout.LayoutParams(dp(64), ViewGroup.LayoutParams.WRAP_CONTENT))
            row.addView(TextView(context).apply {
                text = item.title?.takeIf { it.isNotBlank() } ?: item.id.orEmpty()
                setTextColor(Color.WHITE)
                setTextSize(TypedValue.COMPLEX_UNIT_SP, 24f)
                maxLines = 2
                ellipsize = TextUtils.TruncateAt.END
            }, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f).apply { marginStart = dp(12) })
            list.addView(row, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply {
                if (i > 0) topMargin = dp(10)
            })
        }
        // D-pad: wrap focus inside the list (never escape to the content behind)
        val rows = (0 until list.childCount).map { list.getChildAt(it) }
        rows.forEachIndexed { i, r ->
            r.nextFocusUpId = rows[(i - 1 + rows.size) % rows.size].id
            r.nextFocusDownId = rows[(i + 1) % rows.size].id
            r.nextFocusLeftId = r.id
            r.nextFocusRightId = r.id
        }
        panel.addView(TextView(context).apply {
            text = hint
            setTextColor(Color.parseColor("#B0BEC5"))
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 16f)
        }, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(12) })
        return panel
    }

    fun itemRows(panel: View): List<View> {
        val out = mutableListOf<View>()
        fun walk(v: View) {
            if (v.tag is GuestMenuItem) out += v
            if (v is ViewGroup) for (i in 0 until v.childCount) walk(v.getChildAt(i))
        }
        walk(panel)
        return out
    }
}

/**
 * Guest-facing layers of the player: personal welcome card, checkout reminder banner, message card,
 * guest menu side panel and its full-screen detail views (QR, cast instructions, embedded content).
 * All layers sit below the emergency layer. Every build step is wrapped so a bad server value can
 * never crash the kiosk. Main thread only.
 */
class GuestUi(
    private val activity: android.app.Activity,
    private val root: FrameLayout,
    private val emergencyLayer: View,
    private val cache: ContentCache,
    private val listener: Listener,
) {
    interface Listener {
        /** A full-screen guest layer opened (true) / closed (false): pause / resume the main player. */
        fun onFullScreenLayer(open: Boolean)
        /** Live TV / HDMI selected in the menu. */
        fun onSwitchInput(input: String, label: String)
        fun onItemPlayed(item: ContentItem, startedAtMillis: Long, durationSec: Int)
    }

    private val handler = Handler(Looper.getMainLooper())
    private val d = activity.resources.displayMetrics.density
    private fun dp(v: Int) = (v * d).toInt()

    private var welcomeView: View? = null
    private var reminderView: View? = null
    private var reminderId: String? = null
    private var messageView: View? = null
    private var menuView: View? = null
    private var detailView: View? = null
    private var detailPlayer: ContentPlayer? = null
    private var menuContent: Content? = null

    val welcomeVisible get() = welcomeView != null
    val reminderVisible get() = reminderView != null
    val messageVisible get() = messageView != null
    val menuOpen get() = menuView != null
    val detailOpen get() = detailView != null
    /** True while the main playlist must not play (welcome card or a full-screen detail). */
    val blocksPlayback get() = welcomeVisible || detailOpen

    private val hideWelcomeRunnable = Runnable { hideWelcome(notify = true) }
    private val hideMessageRunnable = Runnable { hideMessage() }
    private val reminderTimeout = Runnable {
        reminderId?.let { GuestSession.remindersHidden.add(it) }
        removeReminder()
    }
    private val menuTimeout = Runnable { closeAll() }

    // ------------------------------------------------------------------ helpers

    fun localized(c: Content?): Context = GuestLocale.context(activity, c?.guest?.language)

    fun accent(c: Content?): Int = Utils.parseColor(c?.branding?.color, Color.parseColor("#FFC107"))

    private fun addLayer(v: View, lp: FrameLayout.LayoutParams) {
        val idx = root.indexOfChild(emergencyLayer).let { if (it < 0) root.childCount else it }
        root.addView(v, idx, lp)
    }

    private fun removeLayer(v: View?) {
        if (v == null) return
        try {
            v.animate().cancel()
            root.removeView(v)
        } catch (e: Exception) {
            Log.w(TAG, "remove layer failed", e)
        }
    }

    private fun text(ctx: Context, value: CharSequence?, sp: Float, color: Int = Color.WHITE, bold: Boolean = false, center: Boolean = false) =
        TextView(ctx).apply {
            text = value
            setTextColor(color)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, sp)
            if (bold) setTypeface(typeface, Typeface.BOLD)
            if (center) gravity = Gravity.CENTER_HORIZONTAL
            setLineSpacing(0f, 1.1f)
        }

    private fun qrView(content: String?, sizeDp: Int): ImageView? {
        val bmp = QrCodes.bitmap(content, dp(sizeDp)) ?: return null
        return ImageView(activity).apply {
            setImageBitmap(bmp)
            setBackgroundColor(Color.WHITE)
            setPadding(dp(8), dp(8), dp(8), dp(8))
            scaleType = ImageView.ScaleType.FIT_CENTER
            contentDescription = null
        }
    }

    private fun logoView(c: Content?, heightDp: Int): ImageView? {
        val url = c?.hotel?.logoUrl?.takeIf { it.isNotBlank() } ?: c?.branding?.logoUrl?.takeIf { it.isNotBlank() } ?: return null
        return try {
            ImageView(activity).apply {
                adjustViewBounds = true
                maxHeight = dp(heightDp)
                maxWidth = dp(heightDp * 3)
                scaleType = ImageView.ScaleType.FIT_CENTER
                Glide.with(activity).load(cache.cachedFile(url) ?: url).into(this)
            }
        } catch (e: Exception) {
            null
        }
    }

    private fun card(bg: Int, stroke: Int?, radius: Int = 18) = GradientDrawable().apply {
        cornerRadius = dp(radius).toFloat()
        setColor(bg)
        if (stroke != null) setStroke(dp(3), stroke)
    }

    // ------------------------------------------------------------------ welcome card (#1)

    fun showWelcome(c: Content) {
        hideWelcome(notify = false)
        closeAll(notify = false)
        val w = c.welcome ?: return
        try {
            val ctx = localized(c)
            val accent = accent(c)
            val layer = FrameLayout(activity).apply {
                background = GradientDrawable(GradientDrawable.Orientation.TL_BR, intArrayOf(Color.parseColor("#FF0B1020"), Color.parseColor("#FF1A1030")))
                isClickable = true
                setOnClickListener { hideWelcome(notify = true) }
            }
            val row = LinearLayout(activity).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                setPadding(dp(56), dp(40), dp(56), dp(40))
            }
            val left = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
            logoView(c, 110)?.let { left.addView(it, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { bottomMargin = dp(20) }) }
            val name = c.guest?.name?.takeIf { it.isNotBlank() } ?: c.guest?.firstName
            val title = w.title?.takeIf { it.isNotBlank() }
                ?: if (!name.isNullOrBlank()) ctx.getString(R.string.guest_welcome_title, name)
                else c.hotel?.name?.let { ctx.getString(R.string.welcome_to, it) } ?: ctx.getString(R.string.welcome)
            left.addView(text(ctx, title, 46f, Color.WHITE, bold = true))
            left.addView(View(activity).apply { setBackgroundColor(accent) }, LinearLayout.LayoutParams(dp(120), dp(5)).apply { topMargin = dp(16); bottomMargin = dp(16) })
            if (!w.message.isNullOrBlank()) left.addView(text(ctx, w.message, 24f, Color.parseColor("#ECEFF1")))
            val room = c.room?.number ?: Prefs.roomNumber
            if (room.isNotBlank()) left.addView(text(ctx, ctx.getString(R.string.room_label, room), 22f, accent), LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(14) })
            left.addView(text(ctx, ctx.getString(R.string.guest_press_any_key), 16f, Color.parseColor("#90A4AE")), LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(28) })
            row.addView(left, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f))

            val right = LinearLayout(activity).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.TOP
            }
            val wifi = w.wifi
            if (!wifi?.ssid.isNullOrBlank()) {
                val col = qrBlock(ctx, WifiQr.build(wifi!!.ssid, wifi.password), ctx.getString(R.string.guest_scan_wifi), 170)
                col.addView(text(ctx, ctx.getString(R.string.guest_wifi_network, wifi.ssid), 18f, Color.WHITE, center = true))
                if (!wifi.password.isNullOrBlank()) col.addView(text(ctx, ctx.getString(R.string.guest_wifi_password, wifi.password), 18f, Color.WHITE, center = true))
                right.addView(col)
            }
            val services = c.services
            if (services?.enabled == true && !services.url.isNullOrBlank()) {
                val label = services.label?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.guest_scan_services)
                right.addView(qrBlock(ctx, services.url, label, 170), LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { marginStart = dp(28) })
            }
            if (right.childCount > 0) row.addView(right, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { marginStart = dp(32) })
            layer.addView(row, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER_VERTICAL))
            addLayer(layer, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
            welcomeView = layer
            removeReminder()
            layer.alpha = 0f
            layer.animate().alpha(1f).setDuration(500).start()
            handler.postDelayed(hideWelcomeRunnable, GuestPrompts.welcomeDurationSec(w) * 1000L)
            listener.onFullScreenLayer(true)
        } catch (e: Throwable) {
            Log.e(TAG, "welcome card failed", e)
            hideWelcome(notify = true)
        }
    }

    private fun qrBlock(ctx: Context, qrText: String?, caption: String, sizeDp: Int): LinearLayout {
        val col = LinearLayout(activity).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_HORIZONTAL
            background = card(Color.parseColor("#26FFFFFF"), null)
            setPadding(dp(16), dp(16), dp(16), dp(16))
        }
        qrView(qrText, sizeDp)?.let { col.addView(it, LinearLayout.LayoutParams(dp(sizeDp), dp(sizeDp))) }
        col.addView(text(ctx, caption, 17f, Color.parseColor("#FFE082"), center = true).apply { maxWidth = dp(sizeDp + 40) },
            LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(10) })
        return col
    }

    fun hideWelcome(notify: Boolean) {
        handler.removeCallbacks(hideWelcomeRunnable)
        val v = welcomeView ?: return
        welcomeView = null
        removeLayer(v)
        if (notify) listener.onFullScreenLayer(false)
    }

    // ------------------------------------------------------------------ checkout reminder (#6)

    /** Shows the banner for [c]'s checkout_reminder (caller decided it should be visible). */
    fun showReminder(c: Content) {
        val r = c.checkoutReminder ?: return
        if (reminderView != null && reminderId == r.id) return
        removeReminder()
        try {
            val ctx = localized(c)
            val accent = accent(c)
            val bar = LinearLayout(activity).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                background = card(Color.parseColor("#EE15202B"), accent)
                setPadding(dp(22), dp(14), dp(22), dp(14))
                isClickable = true
                setOnClickListener { dismissReminder() }
            }
            bar.addView(text(ctx, "🧳", 34f)) // 🧳
            val col = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
            col.addView(text(ctx, r.text, 22f, Color.WHITE, bold = true))
            col.addView(text(ctx, ctx.getString(R.string.guest_reminder_hint), 15f, Color.parseColor("#B0BEC5")))
            bar.addView(col, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f).apply { marginStart = dp(16); marginEnd = dp(16) })
            val url = c.services?.takeIf { it.enabled == true }?.url
            qrView(url, 96)?.let { bar.addView(it, LinearLayout.LayoutParams(dp(96), dp(96))) }
            addLayer(bar, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.BOTTOM).apply {
                leftMargin = dp(80); rightMargin = dp(80); bottomMargin = dp(76)
            })
            reminderView = bar
            reminderId = r.id
            handler.postDelayed(reminderTimeout, REMINDER_VISIBLE_MS)
        } catch (e: Throwable) {
            Log.e(TAG, "reminder failed", e)
            removeReminder()
        }
    }

    /** Guest dismissed the banner: never show this reminder id again. */
    fun dismissReminder() {
        val id = reminderId
        if (id != null) {
            val set = IdSet.parse(Prefs.dismissedReminderIds)
            set.add(id)
            Prefs.dismissedReminderIds = set.serialize()
        }
        removeReminder()
    }

    fun removeReminder() {
        handler.removeCallbacks(reminderTimeout)
        removeLayer(reminderView)
        reminderView = null
        reminderId = null
    }

    // ------------------------------------------------------------------ message card (SHOW_MESSAGE)

    fun showMessage(c: Content?, title: String?, message: String?, durationSec: Int) {
        hideMessage()
        try {
            val ctx = localized(c)
            val accent = accent(c)
            val box = LinearLayout(activity).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER_HORIZONTAL
                background = card(Color.parseColor("#F01C232B"), accent, 22)
                setPadding(dp(36), dp(26), dp(36), dp(22))
                isClickable = true
                setOnClickListener { hideMessage() }
            }
            if (!title.isNullOrBlank()) box.addView(text(ctx, title, 32f, Color.WHITE, bold = true, center = true))
            if (!message.isNullOrBlank()) box.addView(text(ctx, message, 24f, Color.parseColor("#ECEFF1"), center = true),
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(8) })
            box.addView(text(ctx, ctx.getString(R.string.guest_message_hint), 15f, Color.parseColor("#90A4AE"), center = true),
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(14) })
            addLayer(box, FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER).apply {
                leftMargin = dp(120); rightMargin = dp(120)
            })
            messageView = box
            box.alpha = 0f
            box.animate().alpha(1f).setDuration(300).start()
            handler.postDelayed(hideMessageRunnable, durationSec.coerceIn(3, 3600) * 1000L)
        } catch (e: Throwable) {
            Log.e(TAG, "message failed", e)
            hideMessage()
        }
    }

    fun hideMessage() {
        handler.removeCallbacks(hideMessageRunnable)
        removeLayer(messageView)
        messageView = null
    }

    // ------------------------------------------------------------------ guest menu

    fun openMenu(c: Content, items: List<GuestMenuItem>) {
        if (items.isEmpty()) return
        closeAll(notify = false)
        try {
            val ctx = localized(c)
            val title = c.hotel?.name?.takeIf { it.isNotBlank() } ?: c.branding?.product?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.guest_menu_title)
            val room = c.room?.number ?: Prefs.roomNumber
            val subtitle = if (room.isNotBlank()) ctx.getString(R.string.room_label, room) else null
            val panel = GuestMenuPanel.build(activity, title, subtitle, ctx.getString(R.string.guest_menu_hint), items, accent(c)) { onSelect(it) }
            addLayer(panel, FrameLayout.LayoutParams(dp(400), ViewGroup.LayoutParams.MATCH_PARENT, Gravity.END))
            menuView = panel
            menuContent = c
            panel.translationX = dp(400).toFloat()
            panel.animate().translationX(0f).setDuration(220).start()
            GuestMenuPanel.itemRows(panel).firstOrNull()?.requestFocus()
            touch()
        } catch (e: Throwable) {
            Log.e(TAG, "guest menu failed", e)
            closeAll()
        }
    }

    /** Any key while the menu is open: restart the 60 s auto-close timer. */
    fun touch() {
        handler.removeCallbacks(menuTimeout)
        if (menuView != null || detailView != null) {
            handler.postDelayed(menuTimeout, if (detailPlayer != null) CONTENT_DETAIL_TIMEOUT_MS else MENU_TIMEOUT_MS)
        }
    }

    /** BACK: detail → menu → closed. */
    fun back() {
        when {
            detailView != null -> {
                closeDetail()
                menuView?.let { m ->
                    val rows = GuestMenuPanel.itemRows(m)
                    if (rows.none { it.isFocused }) rows.firstOrNull()?.requestFocus()
                }
                touch()
            }
            menuView != null -> closeAll()
        }
    }

    fun closeAll(notify: Boolean = true) {
        handler.removeCallbacks(menuTimeout)
        closeDetail(notify)
        removeLayer(menuView)
        menuView = null
        menuContent = null
    }

    /** Emergency / power off / suspended: remove every guest layer at once. */
    fun hideAll() {
        closeAll(notify = false)
        hideWelcome(notify = false)
        hideMessage()
        removeReminder()
    }

    private fun onSelect(item: GuestMenuItem) {
        val c = menuContent ?: SyncManager.content.value ?: return
        touch()
        SyncManager.reportEvent("guest_menu_item", mapOf("id" to item.id, "type" to item.type))
        when (item.type) {
            GuestMenuItem.TYPE_QR -> {
                openQrDetail(c, item)
                SyncManager.reportEvent("qr_shown", mapOf("id" to item.id, "url" to item.url))
            }
            GuestMenuItem.TYPE_CAST -> openCastDetail(c, item)
            GuestMenuItem.TYPE_CONTENT -> openContentDetail(c, item)
            GuestMenuItem.TYPE_LIVE_TV -> listener.onSwitchInput("live_tv", item.title ?: localized(c).getString(R.string.live_tv))
            GuestMenuItem.TYPE_INPUT -> listener.onSwitchInput(item.input.orEmpty(), item.title ?: item.input.orEmpty())
        }
    }

    private fun detailFrame(): FrameLayout = FrameLayout(activity).apply {
        setBackgroundColor(Color.parseColor("#F20B1020"))
        isClickable = true
    }

    private fun showDetail(v: View) {
        closeDetail(notify = false)
        addLayer(v, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
        detailView = v
        // keep the side panel (if open) out of the way but alive for BACK
        menuView?.visibility = View.GONE
        listener.onFullScreenLayer(true)
        touch()
    }

    private fun closeDetail(notify: Boolean = true) {
        val v = detailView ?: return
        detailView = null
        try {
            detailPlayer?.release()
        } catch (e: Throwable) {
            Log.w(TAG, "detail player release failed", e)
        }
        detailPlayer = null
        removeLayer(v)
        menuView?.visibility = View.VISIBLE
        if (notify) listener.onFullScreenLayer(false)
    }

    private fun openQrDetail(c: Content, item: GuestMenuItem) {
        try {
            val ctx = localized(c)
            val frame = detailFrame()
            val col = LinearLayout(activity).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER_HORIZONTAL
            }
            col.addView(text(ctx, item.title ?: "", 40f, Color.WHITE, bold = true, center = true))
            val qr = qrView(item.url, 380)
            if (qr != null) col.addView(qr, LinearLayout.LayoutParams(dp(300), dp(300)).apply { topMargin = dp(18) })
            col.addView(text(ctx, ctx.getString(R.string.guest_qr_hint), 20f, accent(c), center = true),
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(16) })
            col.addView(text(ctx, item.url, 16f, Color.parseColor("#B0BEC5"), center = true).apply { maxLines = 2; ellipsize = TextUtils.TruncateAt.MIDDLE },
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(6) })
            col.addView(text(ctx, ctx.getString(R.string.guest_back_hint), 15f, Color.parseColor("#90A4AE"), center = true),
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(18) })
            frame.addView(col, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER))
            showDetail(frame)
        } catch (e: Throwable) {
            Log.e(TAG, "QR detail failed", e)
            closeDetail()
        }
    }

    private fun openCastDetail(c: Content, item: GuestMenuItem) {
        try {
            val ctx = localized(c)
            val frame = detailFrame()
            val row = LinearLayout(activity).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                setPadding(dp(64), dp(32), dp(64), dp(32))
            }
            val left = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
            left.addView(text(ctx, item.title?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.guest_cast_title), 40f, Color.WHITE, bold = true))
            left.addView(text(ctx, item.text?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.guest_cast_default), 24f, Color.parseColor("#ECEFF1")),
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(16) })
            left.addView(text(ctx, ctx.getString(R.string.guest_back_hint), 15f, Color.parseColor("#90A4AE")),
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(24) })
            row.addView(left, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f))
            val wifi = c.welcome?.wifi
            if (!wifi?.ssid.isNullOrBlank()) {
                val col = qrBlock(ctx, WifiQr.build(wifi!!.ssid, wifi.password), ctx.getString(R.string.guest_scan_wifi), 220)
                col.addView(text(ctx, ctx.getString(R.string.guest_wifi_network, wifi.ssid), 18f, Color.WHITE, center = true))
                if (!wifi.password.isNullOrBlank()) col.addView(text(ctx, ctx.getString(R.string.guest_wifi_password, wifi.password), 18f, Color.WHITE, center = true))
                row.addView(col, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { marginStart = dp(40) })
            }
            frame.addView(row, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER))
            showDetail(frame)
        } catch (e: Throwable) {
            Log.e(TAG, "cast detail failed", e)
            closeDetail()
        }
    }

    private fun openContentDetail(c: Content, item: GuestMenuItem) {
        val ci = item.content ?: return
        try {
            val ctx = localized(c)
            val frame = detailFrame().apply { setBackgroundColor(Color.BLACK) }
            val stage = FrameLayout(activity)
            frame.addView(stage, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
            val hint = text(ctx, ctx.getString(R.string.guest_back_hint), 15f, Color.WHITE).apply {
                background = card(Color.parseColor("#99000000"), null, 12)
                setPadding(dp(14), dp(6), dp(14), dp(6))
            }
            frame.addView(hint, FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.BOTTOM or Gravity.END).apply {
                rightMargin = dp(24); bottomMargin = dp(20)
            })
            hint.postDelayed({ hint.animate().alpha(0f).setDuration(600).start() }, 6000)
            showDetail(frame)
            val player = ContentPlayer(activity, stage, cache, object : ContentPlayer.Listener {
                override fun onItemStarted(item: ContentItem) {}
                override fun onItemFinished(item: ContentItem, startedAtMillis: Long, durationSec: Int) =
                    listener.onItemPlayed(item, startedAtMillis, durationSec)
                override fun onNothingToPlay() {
                    stage.addView(text(ctx, ctx.getString(R.string.input_unavailable), 26f, Color.WHITE, center = true),
                        FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER))
                }
            })
            detailPlayer = player
            touch()
            player.setContent(Content(hash = "guest-menu-${item.id}", mode = "assigned", items = listOf(ci), playlist = Playlist(loop = true, transition = "none"), overlay = c.overlay), force = true)
        } catch (e: Throwable) {
            Log.e(TAG, "content detail failed", e)
            closeDetail()
        }
    }

    fun release() {
        handler.removeCallbacksAndMessages(null)
        hideAll()
    }

    companion object {
        private const val TAG = "GuestUi"
        const val MENU_TIMEOUT_MS = 60_000L
        const val CONTENT_DETAIL_TIMEOUT_MS = 10 * 60_000L
        const val REMINDER_VISIBLE_MS = 2 * 60_000L
    }
}
