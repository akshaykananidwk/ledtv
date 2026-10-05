package com.hotelcast.tv

import android.app.Activity
import android.app.AlertDialog
import android.os.SystemClock
import android.text.Editable
import android.text.InputFilter
import android.text.InputType
import android.text.TextWatcher
import android.util.TypedValue
import android.view.Gravity
import android.view.WindowManager
import android.view.inputmethod.EditorInfo
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.Toast

/**
 * 4-digit PIN prompt guarding SettingsActivity. The PIN is checked against the server's
 * settings_pin_hash (sha256), or the default PIN 1234 when no hash is known yet.
 * After 5 wrong attempts the dialog is locked for 60 seconds.
 */
object PinDialog {
    private var failures = 0
    private var lockedUntil = 0L

    fun show(activity: Activity, onDismiss: () -> Unit = {}, onSuccess: () -> Unit): AlertDialog? {
        if (activity.isFinishing) return null
        if (SystemClock.elapsedRealtime() < lockedUntil) {
            Toast.makeText(activity, activity.getString(R.string.pin_wrong), Toast.LENGTH_SHORT).show()
            return null
        }
        val input = EditText(activity).apply {
            inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD
            filters = arrayOf(InputFilter.LengthFilter(4))
            hint = activity.getString(R.string.pin_hint)
            gravity = Gravity.CENTER
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 34f)
            imeOptions = EditorInfo.IME_ACTION_DONE or EditorInfo.IME_FLAG_NO_EXTRACT_UI
            isSingleLine = true
        }
        val pad = (24 * activity.resources.displayMetrics.density).toInt()
        val wrapper = FrameLayout(activity).apply {
            setPadding(pad, pad / 2, pad, 0)
            addView(input)
        }
        val dialog = AlertDialog.Builder(activity)
            .setTitle(R.string.pin_title)
            .setView(wrapper)
            .setPositiveButton(R.string.ok, null)
            .setNegativeButton(R.string.cancel) { d, _ -> d.dismiss() }
            .create()

        fun check() {
            val pin = input.text?.toString().orEmpty()
            if (Utils.verifyPin(pin, Prefs.pinHash)) {
                failures = 0
                dialog.dismiss()
                onSuccess()
            } else {
                failures++
                input.setText("")
                input.error = activity.getString(R.string.pin_wrong)
                if (failures >= 5) {
                    failures = 0
                    lockedUntil = SystemClock.elapsedRealtime() + 60_000
                    dialog.dismiss()
                }
            }
        }

        input.setOnEditorActionListener { _, _, _ -> check(); true }
        input.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            override fun afterTextChanged(s: Editable?) {
                if ((s?.length ?: 0) == 4) check()
            }
        })
        dialog.setOnShowListener {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener { check() }
            input.requestFocus()
        }
        dialog.setOnDismissListener { onDismiss() }
        dialog.window?.setSoftInputMode(WindowManager.LayoutParams.SOFT_INPUT_STATE_VISIBLE)
        dialog.show()
        return dialog
    }
}
