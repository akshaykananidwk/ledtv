package com.hotelcast.tv

import android.content.Context
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File
import java.io.IOException

/**
 * Offline cache:
 *  - the last Content object as JSON (filesDir/content/last_content.json)
 *  - media files (images/videos) in filesDir/media, named by sha256(url)
 */
class ContentCache(context: Context) {
    private val appContext = context.applicationContext
    private val contentDir = File(appContext.filesDir, "content")
    private val contentFile = File(contentDir, "last_content.json")
    val mediaDir = File(appContext.filesDir, "media")

    @Synchronized
    fun saveContent(content: Content) {
        try {
            contentDir.mkdirs()
            val tmp = File(contentDir, "last_content.json.tmp")
            tmp.writeText(ApiClient.gson.toJson(content), Charsets.UTF_8)
            if (!tmp.renameTo(contentFile)) {
                contentFile.delete()
                tmp.renameTo(contentFile)
            }
        } catch (e: Exception) {
            Log.w(TAG, "saveContent failed", e)
        }
    }

    @Synchronized
    fun loadContent(): Content? = try {
        if (contentFile.exists()) ApiClient.gson.fromJson(contentFile.readText(Charsets.UTF_8), Content::class.java) else null
    } catch (e: Exception) {
        Log.w(TAG, "loadContent failed", e)
        null
    }

    fun fileFor(url: String): File {
        val ext = url.substringBefore('?').substringBefore('#').substringAfterLast('/', "")
            .substringAfterLast('.', "").lowercase()
            .takeIf { it.length in 2..5 && it.all { c -> c.isLetterOrDigit() } }
        val name = Utils.sha256Hex(url).take(40) + (if (ext != null) ".$ext" else "")
        return File(mediaDir, name)
    }

    /** Cached file for [url] if fully downloaded, else null. */
    fun cachedFile(url: String?): File? {
        if (url.isNullOrBlank()) return null
        val f = fileFor(url)
        return if (f.exists() && f.length() > 0) f else null
    }

    /** Downloads [url] into the cache (no-op if present). Returns the file or null on failure. */
    suspend fun download(url: String): File? = withContext(Dispatchers.IO) {
        cachedFile(url)?.let { return@withContext it }
        mediaDir.mkdirs()
        val target = fileFor(url)
        val tmp = File(mediaDir, target.name + ".part")
        try {
            val req = Request.Builder().url(url).get().header("Accept", "*/*").build()
            ApiClient.downloadHttp.newCall(req).execute().use { resp ->
                if (!resp.isSuccessful) throw IOException("HTTP ${resp.code}")
                val body = resp.body ?: throw IOException("empty body")
                val len = body.contentLength()
                val free = DeviceInfo.freeStorageMb(appContext) * 1024L * 1024L
                if (len > 0 && free in 0 until len + MIN_FREE_BYTES) throw IOException("Not enough storage for $url")
                body.byteStream().use { input -> tmp.outputStream().use { out -> input.copyTo(out, 64 * 1024) } }
                if (len > 0 && tmp.length() != len) throw IOException("Truncated download")
            }
            if (!tmp.renameTo(target)) throw IOException("rename failed")
            target
        } catch (e: Exception) {
            Log.w(TAG, "download failed: $url (${e.message})")
            tmp.delete()
            null
        }
    }

    /** Delete media not referenced by [keepUrls]. */
    fun prune(keepUrls: Collection<String>) {
        try {
            val keep = keepUrls.map { fileFor(it).name }.toSet()
            mediaDir.listFiles()?.forEach { f ->
                if (f.name !in keep) f.delete()
            }
        } catch (e: Exception) {
            Log.w(TAG, "prune failed", e)
        }
    }

    fun deleteMedia(url: String) {
        try { fileFor(url).delete() } catch (_: Exception) { }
    }

    @Synchronized
    fun clearAll() {
        try {
            contentDir.deleteRecursively()
            mediaDir.deleteRecursively()
            File(appContext.cacheDir, "image_manager_disk_cache").deleteRecursively()
        } catch (e: Exception) {
            Log.w(TAG, "clearAll failed", e)
        }
    }

    companion object {
        private const val TAG = "ContentCache"
        private const val MIN_FREE_BYTES = 200L * 1024 * 1024

        /** All media URLs of a content object that should be cached. */
        fun mediaUrls(content: Content?): List<String> {
            if (content == null) return emptyList()
            val urls = content.items.orEmpty().flatMap { it.cacheableUrls() }.toMutableList()
            content.hotel?.logoUrl?.takeIf { it.isNotBlank() }?.let { urls.add(it) }
            content.branding?.logoUrl?.takeIf { it.isNotBlank() }?.let { urls.add(it) }
            return urls.distinct()
        }
    }
}
