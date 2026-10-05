package com.hotelcast.tv

import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Canvas
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.provider.Settings
import android.util.Log
import android.view.KeyEvent
import android.view.MotionEvent
import android.view.PixelCopy
import android.view.View
import android.view.WindowManager
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import com.bumptech.glide.Glide
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.math.roundToInt

/**
 * Kiosk display. Shows the room's content full screen with the overlay (logo, clock, weather,
 * ticker), the emergency layer, the screen-off black layer and the welcome screen.
 *
 * Hidden settings gesture (opens the PIN dialog):
 *  - MENU key on the remote, or
 *  - 5 presses of BACK within 3 seconds, or
 *  - D-pad UP, UP, DOWN, DOWN, or
 *  - holding OK / D-pad centre for 3 seconds, or
 *  - (touch screens) 5 taps in the top-left corner within 3 seconds.
 *
 * Guest features (V2): a short press of OK / D-pad centre opens the guest menu (side panel), the
 * personal welcome card, the checkout reminder banner, SHOW_MESSAGE cards and the suspended screen
 * (see [GuestUi]). Emergency always stays on top of all of them.
 */
class MainActivity : AppCompatActivity(), ContentPlayer.Listener, PlayerUi, GuestUi.Listener {

    private lateinit var stage: FrameLayout
    private lateinit var welcomeLayer: LinearLayout
    private lateinit var welcomeLogo: ImageView
    private lateinit var welcomeTitle: TextView
    private lateinit var welcomeSubtitle: TextView
    private lateinit var overlayLayer: View
    private lateinit var overlayLogo: ImageView
    private lateinit var overlayTopRight: View
    private lateinit var overlayClock: TextView
    private lateinit var overlayWeather: TextView
    private lateinit var overlayTicker: MarqueeView
    private lateinit var blackLayer: View
    private lateinit var emergencyLayer: LinearLayout
    private lateinit var emergencyTitle: TextView
    private lateinit var emergencyMessage: TextView
    private lateinit var offlineDot: View
    private lateinit var welcomeFooter: TextView
    private lateinit var suspendedLayer: View
    private lateinit var suspendedLogo: ImageView
    private lateinit var suspendedTitle: TextView
    private lateinit var suspendedMessage: TextView
    private lateinit var suspendedSupport: TextView
    private lateinit var guestUi: GuestUi
    private var suspendedLogoUrl: String? = null
    private var rendering = false

    private var player: ContentPlayer? = null
    private val handler = Handler(Looper.getMainLooper())
    private var overlayClockFormat: SimpleDateFormat? = null
    private var overlayLogoUrl: String? = null
    private var welcomeLogoUrl: String? = null
    private var pinDialogShowing = false
    private var setupLaunched = false

    // gesture state
    private val backPresses = ArrayDeque<Long>()
    private val dpadSequence = ArrayDeque<Int>()
    private val cornerTaps = ArrayDeque<Long>()
    private val centerKey = CenterKeyTracker(LONG_PRESS_MS)

    private val overlayClockTicker = object : Runnable {
        override fun run() {
            overlayClockFormat?.let { fmt ->
                overlayClock.text = try { fmt.format(Date()) } catch (e: Exception) { "" }
            }
            handler.postDelayed(this, 1000 - System.currentTimeMillis() % 1000)
        }
    }

