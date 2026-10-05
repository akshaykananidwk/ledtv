@file:Suppress("DEPRECATION") // ExoPlayer 2.x is deprecated in favour of Media3; 2.19.1 is used deliberately.

package com.hotelcast.tv

import android.animation.Animator
import android.animation.AnimatorListenerAdapter
import android.annotation.SuppressLint
import android.content.Context
import android.graphics.Color
import android.graphics.drawable.Drawable
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import com.bumptech.glide.Glide
import com.bumptech.glide.load.DataSource
import com.bumptech.glide.load.engine.GlideException
import com.bumptech.glide.request.RequestListener
import com.bumptech.glide.request.target.Target
import com.google.android.exoplayer2.ExoPlayer
import com.google.android.exoplayer2.MediaItem
import com.google.android.exoplayer2.PlaybackException
import com.google.android.exoplayer2.Player
import com.google.android.exoplayer2.source.DefaultMediaSourceFactory
import com.google.android.exoplayer2.source.rtsp.RtspMediaSource
import com.google.android.exoplayer2.ui.AspectRatioFrameLayout
import com.google.android.exoplayer2.ui.StyledPlayerView
import com.google.android.exoplayer2.upstream.DefaultDataSource
import com.google.android.exoplayer2.upstream.DefaultHttpDataSource
import com.google.android.exoplayer2.util.MimeTypes
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Renders a [Content] playlist into [stage]: images (fade/slide transitions), videos (loop/once, mute),
 * live streams (HLS/RTSP/DASH/progressive with auto-retry), WebView items (timetable/html/url/youtube),
 * announcements (fullscreen/marquee) and clocks (digital/analog).
 *
 * Rendering never throws: a failing item is logged and skipped; if every item fails the
 * [Listener.onNothingToPlay] fallback is shown and playback is retried later.
 * Must be used from the main thread.
 */
