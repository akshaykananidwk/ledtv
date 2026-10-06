package com.hotelcast.tv

import android.app.AlertDialog
import android.app.Application
import android.content.Intent
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.provider.Settings
import android.text.InputType
import android.util.Log
import android.util.TypedValue
import android.view.Gravity
import android.view.KeyEvent
import android.view.View
import android.view.WindowManager
import android.view.inputmethod.EditorInfo
import android.widget.Button
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch

/**
 * Holds the QR flow across configuration changes (rotation, density, locale). Polling runs while the
 * screen is started ([resume] / [pause]); a registration that has begun always finishes.
 */
class QrSetupViewModel(app: Application) : AndroidViewModel(app) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private var pollJob: Job? = null
    private var registerJob: Job? = null

    private val flow = QrSetupFlow(
        backend = RetrofitProvisionBackend(),
        startBody = { ProvisionStartRequest(Prefs.deviceId, DeviceInfo.model, DeviceInfo.appVersion) },
        clock = { SystemClock.elapsedRealtime() },
        isRegistered = { Prefs.isRegistered },
        describe = { ApiClient.describe(it) },
    )

    private val _state = MutableStateFlow<QrSetupState>(QrSetupState.Starting(currentServer()))
    val state: StateFlow<QrSetupState> = _state

    /** Result of the last network check (DNS / HTTPS / HTTP / TV clock), null while not needed. */
    private val _report = MutableStateFlow<NetReport?>(null)
    val report: StateFlow<NetReport?> = _report
    private var diagJob: Job? = null
    private var lastDiagAt = 0L

    private fun onState(s: QrSetupState) {
        _state.value = s
        when (s) {
            is QrSetupState.Waiting, is QrSetupState.Claimed, is QrSetupState.Registered -> _report.value = null
            is QrSetupState.Offline -> diagnose(s.server)
            else -> {}
        }
    }

    /** Find out *why* the server is unreachable (at most every 20 s), and retry at once when fixed. */
    private fun diagnose(server: String) {
        val now = SystemClock.elapsedRealtime()
        if (diagJob?.isActive == true || now - lastDiagAt < 20_000) return
        lastDiagAt = now
        val hadOffset = NetworkTime.clockWrong
        diagJob = scope.launch {
            val r = try {
                NetDiagnostics.run(getApplication(), server)
            } catch (e: Exception) {
                Log.w(TAG, "Diagnostics failed", e)
                return@launch
            }
            if (currentServer() != server) return@launch
            val st = _state.value
            if (st is QrSetupState.Waiting || st is QrSetupState.Claimed || st is QrSetupState.Registered) return@launch
            _report.value = r
            // HTTPS works now (e.g. TV clock corrected / network time learned) → don't wait for the backoff.
            if (r.httpsOk || (r.clockWrong && !hadOffset)) kick()
        }
    }

    fun currentServer(): String = QrSetupLogic.effectiveServer(Prefs.serverUrl, BuildConfig.DEFAULT_SERVER_URL)

    private val isFinal: Boolean
        get() = when (_state.value) {
            is QrSetupState.Claimed, is QrSetupState.Registered,
            is QrSetupState.RegisterFailed, is QrSetupState.AlreadyRegistered -> true
            else -> false
        }

    /** Screen visible: start / continue polling (keeps the current code if it is still valid). */
    fun resume() {
        if (pollJob?.isActive == true || registerJob?.isActive == true || isFinal) return
        launchPoll(_state.value.visibleSession)
    }

    /** Screen hidden (e.g. Android Wi-Fi settings on top): stop polling, keep the code. */
    fun pause() {
        pollJob?.cancel()
        pollJob = null
    }

    /** Network came back: retry now instead of waiting for the backoff. */
    fun kick() {
        if (isFinal || registerJob?.isActive == true) return
        pollJob?.cancel()
        launchPoll(_state.value.visibleSession)
    }

    /** "Try again" / server changed: forget the code and start over. */
    fun restart() {
        pollJob?.cancel()
        if (registerJob?.isActive == true) return
        diagJob?.cancel()
        lastDiagAt = 0L
        _report.value = null
        _state.value = QrSetupState.Starting(currentServer())
        launchPoll(null)
    }

    private fun launchPoll(resume: QrSession?) {
        val server = currentServer()
        val keep = resume?.takeIf { _state.value.server == server }
        pollJob = scope.launch {
            val claimed = flow.run(server, keep) { onState(it) }
            if (claimed != null) register(server, claimed)
        }
    }

    private fun register(server: String, cfg: ClaimedConfig) {
        registerJob = scope.launch {
            if (Prefs.isRegistered) {
                _state.value = QrSetupState.AlreadyRegistered(server)
                return@launch
            }
            Log.i(TAG, "QR claimed: room=${cfg.room} server=${cfg.serverUrl}")
            cfg.hotelName?.let { Prefs.hotelName = it }
            // Same path as the bulk-tool extras: save fields → Registrar.register → HotelCastSetup log.
            val outcome = Provisioning.saveAndRegister(getApplication(), cfg.toProvisioningRequest())
            _state.value = if (outcome.ok) {
                QrSetupState.Registered(server, outcome.room ?: cfg.room)
            } else {
                QrSetupState.RegisterFailed(server, cfg, outcome.errorCode, outcome.message)
            }
        }
    }

    override fun onCleared() {
        scope.cancel()
        super.onCleared()
    }

    private companion object {
        const val TAG = "QrSetup"
    }
}

