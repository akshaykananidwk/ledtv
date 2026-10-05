package com.hotelcast.tv

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
        statusMessage = findViewById(R.id.status_message)
        deviceInfo = findViewById(R.id.device_info)

        inputServer.setText(Prefs.serverUrl)
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

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() = leave()
        })
        applyMode()

        // Focus the first empty field (or the register button) so the remote works immediately.
        val firstEmpty = listOf(inputServer, inputRoom, inputKey).firstOrNull { it.text.isNullOrBlank() }
        (firstEmpty ?: btnRegister).requestFocus()
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
        findViewById<TextView>(R.id.settings_title).setText(if (isSetup) R.string.setup_title else R.string.settings_title)
        btnBack.visibility = if (isSetup) View.GONE else View.VISIBLE
    }

    private fun leave() {
        if (isSetup) return // nothing to go back to before registration
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
        showStatus(getString(R.string.registering), ok = true)
        setBusy(true)
        busyJob = lifecycleScope.launch {
            try {
                val req = RegisterRequest(
                    deviceId = Prefs.deviceId,
                    roomNumber = room,
                    registrationKey = key,
                    appVersion = DeviceInfo.appVersion,
                    appVersionCode = DeviceInfo.appVersionCode,
                    androidVersion = DeviceInfo.androidVersion,
                    model = DeviceInfo.model,
                    ipAddress = withContext(Dispatchers.IO) { DeviceInfo.ipAddress() },
                )
                val data = withContext(Dispatchers.IO) {
                    val api = ApiClient.serviceFor(base)
                    ApiClient.call { api.register(req) }
                }
                val token = data?.token
                if (token.isNullOrBlank()) throw IllegalStateException("Server returned no token")

                SyncManager.stop()
                Prefs.serverUrl = inputServer.text.toString().trim()
                Prefs.apiBase = base
                Prefs.registrationKey = key
                Prefs.roomNumber = data.room?.number ?: room
                Prefs.roomName = data.room?.name.orEmpty()
                Prefs.roomId = data.room?.id ?: 0L
                data.pollInterval?.let { Prefs.pollIntervalSec = it }
                data.heartbeatInterval?.let { Prefs.heartbeatIntervalSec = it }
                Prefs.pinHash = data.settingsPinHash
                Prefs.currentHash = "" // ask for the full content object on the next poll
                Prefs.token = token

                showStatus(getString(R.string.register_ok, Prefs.roomNumber), ok = true)
                PollService.start(this@SettingsActivity)
                SyncManager.pollNow()
                startActivity(
                    Intent(this@SettingsActivity, MainActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
                )
                finish()
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                showStatus(getString(R.string.register_failed, ApiClient.describe(e)), ok = false)
            } finally {
                setBusy(false)
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
            getString(R.string.info_status) to status,
            getString(R.string.info_last_poll) to lastPoll,
            getString(R.string.info_device_owner) to getString(if (KioskHelper.isDeviceOwner(this)) R.string.yes else R.string.no),
        )
        deviceInfo.text = lines.joinToString("\n") { (k, v) -> "$k: $v" }
    }

    companion object {
        const val EXTRA_SETUP = "setup"
    }
}