    /** After "Exit kiosk" the suspension expires automatically and the player comes back to the front. */
    private val kioskReentry = object : Runnable {
        override fun run() {
            if (isFinishing) return
            if (Prefs.isKioskSuspended) {
                handler.postDelayed(this, 30_000)
                return
            }
            if (lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED)) {
                KioskHelper.enterKioskIfPossible(this@MainActivity)
            } else {
                try {
                    startActivity(
                        Intent(this@MainActivity, MainActivity::class.java)
                            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
                    )
                } catch (e: Exception) {
                    Log.w(TAG, "Cannot bring player to front", e)
                }
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Prefs.init(this)
        SyncManager.init(this)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        allowWakeFromStandby()
        setContentView(R.layout.activity_main)
        bindViews()
        hideSystemUi()
        KioskHelper.applyDeviceOwnerPolicies(this)

        player = ContentPlayer(this, stage, SyncManager.contentCache, this)
        guestUi = GuestUi(this, findViewById(R.id.root), emergencyLayer, SyncManager.contentCache, this)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                registerBackPress()
            }
        })

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                launch { SyncManager.content.collect { render() } }
                launch {
                    SyncManager.status.collect { st ->
                        offlineDot.visibility = if (Prefs.isRegistered && !st.online && st.lastError != null) View.VISIBLE else View.GONE
                    }
                }
                launch { SyncManager.events.collect { onSyncEvent(it) } }
            }
        }
        handleIntent(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIntent(intent)
    }

    /** Let this activity switch the screen on when the server wakes the TV (see [PowerController]). */
    @Suppress("DEPRECATION")
    private fun allowWakeFromStandby() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setTurnScreenOn(true)
            setShowWhenLocked(true)
        } else {
            window.addFlags(
                WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON or
                    WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD
            )
        }
    }

    private fun handleIntent(intent: Intent?) {
        if (intent?.getBooleanExtra(EXTRA_OPEN_ANDROID_SETTINGS, false) == true) {
            intent.removeExtra(EXTRA_OPEN_ANDROID_SETTINGS)
            KioskHelper.exitKiosk(this)
            handler.removeCallbacks(kioskReentry)
            handler.postDelayed(kioskReentry, 30_000)
            try {
                startActivity(Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            } catch (e: Exception) {
                Log.w(TAG, "Cannot open Android settings", e)
            }
        }
    }

    private fun bindViews() {
        stage = findViewById(R.id.stage)
        welcomeLayer = findViewById(R.id.welcome_layer)
        welcomeLogo = findViewById(R.id.welcome_logo)
        welcomeTitle = findViewById(R.id.welcome_title)
        welcomeSubtitle = findViewById(R.id.welcome_subtitle)
        overlayLayer = findViewById(R.id.overlay_layer)
        overlayLogo = findViewById(R.id.overlay_logo)
        overlayTopRight = findViewById(R.id.overlay_top_right)
        overlayClock = findViewById(R.id.overlay_clock)
        overlayWeather = findViewById(R.id.overlay_weather)
        overlayTicker = findViewById(R.id.overlay_ticker)
        blackLayer = findViewById(R.id.black_layer)
        emergencyLayer = findViewById(R.id.emergency_layer)
        emergencyTitle = findViewById(R.id.emergency_title)
        emergencyMessage = findViewById(R.id.emergency_message)
        offlineDot = findViewById(R.id.offline_dot)
        welcomeFooter = findViewById(R.id.welcome_footer)
        suspendedLayer = findViewById(R.id.suspended_layer)
        suspendedLogo = findViewById(R.id.suspended_logo)
        suspendedTitle = findViewById(R.id.suspended_title)
        suspendedMessage = findViewById(R.id.suspended_message)
        suspendedSupport = findViewById(R.id.suspended_support)
    }

    override fun onStart() {
        super.onStart()
        if (!Prefs.isRegistered) {
            showWelcome(null, getString(R.string.connecting))
            openSetup()
            return
        }
        setupLaunched = false
        UiBridge.player = this
        // The guest switched the TV on with the remote while it is scheduled off → show content.
        if (PowerController.wasSleptBySchedule()) PowerController.guestOverride()
        PollService.start(this)
        SyncManager.pollNow()
        render()
    }

    override fun onResume() {
        super.onResume()
        hideSystemUi()
        // Back from Live TV / HDMI: only HotelCast may run in lock task again.
        if (KioskHelper.externalAppActive) KioskHelper.restoreKioskPackages(this)
        if (Prefs.isRegistered) {
            KioskHelper.enterKioskIfPossible(this)
            if (Prefs.isKioskSuspended) {
                handler.removeCallbacks(kioskReentry)
                handler.postDelayed(kioskReentry, 30_000)
            }
        }
    }

    override fun onStop() {
        super.onStop()
        setupLaunched = false
        UiBridge.clear(this)
        handler.removeCallbacks(overlayClockTicker)
        overlayTicker.stop()
        player?.stop()
        // Leaving for Live TV / HDMI / settings: close the menu and any full-screen guest detail.
        try { guestUi.closeAll(notify = false) } catch (_: Throwable) {}
        centerKey.reset()
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        try { guestUi.release() } catch (_: Throwable) {}
        player?.release()
        player = null
        super.onDestroy()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) hideSystemUi()
    }

    private fun hideSystemUi() {
        try {
            WindowCompat.setDecorFitsSystemWindows(window, false)
            WindowInsetsControllerCompat(window, window.decorView).apply {
                hide(WindowInsetsCompat.Type.systemBars())
                systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = (View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                or View.SYSTEM_UI_FLAG_FULLSCREEN
                or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                or View.SYSTEM_UI_FLAG_LAYOUT_STABLE)
        } catch (e: Exception) {
            Log.w(TAG, "hideSystemUi failed", e)
        }
    }

    // ------------------------------------------------------------------ rendering

    private fun render() {
        if (!lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED) || !Prefs.isRegistered) return
        if (rendering) return // re-entrant call from a guest-layer callback
        rendering = true
        val c = SyncManager.content.value
        try {
            when {
                c != null && c.isEmergency -> {
                    guestUi.hideAll()
                    player?.stop()
                    suspendedLayer.visibility = View.GONE
                    setScreenAwake(true)
                    showEmergency(c)
                }
                PowerController.desiredOff(c) && !PowerController.isLocallyOverridden() -> {
                    guestUi.hideAll()
                    player?.stop()
                    hideOverlay()
                    welcomeLayer.visibility = View.GONE
                    emergencyLayer.visibility = View.GONE
                    suspendedLayer.visibility = View.GONE
                    blackLayer.visibility = View.VISIBLE
                    // Black-screen mode keeps the TV awake so it can always be switched on remotely.
                    setScreenAwake(PowerController.blackMode(c))
                }
                c == null -> {
                    resetLayers()
                    showWelcome(null, getString(R.string.connecting))
                }
                c.isSuspended -> {
                    resetLayers()
                    guestUi.hideAll()
                    player?.stop()
                    hideOverlay()
                    welcomeLayer.visibility = View.GONE
                    showSuspended(c)
                }
                else -> {
                    resetLayers()
                    maybeShowWelcomeCard(c)
                    if (guestUi.blocksPlayback) {
                        // Welcome card / full-screen guest detail: no playback (and no ad impressions) behind it.
                        player?.stop()
                        hideOverlay()
                    } else if (c.isEmpty) {
                        player?.stop()
                        showWelcome(c, null)
                        applyOverlay(c)
                    } else {
                        welcomeLayer.visibility = View.GONE
                        applyOverlay(c)
                        player?.setContent(c)
                    }
                    updateReminder(c)
                }
            }
        } catch (e: Throwable) {
            Log.e(TAG, "render failed", e)
            ErrorLog.add("render", e.toString())
            try {
                player?.stop()
                showWelcome(c, null)
            } catch (_: Throwable) {
            }
        } finally {
            rendering = false
        }
    }

    /** Personal welcome card: once per welcome.id, and again after each power-on within 24 h of check-in. */
    private fun maybeShowWelcomeCard(c: Content) {
        val powerOn = GuestSession.powerOnPending
        GuestSession.powerOnPending = false
        if (guestUi.welcomeVisible) return
        val shown = IdSet.parse(Prefs.shownWelcomeIds)
        if (!GuestPrompts.shouldShowWelcome(c.welcome, c.guest, shown, System.currentTimeMillis(), powerOn)) return
        shown.add(c.welcome?.id)
        Prefs.shownWelcomeIds = shown.serialize()
        guestUi.showWelcome(c)
        SyncManager.reportEvent("welcome_shown", mapOf("id" to c.welcome?.id))
    }

    private fun updateReminder(c: Content) {
        if (guestUi.welcomeVisible || guestUi.detailOpen) return
        val show = GuestPrompts.shouldShowReminder(c.checkoutReminder, IdSet.parse(Prefs.dismissedReminderIds), GuestSession.remindersHidden.toSet())
        if (show) guestUi.showReminder(c) else guestUi.removeReminder()
    }

    private fun showSuspended(c: Content) {
        val ctx = guestUi.localized(c)
        val s = c.suspended
        suspendedTitle.text = s?.title?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.suspended_default_title)
        suspendedMessage.text = s?.message?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.suspended_default_message)
        val support = c.branding?.support?.takeIf { it.isNotBlank() }
        suspendedSupport.text = support?.let { ctx.getString(R.string.suspended_support, it) }
        suspendedSupport.visibility = if (support != null) View.VISIBLE else View.GONE
        suspendedSupport.setTextColor(guestUi.accent(c))
        val logo = c.branding?.logoUrl?.takeIf { it.isNotBlank() } ?: c.hotel?.logoUrl?.takeIf { it.isNotBlank() }
        if (logo != null) {
            suspendedLogo.visibility = View.VISIBLE
            if (logo != suspendedLogoUrl) {
                suspendedLogoUrl = logo
                Glide.with(this).load(SyncManager.contentCache.cachedFile(logo) ?: logo).into(suspendedLogo)
            }
        } else {
            suspendedLogo.visibility = View.GONE
            suspendedLogoUrl = null
        }
        suspendedLayer.visibility = View.VISIBLE
    }

    private fun resetLayers() {
        blackLayer.visibility = View.GONE
        emergencyLayer.visibility = View.GONE
        suspendedLayer.visibility = View.GONE
        setScreenAwake(true)
    }

    private fun setScreenAwake(on: Boolean) {
        if (on) window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        else window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        findViewById<View>(R.id.root).keepScreenOn = on
    }

    private fun showEmergency(c: Content) {
        hideOverlay()
        welcomeLayer.visibility = View.GONE
        blackLayer.visibility = View.GONE
        val e = c.emergency
        val ann = c.items.orEmpty().firstOrNull { it.type == ContentItem.TYPE_ANNOUNCEMENT }
        val title = e?.title ?: ann?.text ?: getString(R.string.emergency_default_title)
        val message = e?.message ?: ann?.subtitle ?: ""
        val bg = Utils.parseColor(e?.bgColor ?: ann?.bgColor, ContextCompat.getColor(this, R.color.emergency_bg))
        val fg = Utils.parseColor(e?.textColor ?: ann?.textColor, ContextCompat.getColor(this, R.color.white))
        emergencyLayer.setBackgroundColor(bg)
        emergencyTitle.setTextColor(fg)
        emergencyMessage.setTextColor(fg)
        emergencyTitle.text = title
        emergencyMessage.text = message
        emergencyMessage.visibility = if (message.isBlank()) View.GONE else View.VISIBLE
        emergencyLayer.visibility = View.VISIBLE
        emergencyLayer.bringToFront()
        offlineDot.bringToFront()
    }

    private fun showWelcome(c: Content?, statusText: String?) {
        welcomeLayer.visibility = View.VISIBLE
        val hotelName = c?.hotel?.name?.takeIf { it.isNotBlank() } ?: c?.branding?.product?.takeIf { it.isNotBlank() }
        welcomeTitle.text = statusText ?: hotelName?.let { getString(R.string.welcome_to, it) } ?: getString(R.string.welcome)
        val room = c?.room?.number ?: Prefs.roomNumber
        welcomeSubtitle.text = if (room.isNotBlank()) getString(R.string.room_label, room) else ""
        val logo = c?.hotel?.logoUrl?.takeIf { it.isNotBlank() } ?: c?.branding?.logoUrl?.takeIf { it.isNotBlank() }
        welcomeSubtitle.setTextColor(Utils.parseColor(c?.branding?.color, ContextCompat.getColor(this, R.color.accent)))
        val product = c?.branding?.product?.takeIf { it.isNotBlank() }
        welcomeFooter.text = product
        welcomeFooter.visibility = if (product != null && product != hotelName) View.VISIBLE else View.GONE
        if (logo != null) {
            welcomeLogo.visibility = View.VISIBLE
            if (logo != welcomeLogoUrl) {
                welcomeLogoUrl = logo
                Glide.with(this).load(SyncManager.contentCache.cachedFile(logo) ?: logo).into(welcomeLogo)
            }
        } else {
            welcomeLogo.visibility = View.GONE
            welcomeLogoUrl = null
        }
    }

    private fun applyOverlay(c: Content) {
        val o = c.overlay
        if (o == null) {
            hideOverlay()
            return
        }
        overlayLayer.visibility = View.VISIBLE

        // Logo (top-left)
        val logo = c.hotel?.logoUrl?.takeIf { o.logo == true && it.isNotBlank() }
        if (logo != null) {
            overlayLogo.visibility = View.VISIBLE
            if (logo != overlayLogoUrl) {
                overlayLogoUrl = logo
                Glide.with(this).load(SyncManager.contentCache.cachedFile(logo) ?: logo).into(overlayLogo)
            }
        } else {
            overlayLogo.visibility = View.GONE
            overlayLogoUrl = null
        }

        // Clock (top-right)
        handler.removeCallbacks(overlayClockTicker)
        if (o.clock == true) {
            overlayClockFormat = try {
                SimpleDateFormat(o.clockFormat?.takeIf { it.isNotBlank() } ?: "hh:mm a", Locale.getDefault())
            } catch (e: Exception) {
                SimpleDateFormat("hh:mm a", Locale.getDefault())
            }
            overlayClock.visibility = View.VISIBLE
            handler.post(overlayClockTicker)
        } else {
            overlayClockFormat = null
            overlayClock.visibility = View.GONE
        }

        // Weather
        val w = o.weather
        if (w != null && w.enabled == true && w.tempC != null) {
            overlayWeather.text = getString(R.string.weather_format, w.icon.orEmpty(), w.tempC.roundToInt(), w.city.orEmpty()).trim()
            overlayWeather.visibility = View.VISIBLE
        } else {
            overlayWeather.visibility = View.GONE
        }
        overlayTopRight.visibility = if (overlayClock.visibility == View.VISIBLE || overlayWeather.visibility == View.VISIBLE) View.VISIBLE else View.GONE

        // Ticker (bottom)
        val t = o.ticker
        if (t != null && !t.text.isNullOrBlank()) {
            overlayTicker.configure(
                text = t.text,
                textColor = Utils.parseColor(t.textColor, ContextCompat.getColor(this, R.color.accent)),
                bgColor = Utils.parseColor(t.bgColor, ContextCompat.getColor(this, R.color.black)),
                speed = t.speed ?: 5,
            )
            overlayTicker.visibility = View.VISIBLE
        } else {
            overlayTicker.stop()
            overlayTicker.visibility = View.GONE
        }
    }

    private fun hideOverlay() {
        handler.removeCallbacks(overlayClockTicker)
        overlayTicker.stop()
        overlayLayer.visibility = View.GONE
    }

    // ------------------------------------------------------------------ ContentPlayer.Listener

    override fun onItemStarted(item: ContentItem) {
        SyncManager.currentItemId = item.id
        welcomeLayer.visibility = View.GONE
    }

    override fun onItemFinished(item: ContentItem, startedAtMillis: Long, durationSec: Int) {
        SyncManager.reportPlayed(item.id, startedAtMillis, durationSec, item.adCampaignId)
    }

    override fun onNothingToPlay() {
        showWelcome(SyncManager.content.value, null)
    }

    // ------------------------------------------------------------------ sync events

    private fun onSyncEvent(e: SyncEvent) {
        when (e) {
            SyncEvent.ScreenStateChanged -> render()
            SyncEvent.Reload -> {
                player?.stop()
                recreate()
            }
            SyncEvent.Unregistered -> {
                player?.stop()
                PollService.stop(this)
                openSetup()
            }
        }
    }

    private fun openSetup() {
        if (setupLaunched) return
        setupLaunched = true
        startActivity(Intent(this, SettingsActivity::class.java).putExtra(SettingsActivity.EXTRA_SETUP, true))
    }

    // ------------------------------------------------------------------ hidden settings gesture

    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        if (pinDialogShowing) return super.dispatchKeyEvent(event)
        val code = event.keyCode
        val down = event.action == KeyEvent.ACTION_DOWN
        val up = event.action == KeyEvent.ACTION_UP
        // Guest turned the TV on with the remote while it is scheduled "off": show content again.
        if (up && code != KeyEvent.KEYCODE_MENU &&
            blackLayer.visibility == View.VISIBLE && PowerController.desiredOff(SyncManager.content.value)
        ) {
            PowerController.guestOverride()
            render()
            return true
        }
        if (isVolumeKey(code)) {
            // Let the system change the volume, then clamp it to volume.max / the night max.
            if (up) handler.postDelayed({ SyncManager.enforceVolumeSoon() }, 300)
            return super.dispatchKeyEvent(event)
        }
        val isCenter = code == KeyEvent.KEYCODE_DPAD_CENTER || code == KeyEvent.KEYCODE_ENTER || code == KeyEvent.KEYCODE_NUMPAD_ENTER
        val isBack = code == KeyEvent.KEYCODE_BACK || code == KeyEvent.KEYCODE_ESCAPE

        // 1. Welcome card: any key dismisses it (handled on key up).
        if (guestUi.welcomeVisible && emergencyLayer.visibility != View.VISIBLE) {
            if (up) {
                centerKey.reset()
                guestUi.hideWelcome(notify = true)
            }
            return true
        }
        // 2. Message card: OK / BACK dismiss it.
        if (guestUi.messageVisible && (isCenter || isBack)) {
            if (up) {
                centerKey.reset()
                guestUi.hideMessage()
            }
            return true
        }
        // 3. Guest menu / detail open.
        if (guestUi.menuOpen || guestUi.detailOpen) {
            guestUi.touch()
            val inList = guestUi.menuOpen && !guestUi.detailOpen
            when {
                isBack -> {
                    if (down && event.repeatCount == 0) {
                        guestUi.back()
                        registerBackPress()
                    }
                    return true
                }
                code == KeyEvent.KEYCODE_MENU -> {
                    if (up) {
                        guestUi.closeAll()
                        requestSettings()
                    }
                    return true
                }
                isCenter -> {
                    if (down) {
                        if (centerKey.onDown(event.repeatCount, event.eventTime) == CenterKeyTracker.Action.LONG_PRESS) {
                            guestUi.closeAll()
                            requestSettings()
                            return true
                        }
                        return if (inList) super.dispatchKeyEvent(event) else true
                    }
                    if (up) {
                        when (centerKey.onUp(event.eventTime)) {
                            CenterKeyTracker.Action.SHORT_PRESS -> return if (inList) super.dispatchKeyEvent(event) else true
                            CenterKeyTracker.Action.LONG_PRESS -> {
                                guestUi.closeAll()
                                requestSettings()
                            }
                            else -> Unit
                        }
                    }
                    return true
                }
                code == KeyEvent.KEYCODE_DPAD_UP || code == KeyEvent.KEYCODE_DPAD_DOWN ||
                    code == KeyEvent.KEYCODE_DPAD_LEFT || code == KeyEvent.KEYCODE_DPAD_RIGHT -> {
                    return if (inList) super.dispatchKeyEvent(event) else true
                }
            }
            return super.dispatchKeyEvent(event)
        }
        // 4. Normal playback.
        when (code) {
            KeyEvent.KEYCODE_MENU -> {
                if (up) requestSettings()
                return true
            }
            KeyEvent.KEYCODE_BACK, KeyEvent.KEYCODE_ESCAPE -> {
                if (down && event.repeatCount == 0) {
                    if (guestUi.reminderVisible) guestUi.dismissReminder()
                    registerBackPress()
                }
                return true // never leave the kiosk with BACK
            }
            KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_ENTER, KeyEvent.KEYCODE_NUMPAD_ENTER -> {
                if (down) {
                    // Long press (3 s, key repeats) → settings, exactly as before.
                    if (centerKey.onDown(event.repeatCount, event.eventTime) == CenterKeyTracker.Action.LONG_PRESS) requestSettings()
                } else if (up) {
                    when (centerKey.onUp(event.eventTime)) {
                        CenterKeyTracker.Action.SHORT_PRESS -> {
                            if (guestUi.reminderVisible) guestUi.dismissReminder() else openGuestMenu()
                        }
                        // remotes that send no key repeats: long press detected on release
                        CenterKeyTracker.Action.LONG_PRESS -> requestSettings()
                        else -> Unit
                    }
                }
                return true
            }
            KeyEvent.KEYCODE_DPAD_UP, KeyEvent.KEYCODE_DPAD_DOWN, KeyEvent.KEYCODE_DPAD_LEFT, KeyEvent.KEYCODE_DPAD_RIGHT -> {
                if (down && event.repeatCount == 0) {
                    dpadSequence.addLast(code)
                    while (dpadSequence.size > SECRET_SEQUENCE.size) dpadSequence.removeFirst()
                    if (dpadSequence.toList() == SECRET_SEQUENCE) {
                        dpadSequence.clear()
                        requestSettings()
                    }
                }
                return true
            }
        }
        return super.dispatchKeyEvent(event)
    }

    private fun isVolumeKey(code: Int) =
        code == KeyEvent.KEYCODE_VOLUME_UP || code == KeyEvent.KEYCODE_VOLUME_DOWN || code == KeyEvent.KEYCODE_VOLUME_MUTE

    // ------------------------------------------------------------------ guest menu

    /** Short OK press: open the guest menu when content (or the welcome/empty screen) is showing. */
    private fun openGuestMenu() {
        val c = SyncManager.content.value ?: return
        if (c.isEmergency || c.isSuspended || isFinishing) return
        if (PowerController.desiredOff(c) && !PowerController.isLocallyOverridden()) return
        val items = c.effectiveGuestMenu()
        if (items.isEmpty()) return
        guestUi.openMenu(c, items)
        SyncManager.reportEvent("guest_menu_open", mapOf("items" to items.size))
    }

    override fun onFullScreenLayer(open: Boolean) {
        if (open) {
            player?.stop()
            hideOverlay()
        } else {
            render()
        }
    }

    override fun onSwitchInput(input: String, label: String) {
        switchInput(input, label)
    }

    override fun onItemPlayed(item: ContentItem, startedAtMillis: Long, durationSec: Int) {
        SyncManager.reportPlayed(item.id, startedAtMillis, durationSec, item.adCampaignId)
    }

    private fun switchInput(input: String, label: String, fromAdmin: Boolean = false): InputSwitcher.Result {
        val c = SyncManager.content.value
        val target = InputSwitcher.parse(input) ?: return InputSwitcher.Result(false, "Unknown input '$input'")
        guestUi.closeAll(notify = false)
        val result = try {
            InputSwitcher.open(this, target, label, allowSettings = fromAdmin)
        } catch (e: Throwable) {
            Log.e(TAG, "input switch failed", e)
            InputSwitcher.Result(false, e.message ?: "failed")
        }
        SyncManager.reportEvent("input_switch", mapOf("input" to input, "ok" to result.ok, "package" to result.packageName, "message" to result.message))
        if (!result.ok) {
            ErrorLog.add("input", result.message)
            val ctx = guestUi.localized(c)
            guestUi.showMessage(c, label, ctx.getString(R.string.input_unavailable), 8)
            render()
        }
        return result
    }

    // ------------------------------------------------------------------ PlayerUi (server commands)

    override fun showWelcomeNow(): String {
        val c = SyncManager.content.value ?: throw CommandFailedException("No content yet")
        if (c.isEmergency) throw CommandFailedException("Not shown: emergency active")
        if (c.isSuspended) throw CommandFailedException("Not shown: hotel suspended")
        if (c.welcome == null) throw CommandFailedException("No welcome for this room (no guest checked in)")
        if (PowerController.desiredOff(c) && !PowerController.isLocallyOverridden()) throw CommandFailedException("Not shown: TV is switched off")
        guestUi.showWelcome(c)
        if (!guestUi.welcomeVisible) throw CommandFailedException("Welcome card could not be built")
        player?.stop()
        hideOverlay()
        return "Welcome shown for ${GuestPrompts.welcomeDurationSec(c.welcome)} s"
    }

    override fun showMessage(title: String?, message: String?, durationSec: Int): String {
        val c = SyncManager.content.value
        if (c?.isEmergency == true) throw CommandFailedException("Not shown: emergency active")
        guestUi.showMessage(c, title, message, durationSec)
        if (!guestUi.messageVisible) throw CommandFailedException("Message could not be shown")
        val off = PowerController.desiredOff(c) && !PowerController.isLocallyOverridden()
        return if (off || !PowerController.isScreenInteractive()) "Message shown for $durationSec s (TV is off)" else "Message shown for $durationSec s"
    }

    override fun openInput(input: String): String {
        val c = SyncManager.content.value
        if (c?.isEmergency == true) throw CommandFailedException("Not switched: emergency active")
        val label = when (val t = InputSwitcher.parse(input)) {
            is InputSwitcher.Target.Hdmi -> "HDMI ${t.port}"
            InputSwitcher.Target.LiveTv -> guestUi.localized(c).getString(R.string.live_tv)
            null -> throw CommandFailedException("Unknown input '$input'")
        }
        val r = switchInput(input, label, fromAdmin = true)
        if (!r.ok) throw CommandFailedException(r.message)
        return r.message
    }

    override fun captureScreen(callback: (Bitmap?) -> Unit) {
        val decor = window?.decorView
        val w = decor?.width ?: 0
        val h = decor?.height ?: 0
        if (decor == null || w <= 0 || h <= 0) {
            callback(null)
            return
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            try {
                val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
                PixelCopy.request(window, bmp, { result ->
                    if (result == PixelCopy.SUCCESS) {
                        callback(bmp)
                    } else {
                        Log.w(TAG, "PixelCopy failed ($result), falling back to View.draw")
                        bmp.recycle()
                        callback(drawDecor(decor, w, h))
                    }
                }, handler)
                return
            } catch (e: Throwable) {
                Log.w(TAG, "PixelCopy unavailable: ${e.message}")
            }
        }
        callback(drawDecor(decor, w, h))
    }

    /** API < 26 fallback. Video surfaces (SurfaceView) come out black. */
    private fun drawDecor(decor: View, w: Int, h: Int): Bitmap? = try {
        val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        decor.draw(Canvas(bmp))
        bmp
    } catch (e: Throwable) {
        Log.w(TAG, "View.draw capture failed", e)
        null
    }

    override fun dispatchTouchEvent(ev: MotionEvent): Boolean {
        if (ev.actionMasked == MotionEvent.ACTION_DOWN) {
            val corner = 150 * resources.displayMetrics.density
            if (ev.x < corner && ev.y < corner) {
                val now = SystemClock.uptimeMillis()
                cornerTaps.addLast(now)
                while (cornerTaps.isNotEmpty() && now - cornerTaps.first() > GESTURE_WINDOW_MS) cornerTaps.removeFirst()
                if (cornerTaps.size >= 5) {
                    cornerTaps.clear()
                    requestSettings()
                }
            }
        }
        return super.dispatchTouchEvent(ev)
    }

    private fun registerBackPress() {
        val now = SystemClock.uptimeMillis()
        backPresses.addLast(now)
        while (backPresses.isNotEmpty() && now - backPresses.first() > GESTURE_WINDOW_MS) backPresses.removeFirst()
        if (backPresses.size >= 5) {
            backPresses.clear()
            requestSettings()
        }
    }

    @Deprecated("Deprecated in Java")
    @Suppress("DEPRECATION", "MissingSuperCall")
    override fun onBackPressed() {
        // Kiosk: BACK never leaves the player (counted towards the settings gesture instead).
        registerBackPress()
    }

    private fun requestSettings() {
        if (pinDialogShowing || isFinishing) return
        if (!Prefs.isRegistered) {
            setupLaunched = false
            openSetup()
            return
        }
        pinDialogShowing = true
        val d = PinDialog.show(this, onDismiss = {
            pinDialogShowing = false
            hideSystemUi()
        }) {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
        if (d == null) pinDialogShowing = false
    }

    companion object {
        private const val TAG = "MainActivity"
        const val EXTRA_OPEN_ANDROID_SETTINGS = "open_android_settings"
        const val EXTRA_WAKE = "wake_from_standby"
        private const val GESTURE_WINDOW_MS = 3000L
        private const val LONG_PRESS_MS = 3000L
        private val SECRET_SEQUENCE = listOf(
            KeyEvent.KEYCODE_DPAD_UP, KeyEvent.KEYCODE_DPAD_UP,
            KeyEvent.KEYCODE_DPAD_DOWN, KeyEvent.KEYCODE_DPAD_DOWN,
        )
    }
}
