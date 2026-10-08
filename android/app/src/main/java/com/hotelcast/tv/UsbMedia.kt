package com.hotelcast.tv

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.Application
import android.app.admin.DevicePolicyManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.storage.StorageManager
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.io.File
import java.util.Locale

/**
 * 2.4 USB / offline mode — pure part (unit tested in UsbPlaylistTest): which files of a
 * `KrishnaCloud` / `HotelCast` folder play, in which order and for how long, and when the TV plays
 * them instead of the server's content.
 *
 * `playlist.txt` (optional, UTF-8) — one file per line, an optional duration in seconds after the
 * name, separated by a comma, semicolon, `|`, `=`, a tab or (when the name has no spaces at the end)
 * spaces. `#` starts a comment. Durations: images default to 10 s, videos to 0 (play to the end).
 * ```
 * # lobby loop
 * 01 welcome.jpg, 8
 * offer.png 15
 * hotel-film.mp4
 * ```
 * When playlist.txt lists at least one existing file, only the listed files play in that order;
 * otherwise every image / video plays in natural name order (`2.jpg` before `10.jpg`).
 */
object UsbPlaylist {
    val FOLDER_NAMES = listOf("KrishnaCloud", "HotelCast")
    const val PLAYLIST_FILE = "playlist.txt"
    const val DEFAULT_IMAGE_SEC = 10
    private const val MAX_DURATION_SEC = 86_400
    const val MAX_FILES = 500

    val IMAGE_EXT = setOf("jpg", "jpeg", "png", "webp", "gif", "bmp")
    val VIDEO_EXT = setOf("mp4", "m4v", "mkv", "webm", "mov", "3gp", "ts", "avi", "mpg", "mpeg")

    data class Entry(val name: String, val durationSec: Int?)

    /** A media file of the folder: [name] (file name) and [uri] (what the player loads). */
    data class MediaFile(val name: String, val uri: String, val size: Long = 0L, val modified: Long = 0L)

    fun extension(name: String): String = name.substringAfterLast('.', "").lowercase(Locale.ROOT)

    /** "image", "video" or null (not playable / hidden file). */
    fun typeOf(name: String): String? {
        if (name.startsWith(".")) return null // macOS "._x.jpg" resource forks, hidden files
        val ext = extension(name)
        return when (ext) {
            in IMAGE_EXT -> ContentItem.TYPE_IMAGE
            in VIDEO_EXT -> ContentItem.TYPE_VIDEO
            else -> null
        }
    }

    /** Natural, case-insensitive order: "2 b.jpg" < "10 a.jpg", "a.jpg" < "B.jpg". */
    val naturalOrder: Comparator<String> = Comparator { a, b ->
        val x = a.lowercase(Locale.ROOT)
        val y = b.lowercase(Locale.ROOT)
        var i = 0
        var j = 0
        while (i < x.length && j < y.length) {
            val cx = x[i]
            val cy = y[j]
            if (cx.isDigit() && cy.isDigit()) {
                val si = i
                val sj = j
                while (i < x.length && x[i].isDigit()) i++
                while (j < y.length && y[j].isDigit()) j++
                val nx = x.substring(si, i).trimStart('0')
                val ny = y.substring(sj, j).trimStart('0')
                if (nx.length != ny.length) return@Comparator nx.length - ny.length
                val c = nx.compareTo(ny)
                if (c != 0) return@Comparator c
                val lenDiff = (i - si) - (j - sj) // "01" after "1"
                if (lenDiff != 0) return@Comparator lenDiff
            } else {
                if (cx != cy) return@Comparator cx.compareTo(cy)
                i++
                j++
            }
        }
        val rest = (x.length - i) - (y.length - j)
        if (rest != 0) rest else a.compareTo(b)
    }

    /** Media files of a folder listing in play order (unsupported / hidden files dropped). */
    fun ordered(names: Collection<String>): List<String> =
        names.filter { typeOf(it) != null }.distinct().sortedWith(naturalOrder).take(MAX_FILES)

