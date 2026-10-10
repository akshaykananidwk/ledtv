package com.hotelcast.tv

import android.annotation.SuppressLint
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.util.Log
import androidx.core.content.FileProvider
import android.content.pm.PackageInfo
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File
import java.io.FileOutputStream
import java.io.IOException

/**
 * Over-the-air updates (UPDATE_APP command):
 *  1. download the APK with the device auth headers,
 *  2. verify its sha256 and that it is our package,
 *  3. install it — silently via a PackageInstaller session when the app is device owner,
 *     otherwise via the system installer UI (ACTION_INSTALL_PACKAGE + FileProvider).
 * After a silent self-update Android kills the process; BootReceiver (MY_PACKAGE_REPLACED)
 * relaunches MainActivity.
 *
 * 2.6.0: downloads resume (HTTP Range) and are kept per version (hotelcast-<code>.apk), so a required update
 * fetched in the background while the TV plays ([prefetch]) installs at the next start even offline.
 * [downloadVerified] + [installForGate] are used by the "Update required" screen (UpdateActivity).
 */
class AppUpdater(private val context: Context) {

    data class Result(val ok: Boolean, val message: String)

    suspend fun update(
        url: String,
        expectedSha256: String?,
        versionCode: Int?,
        versionName: String?,
        beforeInstall: suspend (String) -> Unit = {},
    ): Result = withContext(Dispatchers.IO) {
        if (versionCode != null && versionCode in 1..BuildConfig.VERSION_CODE) {
            return@withContext Result(true, "Already up to date (installed ${BuildConfig.VERSION_CODE}, offered $versionCode)")
        }
        val apk = try {
            downloadMutex.withLock { download(url, versionCode ?: 0, 0L) { _, _ -> } }
        } catch (e: Exception) {
            return@withContext Result(false, "Download failed: ${ApiClient.describe(e)}")
        }
        try {
            if (!expectedSha256.isNullOrBlank()) {
                val actual = Utils.sha256Hex(apk)
                if (!actual.equals(expectedSha256.trim(), ignoreCase = true)) {
                    apk.delete()
                    return@withContext Result(false, "SHA-256 mismatch (expected $expectedSha256, got $actual)")
                }
            }
            val info = context.packageManager.getPackageArchiveInfo(apk.absolutePath, 0)
            if (info == null || info.packageName != context.packageName) {
                apk.delete()
                return@withContext Result(false, "APK is not a valid ${context.packageName} package")
            }
            val label = versionName ?: versionCode?.toString() ?: "?"
            beforeInstall("Installing $label")
            if (KioskHelper.isDeviceOwner(context)) {
                installSilently(apk)
                Result(true, "Silent install of $label started")
            } else {
                installInteractive(apk)
                Result(true, "Installer opened for $label")
            }
        } catch (e: Exception) {
            Log.e(TAG, "install failed", e)
            Result(false, "Install failed: ${e.message ?: e.javaClass.simpleName}")
        }
    }

    private fun updatesDir(): File {
        // API < 24 cannot use FileProvider with the system installer; use a world-readable external dir.
        val base = if (Build.VERSION.SDK_INT < Build.VERSION_CODES.N) context.externalCacheDir ?: context.cacheDir else context.cacheDir
        return File(base, "updates").apply { mkdirs() }
    }

    /** Failure of the gate's download / install step, classified for the "Update required" screen. */
    class UpdateException(val failure: UpdateGate.Failure, message: String) : IOException(message)

