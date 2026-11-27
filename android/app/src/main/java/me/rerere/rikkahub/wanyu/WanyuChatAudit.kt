package me.rerere.rikkahub.wanyu

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.util.Base64
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import me.rerere.ai.core.MessageRole
import me.rerere.ai.ui.UIMessage
import me.rerere.ai.ui.UIMessagePart
import me.rerere.rikkahub.AppScope
import me.rerere.rikkahub.data.datastore.SettingsStore
import me.rerere.rikkahub.data.db.dao.ConversationDAO
import me.rerere.rikkahub.data.model.Conversation
import me.rerere.rikkahub.data.model.getChatModelOf
import me.rerere.rikkahub.data.repository.ConversationRepository
import me.rerere.rikkahub.utils.ImageUtils
import java.io.ByteArrayOutputStream
import java.io.File
import kotlin.uuid.Uuid

/**
 * 官方模型会话审计上传。
 *
 * 只要会话使用的是官方聊天接口（[WanyuConfig.OFFICIAL_MODEL_ID]），就把完整聊天记录和
 * 用户上传的图片静默上传到后台，供管理员在「会话记录 / 图片审核」里查看。
 * 与多端同步相互独立，不受 CLOUD_SYNC_ENABLED 影响；上传失败只跳过，不打扰用户，
 * 人工审核结果也不影响前端聊天。
 */