    /** Parses playlist.txt. Unknown / empty lines are skipped; never throws. */
    fun parsePlaylist(text: String): List<Entry> {
        val out = mutableListOf<Entry>()
        for (raw in text.removePrefix("﻿").lineSequence()) {
            val line = raw.substringBefore('#').trim()
            if (line.isEmpty()) continue
            var name = line
            var duration: Int? = null
            val sep = line.indexOfLast { it == ',' || it == ';' || it == '|' || it == '=' || it == '\t' }
            if (sep >= 0) {
                name = line.substring(0, sep).trim()
                duration = parseDuration(line.substring(sep + 1))
            } else {
                val m = Regex("^(.*\\S)\\s+(\\d{1,6})\\s*(s|sec|secs|seconds)?$", RegexOption.IGNORE_CASE).find(line)
                // "My film 2.mp4" has no duration: only take the number when the name keeps an extension.
                if (m != null && typeOf(m.groupValues[1].trim()) != null) {
                    name = m.groupValues[1].trim()
                    duration = parseDuration(m.groupValues[2])
                }
            }
            name = name.trim().trim('"').substringAfterLast('/').substringAfterLast('\\')
            if (name.isEmpty() || typeOf(name) == null) continue
            out.add(Entry(name, duration))
            if (out.size >= MAX_FILES) break
        }
        return out
    }

    private fun parseDuration(s: String): Int? {
        val t = s.trim().lowercase(Locale.ROOT).removeSuffix("seconds").removeSuffix("secs").removeSuffix("sec").removeSuffix("s").trim()
        val n = t.toIntOrNull() ?: return null
        return n.coerceIn(0, MAX_DURATION_SEC)
    }

    /**
     * Builds the play list: playlist.txt order and durations when it names existing files
     * (case-insensitive), otherwise all files in natural order.
     */
    fun build(files: List<MediaFile>, playlist: List<Entry>?, defaultImageSec: Int = DEFAULT_IMAGE_SEC): List<ContentItem> {
        val byName = files.filter { typeOf(it.name) != null }.associateBy { it.name.lowercase(Locale.ROOT) }
        val listed = playlist.orEmpty().mapNotNull { e -> byName[e.name.lowercase(Locale.ROOT)]?.let { it to e.durationSec } }
        val chosen: List<Pair<MediaFile, Int?>> = if (listed.isNotEmpty()) listed else
            ordered(byName.values.map { it.name }).mapNotNull { n -> byName[n.lowercase(Locale.ROOT)]?.let { it to null } }
        return chosen.take(MAX_FILES).map { (f, dur) ->
            val type = typeOf(f.name)!!
            val seconds = dur ?: if (type == ContentItem.TYPE_IMAGE) defaultImageSec else 0
            ContentItem(
                type = type,
                title = f.name,
                url = f.uri,
                // A video with 0 s plays to its end; an image needs a time.
                duration = if (type == ContentItem.TYPE_IMAGE && seconds <= 0) defaultImageSec else seconds,
                loop = type == ContentItem.TYPE_VIDEO,
            )
        }
    }

    /** Stable hash of a USB play list (changes when files, order, sizes or durations change). */
    fun hash(items: List<ContentItem>, files: List<MediaFile> = emptyList()): String {
        val sizes = files.associate { it.uri to "${it.size}:${it.modified}" }
        val sig = items.joinToString("\n") { "${it.url}|${it.duration}|${sizes[it.url].orEmpty()}" }
        return "usb-" + Utils.sha256Hex(sig).take(32)
    }

    /** What the TV plays. */
    enum class Source { SERVER, USB }

    /**
     * USB is used when there are USB items and either the admin forced it for the room (`usb_mode`)
     * or there is no server / cached content at all (never registered, or offline before the first
     * content arrived). Cached server content always wins over USB otherwise.
     */
    fun decide(server: Content?, usbItems: List<ContentItem>): Source = when {
        usbItems.isEmpty() -> Source.SERVER
        server == null -> Source.USB
        server.usbMode == true -> Source.USB
        else -> Source.SERVER
    }