    /**
     * Download (resuming a partial file), verify sha256, package and versionCode of [info]. Returns the APK file.
     * Throws [UpdateException].
     */
    suspend fun downloadVerified(info: AppUpdateInfo, onProgress: (Long, Long) -> Unit = { _, _ -> }): File = withContext(Dispatchers.IO) {
        val url = info.url?.takeIf { it.isNotBlank() } ?: throw UpdateException(UpdateGate.Failure.WRONG_APK, "No download URL")
        downloadMutex.withLock {
            val target = File(updatesDir(), "hotelcast-${info.versionCode}.apk")
            if (target.isFile && verified(target, info)) {
                onProgress(target.length(), target.length())
                return@withLock target
            }
            val apk = try {
                download(url, info.versionCode, info.size, onProgress)
            } catch (e: UpdateException) {
                throw e
            } catch (e: Exception) {
                throw UpdateException(UpdateGate.Failure.DOWNLOAD, ApiClient.describe(e))
            }
            val sha = info.sha256?.trim().orEmpty()
            if (sha.isNotEmpty() && !Utils.sha256Hex(apk).equals(sha, ignoreCase = true)) {
                apk.delete()
                throw UpdateException(UpdateGate.Failure.CHECKSUM, "SHA-256 mismatch")
            }
            val pi = archiveInfo(apk)
            if (pi == null || pi.packageName != context.packageName) {
                apk.delete()
                throw UpdateException(UpdateGate.Failure.WRONG_APK, "Not a ${context.packageName} package")
            }
            @Suppress("DEPRECATION")
            val code = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) pi.longVersionCode.toInt() else pi.versionCode
            if (code <= BuildConfig.VERSION_CODE || code < info.requiredVersionCode) {
                apk.delete()
                throw UpdateException(UpdateGate.Failure.WRONG_APK, "APK has versionCode $code")
            }
            apk
        }
    }

    /** Background download of a required update while the TV keeps playing (installed at the next start). */
    suspend fun prefetch(info: AppUpdateInfo) {
        if (!UpdateGate.mustUpdate(BuildConfig.VERSION_CODE, info) || downloadMutex.isLocked) return
        try {
            downloadVerified(info)
            Log.i(TAG, "Required update ${info.label} downloaded; installs at the next app start")
        } catch (e: Exception) {
            Log.w(TAG, "Prefetch of ${info.label} failed: ${e.message}")
        }
    }

    private fun verified(file: File, info: AppUpdateInfo): Boolean {
        val sha = info.sha256?.trim().orEmpty()
        return sha.isNotEmpty() && Utils.sha256Hex(file).equals(sha, ignoreCase = true)
    }

    private fun archiveInfo(apk: File): PackageInfo? = try {
        context.packageManager.getPackageArchiveInfo(apk.absolutePath, 0)
    } catch (_: Exception) {
        null
    }

    /**
     * Install for the gate: silent when device owner; otherwise a PackageInstaller session whose confirmation
     * screen the system shows (InstallResultReceiver opens it). Results arrive in [UpdateStatus].
     */
    fun installForGate(apk: File) {
        if (!KioskHelper.isDeviceOwner(context) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
            !context.packageManager.canRequestPackageInstalls()
        ) {
            throw UpdateException(UpdateGate.Failure.INSTALL_BLOCKED, "\"Install unknown apps\" is not allowed for this app")
        }
        if (!KioskHelper.isDeviceOwner(context)) {
            // Lock task would block the system confirmation screen.
            Prefs.kioskSuspendedUntil = System.currentTimeMillis() + 5 * 60_000L
        }
        try {
            installSilently(apk)
        } catch (e: Exception) {
            Log.e(TAG, "gate install failed", e)
            throw UpdateException(UpdateGate.Failure.INSTALL_FAILED, e.message ?: e.javaClass.simpleName)
        }
    }

    /** Opens Android's "Install unknown apps" page for this app (non device-owner TVs). */
    fun openUnknownSourcesSettings(): Boolean = try {
        val i = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))
        } else {
            @Suppress("DEPRECATION")
            Intent(Settings.ACTION_SECURITY_SETTINGS)
        }
        Prefs.kioskSuspendedUntil = System.currentTimeMillis() + 5 * 60_000L
        context.startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        true
    } catch (e: Exception) {
        Log.w(TAG, "Cannot open install settings", e)
        false
    }

    /**
     * Downloads [url] to hotelcast-<code>.apk, continuing hotelcast-<code>.apk.part with a Range request.
     * Older update files are removed. [expectedSize] (0 = unknown) is used for the free-space check.
     */
    @SuppressLint("UsableSpace") // the APK must fit next to the installer copy; clearable cache is not counted on purpose
    private fun download(url: String, versionCode: Int, expectedSize: Long, onProgress: (Long, Long) -> Unit): File {
        val dir = updatesDir()
        val base = "hotelcast-$versionCode.apk"
        dir.listFiles()?.forEach { if (it.name != base && it.name != "$base.part") it.delete() }
        val target = File(dir, base)
        val tmp = File(dir, "$base.part")
        target.delete()
        var have = if (tmp.isFile) tmp.length() else 0L
        if (!UpdateGate.hasSpace(dir.usableSpace, expectedSize, have)) {
            throw UpdateException(UpdateGate.Failure.NO_SPACE, "Not enough free space")
        }
        for (attempt in 0..1) {
            val req = Request.Builder().url(url).get()
                .header("Accept", "application/vnd.android.package-archive,*/*")
                .apply {
                    // Explicit auth headers (interceptor only adds them for the API host; APK may live elsewhere on same server)
                    Prefs.token?.let { header("Authorization", "Bearer $it") }
                    header("X-Device-Id", Prefs.deviceId)
                    if (have > 0) header("Range", "bytes=$have-")
                }
                .build()
            ApiClient.downloadHttp.newCall(req).execute().use { resp ->
                if (resp.code == 416 && have > 0) {
                    tmp.delete()
                    have = 0
                    return@use
                }
                if (!resp.isSuccessful) throw IOException("HTTP ${resp.code}")
                val body = resp.body ?: throw IOException("Empty response")
                val append = resp.code == 206 && have > 0
                if (!append) have = 0
                val total = if (body.contentLength() > 0) have + body.contentLength() else expectedSize
                body.byteStream().use { input ->
                    FileOutputStream(tmp, append).use { out ->
                        val buf = ByteArray(64 * 1024)
                        var done = have
                        var lastReport = 0L
                        while (true) {
                            val n = input.read(buf)
                            if (n < 0) break
                            out.write(buf, 0, n)
                            done += n
                            val now = System.currentTimeMillis()
                            if (now - lastReport > 250) {
                                onProgress(done, total)
                                lastReport = now
                            }
                        }
                        onProgress(done, total)
                    }
                }
                if (total > 0 && tmp.length() != total) throw IOException("Truncated download")
                if (!tmp.renameTo(target)) throw IOException("rename failed")
                @Suppress("SetWorldReadable")
                target.setReadable(true, false)
                return target
            }
        }
        throw IOException("Download could not be resumed")
    }

    private fun installSilently(apk: File) {
        val installer = context.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(context.packageName)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
            }
        }
        val sessionId = installer.createSession(params)
        val session = installer.openSession(sessionId)
        try {
            apk.inputStream().use { input ->
                session.openWrite("base.apk", 0, apk.length()).use { out ->
                    input.copyTo(out, 64 * 1024)
                    session.fsync(out)
                }
            }
            val intent = Intent(context, InstallResultReceiver::class.java).setAction(ACTION_INSTALL_RESULT)
            var flags = PendingIntent.FLAG_UPDATE_CURRENT
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) flags = flags or PendingIntent.FLAG_MUTABLE
            val pi = PendingIntent.getBroadcast(context, sessionId, intent, flags)
            session.commit(pi.intentSender)
        } catch (e: Exception) {
            session.abandon()
            throw e
        } finally {
            session.close()
        }
    }

    private fun installInteractive(apk: File) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !context.packageManager.canRequestPackageInstalls()) {
            // Ask the user to allow "Install unknown apps" for HotelCast, then the next UPDATE_APP works.
            val i = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(i)
            throw IOException("\"Install unknown apps\" permission not granted; settings opened on the TV")
        }
        val uri: Uri = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", apk)
        } else {
            Uri.fromFile(apk)
        }
        @Suppress("DEPRECATION")
        val intent = Intent(Intent.ACTION_INSTALL_PACKAGE).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_GRANT_READ_URI_PERMISSION)
            putExtra(Intent.EXTRA_NOT_UNKNOWN_SOURCE, true)
            putExtra(Intent.EXTRA_RETURN_RESULT, false)
        }
        // Lock task would block the installer UI.
        Prefs.kioskSuspendedUntil = System.currentTimeMillis() + 5 * 60_000L
        try {
            context.startActivity(intent)
        } catch (e: Exception) {
            val view = Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(uri, "application/vnd.android.package-archive")
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }
            context.startActivity(view)
        }
    }

    companion object {
        private const val TAG = "AppUpdater"
        /** One download at a time (gate screen, background prefetch, UPDATE_APP command). */
        private val downloadMutex = Mutex()
        const val ACTION_INSTALL_RESULT = "com.hotelcast.tv.INSTALL_RESULT"
    }
}