/**
 * First-run screen: big QR code + 6-character code. Staff scan it with a phone, pick the room in the
 * admin panel and the TV registers itself — nothing to type with the remote. Opened (without PIN)
 * whenever the TV is not registered; "Enter details manually" opens the classic form.
 */
class QrSetupActivity : AppCompatActivity() {

    private lateinit var vm: QrSetupViewModel
    private lateinit var title: TextView
    private lateinit var subtitle: TextView
    private lateinit var steps: LinearLayout
    private lateinit var status: TextView
    private lateinit var countdown: TextView
    private lateinit var network: TextView
    private lateinit var serverLine: TextView
    private lateinit var qrCard: FrameLayout
    private lateinit var qrImage: ImageView
    private lateinit var qrProgress: ProgressBar
    private lateinit var qrSide: View
    private lateinit var qrSideIcon: TextView
    private lateinit var qrSideText: TextView
    private lateinit var detail: TextView
    private lateinit var btnHttp: Button
    private lateinit var btnTime: Button
    private lateinit var codeView: TextView
    private lateinit var codeCaption: TextView
    private lateinit var btnTryAgain: Button
    private lateinit var btnWifi: Button
    private lateinit var btnManual: Button
    private lateinit var btnServer: Button
    private lateinit var doneLayer: View
    private lateinit var doneTitle: TextView

    private val handler = Handler(Looper.getMainLooper())
    private var accent = DEFAULT_ACCENT
    private var renderedQrUrl: String? = null
    private var lastNetType: String? = null
    private var leaving = false
    private var serverDialog: AlertDialog? = null

