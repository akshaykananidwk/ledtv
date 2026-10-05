package com.hotelcast.tv

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.provider.Settings
import android.util.Log
import android.view.KeyEvent
import android.view.MotionEvent
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
 */
class MainActivity : AppCompatActivity(), ContentPlayer.Listener {

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
    private var centerDownAt = 0L
    private var centerLongFired = false

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
    }

    override fun onStart() {
        super.onStart()
        if (!Prefs.isRegistered) {
            showWelcome(null, getString(R.string.connecting))
            openSetup()
            return
        }
        setupLaunched = false
        // The guest switched the TV on with the remote while it is scheduled off → show content.
        if (PowerController.wasSleptBySchedule()) PowerController.guestOverride()
        PollService.start(this)
        SyncManager.pollNow()
        render()
    }

    override fun onResume() {
        super.onResume()
        hideSystemUi()
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
        handler.removeCallbacks(overlayClockTicker)
        overlayTicker.stop()
        player?.stop()
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
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
        val c = SyncManager.content.value
        try {
            when {
                c != null && c.isEmergency -> {
                    player?.stop()
                    setScreenAwake(true)
                    showEmergency(c)
                }
                PowerController.desiredOff(c) && !PowerController.isLocallyOverridden() -> {
                    player?.stop()
                    hideOverlay()
                    welcomeLayer.visibility = View.GONE
                    emergencyLayer.visibility = View.GONE
                    blackLayer.visibility = View.VISIBLE
                    // Black-screen mode keeps the TV awake so it can always be switched on remotely.
                    setScreenAwake(PowerController.blackMode(c))
                }
                c == null -> {
                    resetLayers()
                    showWelcome(null, getString(R.string.connecting))
                }
                c.isEmpty -> {
                    resetLayers()
                    player?.stop()
                    showWelcome(c, null)
                    applyOverlay(c)
                }
                else -> {
                    resetLayers()
                    welcomeLayer.visibility = View.GONE
                    applyOverlay(c)
                    player?.setContent(c)
                }
            }
        } catch (e: Throwable) {
            Log.e(TAG, "render failed", e)
            try {
                player?.stop()
                showWelcome(c, null)
            } catch (_: Throwable) {
            }
        }
    }

    private fun resetLayers() {
        blackLayer.visibility = View.GONE
        emergencyLayer.visibility = View.GONE
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
        val hotelName = c?.hotel?.name?.takeIf { it.isNotBlank() }
        welcomeTitle.text = statusText ?: hotelName?.let { getString(R.string.welcome_to, it) } ?: getString(R.string.welcome)
        val room = c?.room?.number ?: Prefs.roomNumber
        welcomeSubtitle.text = if (room.isNotBlank()) getString(R.string.room_label, room) else ""
        val logo = c?.hotel?.logoUrl?.takeIf { it.isNotBlank() }
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
        SyncManager.reportPlayed(item.id, startedAtMillis, durationSec)
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
        // Guest turned the TV on with the remote while it is scheduled "off": show content again.
        if (event.action == KeyEvent.ACTION_UP && code != KeyEvent.KEYCODE_MENU &&
            blackLayer.visibility == View.VISIBLE && PowerController.desiredOff(SyncManager.content.value)
        ) {
            PowerController.guestOverride()
            render()
            return true
        }
        when (code) {
            KeyEvent.KEYCODE_MENU -> {
                if (event.action == KeyEvent.ACTION_UP) requestSettings()
                return true
            }
            KeyEvent.KEYCODE_BACK, KeyEvent.KEYCODE_ESCAPE -> {
                if (event.action == KeyEvent.ACTION_DOWN && event.repeatCount == 0) registerBackPress()
                return true // never leave the kiosk with BACK
            }
            KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_ENTER, KeyEvent.KEYCODE_NUMPAD_ENTER -> {
                if (event.action == KeyEvent.ACTION_DOWN) {
                    if (event.repeatCount == 0) {
                        centerDownAt = SystemClock.uptimeMillis()
                        centerLongFired = false
                    } else if (!centerLongFired && SystemClock.uptimeMillis() - centerDownAt >= LONG_PRESS_MS) {
                        centerLongFired = true
                        requestSettings()
                    }
                }
                return true
            }
            KeyEvent.KEYCODE_DPAD_UP, KeyEvent.KEYCODE_DPAD_DOWN, KeyEvent.KEYCODE_DPAD_LEFT, KeyEvent.KEYCODE_DPAD_RIGHT -> {
                if (event.action == KeyEvent.ACTION_DOWN && event.repeatCount == 0) {
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