    /** The content the player shows, given the server / cached content and the USB items. */
    fun effective(server: Content?, usbItems: List<ContentItem>, usbHash: String): Content? {
        if (decide(server, usbItems) == Source.SERVER) return server
        if (server == null) {
            return Content(hash = usbHash, mode = MODE_USB, items = usbItems, playlist = Playlist(name = "USB", transition = "fade", loop = true))
        }
        // Emergency, off and suspended keep their meaning; only the playlist is replaced.
        if (server.isEmergency || server.isSuspended || server.isOff) return server
        return server.copy(
            hash = (server.hash ?: "") + "+" + usbHash,
            mode = MODE_USB,
            items = usbItems,
            playlist = Playlist(name = "USB", transition = server.playlist?.transition ?: "fade", loop = true),
        )
    }

    const val MODE_USB = "usb"
}

/**
 * 2.4 USB / offline mode — device side: finds a `KrishnaCloud` (or `HotelCast`) folder at the root
 * of any mounted storage (USB drive, SD card, internal shared storage), keeps the list of its media
 * in [items] and rescans on ACTION_MEDIA_MOUNTED / UNMOUNTED and every 30 s.
 *
 * Mounts: StorageManager volumes (API 24+; `StorageVolume.directory` on API 30+), `/storage/` *
 * (all APIs, the only source before API 24), `/mnt/usb_storage`-style folders of older boxes and the
 * internal shared storage last. Permissions: READ_EXTERNAL_STORAGE up to API 32, READ_MEDIA_IMAGES /
 * READ_MEDIA_VIDEO on 33+; a device-owner TV grants them to itself silently, otherwise they are
 * requested once per app start on the player / setup screen when USB playback may be needed.
 */
@SuppressLint("StaticFieldLeak") // application context only
object UsbMedia {
    private const val TAG = "UsbMedia"
    private const val RESCAN_MS = 30_000L
    const val PERMISSION_REQUEST = 4242

    private lateinit var app: Context
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var loop: Job? = null

    private val _items = MutableStateFlow<List<ContentItem>>(emptyList())
    /** Playable USB items (empty = no folder / no media / no permission). */
    val items: StateFlow<List<ContentItem>> = _items

    @Volatile var hash: String = "usb-none"
        private set
    @Volatile var folder: String? = null
        private set
    @Volatile var lastError: String? = null
        private set
    @Volatile private var askedThisRun = false

    /** True when the player may need USB (set by SyncManager: no content / usb_mode / unregistered). */
    @Volatile var mayNeedUsb: Boolean = false

    @Synchronized
    fun init(context: Context) {
        if (::app.isInitialized) return
        app = context.applicationContext
        grantSelfIfDeviceOwner(app)
        try {
            val filter = IntentFilter().apply {
                addAction(Intent.ACTION_MEDIA_MOUNTED)
                addAction(Intent.ACTION_MEDIA_UNMOUNTED)
                addAction(Intent.ACTION_MEDIA_REMOVED)
                addAction(Intent.ACTION_MEDIA_EJECT)
                addAction(Intent.ACTION_MEDIA_BAD_REMOVAL)
                addDataScheme("file")
            }
            ContextCompat.registerReceiver(app, mountReceiver, filter, ContextCompat.RECEIVER_EXPORTED)
        } catch (e: Exception) {
            Log.w(TAG, "mount receiver failed: ${e.message}")
        }
        (app as? Application)?.registerActivityLifecycleCallbacks(permissionAsker)
        loop = scope.launch {
            while (isActive) {
                rescanNow()
                delay(RESCAN_MS)
            }
        }
    }