/** Receives PackageInstaller session results. */
class InstallResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)
        val msg = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE)
        Log.i("InstallResult", "status=$status message=$msg")
        when (status) {
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                @Suppress("DEPRECATION")
                val confirm: Intent? = if (Build.VERSION.SDK_INT >= 33) {
                    intent.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
                } else {
                    intent.getParcelableExtra(Intent.EXTRA_INTENT)
                }
                if (confirm != null) {
                    try {
                        Prefs.init(context)
                        Prefs.kioskSuspendedUntil = System.currentTimeMillis() + 5 * 60_000L
                        context.startActivity(confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                    } catch (e: Exception) {
                        Log.w("InstallResult", "Cannot open confirmation", e)
                    }
                }
            }
            PackageInstaller.STATUS_SUCCESS -> {
                // Normally our process is killed before this arrives; if not, restart into the new version.
                try {
                    context.packageManager.getLaunchIntentForPackage(context.packageName)?.let {
                        context.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                    }
                } catch (_: Exception) {
                }
            }
            else -> Log.w("InstallResult", "Install failed: $status $msg")
        }
        // 2.6.0: the "Update required" screen shows the result (signature mismatch, blocked, cancelled …).
        UpdateGate.classifyInstall(status, msg)?.let { UpdateStatus.installFailed(it, msg ?: "status $status") }
    }

}
