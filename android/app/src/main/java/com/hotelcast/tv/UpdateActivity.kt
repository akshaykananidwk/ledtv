package com.hotelcast.tv

import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.content.res.ColorStateList
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.View
import android.view.WindowManager
import android.widget.Button
import android.widget.ProgressBar
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * 2.6.0 "Update required" (docs/modules/apk_manager.md): full-screen, cannot be dismissed (BACK is ignored;
 * HOME keeps normal Android behaviour on a TV that is not device owner, but the player never plays content
 * while the app is outdated and shows this screen again when opened). Downloads (resume, sha256) and installs
 * the required version: silently when device owner, else through the system installer.
 * Failures: clear text, automatic retry every 30 s ([UpdateGate.shouldAutoRetry]); after 3 failed installs of
 * the same version the screen stays with the error and "Try again" (the old version is never used).
 */
class UpdateActivity : AppCompatActivity() {

    private lateinit var progress: ProgressBar
    private lateinit var status: TextView
    private lateinit var error: TextView
    private lateinit var detail: TextView
    private lateinit var versions: TextView
    private lateinit var buttons: View
    private lateinit var retry: Button
    private lateinit var allow: Button

    private var info: AppUpdateInfo? = null
    private var job: Job? = null
    private var waitingForInstall = false
    private var firstAttempt = true
    private var lastFailure: UpdateGate.Failure? = null
    private val handler = Handler(Looper.getMainLooper())
    private val updater by lazy { AppUpdater(applicationContext) }
    private val autoRetry = Runnable { attempt() }
    private val installTimeout = Runnable {
        if (waitingForInstall) onFailure(UpdateGate.Failure.INSTALL_FAILED, getString(R.string.update_no_result))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Prefs.init(this)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setContentView(R.layout.activity_update)
        progress = findViewById(R.id.update_progress)
        status = findViewById(R.id.update_status)
        error = findViewById(R.id.update_error)
        detail = findViewById(R.id.update_detail)
        versions = findViewById(R.id.update_versions)
        buttons = findViewById(R.id.update_buttons)
        retry = findViewById(R.id.update_retry)
        allow = findViewById(R.id.update_allow)
        applyBrand()

        info = intent?.getStringExtra(EXTRA_INFO)?.let {
            try { ApiClient.gson.fromJson(it, AppUpdateInfo::class.java) } catch (_: Exception) { null }
        } ?: Prefs.appUpdate

        // The screen cannot be dismissed: BACK does nothing.
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() = Unit
        })
        retry.setOnClickListener { attempt() }
        allow.setOnClickListener { updater.openUnknownSourcesSettings() }

        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                UpdateStatus.events.collect { ev ->
                    if (ev != null && waitingForInstall) onFailure(ev.failure, ev.message)
                }
            }
        }
        showVersions()
    }

    override fun onResume() {
        super.onResume()
        hideSystemUi()
        val i = info
        if (i == null || !UpdateGate.mustUpdate(BuildConfig.VERSION_CODE, i)) {
            openPlayer()
            return
        }
        if (KioskHelper.isDeviceOwner(this)) KioskHelper.enterKioskIfPossible(this)
        if (job?.isActive == true || waitingForInstall) return
        // First start, or back from "Install unknown apps" settings: try right away.
        if (firstAttempt || lastFailure == UpdateGate.Failure.INSTALL_BLOCKED) attempt()
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        super.onDestroy()
    }

    private fun applyBrand() {
        val product = Prefs.brandProduct.takeIf { it.isNotBlank() } ?: getString(R.string.app_name)
        findViewById<TextView>(R.id.update_product).text = product
        val color = try {
            Prefs.brandColor.takeIf { it.isNotBlank() }?.let { Color.parseColor(it) }
        } catch (_: Exception) {
            null
        }
        if (color != null) {
            findViewById<View>(R.id.update_brand_bar).setBackgroundColor(color)
            progress.progressTintList = ColorStateList.valueOf(color)
        }
    }

    private fun showVersions() {
        val i = info ?: return
        versions.text = getString(R.string.update_versions, BuildConfig.VERSION_NAME, i.label)
    }

    private fun failuresFor(code: Int): Int = if (Prefs.updateFailVersion == code) Prefs.updateFailCount else 0

    /** One download + install attempt. */
    private fun attempt() {
        if (job?.isActive == true) return
        handler.removeCallbacks(autoRetry)
        handler.removeCallbacks(installTimeout)
        lastFailure = null
        UpdateStatus.clear()
        waitingForInstall = false
        val refresh = !firstAttempt
        firstAttempt = false
        error.visibility = View.GONE
        detail.visibility = View.GONE
        buttons.visibility = View.GONE
        allow.visibility = View.GONE
        progress.isIndeterminate = true
        status.text = getString(R.string.update_downloading_unknown)
        job = lifecycleScope.launch {
            try {
                if (refresh) {
                    // The release may have changed (new upload, no longer required): ask the server again.
                    val answer = UpdateCheck.fetch()
                    if (answer.reached) {
                        UpdateCheck.onServerInfo(applicationContext, answer.info)
                        if (!UpdateGate.mustUpdate(BuildConfig.VERSION_CODE, answer.info)) {
                            openPlayer()
                            return@launch
                        }
                        info = answer.info
                        showVersions()
                    }
                }
                val i = info ?: return@launch
                val apk = updater.downloadVerified(i) { done, total ->
                    runOnUiThread { showProgress(done, total) }
                }
                progress.isIndeterminate = true
                status.text = getString(R.string.update_installing)
                waitingForInstall = true
                withContext(Dispatchers.IO) { updater.installForGate(apk) }
                if (!KioskHelper.isDeviceOwner(this@UpdateActivity)) status.text = getString(R.string.update_confirm_hint)
                // A successful install restarts the app (BootReceiver MY_PACKAGE_REPLACED); no result → retry.
                handler.postDelayed(installTimeout, if (KioskHelper.isDeviceOwner(this@UpdateActivity)) 120_000L else 300_000L)
            } catch (e: CancellationException) {
                throw e
            } catch (e: AppUpdater.UpdateException) {
                onFailure(e.failure, e.message ?: "")
            } catch (e: Exception) {
                Log.w(TAG, "update attempt failed", e)
                onFailure(UpdateGate.Failure.DOWNLOAD, ApiClient.describe(e))
            }
        }
    }

    private fun showProgress(done: Long, total: Long) {
        val pct = UpdateGate.percent(done, total)
        if (pct < 0) {
            progress.isIndeterminate = true
            status.text = getString(R.string.update_downloading_unknown)
        } else {
            progress.isIndeterminate = false
            progress.progress = pct
            status.text = getString(R.string.update_downloading, pct)
        }
    }

    private fun onFailure(failure: UpdateGate.Failure, message: String) {
        waitingForInstall = false
        lastFailure = failure
        handler.removeCallbacks(installTimeout)
        val code = info?.versionCode ?: 0
        if (UpdateGate.countsAsInstallAttempt(failure)) {
            if (Prefs.updateFailVersion != code) {
                Prefs.updateFailVersion = code
                Prefs.updateFailCount = 0
            }
            Prefs.updateFailCount = Prefs.updateFailCount + 1
        }
        val failures = failuresFor(code)
        Log.w(TAG, "update failed: $failure $message (install failures $failures)")
        ErrorLog.add("update", "$failure: $message")
        progress.isIndeterminate = false
        progress.progress = 0
        error.text = getString(
            when (failure) {
                UpdateGate.Failure.DOWNLOAD -> R.string.update_err_download
                UpdateGate.Failure.NO_SPACE -> R.string.update_err_space
                UpdateGate.Failure.CHECKSUM -> R.string.update_err_checksum
                UpdateGate.Failure.WRONG_APK -> R.string.update_err_wrong_apk
                UpdateGate.Failure.INSTALL_BLOCKED -> R.string.update_err_blocked
                UpdateGate.Failure.SIGNATURE -> R.string.update_err_signature
                UpdateGate.Failure.CANCELLED -> R.string.update_err_cancelled
                UpdateGate.Failure.INSTALL_FAILED -> R.string.update_err_failed
            }
        )
        error.visibility = View.VISIBLE
        detail.text = listOfNotNull(
            message.takeIf { it.isNotBlank() },
            if (failures > 0) getString(R.string.update_attempts, failures, UpdateGate.MAX_AUTO_INSTALL_ATTEMPTS) else null,
        ).joinToString(" · ")
        detail.visibility = if (detail.text.isNullOrBlank()) View.GONE else View.VISIBLE
        allow.visibility = if (failure == UpdateGate.Failure.INSTALL_BLOCKED) View.VISIBLE else View.GONE
        buttons.visibility = View.VISIBLE
        if (UpdateGate.shouldAutoRetry(failure, failures)) {
            status.text = getString(R.string.update_retry_in, (UpdateGate.RETRY_DELAY_MS / 1000).toInt())
            handler.postDelayed(autoRetry, UpdateGate.RETRY_DELAY_MS)
        } else {
            status.text = getString(R.string.update_retry_manual)
        }
        (if (allow.visibility == View.VISIBLE) allow else retry).requestFocus()
    }

    /** No (longer a) required update: back to the player. */
    private fun openPlayer() {
        try {
            startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP))
        } catch (e: Exception) {
            Log.w(TAG, "Cannot open the player", e)
        }
        finish()
    }

    private fun hideSystemUi() {
        try {
            WindowCompat.setDecorFitsSystemWindows(window, false)
            WindowInsetsControllerCompat(window, window.decorView).apply {
                hide(WindowInsetsCompat.Type.systemBars())
                systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } catch (_: Exception) {
        }
    }

    companion object {
        private const val TAG = "UpdateActivity"
        private const val EXTRA_INFO = "update_info"

        fun intent(context: Context, info: AppUpdateInfo?): Intent =
            Intent(context, UpdateActivity::class.java)
                .putExtra(EXTRA_INFO, info?.let { ApiClient.gson.toJson(it) })
                .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
    }
}
