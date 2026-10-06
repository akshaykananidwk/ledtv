package com.hotelcast.tv

import android.app.AlertDialog
import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.KeyEvent
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.DateFormat
import java.util.Date

/**
 * Setup + maintenance screen (D-pad friendly). Reached via the hidden gesture + PIN on the player,
 * or directly (no PIN) on first run / after the server revoked the device.
 */
class SettingsActivity : AppCompatActivity() {

    private lateinit var inputServer: EditText
    private lateinit var inputRoom: EditText
    private lateinit var inputKey: EditText
    private lateinit var btnRegister: Button
    private lateinit var btnTest: Button
    private lateinit var btnClear: Button
    private lateinit var btnExit: Button
    private lateinit var btnBack: Button
    private lateinit var btnResetup: Button
    private lateinit var statusMessage: TextView
    private lateinit var deviceInfo: TextView

    private val handler = Handler(Looper.getMainLooper())
    private var busyJob: Job? = null
    private val isSetup: Boolean get() = !Prefs.isRegistered

    private val infoUpdater = object : Runnable {
        override fun run() {
            refreshDeviceInfo()
            handler.postDelayed(this, 2000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Prefs.init(this)
        SyncManager.init(this)
        setContentView(R.layout.activity_settings)

        inputServer = findViewById(R.id.input_server)
        inputRoom = findViewById(R.id.input_room)
        inputKey = findViewById(R.id.input_key)
        btnRegister = findViewById(R.id.btn_register)
        btnTest = findViewById(R.id.btn_test)
        btnClear = findViewById(R.id.btn_clear_cache)
        btnExit = findViewById(R.id.btn_exit_kiosk)
        btnBack = findViewById(R.id.btn_back)
        btnResetup = findViewById(R.id.btn_resetup_qr)
        statusMessage = findViewById(R.id.status_message)
        deviceInfo = findViewById(R.id.device_info)

        // Nothing configured yet → the built-in server (BuildConfig.DEFAULT_SERVER_URL).
        inputServer.setText(QrSetupLogic.effectiveServer(Prefs.serverUrl, BuildConfig.DEFAULT_SERVER_URL))
        inputRoom.setText(Prefs.roomNumber)
        inputKey.setText(Prefs.registrationKey)

        btnRegister.setOnClickListener { register() }
        btnTest.setOnClickListener { testConnection() }
        btnClear.setOnClickListener {
            SyncManager.clearCacheLocal()
            showStatus(getString(R.string.cache_cleared), ok = true)
        }
        btnExit.setOnClickListener { exitKiosk() }
        btnBack.setOnClickListener { leave() }
        btnResetup.setOnClickListener { confirmResetup() }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() = leave()
        })
        applyMode()

        // Focus the first empty field (or the register button) so the remote works immediately.
        val firstEmpty = listOf(inputServer, inputRoom, inputKey).firstOrNull { it.text.isNullOrBlank() }
        (firstEmpty ?: btnRegister).requestFocus()
        if (savedInstanceState == null) handleProvisioning(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleProvisioning(intent)
    }

    override fun onResume() {
        super.onResume()
        applyMode()
        handler.post(infoUpdater)
    }

    override fun onPause() {
        handler.removeCallbacks(infoUpdater)
        super.onPause()
    }

    private fun applyMode() {
        val product = Prefs.brandProduct.takeIf { it.isNotBlank() }
        findViewById<TextView>(R.id.settings_title).text = when {
            product == null -> getString(if (isSetup) R.string.setup_title else R.string.settings_title)
            isSetup -> getString(R.string.setup_title_brand, product)
            else -> getString(R.string.settings_title_brand, product)
        }
        // Before registration "Back" returns to the QR setup screen (the player needs a room first).
        btnBack.setText(if (isSetup) R.string.back_to_qr else R.string.back_to_player)
        findViewById<View>(R.id.resetup_row).visibility = if (isSetup) View.GONE else View.VISIBLE
    }

    private fun leave() {
        if (isSetup) {
            if (busyJob?.isActive == true) return
            if (!intent.getBooleanExtra(EXTRA_FROM_QR, false)) {
                startActivity(Intent(this, QrSetupActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP))
            }
        }
        finish()
    }

    /** "Re-setup with QR": move this TV to another room without typing. */
    private fun confirmResetup() {
        if (busyJob?.isActive == true) return
        val room = Prefs.roomNumber.ifBlank { "-" }
        AlertDialog.Builder(this)
            .setTitle(R.string.resetup_confirm_title)
            .setMessage(getString(R.string.resetup_confirm_message, room))
            .setPositiveButton(R.string.resetup_confirm_yes) { _, _ -> resetupWithQr() }
            .setNegativeButton(R.string.cancel, null)
            .show()
            .also { it.getButton(AlertDialog.BUTTON_NEGATIVE)?.requestFocus() }
    }

    private fun resetupWithQr() {
        android.util.Log.i("SettingsActivity", "Unregistered room=${Prefs.roomNumber} for re-setup with QR")
        SyncManager.stop()
        PollService.stop(this)
        Prefs.clearRegistration()
        startActivity(Intent(this, QrSetupActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP))
        finish()
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        // Remote MENU closes settings (mirror of the gesture that opened it).
        if (keyCode == KeyEvent.KEYCODE_MENU && !isSetup) {
            finish()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    // ------------------------------------------------------------------ actions

    private fun readInputs(): Triple<String, String, String>? {
        val base = ServerUrl.normalize(inputServer.text?.toString())
        val room = inputRoom.text?.toString()?.trim().orEmpty()
        val key = inputKey.text?.toString()?.trim().orEmpty()
        var ok = true
        if (base == null) {
            inputServer.error = getString(R.string.invalid_url)
            ok = false
        }
        if (room.isEmpty()) {
            inputRoom.error = getString(R.string.field_required)
            ok = false
        }
        if (key.isEmpty()) {
            inputKey.error = getString(R.string.field_required)
            ok = false
        }
        return if (ok) Triple(base!!, room, key) else null
    }

    private fun register() {
        if (busyJob?.isActive == true) return
        val (base, room, key) = readInputs() ?: return
        doRegister(inputServer.text.toString(), base, room, key, provisioned = false)
    }

    private fun doRegister(serverRaw: String, base: String, room: String, key: String, provisioned: Boolean) {
        showStatus(getString(R.string.registering), ok = true)
        setBusy(true)
        busyJob = lifecycleScope.launch {
            try {
                val outcome = if (provisioned) {
                    Provisioning.saveAndRegister(applicationContext, Provisioning.Request(serverRaw, base, room, key, autoRegister = true, force = true))
                } else {
                    Registrar.register(applicationContext, serverRaw, base, room, key)
                }
                if (!outcome.ok) {
                    showStatus(registerErrorText(outcome), ok = false)
                    return@launch
                }
                showStatus(getString(R.string.register_ok, Prefs.roomNumber), ok = true)
                if (provisioned) delay(2_500) // let the installer / technician read the result
                startActivity(
                    Intent(this@SettingsActivity, MainActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
                )
                finish()
            } finally {
                setBusy(false)
            }
        }
    }

    /** Clear, translated messages for the V2 licence / suspension errors. */
    private fun registerErrorText(o: RegisterOutcome): String = when (o.errorCode) {
        "HOTEL_SUSPENDED" -> getString(R.string.register_error_hotel_suspended)
        "LICENSE_LIMIT" -> getString(R.string.register_error_license_limit)
        "INVALID_REGISTRATION_KEY" -> getString(R.string.register_error_invalid_key)
        "ROOM_NOT_FOUND" -> getString(R.string.register_error_room_not_found)
        else -> getString(R.string.register_failed, o.message)
    }

    /**
     * Provisioning extras from the bulk setup tool (see [Provisioning]). This activity is exported
     * only to holders of android.permission.DUMP (adb shell / system), so extras cannot come from
     * another app.
     */
    private fun handleProvisioning(intent: Intent?) {
        val parsed = Provisioning.parse(intent?.extras)
        when (parsed) {
            Provisioning.Parsed.None -> return
            is Provisioning.Parsed.Invalid -> {
                Provisioning.logResult("FAILED ${parsed.reason}")
                showStatus(getString(R.string.provision_invalid, parsed.reason), ok = false)
            }
            is Provisioning.Parsed.Valid -> {
                val r = parsed.request
                val refusal = Provisioning.refusal(r, Prefs.isRegistered)
                if (refusal != null) {
                    Provisioning.logResult("FAILED $refusal")
                    showStatus(getString(R.string.provision_rejected, refusal), ok = false)
                    return
                }
                inputServer.setText(r.serverUrl)
                inputRoom.setText(r.room)
                inputKey.setText(r.key)
                Provisioning.saveFields(r)
                if (r.autoRegister) {
                    doRegister(r.serverUrl, r.apiBase, r.room, r.key, provisioned = true)
                } else {
                    Provisioning.logResult("SAVED room=${r.room} (hc_autoregister=false)")
                    showStatus(getString(R.string.provision_filled), ok = true)
                    btnRegister.requestFocus()
                }
            }
        }
    }

    private fun testConnection() {
        if (busyJob?.isActive == true) return
        val base = ServerUrl.normalize(inputServer.text?.toString())
        if (base == null) {
            inputServer.error = getString(R.string.invalid_url)
            return
        }
        showStatus(getString(R.string.testing), ok = true)
        setBusy(true)
        busyJob = lifecycleScope.launch {
            try {
                val h = withContext(Dispatchers.IO) {
                    val api = ApiClient.serviceFor(base)
                    ApiClient.call { api.health() }
                }
                val db = if (h?.db == true) getString(R.string.yes) else getString(R.string.no)
                showStatus(getString(R.string.test_ok, h?.version ?: "?", db) + "\n$base", ok = true)
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                showStatus(getString(R.string.test_failed, ApiClient.describe(e)) + "\n$base", ok = false)
            } finally {
                setBusy(false)
            }
        }
    }

    private fun exitKiosk() {
        Prefs.kioskSuspendedUntil = System.currentTimeMillis() + 10 * 60_000L
        showStatus(getString(R.string.kiosk_suspended), ok = true)
        // Lock task must be stopped by the activity that started it (MainActivity), which then opens Android settings.
        startActivity(
            Intent(this, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(MainActivity.EXTRA_OPEN_ANDROID_SETTINGS, true)
        )
        finish()
    }

    private fun setBusy(busy: Boolean) {
        btnRegister.isEnabled = !busy
        btnTest.isEnabled = !busy
    }

    private fun showStatus(text: String, ok: Boolean) {
        statusMessage.text = text
        statusMessage.setTextColor(ContextCompat.getColor(this, if (ok) R.color.accent else R.color.offline_dot))
    }

    private fun refreshDeviceInfo() {
        val st = SyncManager.status.value
        val status = when {
            !Prefs.isRegistered -> getString(R.string.status_not_registered)
            st.online -> getString(R.string.status_online)
            else -> getString(R.string.status_offline) + (st.lastError?.let { " ($it)" } ?: "")
        }
        val lastPoll = Prefs.lastPollTime.takeIf { it > 0 }
            ?.let { DateFormat.getDateTimeInstance(DateFormat.SHORT, DateFormat.MEDIUM).format(Date(it)) }
            ?: getString(R.string.never)
        val room = listOf(Prefs.roomNumber, Prefs.roomName).filter { it.isNotBlank() }.joinToString(" – ").ifBlank { "-" }
        val lines = listOf(
            getString(R.string.info_device_id) to Prefs.deviceId,
            getString(R.string.info_ip) to (DeviceInfo.ipAddress() ?: "-"),
            getString(R.string.info_network) to DeviceInfo.networkType(this),
            getString(R.string.info_app_version) to "${DeviceInfo.appVersion} (${DeviceInfo.appVersionCode})",
            getString(R.string.info_android) to "${DeviceInfo.androidVersion} (API ${android.os.Build.VERSION.SDK_INT})",
            getString(R.string.info_model) to DeviceInfo.model,
            getString(R.string.info_room) to room,
            getString(R.string.info_hotel) to Prefs.hotelName.ifBlank { "-" },
            getString(R.string.info_status) to status,
            getString(R.string.info_last_poll) to lastPoll,
            getString(R.string.info_device_owner) to getString(if (KioskHelper.isDeviceOwner(this)) R.string.yes else R.string.no),
        )
        deviceInfo.text = lines.joinToString("\n") { (k, v) -> "$k: $v" }
    }

    companion object {
        const val EXTRA_SETUP = "setup"
        /** Opened from the QR setup screen ("Enter details manually"): Back returns there. */
        const val EXTRA_FROM_QR = "from_qr"
    }
}