class ContentPlayer(
    private val context: Context,
    private val stage: FrameLayout,
    private val cache: ContentCache,
    private val listener: Listener,
) {
    interface Listener {
        fun onItemStarted(item: ContentItem)
        fun onItemFinished(item: ContentItem, startedAtMillis: Long, durationSec: Int)
        fun onNothingToPlay()
    }

    private val handler = Handler(Looper.getMainLooper())
    private var content: Content? = null
    private var items: List<ContentItem> = emptyList()
    private var index = -1
    private var currentItem: ContentItem? = null
    private var currentView: View? = null
    private var itemStartedAt = 0L
    private var player: ExoPlayer? = null
    private var playerRetries = 0
    private var consecutiveFailures = 0
    private var transition = "fade"
    private var loopPlaylist = true

    val currentHash: String? get() = content?.hash
    val isActive: Boolean get() = content != null
    val currentItemId: Long? get() = currentItem?.id

    private val advanceRunnable = Runnable { advance() }
    private val retryRunnable = Runnable { retryCurrent() }
    private val refreshRunnable = Runnable { refreshCurrentWeb() }
    private val clockTicker = object : Runnable {
        override fun run() {
            (currentView?.getTag(TAG_CLOCK_UPDATER) as? (() -> Unit))?.invoke()
            handler.postDelayed(this, 1000 - System.currentTimeMillis() % 1000)
        }
    }

    // ------------------------------------------------------------------ public API

    /** Starts playing [newContent]. No-op if the same content is already playing (unless [force]). */
    fun setContent(newContent: Content, force: Boolean = false) {
        if (!force && content != null && content?.hash == newContent.hash && !newContent.hash.isNullOrBlank()) return
        stop()
        content = newContent
        items = newContent.playableItems()
        transition = newContent.playlist?.transition?.lowercase() ?: "fade"
        loopPlaylist = newContent.playlist?.loop != false
        consecutiveFailures = 0
        if (items.isEmpty()) {
            listener.onNothingToPlay()
            return
        }
        showItem(0)
    }

    /** Stops everything and clears the stage (activity stop, screen off, emergency, new content). */
    fun stop() {
        handler.removeCallbacksAndMessages(null)
        finishCurrentItem()
        releasePlayer()
        for (i in stage.childCount - 1 downTo 0) disposeView(stage.getChildAt(i))
        stage.removeAllViews()
        currentView = null
        currentItem = null
        content = null
        items = emptyList()
        index = -1
    }

    fun release() = stop()

    // ------------------------------------------------------------------ playlist

    private fun showItem(i: Int) {
        handler.removeCallbacks(advanceRunnable)
        handler.removeCallbacks(retryRunnable)
        handler.removeCallbacks(refreshRunnable)
        handler.removeCallbacks(clockTicker)
        finishCurrentItem()
        releasePlayer()
        if (items.isEmpty()) return
        index = i.coerceIn(0, items.lastIndex)
        val item = items[index]
        currentItem = item
        itemStartedAt = System.currentTimeMillis()
        playerRetries = 0
        val view = try {
            buildView(item)
        } catch (e: Throwable) {
            Log.e(TAG, "Cannot render item ${item.id} (${item.type})", e)
            null
        }
        if (view == null) {
            onItemError(item)
            return
        }
        swapIn(view)
        try {
            listener.onItemStarted(item)
        } catch (e: Exception) {
            Log.w(TAG, "listener failed", e)
        }
        scheduleAdvance(item)
    }

    private fun scheduleAdvance(item: ContentItem) {
        if (items.size <= 1) return
        val d = item.durationSec
        when {
            d > 0 -> handler.postDelayed(advanceRunnable, d * 1000L)
            // duration 0 in a playlist: videos advance when they end; finite-by-nature items get a default.
            item.type == ContentItem.TYPE_VIDEO -> Unit
            else -> handler.postDelayed(advanceRunnable, DEFAULT_ITEM_SEC * 1000L)
        }
    }

    private fun advance() {
        if (items.isEmpty()) return
        var next = index + 1
        if (next > items.lastIndex) {
            if (!loopPlaylist) return // stay on the last item
            next = 0
        }
        if (items.size == 1 && next == index) return
        showItem(next)
    }

    private fun finishCurrentItem() {
        val item = currentItem ?: return
        val dur = ((System.currentTimeMillis() - itemStartedAt) / 1000).toInt()
        try {
            listener.onItemFinished(item, itemStartedAt, dur)
        } catch (e: Exception) {
            Log.w(TAG, "listener failed", e)
        }
        currentItem = null
    }

    private fun onItemError(item: ContentItem) {
        consecutiveFailures++
        if (consecutiveFailures >= items.size.coerceAtLeast(1)) {
            Log.w(TAG, "All items failed – showing fallback, retrying in ${FAILED_RETRY_SEC}s")
            consecutiveFailures = 0
            listener.onNothingToPlay()
            handler.removeCallbacks(advanceRunnable)
            handler.postDelayed({ showItem(if (items.size > 1) (index + 1) % items.size else 0) }, FAILED_RETRY_SEC * 1000L)
            return
        }
        if (items.size > 1) {
            handler.removeCallbacks(advanceRunnable)
            handler.postDelayed(advanceRunnable, 1500)
        } else {
            handler.removeCallbacks(retryRunnable)
            handler.postDelayed(retryRunnable, 15_000)
        }
        Log.w(TAG, "Item ${item.id} (${item.type}) failed")
    }

    private fun onItemOk() {
        consecutiveFailures = 0
    }

    private fun retryCurrent() {
        if (items.isNotEmpty()) showItem(index.coerceAtLeast(0))
    }

    // ------------------------------------------------------------------ transitions

    private fun swapIn(view: View) {
        val old = currentView
        currentView = view
        stage.addView(view, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
        if (view.getTag(TAG_CLOCK_UPDATER) != null) handler.post(clockTicker)
        if (old == null) return
        val w = stage.width.toFloat().takeIf { it > 0 } ?: 1920f
        val done = object : AnimatorListenerAdapter() {
            override fun onAnimationEnd(animation: Animator) = removeOld(old)
            override fun onAnimationCancel(animation: Animator) = removeOld(old)
        }
        when (transition) {
            "none", "cut" -> removeOld(old)
            "slide", "slide_left" -> {
                view.translationX = w
                view.animate().translationX(0f).setDuration(TRANSITION_MS).start()
                old.animate().translationX(-w).setDuration(TRANSITION_MS).setListener(done).start()
            }
            else -> {
                view.alpha = 0f
                view.animate().alpha(1f).setDuration(TRANSITION_MS).setListener(object : AnimatorListenerAdapter() {
                    override fun onAnimationEnd(animation: Animator) = removeOld(old)
                    override fun onAnimationCancel(animation: Animator) = removeOld(old)
                }).start()
            }
        }
    }

    private fun removeOld(old: View) {
        if (old === currentView) return
        disposeView(old)
        stage.removeView(old)
    }

    private fun disposeView(v: View?) {
        try {
            v?.animate()?.setListener(null)?.cancel()
            when (v) {
                is WebView -> {
                    v.stopLoading()
                    v.webChromeClient = null
                    v.loadUrl("about:blank")
                    (v.parent as? ViewGroup)?.removeView(v)
                    v.removeAllViews()
                    v.destroy()
                }
                is StyledPlayerView -> v.player = null
                is ImageView -> try { Glide.with(context.applicationContext).clear(v) } catch (_: Exception) { }
                is MarqueeView -> v.stop()
                is ViewGroup -> for (i in 0 until v.childCount) {
                    val c = v.getChildAt(i)
                    if (c is MarqueeView) c.stop()
                }
            }
        } catch (e: Throwable) {
            Log.w(TAG, "disposeView failed", e)
        }
    }

    // ------------------------------------------------------------------ item views

    private fun buildView(item: ContentItem): View? = when (item.type) {
        ContentItem.TYPE_IMAGE -> buildImage(item)
        ContentItem.TYPE_VIDEO, ContentItem.TYPE_STREAM -> buildPlayer(item)
        ContentItem.TYPE_TIMETABLE, ContentItem.TYPE_HTML -> buildWeb(item, html = item.html)
        ContentItem.TYPE_URL -> buildWeb(item, url = item.url)
        ContentItem.TYPE_YOUTUBE -> buildWeb(item, url = youtubeUrl(item))
        ContentItem.TYPE_ANNOUNCEMENT -> buildAnnouncement(item)
        ContentItem.TYPE_CLOCK -> buildClock(item)
        else -> null
    }

    private fun buildImage(item: ContentItem): View {
        val iv = ImageView(context).apply {
            scaleType = ImageView.ScaleType.FIT_CENTER
            setBackgroundColor(Color.BLACK)
        }
        val url = item.url!!
        val local = cache.cachedFile(url)
        Glide.with(context.applicationContext)
            .load(local ?: url)
            .listener(object : RequestListener<Drawable> {
                override fun onLoadFailed(e: GlideException?, model: Any?, target: Target<Drawable>, isFirstResource: Boolean): Boolean {
                    Log.w(TAG, "Image failed: $url (${e?.message})")
                    if (model is File) cache.deleteMedia(url)
                    handler.post { if (currentItem === item) onItemError(item) }
                    return false
                }

                override fun onResourceReady(resource: Drawable, model: Any, target: Target<Drawable>?, dataSource: DataSource, isFirstResource: Boolean): Boolean {
                    handler.post { onItemOk() }
                    return false
                }
            })
            .into(iv)
        return iv
    }

    private fun buildPlayer(item: ContentItem): View {
        val isStream = item.type == ContentItem.TYPE_STREAM
        val url = item.url!!
        val local = if (isStream) null else cache.cachedFile(url)
        val uri: Uri = if (local != null) Uri.fromFile(local) else Uri.parse(url)

        val http = DefaultHttpDataSource.Factory()
            .setAllowCrossProtocolRedirects(true)
            .setConnectTimeoutMs(10_000)
            .setReadTimeoutMs(20_000)
            .setUserAgent("HotelCastTV/${BuildConfig.VERSION_NAME}")
        val dataSourceFactory = DefaultDataSource.Factory(context, http)
        val exo = ExoPlayer.Builder(context)
            .setMediaSourceFactory(DefaultMediaSourceFactory(dataSourceFactory))
            .build()
        player = exo

        val lower = url.lowercase(Locale.US)
        val mediaItem = MediaItem.Builder().setUri(uri).apply {
            when {
                lower.contains(".m3u8") -> setMimeType(MimeTypes.APPLICATION_M3U8)
                lower.contains(".mpd") -> setMimeType(MimeTypes.APPLICATION_MPD)
                lower.startsWith("rtsp://") || lower.startsWith("rtsps://") -> setMimeType(MimeTypes.APPLICATION_RTSP)
            }
        }.build()
        if (lower.startsWith("rtsp://") || lower.startsWith("rtsps://")) {
            exo.setMediaSource(RtspMediaSource.Factory().setTimeoutMs(10_000).createMediaSource(mediaItem))
        } else {
            exo.setMediaItem(mediaItem)
        }

        exo.volume = if (item.mute == true) 0f else 1f
        val loopVideo = !isStream && item.loop == true && (items.size == 1 || item.durationSec > 0)
        exo.repeatMode = if (loopVideo) Player.REPEAT_MODE_ONE else Player.REPEAT_MODE_OFF
        exo.addListener(object : Player.Listener {
            override fun onPlaybackStateChanged(state: Int) {
                if (currentItem !== item || player !== exo) return
                when (state) {
                    Player.STATE_READY -> {
                        playerRetries = 0
                        onItemOk()
                    }
                    Player.STATE_ENDED -> {
                        if (isStream) {
                            // a live stream should never end – treat as an error and reconnect
                            scheduleStreamRetry(exo, mediaItem)
                        } else if (items.size > 1 && item.durationSec == 0) {
                            advance()
                        } else if (items.size == 1 && item.loop == true) {
                            exo.seekTo(0)
                            exo.play()
                        }
                    }
                }
            }

            override fun onPlayerError(error: PlaybackException) {
                if (currentItem !== item || player !== exo) return
                Log.w(TAG, "Playback error (${item.type}) $url: ${error.errorCodeName}")
                when {
                    isStream -> scheduleStreamRetry(exo, mediaItem)
                    local != null -> {
                        // Corrupt cache file: drop it and play from the network.
                        cache.deleteMedia(url)
                        handler.post { if (currentItem === item) showItem(index) }
                    }
                    items.size > 1 -> onItemError(item)
                    else -> scheduleStreamRetry(exo, MediaItem.fromUri(url))
                }
            }
        })
        exo.prepare()
        exo.playWhenReady = true

        return StyledPlayerView(context).apply {
            useController = false
            setShutterBackgroundColor(Color.BLACK)
            setKeepContentOnPlayerReset(true)
            resizeMode = AspectRatioFrameLayout.RESIZE_MODE_FIT
            isFocusable = false
            this.player = exo
        }
    }

    private fun scheduleStreamRetry(exo: ExoPlayer, mediaItem: MediaItem) {
        val delaySec = (2L shl playerRetries.coerceAtMost(4)).coerceAtMost(30)
        playerRetries++
        handler.removeCallbacks(retryRunnable)
        handler.postDelayed({
            if (player !== exo) return@postDelayed
            try {
                Log.i(TAG, "Reconnecting stream (attempt $playerRetries)")
                if (exo.mediaItemCount == 0 || exo.currentMediaItem?.localConfiguration?.uri != mediaItem.localConfiguration?.uri) {
                    if (mediaItem.localConfiguration?.mimeType == MimeTypes.APPLICATION_RTSP) {
                        exo.setMediaSource(RtspMediaSource.Factory().setForceUseRtpTcp(playerRetries % 2 == 0).createMediaSource(mediaItem))
                    } else {
                        exo.setMediaItem(mediaItem)
                    }
                }
                exo.seekToDefaultPosition()
                exo.prepare()
                exo.playWhenReady = true
            } catch (e: Exception) {
                Log.w(TAG, "Stream retry failed", e)
            }
        }, delaySec * 1000)
    }

    private fun releasePlayer() {
        val p = player ?: return
        player = null
        try {
            p.stop()
            p.release()
        } catch (e: Exception) {
            Log.w(TAG, "player release failed", e)
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun buildWeb(item: ContentItem, html: String? = null, url: String? = null): View {
        val web = WebView(context)
        web.setBackgroundColor(Color.BLACK)
        web.isFocusable = false
        web.isFocusableInTouchMode = false
        web.isVerticalScrollBarEnabled = false
        web.isHorizontalScrollBarEnabled = false
        web.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            loadWithOverviewMode = true
            useWideViewPort = true
            defaultTextEncodingName = "utf-8"
            cacheMode = WebSettings.LOAD_DEFAULT
            mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
                @Suppress("DEPRECATION")
                setRenderPriority(WebSettings.RenderPriority.HIGH)
            }
        }
        web.webChromeClient = WebChromeClient()
        web.webViewClient = object : WebViewClient() {
            @Deprecated("Deprecated in Java")
            override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean = false

            override fun onPageFinished(view: WebView?, url: String?) {
                if (currentItem === item) onItemOk()
            }

            override fun onReceivedError(view: WebView?, request: WebResourceRequest?, error: WebResourceError?) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && request?.isForMainFrame == true && currentItem === item) {
                    Log.w(TAG, "WebView error ${error?.errorCode} for ${request.url}")
                    handler.removeCallbacks(refreshRunnable)
                    handler.postDelayed(refreshRunnable, 30_000)
                }
            }

            @Deprecated("Deprecated in Java")
            override fun onReceivedError(view: WebView?, errorCode: Int, description: String?, failingUrl: String?) {
                if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M && currentItem === item) {
                    handler.removeCallbacks(refreshRunnable)
                    handler.postDelayed(refreshRunnable, 30_000)
                }
            }
        }
        web.setTag(TAG_WEB_HTML, html)
        web.setTag(TAG_WEB_URL, url)
        loadWeb(web)
        val refresh = item.refreshSec ?: 0
        if (refresh > 0) handler.postDelayed(refreshRunnable, refresh * 1000L)
        return web
    }

    private fun loadWeb(web: WebView) {
        val html = web.getTag(TAG_WEB_HTML) as? String
        val url = web.getTag(TAG_WEB_URL) as? String
        if (html != null) {
            val base = Prefs.apiBase.ifBlank { null }
            web.loadDataWithBaseURL(base, html, "text/html", "UTF-8", null)
        } else if (url != null) {
            web.loadUrl(url)
        }
    }

    private fun refreshCurrentWeb() {
        val web = currentView as? WebView ?: return
        val item = currentItem ?: return
        try {
            loadWeb(web)
        } catch (e: Exception) {
            Log.w(TAG, "web refresh failed", e)
        }
        val refresh = item.refreshSec ?: 0
        if (refresh > 0) handler.postDelayed(refreshRunnable, refresh * 1000L)
    }

    private fun youtubeUrl(item: ContentItem): String {
        val base = item.embedUrl?.takeIf { it.isNotBlank() } ?: item.url!!
        if (!base.contains("/embed/")) return base
        val sep = if (base.contains('?')) "&" else "?"
        val mute = if (item.mute == true) "1" else "0"
        return if (base.contains("autoplay=")) base else "$base${sep}autoplay=1&mute=$mute&controls=0&rel=0&playsinline=1&loop=1"
    }

    private fun buildAnnouncement(item: ContentItem): View {
        val bg = Utils.parseColor(item.bgColor, Color.parseColor("#0D47A1"))
        val fg = Utils.parseColor(item.textColor, Color.WHITE)
        val size = (item.fontSize ?: 56f).coerceIn(12f, 200f)
        val root = FrameLayout(context).apply { setBackgroundColor(bg) }
        if (item.style == "marquee") {
            val col = LinearLayout(context).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER
            }
            if (!item.subtitle.isNullOrBlank()) {
                col.addView(text(item.subtitle, fg, size * 0.5f), LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { bottomMargin = dp(24) })
            }
            val mv = MarqueeView(context)
            mv.configure(item.text ?: item.subtitle.orEmpty(), fg, Color.TRANSPARENT, 5, size)
            col.addView(mv, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, spPx(size * 1.8f)))
            root.addView(col, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER))
        } else {
            val col = LinearLayout(context).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER
                setPadding(dp(64), dp(48), dp(64), dp(48))
            }
            if (!item.text.isNullOrBlank()) col.addView(text(item.text, fg, size, bold = true))
            if (!item.subtitle.isNullOrBlank()) {
                col.addView(text(item.subtitle, fg, size * 0.55f), LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(24) })
            }
            root.addView(col, FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))
        }
        return root
    }

    private fun buildClock(item: ContentItem): View {
        val bg = Utils.parseColor(item.bgColor, Color.BLACK)
        val fg = Utils.parseColor(item.textColor, Color.WHITE)
        val root = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setBackgroundColor(bg)
        }
        val date = text("", fg, 36f)
        val dateFmt = SimpleDateFormat("EEEE, d MMMM yyyy", Locale.getDefault())
        if (item.style == "analog") {
            val clock = AnalogClockView(context).apply { color = fg }
            val size = (context.resources.displayMetrics.heightPixels * 0.65f).toInt()
            root.addView(clock, LinearLayout.LayoutParams(size, size))
            root.addView(date, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(16) })
            root.setTag(TAG_CLOCK_UPDATER, { date.text = dateFmt.format(Date()) })
        } else {
            val time = text("", fg, 140f, bold = true)
            val pattern = content?.overlay?.clockFormat?.takeIf { it.isNotBlank() } ?: "hh:mm a"
            val timeFmt = try { SimpleDateFormat(pattern, Locale.getDefault()) } catch (e: Exception) { SimpleDateFormat("hh:mm a", Locale.getDefault()) }
            root.addView(time)
            root.addView(date, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(8) })
            root.setTag(TAG_CLOCK_UPDATER, {
                val now = Date()
                time.text = timeFmt.format(now)
                date.text = dateFmt.format(now)
            })
        }
        (root.getTag(TAG_CLOCK_UPDATER) as? (() -> Unit))?.invoke()
        return root
    }

    private fun text(value: String?, color: Int, sizeSp: Float, bold: Boolean = false) = TextView(context).apply {
        text = value
        setTextColor(color)
        setTextSize(TypedValue.COMPLEX_UNIT_SP, sizeSp)
        gravity = Gravity.CENTER
        if (bold) setTypeface(typeface, android.graphics.Typeface.BOLD)
        setLineSpacing(0f, 1.15f)
    }

    private fun dp(v: Int) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v.toFloat(), context.resources.displayMetrics).toInt()
    private fun spPx(v: Float) = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_SP, v, context.resources.displayMetrics).toInt()

    companion object {
        private const val TAG = "ContentPlayer"
        private const val TRANSITION_MS = 700L
        private const val DEFAULT_ITEM_SEC = 10
        private const val FAILED_RETRY_SEC = 60
        private val TAG_CLOCK_UPDATER = R.id.tag_clock_updater
        private val TAG_WEB_HTML = R.id.tag_web_html
        private val TAG_WEB_URL = R.id.tag_web_url
    }
}
