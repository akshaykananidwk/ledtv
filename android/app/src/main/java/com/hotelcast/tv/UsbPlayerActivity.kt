package com.hotelcast.tv

import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.KeyEvent
import android.view.View
import android.view.WindowManager
import android.widget.FrameLayout
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import kotlinx.coroutines.launch

/**
 * 2.4 USB / offline mode for a TV that is **not registered**: plays the USB `KrishnaCloud` folder
 * full screen. Registered TVs play USB inside [MainActivity] (SyncManager swaps the content), so
 * this screen only exists until the TV is set up:
 *  - MENU, BACK ×5 or holding OK for 3 s opens the QR setup screen;
 *  - the USB drive removed / emptied → back to the normal start (setup screen);
 *  - registered meanwhile (QR setup, adb provisioning) → the normal player opens.
 */
class UsbPlayerActivity : AppCompatActivity(), ContentPlayer.Listener {

    private var player: ContentPlayer? = null
    private val handler = Handler(Looper.getMainLooper())
    private val backPresses = ArrayDeque<Long>()
    private var okDownAt = 0L

    private val registrationCheck = object : Runnable {
        override fun run() {
            if (Prefs.isRegistered) {
                openMain()
                return
            }
            handler.postDelayed(this, 3_000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Prefs.init(this)
        SyncManager.init(this)
        UsbMedia.init(this)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        val stage = FrameLayout(this).apply { setBackgroundColor(Color.BLACK) }
        setContentView(stage)
        @Suppress("DEPRECATION")
        window.decorView.systemUiVisibility = (View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY or View.SYSTEM_UI_FLAG_FULLSCREEN
            or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
            or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION or View.SYSTEM_UI_FLAG_LAYOUT_STABLE)
        player = ContentPlayer(this, stage, SyncManager.contentCache, this)
        lifecycleScope.launch {
            repeatOnLifecycle(Lifecycle.State.STARTED) {
                UsbMedia.items.collect { items ->
                    if (items.isEmpty()) {
                        Log.i(TAG, "USB media gone")
                        openMain()
                    } else {
                        UsbPlaylist.effective(null, items, UsbMedia.hash)?.let { player?.setContent(it) }
                    }
                }
            }
        }
    }

    override fun onStart() {
        super.onStart()
        if (Prefs.isRegistered) {
            openMain()
            return
        }
        handler.post(registrationCheck)
    }

    override fun onStop() {
        super.onStop()
        handler.removeCallbacks(registrationCheck)
        player?.stop()
    }

    override fun onDestroy() {
        handler.removeCallbacksAndMessages(null)
        player?.release()
        player = null
        super.onDestroy()
    }

    private fun openMain() {
        if (isFinishing) return
        try {
            startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_REORDER_TO_FRONT))
        } catch (e: Exception) {
            Log.w(TAG, "Cannot open the player", e)
        }
        finish()
    }

    private fun openSetup() {
        startActivity(Intent(this, QrSetupActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP))
    }

    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        val code = event.keyCode
        if (code == KeyEvent.KEYCODE_VOLUME_UP || code == KeyEvent.KEYCODE_VOLUME_DOWN || code == KeyEvent.KEYCODE_VOLUME_MUTE) {
            return super.dispatchKeyEvent(event)
        }
        val now = System.currentTimeMillis()
        if (event.action == KeyEvent.ACTION_DOWN) {
            when (code) {
                KeyEvent.KEYCODE_MENU, KeyEvent.KEYCODE_SETTINGS -> openSetup()
                KeyEvent.KEYCODE_BACK -> {
                    backPresses.addLast(now)
                    while (backPresses.isNotEmpty() && now - backPresses.first() > 3_000) backPresses.removeFirst()
                    if (backPresses.size >= 5) {
                        backPresses.clear()
                        openSetup()
                    }
                }
                KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_ENTER -> {
                    if (event.repeatCount == 0) okDownAt = now
                    else if (okDownAt > 0 && now - okDownAt >= 3_000) {
                        okDownAt = 0
                        openSetup()
                    }
                }
            }
        } else if (event.action == KeyEvent.ACTION_UP && (code == KeyEvent.KEYCODE_DPAD_CENTER || code == KeyEvent.KEYCODE_ENTER)) {
            if (okDownAt > 0 && now - okDownAt >= 3_000) openSetup()
            okDownAt = 0
        }
        return true // kiosk: the guest cannot leave with BACK
    }

    override fun onItemStarted(item: ContentItem) {}
    override fun onItemFinished(item: ContentItem, startedAtMillis: Long, durationSec: Int) {}
    override fun onNothingToPlay() {
        // Every file failed (unsupported codec / unreadable): retry after a rescan.
        handler.postDelayed({ UsbMedia.rescan() }, 60_000)
    }

    companion object {
        private const val TAG = "UsbPlayer"

        /** USB plugged into an unregistered TV while another screen (e.g. QR setup) is open. */
        fun startFromBackground(context: Context) {
            try {
                context.startActivity(
                    Intent(context, UsbPlayerActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                )
            } catch (e: Exception) {
                Log.w(TAG, "Cannot open the USB player: ${e.message}")
            }
        }
    }
}
