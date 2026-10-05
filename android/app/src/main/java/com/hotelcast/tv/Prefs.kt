package com.hotelcast.tv

import android.annotation.SuppressLint
import android.content.Context
import android.content.SharedPreferences
import java.util.UUID

/** Persistent device configuration (SharedPreferences). Call [init] once from the Application. */
object Prefs {
    private const val FILE = "hotelcast_prefs"
    private lateinit var sp: SharedPreferences

    fun init(context: Context) {
        if (!::sp.isInitialized) sp = context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)
    }

    val isInitialized: Boolean get() = ::sp.isInitialized

    /** Stable UUID identifying this TV; generated on first use and never changed. */
    val deviceId: String
        @SuppressLint("ApplySharedPref")
        get() {
            val existing = sp.getString(K_DEVICE_ID, null)
            if (!existing.isNullOrBlank()) return existing
            val id = UUID.randomUUID().toString()
            sp.edit().putString(K_DEVICE_ID, id).commit()
            return id
        }

    var serverUrl: String
        get() = sp.getString(K_SERVER_URL, "") ?: ""
        set(v) = sp.edit().putString(K_SERVER_URL, v).apply()

    /** Normalised API base URL, always ending in "/api/". */
    var apiBase: String
        get() = sp.getString(K_API_BASE, "") ?: ""
        set(v) = sp.edit().putString(K_API_BASE, v).apply()

    var token: String?
        get() = sp.getString(K_TOKEN, null)
        @SuppressLint("ApplySharedPref")
        set(v) {
            sp.edit().putString(K_TOKEN, v).commit()
        }

    val isRegistered: Boolean get() = !token.isNullOrBlank() && apiBase.isNotBlank()

    var roomNumber: String
        get() = sp.getString(K_ROOM_NUMBER, "") ?: ""
        set(v) = sp.edit().putString(K_ROOM_NUMBER, v).apply()

    var roomName: String
        get() = sp.getString(K_ROOM_NAME, "") ?: ""
        set(v) = sp.edit().putString(K_ROOM_NAME, v).apply()

    var roomId: Long
        get() = sp.getLong(K_ROOM_ID, 0L)
        set(v) = sp.edit().putLong(K_ROOM_ID, v).apply()

    var registrationKey: String
        get() = sp.getString(K_REG_KEY, "") ?: ""
        set(v) = sp.edit().putString(K_REG_KEY, v).apply()

    var pollIntervalSec: Int
        get() = sp.getInt(K_POLL, DEFAULT_POLL)
        set(v) = sp.edit().putInt(K_POLL, v.coerceIn(3, 60)).apply()

    var heartbeatIntervalSec: Int
        get() = sp.getInt(K_HEARTBEAT, DEFAULT_HEARTBEAT)
        set(v) = sp.edit().putInt(K_HEARTBEAT, v.coerceIn(15, 3600)).apply()

    /** sha256 hex of the settings PIN as provided by the server; null before first registration. */
    var pinHash: String?
        get() = sp.getString(K_PIN_HASH, null)
        set(v) = sp.edit().putString(K_PIN_HASH, v).apply()

    /** Hash of the content currently displayed (sent as `hash` on every poll). */
    var currentHash: String
        get() = sp.getString(K_CURRENT_HASH, "") ?: ""
        set(v) = sp.edit().putString(K_CURRENT_HASH, v).apply()

    /** Set by SCREEN_OFF, cleared by SCREEN_ON. */
    var forcedScreenOff: Boolean
        get() = sp.getBoolean(K_SCREEN_OFF, false)
        set(v) = sp.edit().putBoolean(K_SCREEN_OFF, v).apply()

    /** Last power state the app applied (true = standby). Avoids re-sleeping on every poll. */
    var lastPowerOff: Boolean
        get() = sp.getBoolean(K_LAST_POWER_OFF, false)
        set(v) = sp.edit().putBoolean(K_LAST_POWER_OFF, v).apply()

    /** Guest turned the TV on with the remote while it was scheduled off; cleared on next state change. */
    var powerOverride: Boolean
        get() = sp.getBoolean(K_POWER_OVERRIDE, false)
        set(v) = sp.edit().putBoolean(K_POWER_OVERRIDE, v).apply()

    var kioskSuspendedUntil: Long
        get() = sp.getLong(K_KIOSK_SUSPENDED, 0L)
        set(v) = sp.edit().putLong(K_KIOSK_SUSPENDED, v).apply()

    val isKioskSuspended: Boolean get() = System.currentTimeMillis() < kioskSuspendedUntil

    var handledCommands: String
        get() = sp.getString(K_HANDLED_CMDS, "") ?: ""
        set(v) = sp.edit().putString(K_HANDLED_CMDS, v).apply()

    var lastPollTime: Long
        get() = sp.getLong(K_LAST_POLL, 0L)
        set(v) = sp.edit().putLong(K_LAST_POLL, v).apply()

    var lastCrashTime: Long
        get() = sp.getLong(K_LAST_CRASH, 0L)
        @SuppressLint("ApplySharedPref")
        set(v) {
            sp.edit().putLong(K_LAST_CRASH, v).commit()
        }

    /** Forget the server token (revoked device / re-setup). Keeps URL/room/key for convenience. */
    @SuppressLint("ApplySharedPref")
    fun clearRegistration() {
        sp.edit()
            .remove(K_TOKEN)
            .remove(K_CURRENT_HASH)
            .remove(K_HANDLED_CMDS)
            .commit()
    }

    const val DEFAULT_POLL = 8
    const val DEFAULT_HEARTBEAT = 60

    private const val K_DEVICE_ID = "device_id"
    private const val K_SERVER_URL = "server_url"
    private const val K_API_BASE = "api_base"
    private const val K_TOKEN = "token"
    private const val K_ROOM_NUMBER = "room_number"
    private const val K_ROOM_NAME = "room_name"
    private const val K_ROOM_ID = "room_id"
    private const val K_REG_KEY = "registration_key"
    private const val K_POLL = "poll_interval"
    private const val K_HEARTBEAT = "heartbeat_interval"
    private const val K_PIN_HASH = "settings_pin_hash"
    private const val K_CURRENT_HASH = "current_hash"
    private const val K_SCREEN_OFF = "forced_screen_off"
    private const val K_LAST_POWER_OFF = "last_power_off"
    private const val K_POWER_OVERRIDE = "power_override"
    private const val K_KIOSK_SUSPENDED = "kiosk_suspended_until"
    private const val K_HANDLED_CMDS = "handled_commands"
    private const val K_LAST_POLL = "last_poll_time"
    private const val K_LAST_CRASH = "last_crash_time"
}