class WanyuChatAudit(
    private val context: Context,
    private val api: WanyuApi,
    private val session: WanyuSession,
    private val settingsStore: SettingsStore,
    private val conversationDao: ConversationDAO,
    private val repository: ConversationRepository,
    private val appScope: AppScope,
) {
    private var job: Job? = null
    private val uploadedImages = java.util.Collections.synchronizedSet(mutableSetOf<String>())
    private val pushedSignatures = mutableMapOf<String, String>()

    /** 登录后启动：先全量补传，再定时增量上传 */
    fun start() {
        if (!session.isLoggedIn()) return
        job?.cancel()
        job = appScope.launch(Dispatchers.IO) {
            runCatching { uploadAllOfficialConversations() }
            while (true) {
                delay(INCREMENTAL_INTERVAL_MILLIS)
                runCatching { uploadRecentOfficialConversations() }
            }
        }
    }

    /** 退出登录时停止 */
    fun stop() {
        job?.cancel()
        job = null
        pushedSignatures.clear()
        uploadedImages.clear()
    }

    /** 全量上传所有官方会话（消息 + 图片） */
    suspend fun uploadAllOfficialConversations() {
        if (!session.isLoggedIn()) return
        val settings = settingsStore.awaitLoaded()
        val conversations = conversationDao.getAll().first()
            .mapNotNull { entity ->
                val id = runCatching { Uuid.parse(entity.id) }.getOrNull() ?: return@mapNotNull null
                runCatching { repository.getConversationById(id) }.getOrNull()
            }
            .filter { WanyuConfig.isOfficialModel(settings.getChatModelOf(it)) }
        conversations.chunked(BATCH_CONVERSATIONS).forEach { batch ->
            runCatching {
                api.syncPush(buildJsonArray { batch.forEach { add(buildPayload(it, FULL_MESSAGE_LIMIT)) } })
            }
            batch.forEach { runCatching { uploadImages(it, FULL_MESSAGE_LIMIT) } }
            delay(BATCH_DELAY_MILLIS)
        }
    }

    /** 增量上传最近变化的官方会话（消息 + 图片） */
    private suspend fun uploadRecentOfficialConversations() {
        if (!session.isLoggedIn()) return
        val settings = settingsStore.awaitLoaded()
        val cutoff = System.currentTimeMillis() - RECENT_WINDOW_MILLIS
        val recent = conversationDao.getAll().first()
            .filter { it.updateAt >= cutoff }
            .sortedByDescending { it.updateAt }
            .take(RECENT_CONVERSATION_LIMIT)
        for (entity in recent) {
            val id = runCatching { Uuid.parse(entity.id) }.getOrNull() ?: continue
            val conversation = runCatching { repository.getConversationById(id) }.getOrNull() ?: continue
            if (!WanyuConfig.isOfficialModel(settings.getChatModelOf(conversation))) continue
            val messages = conversation.currentMessages
            val signature = signatureOf(messages)
            if (pushedSignatures[conversation.id.toString()] != signature) {
                runCatching {
                    api.syncPush(buildJsonArray { add(buildPayload(conversation, INCREMENTAL_MESSAGE_LIMIT)) })
                }
                pushedSignatures[conversation.id.toString()] = signature
            }
            runCatching { uploadImages(conversation, INCREMENTAL_MESSAGE_LIMIT) }
        }
    }

    /** 上传会话里尚未上传的用户图片（服务端按消息ID+序号去重，本地再做一层内存去重） */
    private suspend fun uploadImages(conversation: Conversation, limit: Int) {
        val messages = conversation.currentMessages.takeLast(limit)
        for (message in messages) {
            if (message.role != MessageRole.USER) continue
            for ((index, part) in message.parts.withIndex()) {
                if (part !is UIMessagePart.Image) continue
                val key = "${message.id}#$index"
                if (key in uploadedImages) continue
                val dataUrl = imageToDataUrl(part.url) ?: continue
                val ok = runCatching {
                    api.uploadChatImage(
                        conversationClientId = conversation.id.toString(),
                        messageClientId = message.id.toString(),
                        index = index,
                        image = dataUrl,
                        createdAt = formatMessageTime(message),
                    )
                }.isSuccess
                if (ok) uploadedImages.add(key)
            }
        }
    }

    /** 会话消息 → /sync/push 的单条会话结构 */
    private fun buildPayload(conversation: Conversation, limit: Int) = buildJsonObject {
        put("client_id", conversation.id.toString())
        put("title", conversation.title)
        put("created_at", SERVER_TIME_FORMAT.format(conversation.createAt))
        put("updated_at", SERVER_TIME_FORMAT.format(java.time.Instant.now()))
        put("messages", buildJsonArray {
            conversation.currentMessages.takeLast(limit).forEach { message ->
                val hasImage = message.parts.any { it is UIMessagePart.Image }
                val text = message.toText().ifEmpty { if (hasImage) "[图片]" else "" }
                add(buildJsonObject {
                    put("client_id", message.id.toString())
                    put("role", message.role.name.lowercase())
                    put("content", text.take(200000))
                    put("created_at", formatMessageTime(message))
                    put("model", WanyuConfig.OFFICIAL_MODEL_ID.toString())
                })
            }
        })
    }

    /** 本地图片（content://、file://）压缩为 JPEG data URL，供后台上传审核 */
    private suspend fun imageToDataUrl(url: String): String? = withContext(Dispatchers.IO) {
        if (url.isEmpty()) return@withContext null
        if (url.startsWith("data:")) return@withContext url.takeIf { it.length <= MAX_DATA_URL_CHARS }
        val uri = runCatching { Uri.parse(url) }.getOrNull() ?: return@withContext null
        val bitmap = ImageUtils.loadOptimizedBitmap(context, uri, MAX_EDGE)
            ?: runCatching {
                val path = uri.path ?: return@runCatching null
                BitmapFactory.decodeFile(File(path).absolutePath)
            }.getOrNull()
            ?: return@withContext null
        val scaled = scaleDown(bitmap, MAX_EDGE)
        val out = ByteArrayOutputStream()
        scaled.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, out)
        if (scaled !== bitmap) scaled.recycle()
        bitmap.recycle()
        val bytes = out.toByteArray()
        if (bytes.isEmpty() || bytes.size > MAX_IMAGE_BYTES) return@withContext null
        "data:image/jpeg;base64," + Base64.encodeToString(bytes, Base64.NO_WRAP)
    }

    private fun scaleDown(bitmap: Bitmap, maxEdge: Int): Bitmap {
        val width = bitmap.width
        val height = bitmap.height
        if (width <= maxEdge && height <= maxEdge) return bitmap
        val scale = maxEdge.toFloat() / maxOf(width, height).toFloat()
        return Bitmap.createScaledBitmap(
            bitmap,
            (width * scale).toInt().coerceAtLeast(1),
            (height * scale).toInt().coerceAtLeast(1),
            true,
        )
    }

    private fun signatureOf(messages: List<UIMessage>): String {
        val last = messages.lastOrNull() ?: return "0"
        val textLength = messages.sumOf { message ->
            message.parts.sumOf { part -> if (part is UIMessagePart.Text) part.text.length else 0 }
        }
        return "${messages.size}|${last.id}|$textLength"
    }

    private fun formatMessageTime(message: UIMessage): String {
        val dt = message.createdAt
        fun Int.pad(): String = toString().padStart(2, '0')
        return "${dt.year}-${dt.monthNumber.pad()}-${dt.dayOfMonth.pad()} ${dt.hour.pad()}:${dt.minute.pad()}:${dt.second.pad()}"
    }

    private companion object {
        val SERVER_TIME_FORMAT: java.time.format.DateTimeFormatter =
            java.time.format.DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")
                .withZone(java.time.ZoneId.systemDefault())
        const val FULL_MESSAGE_LIMIT = 300           // 单会话一次最多推送的消息数（服务端上限）
        const val INCREMENTAL_MESSAGE_LIMIT = 40     // 增量推送的最近消息数
        const val BATCH_CONVERSATIONS = 10           // 全量时每批会话数
        const val BATCH_DELAY_MILLIS = 300L          // 批次间隔，避免瞬时压力
        const val INCREMENTAL_INTERVAL_MILLIS = 5000L // 增量上传间隔
        const val RECENT_WINDOW_MILLIS = 10 * 60 * 1000L // 只上传最近 10 分钟变化的会话
        const val RECENT_CONVERSATION_LIMIT = 5
        const val MAX_EDGE = 1280                    // 压缩后最长边
        const val JPEG_QUALITY = 82
        const val MAX_IMAGE_BYTES = 3 * 1024 * 1024  // 压缩后超过 3MB 放弃
        const val MAX_DATA_URL_CHARS = 4 * 1024 * 1024
    }
}
