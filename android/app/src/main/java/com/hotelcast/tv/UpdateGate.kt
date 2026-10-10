package com.hotelcast.tv

import com.google.gson.annotations.SerializedName

/**
 * 2.6.0 forced update on app start (server 2.7, docs/modules/apk_manager.md).
 *
 * The server's answer to GET device/app-version (also `app_update` in every poll response): the newest app
 * offered to this TV and the highest *required* versionCode. The TV must not play content while its
 * installed versionCode is lower than [requiredVersionCode].
 */
data class AppUpdateInfo(
    @SerializedName("version_code") val versionCode: Int = 0,
    @SerializedName("version_name") val versionName: String? = null,
    @SerializedName("sha256") val sha256: String? = null,
    @SerializedName("size") val size: Long = 0,
    @SerializedName("url") val url: String? = null,
    @SerializedName("required") val required: Boolean = false,
    @SerializedName("required_version_code") val requiredVersionCode: Int = 0,
    @SerializedName("notes") val notes: String? = null,
) {
    val label: String get() = versionName?.takeIf { it.isNotBlank() } ?: versionCode.toString()
}

/** GET device/app-version response. */
data class AppVersionResponse(
    @SerializedName("installed_version_code") val installedVersionCode: Int? = null,
    @SerializedName("update") val update: AppUpdateInfo? = null,
)

/**
 * Pure decision logic of the start gate (unit tested, no Android types).
 *
 * Rules:
 *  - BLOCK only when the offered version is newer than the installed one AND a required version newer than the
 *    installed one exists (never for a lower / equal version, never without a download URL).
 *  - Server reached → its answer counts (an empty answer clears the cache: the release was withdrawn).
 *  - Server unreachable → the last answer cached from a poll / earlier start counts; no cache → start normally,
 *    so an offline TV keeps showing its cached content.
 *  - Failures: download problems retry automatically every [RETRY_DELAY_MS]; install failures retry
 *    automatically up to [MAX_AUTO_INSTALL_ATTEMPTS] times per version, then the screen stays with the error and
 *    a "Try again" button (the app still never plays the old version — owner's rule, documented).
 */
object UpdateGate {
    const val MAX_AUTO_INSTALL_ATTEMPTS = 3
    const val RETRY_DELAY_MS = 30_000L
    /** How long the cold start waits for the server before deciding from the cache. */
    const val CHECK_TIMEOUT_MS = 6_000L
    /** Free space needed besides the APK itself (installer copy + headroom). */
    const val SPACE_MARGIN_BYTES = 20L * 1024 * 1024

    enum class Source { SERVER, CACHE, NONE }

    data class Decision(val block: Boolean, val info: AppUpdateInfo?, val source: Source)

    enum class Failure { DOWNLOAD, NO_SPACE, CHECKSUM, WRONG_APK, INSTALL_BLOCKED, SIGNATURE, CANCELLED, INSTALL_FAILED }

    fun mustUpdate(installed: Int, info: AppUpdateInfo?): Boolean =
        info != null && info.required && !info.url.isNullOrBlank() &&
            info.versionCode > installed && info.requiredVersionCode > installed

    /**
     * @param serverReached the device/app-version call answered (even with `update: null`)
     * @param server its update info (null = nothing to install)
     * @param cached the last known info (Prefs), used only when the server was not reached
     */
    fun decide(installed: Int, serverReached: Boolean, server: AppUpdateInfo?, cached: AppUpdateInfo?): Decision =
        if (serverReached) {
            Decision(mustUpdate(installed, server), server, Source.SERVER)
        } else if (cached != null) {
            Decision(mustUpdate(installed, cached), cached, Source.CACHE)
        } else {
            Decision(false, null, Source.NONE)
        }

    /** What to keep in the cache after a server answer: the answer itself (null clears it). */
    fun cacheAfter(serverReached: Boolean, server: AppUpdateInfo?, cached: AppUpdateInfo?): AppUpdateInfo? =
        if (serverReached) server else cached

    /** Whether to retry on its own after [RETRY_DELAY_MS]; [installFailures] counts failed installs of this version. */
    fun shouldAutoRetry(failure: Failure, installFailures: Int): Boolean = when (failure) {
        Failure.DOWNLOAD, Failure.CHECKSUM -> true
        Failure.NO_SPACE -> true // the TV may free space (cache cleanup) — cheap to re-check
        Failure.WRONG_APK, Failure.SIGNATURE -> false // needs a new upload on the server; "Try again" stays
        Failure.INSTALL_BLOCKED, Failure.CANCELLED, Failure.INSTALL_FAILED -> installFailures < MAX_AUTO_INSTALL_ATTEMPTS
    }

    /** Failures that count as an install attempt (towards [MAX_AUTO_INSTALL_ATTEMPTS]). */
    fun countsAsInstallAttempt(failure: Failure): Boolean =
        failure == Failure.INSTALL_BLOCKED || failure == Failure.CANCELLED || failure == Failure.INSTALL_FAILED || failure == Failure.SIGNATURE

    /**
     * Maps a PackageInstaller result (EXTRA_STATUS, EXTRA_STATUS_MESSAGE) to a failure, null for success / pending.
     * Status values are android.content.pm.PackageInstaller.STATUS_* (kept as ints for unit tests).
     */
    fun classifyInstall(status: Int, message: String?): Failure? {
        val msg = message.orEmpty()
        if (msg.contains("UPDATE_INCOMPATIBLE", true) || msg.contains("signatures do not match", true) ||
            msg.contains("INCONSISTENT_CERTIFICATES", true) || msg.contains("NO_CERTIFICATES", true)
        ) return Failure.SIGNATURE
        return when (status) {
            STATUS_PENDING_USER_ACTION, STATUS_SUCCESS -> null
            STATUS_FAILURE_ABORTED -> Failure.CANCELLED
            STATUS_FAILURE_BLOCKED -> Failure.INSTALL_BLOCKED
            STATUS_FAILURE_STORAGE -> Failure.NO_SPACE
            STATUS_FAILURE_CONFLICT, STATUS_FAILURE_INCOMPATIBLE -> Failure.SIGNATURE
            STATUS_FAILURE_INVALID -> Failure.WRONG_APK
            else -> Failure.INSTALL_FAILED
        }
    }

    /** Enough free space for an APK of [apkSize] bytes ([alreadyDownloaded] bytes are on disk already). */
    fun hasSpace(usableBytes: Long, apkSize: Long, alreadyDownloaded: Long = 0): Boolean =
        apkSize <= 0 || usableBytes >= (apkSize - alreadyDownloaded).coerceAtLeast(0) + apkSize + SPACE_MARGIN_BYTES

    /** Progress percentage 0..100 (or -1 when the size is unknown). */
    fun percent(done: Long, total: Long): Int =
        if (total <= 0) -1 else ((done.coerceAtMost(total) * 100) / total).toInt()

    // PackageInstaller.STATUS_* values (API 21+).
    const val STATUS_PENDING_USER_ACTION = -1
    const val STATUS_SUCCESS = 0
    const val STATUS_FAILURE = 1
    const val STATUS_FAILURE_BLOCKED = 2
    const val STATUS_FAILURE_ABORTED = 3
    const val STATUS_FAILURE_INVALID = 4
    const val STATUS_FAILURE_CONFLICT = 5
    const val STATUS_FAILURE_STORAGE = 6
    const val STATUS_FAILURE_INCOMPATIBLE = 7
}