    private val mountReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            Log.i(TAG, "${intent.action} ${intent.data}")
            if (intent.action == Intent.ACTION_MEDIA_MOUNTED) askedThisRun = false // new drive: ask again
            rescan()
        }
    }

    fun rescan() {
        if (!::app.isInitialized) return
        scope.launch {
            delay(1_500) // the volume needs a moment after MEDIA_MOUNTED
            rescanNow()
        }
    }

    @Synchronized
    private fun rescanNow() {
        try {
            val dir = findFolder(roots(app))
            folder = dir?.absolutePath
            val files = dir?.listFiles()?.filter { it.isFile && UsbPlaylist.typeOf(it.name) != null }.orEmpty()
                .map { UsbPlaylist.MediaFile(it.name, Uri.fromFile(it).toString(), it.length(), it.lastModified()) }
            val playlist = dir?.let { d ->
                val f = d.listFiles()?.firstOrNull { it.isFile && it.name.equals(UsbPlaylist.PLAYLIST_FILE, ignoreCase = true) }
                try {
                    f?.takeIf { it.length() < 256 * 1024 }?.readText(Charsets.UTF_8)?.let { UsbPlaylist.parsePlaylist(it) }
                } catch (e: Exception) {
                    // Android 11+: non-media files on shared storage may not be readable → name order.
                    null
                }
            }
            val list = UsbPlaylist.build(files, playlist)
            val h = UsbPlaylist.hash(list, files)
            lastError = if (dir == null) null else if (list.isEmpty()) "no playable files" else null
            if (h != hash || list != _items.value) {
                hash = h
                _items.value = list
                Log.i(TAG, "USB folder ${dir?.absolutePath ?: "-"}: ${list.size} item(s)")
                if (list.isNotEmpty() && !Prefs.isRegistered) UsbPlayerActivity.startFromBackground(app)
            }
        } catch (e: Exception) {
            lastError = e.message
            Log.w(TAG, "scan failed: ${e.message}")
        }
    }

    /** Candidate storage roots: removable volumes first, internal shared storage last. */
    @Suppress("DEPRECATION")
    fun roots(context: Context): List<File> {
        val removable = linkedSetOf<File>()
        val internal = linkedSetOf<File>()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            try {
                val sm = context.getSystemService(Context.STORAGE_SERVICE) as StorageManager
                for (v in sm.storageVolumes) {
                    if (v.state != Environment.MEDIA_MOUNTED && v.state != Environment.MEDIA_MOUNTED_READ_ONLY) continue
                    val dir = volumeDir(v) ?: continue
                    if (v.isPrimary && !v.isRemovable) internal.add(dir) else removable.add(dir)
                }
            } catch (e: Exception) {
                Log.w(TAG, "storage volumes: ${e.message}")
            }
        }
        // Before API 24 (and as a safety net): every folder in /storage except the emulated / self links.
        File("/storage").listFiles()?.filter { it.isDirectory && it.name != "self" && it.name != "emulated" && it.name != "sdcard0" }
            ?.sortedBy { it.name }?.forEach { removable.add(it) }
        for (p in listOf("/mnt/usb_storage", "/mnt/usbhost", "/mnt/udisk", "/mnt/external_sd", "/mnt/sdcard/external_sd")) {
            val f = File(p)
            if (f.isDirectory) {
                removable.add(f)
                f.listFiles()?.filter { it.isDirectory }?.forEach { removable.add(it) } // /mnt/usb_storage/USB_DISK0
            }
        }
        try {
            internal.add(Environment.getExternalStorageDirectory())
        } catch (_: Exception) {
        }
        return (removable + internal).distinctBy { runCatching { it.canonicalPath }.getOrDefault(it.absolutePath) }
    }

    @SuppressLint("DiscouragedPrivateApi")
    private fun volumeDir(v: android.os.storage.StorageVolume): File? {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) return v.directory
        return try {
            // API 24–29: hidden getPath(); /storage scanning below covers devices that block it.
            (v.javaClass.getMethod("getPath").invoke(v) as? String)?.let { File(it) }
        } catch (_: Throwable) {
            null
        }
    }

    @Suppress("DEPRECATION")
    fun hasRemovableVolume(context: Context): Boolean {
        val internal = runCatching { Environment.getExternalStorageDirectory().canonicalPath }.getOrNull()
        return roots(context).any { runCatching { it.canonicalPath }.getOrNull() != internal }
    }

    /** First root that has a KrishnaCloud / HotelCast folder (name compared case-insensitively). */
    fun findFolder(roots: List<File>): File? {
        for (root in roots) {
            val children = try { root.listFiles() } catch (_: Exception) { null } ?: continue
            for (wanted in UsbPlaylist.FOLDER_NAMES) {
                children.firstOrNull { it.isDirectory && it.name.equals(wanted, ignoreCase = true) }?.let { return it }
            }
        }
        return null
    }

    // ------------------------------------------------------------------ permissions

    fun permissions(): Array<String> =
        if (Build.VERSION.SDK_INT >= 33) arrayOf("android.permission.READ_MEDIA_IMAGES", "android.permission.READ_MEDIA_VIDEO")
        else arrayOf(Manifest.permission.READ_EXTERNAL_STORAGE)

    fun hasPermission(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.M ||
            permissions().all { ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED }

    /** Device owner: grant the storage permissions to ourselves without a dialog. */
    fun grantSelfIfDeviceOwner(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M || hasPermission(context) || !KioskHelper.isDeviceOwner(context)) return false
        return try {
            val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
            val admin = KioskHelper.adminComponent(context)
            permissions().all { dpm.setPermissionGrantState(admin, context.packageName, it, DevicePolicyManager.PERMISSION_GRANT_STATE_GRANTED) }
                .also { if (it) Log.i(TAG, "storage permissions granted (device owner)") }
        } catch (e: Exception) {
            Log.w(TAG, "setPermissionGrantState failed: ${e.message}")
            false
        }
    }

    /** "Request at first use": ask once per app start on the player / setup screens when USB may be needed. */
    private val permissionAsker = object : Application.ActivityLifecycleCallbacks {
        override fun onActivityResumed(activity: Activity) {
            if (activity !is MainActivity && activity !is UsbPlayerActivity && activity !is QrSetupActivity) return
            if (hasPermission(activity)) return
            if (grantSelfIfDeviceOwner(activity)) {
                rescan()
                return
            }
            if (askedThisRun || Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return
            // Registered: only when the player may need USB (no content yet / usb_mode). Not registered:
            // only when a USB drive / SD card is mounted, so a normal QR setup is not interrupted.
            if (if (Prefs.isRegistered) !mayNeedUsb else !hasRemovableVolume(activity)) return
            askedThisRun = true
            try {
                ActivityCompat.requestPermissions(activity, permissions(), PERMISSION_REQUEST)
            } catch (e: Exception) {
                Log.w(TAG, "permission request failed: ${e.message}")
            }
        }

        override fun onActivityPaused(activity: Activity) {
            // Back from the permission dialog (or anything else): look again.
            if (::app.isInitialized && _items.value.isEmpty() && hasPermission(activity)) rescan()
        }

        override fun onActivityCreated(activity: Activity, savedInstanceState: Bundle?) {}
        override fun onActivityStarted(activity: Activity) {}
        override fun onActivityStopped(activity: Activity) {}
        override fun onActivitySaveInstanceState(activity: Activity, outState: Bundle) {}
        override fun onActivityDestroyed(activity: Activity) {}
    }

    /** Unregistered TV with USB media: open the USB player instead of the setup screen. */
    fun startPlayerIfUnregistered(activity: Activity): Boolean {
        if (Prefs.isRegistered || _items.value.isEmpty()) return false
        activity.startActivity(Intent(activity, UsbPlayerActivity::class.java))
        return true
    }

    /** Small status object for heartbeats / logs. */
    fun state(source: UsbPlaylist.Source?): Map<String, Any?> = linkedMapOf(
        "source" to source?.name?.lowercase(Locale.ROOT),
        "folder" to (folder != null),
        "files" to _items.value.size,
        "permission" to (::app.isInitialized && hasPermission(app)),
        "error" to lastError,
    )
}
