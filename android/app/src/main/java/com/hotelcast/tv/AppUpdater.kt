package com.hotelcast.tv

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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File
import java.io.IOException

/**
 * Over-the-air updates (UPDATE_APP command):
 *  1. download the APK with the device auth headers,
 *  2. verify its sha256 and that it is our package,
 *  3. install it — silently via a PackageInstaller session when the app is device owner,
 *     otherwise via the system installer UI (ACTION_INSTALL_PACKAGE + FileProvider).
 * After a silent self-update Android kills the process; BootReceiver (MY_PACKAGE_REPLACED)
 * relaunches MainActivity.
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
            download(url)
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

    private fun download(url: String): File {
        val dir = updatesDir()
        dir.listFiles()?.forEach { it.delete() }
        val target = File(dir, "hotelcast-update.apk")
        val tmp = File(dir, "hotelcast-update.apk.part")
        val req = Request.Builder().url(url).get()
            .header("Accept", "application/vnd.android.package-archive,*/*")
            .apply {
                // Explicit auth headers (interceptor only adds them for the API host; APK may live elsewhere on same server)
                Prefs.token?.let { header("Authorization", "Bearer $it") }
                header("X-Device-Id", Prefs.deviceId)
            }
            .build()
        ApiClient.downloadHttp.newCall(req).execute().use { resp ->
            if (!resp.isSuccessful) throw IOException("HTTP ${resp.code}")
            val body = resp.body ?: throw IOException("Empty response")
            body.byteStream().use { input -> tmp.outputStream().use { input.copyTo(it, 64 * 1024) } }
            val len = body.contentLength()
            if (len > 0 && tmp.length() != len) throw IOException("Truncated download")
        }
        if (!tmp.renameTo(target)) throw IOException("rename failed")
        @Suppress("SetWorldReadable")
        target.setReadable(true, false)
        return target
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
    }

}