    private val ticker = object : Runnable {
        override fun run() {
            renderClockParts(vm.state.value)
            refreshNetwork()
            handler.postDelayed(this, 1000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Prefs.init(this)
        SyncManager.init(this)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setContentView(R.layout.activity_qr_setup)
        vm = ViewModelProvider(this)[QrSetupViewModel::class.java]

        title = findViewById(R.id.qr_title)
        subtitle = findViewById(R.id.qr_subtitle)
        steps = findViewById(R.id.qr_steps)
        status = findViewById(R.id.qr_status)
        countdown = findViewById(R.id.qr_countdown)
        network = findViewById(R.id.qr_network)
        serverLine = findViewById(R.id.qr_server)
        qrCard = findViewById(R.id.qr_card)
        qrImage = findViewById(R.id.qr_image)
        qrProgress = findViewById(R.id.qr_progress)
        qrSide = findViewById(R.id.qr_side)
        qrSideIcon = findViewById(R.id.qr_side_icon)
        qrSideText = findViewById(R.id.qr_side_text)
        detail = findViewById(R.id.qr_detail)
        btnHttp = findViewById(R.id.qr_btn_http)
        btnTime = findViewById(R.id.qr_btn_time)
        codeView = findViewById(R.id.qr_code)
        codeCaption = findViewById(R.id.qr_code_caption)
        btnTryAgain = findViewById(R.id.qr_btn_try_again)
        btnWifi = findViewById(R.id.qr_btn_wifi)
        btnManual = findViewById(R.id.qr_btn_manual)
        btnServer = findViewById(R.id.qr_btn_change_server)
        doneLayer = findViewById(R.id.qr_done_layer)
        doneTitle = findViewById(R.id.qr_done_title)

        applyBranding()
        buildSteps()
        sizeQr()

        btnTryAgain.setOnClickListener { vm.restart() }
        btnWifi.setOnClickListener { openWifiSettings() }
        btnManual.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java).putExtra(SettingsActivity.EXTRA_FROM_QR, true))
        }
        btnServer.setOnClickListener { showServerDialog() }
        btnHttp.setOnClickListener {
            val http = vm.report.value?.httpServer ?: return@setOnClickListener
            Prefs.serverUrl = http
            Toast.makeText(this, getString(R.string.qr_http_selected, http), Toast.LENGTH_LONG).show()
            vm.restart()
        }
        btnTime.setOnClickListener { openDateSettings() }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                // Nothing to go back to before registration (the player needs a room).
                if (Prefs.isRegistered) openPlayer()
            }
        })

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                launch { vm.state.collect { render(it) } }
                launch { vm.report.collect { render(vm.state.value) } }
            }
        }
        btnManual.requestFocus()
    }

    override fun onStart() {
        super.onStart()
        if (Prefs.isRegistered && vm.state.value !is QrSetupState.Registered) {
            // Registered meanwhile (manual form, bulk-tool extras, ProvisionReceiver): extras win.
            openPlayer()
            return
        }
        applyBranding()
        vm.resume()
        handler.post(ticker)
    }

    override fun onStop() {
        handler.removeCallbacks(ticker)
        vm.pause()
        super.onStop()
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        serverDialog?.dismiss()
        super.onDestroy()
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        // MENU on the remote is the usual "settings" key: open the manual form.
        if (keyCode == KeyEvent.KEYCODE_MENU && !leaving) {
            btnManual.performClick()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    // ------------------------------------------------------------------ layout helpers

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()

    /** QR edge: ~58 % of the screen height (works for 720p and 1080p, landscape or portrait). */
    private fun sizeQr() {
        val dm = resources.displayMetrics
        val edge = (minOf(dm.heightPixels * 0.58f, dm.widthPixels * 0.40f)).toInt().coerceAtLeast(dp(160))
        qrImage.layoutParams = qrImage.layoutParams.apply {
            width = edge
            height = edge
        }
    }

    private fun applyBranding() {
        val brand = Prefs.brandColor.ifBlank { SyncManager.content.value?.branding?.color.orEmpty() }
        accent = Utils.parseColor(brand, DEFAULT_ACCENT)
        findViewById<View>(R.id.qr_root).background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            intArrayOf(Color.parseColor("#FF0B1020"), blend(accent, Color.parseColor("#FF0B1020"), 0.78f)),
        )
        doneLayer.background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            intArrayOf(Color.parseColor("#FF0B1020"), blend(accent, Color.parseColor("#FF0B1020"), 0.6f)),
        )
        val radius = dp(12).toFloat()
        listOf(btnTryAgain, btnHttp, btnTime, btnWifi, btnManual, btnServer).forEach {
            it.background = GuestMenuPanel.focusBackground(accent, radius)
        }
        qrCard.background = GradientDrawable().apply {
            cornerRadius = dp(18).toFloat()
            setColor(Color.WHITE)
            setStroke(dp(5), accent)
        }
        val product = Prefs.brandProduct.takeIf { it.isNotBlank() }
        title.text = if (product != null) getString(R.string.qr_title_brand, product) else getString(R.string.qr_title)
    }

    private fun blend(a: Int, b: Int, ratioB: Float): Int {
        val r = ratioB.coerceIn(0f, 1f)
        fun ch(shift: Int) = (((a shr shift) and 0xFF) * (1 - r) + ((b shr shift) and 0xFF) * r).toInt()
        return Color.rgb(ch(16), ch(8), ch(0))
    }

    private fun buildSteps() {
        steps.removeAllViews()
        listOf(R.string.qr_step1, R.string.qr_step2, R.string.qr_step3).forEachIndexed { i, res ->
            val row = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
            }
            val num = TextView(this).apply {
                text = (i + 1).toString()
                gravity = Gravity.CENTER
                setTextColor(Color.WHITE)
                setTextSize(TypedValue.COMPLEX_UNIT_SP, 20f)
                setTypeface(typeface, android.graphics.Typeface.BOLD)
                background = GradientDrawable().apply {
                    shape = GradientDrawable.OVAL
                    setColor(accent)
                }
            }
            row.addView(num, LinearLayout.LayoutParams(dp(36), dp(36)))
            row.addView(
                TextView(this).apply {
                    text = getString(res)
                    setTextColor(Color.WHITE)
                    setTextSize(TypedValue.COMPLEX_UNIT_SP, 21f)
                },
                LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f).apply { marginStart = dp(14) },
            )
            steps.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
                topMargin = if (i == 0) 0 else dp(8)
            })
        }
    }

    // ------------------------------------------------------------------ rendering

    private fun render(s: QrSetupState) {
        val session = s.visibleSession
        // QR + code
        if (session != null) {
            if (renderedQrUrl != session.claimUrl) {
                val edge = qrImage.layoutParams.width.coerceAtLeast(dp(160))
                qrImage.setImageBitmap(QrCodes.bitmap(session.claimUrl, edge))
                renderedQrUrl = session.claimUrl
            }
            qrImage.alpha = if (s is QrSetupState.Waiting) 1f else 0.55f
            qrCard.visibility = View.VISIBLE
            qrSide.visibility = View.GONE
            codeView.text = QrSetupLogic.displayCode(session.code)
            codeView.visibility = View.VISIBLE
            codeCaption.visibility = View.VISIBLE
        } else {
            // No code (yet): no empty white box, no "— — —" — a spinner or a clear status instead.
            qrImage.setImageDrawable(null)
            renderedQrUrl = null
            qrCard.visibility = View.GONE
            codeView.visibility = View.GONE
            codeCaption.visibility = View.GONE
            qrSide.visibility = View.VISIBLE
            renderSide(s)
        }

        val report = vm.report.value
        val problem = s is QrSetupState.Offline || s is QrSetupState.Unsupported || s is QrSetupState.RateLimited
        btnHttp.visibility = if (problem && report?.offerHttp == true) View.VISIBLE else View.GONE
        btnTime.visibility = if (problem && report?.clockWrong == true) View.VISIBLE else View.GONE
        renderDetail(s, report)

        btnTryAgain.visibility = if (s is QrSetupState.RegisterFailed) View.VISIBLE else View.GONE
        val busy = s is QrSetupState.Claimed || s is QrSetupState.Registered
        btnManual.isEnabled = !busy
        btnServer.isEnabled = !busy
        if (s is QrSetupState.RegisterFailed && !btnTryAgain.hasFocus()) btnTryAgain.requestFocus()
        if (currentFocus == null || currentFocus?.isEnabled == false || currentFocus?.visibility != View.VISIBLE) {
            when {
                btnTryAgain.visibility == View.VISIBLE -> btnTryAgain
                btnTime.visibility == View.VISIBLE -> btnTime
                btnHttp.visibility == View.VISIBLE -> btnHttp
                else -> btnManual
            }.requestFocus()
        }

        serverLine.text = getString(R.string.qr_server_line, s.server, DeviceInfo.appVersion, Prefs.deviceId.take(8))
        renderClockParts(s)

        when (s) {
            is QrSetupState.Registered -> showDone(s)
            is QrSetupState.AlreadyRegistered -> openPlayer()
            else -> {}
        }
    }

    /** Right side while there is no QR code. */
    private fun renderSide(s: QrSetupState) {
        val checking = s is QrSetupState.Offline && vm.report.value == null
        val busy = s is QrSetupState.Starting || s is QrSetupState.Claimed || checking
        qrProgress.visibility = if (busy) View.VISIBLE else View.GONE
        val icon = when (s) {
            is QrSetupState.Offline, is QrSetupState.Unsupported, is QrSetupState.InvalidServer,
            is QrSetupState.RegisterFailed, is QrSetupState.RateLimited -> if (busy) null else "!"
            is QrSetupState.Registered, is QrSetupState.AlreadyRegistered -> "✔"
            else -> null
        }
        qrSideIcon.visibility = if (icon != null) View.VISIBLE else View.GONE
        qrSideIcon.text = icon.orEmpty()
        qrSideIcon.setTextColor(if (icon == "✔") OK else ERROR)
        qrSideText.text = when (s) {
            is QrSetupState.Starting -> getString(R.string.qr_side_starting)
            is QrSetupState.Claimed -> getString(R.string.qr_status_claimed, s.config.room)
            is QrSetupState.Offline -> if (checking) getString(R.string.qr_detail_checking) else getString(R.string.qr_side_error)
            is QrSetupState.Unsupported, is QrSetupState.InvalidServer, is QrSetupState.RateLimited,
            is QrSetupState.RegisterFailed -> getString(R.string.qr_side_error)
            else -> ""
        }
    }

    /** Plain-language reason why the server cannot be reached (from [NetDiagnostics]). */
    private fun renderDetail(s: QrSetupState, r: NetReport?) {
        val raw = when (s) {
            is QrSetupState.Offline -> s.error
            is QrSetupState.Unsupported -> null // already in the status line
            else -> null
        }
        if (s !is QrSetupState.Offline) {
            detail.visibility = View.GONE
            return
        }
        val lines = mutableListOf<String>()
        if (r == null) {
            lines += getString(R.string.qr_detail_checking)
        } else {
            when {
                !r.dnsOk -> lines += getString(R.string.qr_detail_dns, r.host)
                r.clockWrong -> lines += getString(
                    R.string.qr_detail_clock,
                    formatDate(System.currentTimeMillis()),
                    r.networkTimeMs?.let { formatDate(it) } ?: "?",
                )
                !r.httpsOk -> lines += getString(R.string.qr_detail_ssl, r.httpsError ?: "?")
                else -> lines += getString(R.string.qr_detail_https_ok)
            }
            if (r.offerHttp) lines += getString(R.string.qr_detail_http_ok)
        }
        if (!raw.isNullOrBlank()) lines += getString(R.string.qr_detail_error, raw)
        detail.text = lines.joinToString("\n")
        detail.visibility = View.VISIBLE
    }

    private fun formatDate(ms: Long): String =
        java.text.SimpleDateFormat("dd MMM yyyy HH:mm", java.util.Locale.getDefault()).format(java.util.Date(ms))

    /** Status + countdown (also refreshed every second by [ticker]). */
    private fun renderClockParts(s: QrSetupState) {
        val now = SystemClock.elapsedRealtime()
        fun secs(at: Long) = (((at - now).coerceAtLeast(0) + 999) / 1000).toInt()
        val (text, color) = when (s) {
            is QrSetupState.Starting -> getString(R.string.qr_status_starting) to accent()
            is QrSetupState.Waiting -> getString(R.string.qr_status_waiting) to accent()
            is QrSetupState.Offline ->
                (if (s.network) getString(R.string.qr_status_offline, secs(s.retryAtMs))
                else getString(R.string.qr_status_server_error, s.error, secs(s.retryAtMs))) to ERROR
            is QrSetupState.RateLimited -> getString(R.string.qr_status_rate_limited, secs(s.retryAtMs)) to ERROR
            is QrSetupState.Unsupported -> getString(R.string.qr_status_unsupported, s.error, secs(s.retryAtMs)) to ERROR
            is QrSetupState.InvalidServer -> getString(R.string.qr_status_invalid_server) to ERROR
            is QrSetupState.Claimed -> getString(R.string.qr_status_claimed, s.config.room) to OK
            is QrSetupState.Registered -> getString(R.string.qr_status_registered, s.room) to OK
            is QrSetupState.RegisterFailed -> registerErrorText(s) to ERROR
            is QrSetupState.AlreadyRegistered -> getString(R.string.register_ok, Prefs.roomNumber) to OK
        }
        if (status.text.toString() != text) status.text = text
        status.setTextColor(color)

        val session = s.visibleSession
        countdown.text = when {
            session != null -> getString(R.string.qr_expires_in, QrSetupLogic.formatCountdown(session.expiresAtMs - now))
            else -> ""
        }
        countdown.visibility = if (session != null) View.VISIBLE else View.GONE
    }

    private fun accent(): Int = if (luminance(accent) < 0.25f) Color.parseColor("#FFFFC107") else lighten(accent)

    private fun luminance(c: Int): Float = (0.299f * Color.red(c) + 0.587f * Color.green(c) + 0.114f * Color.blue(c)) / 255f

    /** Status text on the dark background must stay readable: lighten dark brand colours. */
    private fun lighten(c: Int): Int = if (luminance(c) >= 0.55f) c else blend(c, Color.WHITE, 0.45f)

    private fun registerErrorText(s: QrSetupState.RegisterFailed): String {
        val reason = when (s.errorCode) {
            "HOTEL_SUSPENDED" -> getString(R.string.register_error_hotel_suspended)
            "LICENSE_LIMIT" -> getString(R.string.register_error_license_limit)
            "INVALID_REGISTRATION_KEY" -> getString(R.string.register_error_invalid_key)
            "ROOM_NOT_FOUND" -> getString(R.string.register_error_room_not_found)
            else -> getString(R.string.register_failed, s.message)
        }
        return reason + "\n" + getString(R.string.qr_try_again_hint)
    }

    private fun refreshNetwork() {
        val type = DeviceInfo.networkType(this)
        val ip = DeviceInfo.ipAddress() ?: "-"
        network.text = when (type) {
            "wifi" -> getString(R.string.qr_network_wifi, ip)
            "ethernet" -> getString(R.string.qr_network_ethernet, ip)
            "none" -> getString(R.string.qr_network_none)
            else -> getString(R.string.qr_network_other, ip)
        }
        network.setTextColor(if (type == "none") ERROR else Color.parseColor("#FFECEFF1"))
        val prev = lastNetType
        lastNetType = type
        // Network just came back while we are backing off → retry immediately.
        if (prev == "none" && type != "none") vm.kick()
    }

    private fun showDone(s: QrSetupState.Registered) {
        if (leaving) return
        leaving = true
        doneTitle.text = getString(R.string.qr_status_registered, s.room)
        doneLayer.visibility = View.VISIBLE
        handler.postDelayed({ openPlayer() }, SUCCESS_DELAY_MS)
    }

    private fun openPlayer() {
        if (isFinishing) return
        leaving = true
        startActivity(
            Intent(this, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
        )
        finish()
    }

    // ------------------------------------------------------------------ actions

    private fun openWifiSettings() {
        // A device-owner TV may still be in lock task from a previous registration (re-setup).
        if (KioskHelper.isInLockTask(this)) {
            Prefs.kioskSuspendedUntil = System.currentTimeMillis() + 10 * 60_000L
            KioskHelper.exitKiosk(this)
        }
        val intents = listOf(
            Intent(Settings.ACTION_WIFI_SETTINGS),
            Intent("android.settings.NETWORK_SETTINGS"), // some Android TV builds
            Intent(Settings.ACTION_WIRELESS_SETTINGS),
            Intent(Settings.ACTION_SETTINGS),
        )
        for (i in intents) {
            try {
                startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                return
            } catch (e: Exception) {
                Log.w(TAG, "Cannot open ${i.action}: ${e.message}")
            }
        }
        Toast.makeText(this, R.string.qr_wifi_unavailable, Toast.LENGTH_LONG).show()
    }

    private fun openDateSettings() {
        if (KioskHelper.isInLockTask(this)) {
            Prefs.kioskSuspendedUntil = System.currentTimeMillis() + 10 * 60_000L
            KioskHelper.exitKiosk(this)
        }
        for (i in listOf(Intent(Settings.ACTION_DATE_SETTINGS), Intent(Settings.ACTION_SETTINGS))) {
            try {
                startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                return
            } catch (e: Exception) {
                Log.w(TAG, "Cannot open ${i.action}: ${e.message}")
            }
        }
        Toast.makeText(this, R.string.qr_time_unavailable, Toast.LENGTH_LONG).show()
    }

    /** Only the server URL — for hotels with their own HotelCast server. */
    private fun showServerDialog() {
        if (serverDialog?.isShowing == true) return
        val input = EditText(this).apply {
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            imeOptions = EditorInfo.IME_ACTION_DONE or EditorInfo.IME_FLAG_NO_EXTRACT_UI
            isSingleLine = true
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 22f)
            setText(vm.currentServer())
            setSelection(text.length)
            hint = getString(R.string.server_url_hint)
        }
        val hint = TextView(this).apply {
            text = getString(R.string.qr_change_server_hint, BuildConfig.DEFAULT_SERVER_URL)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 16f)
        }
        val wrapper = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(8), dp(24), 0)
            addView(hint)
            addView(input)
        }
        val dialog = AlertDialog.Builder(this)
            .setTitle(R.string.qr_change_server_title)
            .setView(wrapper)
            .setPositiveButton(R.string.ok, null)
            .setNeutralButton(R.string.qr_use_default, null)
            .setNegativeButton(R.string.cancel) { d, _ -> d.dismiss() }
            .create()

        fun apply(raw: String) {
            if (ServerUrl.normalize(raw) == null) {
                input.error = getString(R.string.invalid_url)
                return
            }
            Prefs.serverUrl = raw.trim()
            dialog.dismiss()
            vm.restart()
        }
        input.setOnEditorActionListener { _, _, _ -> apply(input.text?.toString().orEmpty()); true }
        dialog.setOnShowListener {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener { apply(input.text?.toString().orEmpty()) }
            dialog.getButton(AlertDialog.BUTTON_NEUTRAL).setOnClickListener {
                Prefs.serverUrl = ""
                dialog.dismiss()
                vm.restart()
            }
            input.requestFocus()
        }
        dialog.window?.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_STATE_VISIBLE)
        serverDialog = dialog
        dialog.show()
    }

    companion object {
        private const val TAG = "QrSetupActivity"
        private const val SUCCESS_DELAY_MS = 3_000L
        /** Default purple when the hotel has no branding colour yet. */
        private val DEFAULT_ACCENT = Color.parseColor("#FF7C4DFF")
        private val OK = Color.parseColor("#FF69F0AE")
        private val ERROR = Color.parseColor("#FFFF8A80")
    }
}
