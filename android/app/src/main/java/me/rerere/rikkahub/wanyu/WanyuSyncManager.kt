package me.rerere.rikkahub.wanyu

import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import me.rerere.ai.core.MessageRole
import me.rerere.ai.ui.UIMessage
import me.rerere.rikkahub.data.datastore.DEFAULT_ASSISTANT_ID
import me.rerere.rikkahub.data.datastore.Settings
import me.rerere.rikkahub.data.datastore.SettingsStore
import me.rerere.rikkahub.data.db.dao.ConversationDAO
import me.rerere.rikkahub.data.db.entity.ConversationEntity
import me.rerere.rikkahub.data.model.Conversation
import me.rerere.rikkahub.data.model.MessageNode
import me.rerere.rikkahub.data.repository.ConversationRepository
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlin.uuid.Uuid

/**
 * 会话与消息同步。
 * 官方账号登录后自动上传本地会话，再拉取云端新增会话。
 */
class WanyuSyncManager(
    private val api: WanyuApi,
    private val session: WanyuSession,
    private val conversationDao: ConversationDAO,
    private val repository: ConversationRepository,
    private val settingsStore: SettingsStore,
    private val json: Json,
) {
    private val timePattern = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss") // 本地时间 pattern
    private val timeFormat = timePattern.withZone(ZoneId.systemDefault()) // Instant 格式化用

    /** 设置 + 会话 + 消息同步，异常由调用方捕获 */
    suspend fun syncNow() {
        if (!session.isLoggedIn()) return
        reconcileSettings()                             // 先对齐模型配置等设置
        pushLocal()
        pullRemote()
    }

    /**
     * 设置对齐：云端为空时用本地覆盖上传，云端有数据时恢复到本地。
     * 首次登录的新账号走上传分支，老账号换机登录走恢复分支。
     */
    suspend fun reconcileSettings() {
        if (!session.isLoggedIn()) return
        val remote = runCatching {               // 云端读取失败时不影响会话同步
            api.loadSettings()["settings"]?.jsonPrimitive?.content.orEmpty()
        }.getOrNull() ?: return

        if (remote.isEmpty()) {                  // 云端还没有备份
            uploadSettings()                      // 上传本地设置
            return
        }
        val decoded = runCatching {              // 反序列化云端设置
            json.decodeFromString(Settings.serializer(), remote)
        }.getOrNull() ?: return                 // 版本不兼容时保留本地
        settingsStore.update { decoded }         // 写回本地并落盘
    }

    /** 把当前本地设置整体上传，登录后设置变更时也会调用 */
    suspend fun uploadSettings() {
        if (!session.isLoggedIn()) return
        val settings = settingsStore.awaitLoaded() // 等启动读盘完成，避免上传占位值
        val text = json.encodeToString(Settings.serializer(), settings) // 序列化为JSON文本
        api.saveSettings(text)                   // 提交后端
    }

    /** 会话数据变化流：登录期间用于监听本地变更并自动上传 */
    fun observeConversations(): Flow<List<ConversationEntity>> = conversationDao.getAll()

    /** 上传本地会话：仅同步7天内活跃的会话（与云端保留期一致），每批20个 */
    suspend fun pushLocal() {
        val cutoff = System.currentTimeMillis() - SYNC_RETENTION_MILLIS
        val entities = conversationDao.getAll().first().filter { it.updateAt >= cutoff }
        entities.chunked(PUSH_BATCH_SIZE).forEach { batch ->
            val payload = buildJsonArray {
                batch.forEach { entity ->
                    val id = runCatching { Uuid.parse(entity.id) }.getOrNull() ?: return@forEach
                    val conversation = runCatching { repository.getConversationById(id) }.getOrNull()
                        ?: return@forEach
                    add(buildConversation(conversation))
                }
            }
            if (payload.isNotEmpty()) {
                api.syncPush(payload)
            }
        }
    }

    /**
     * 拉取云端增量并合并到本地：
     * - 本地不存在的会话直接落库（换机/重装后恢复）
     * - 云端较新的会话用云端数据重建（保留本地置顶/助手等属性）
     * - 云端标记删除的会话同步删除本地副本
     */
    suspend fun pullRemote() {
        val data = api.syncPull(session.syncSince)
        val serverTime = data["server_time"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()

        val conversations = data["conversations"] as? JsonArray ?: JsonArray(emptyList())
        val messages = data["messages"] as? JsonArray ?: JsonArray(emptyList())
        val messagesByConversation = messages.groupBy { element ->
            element.jsonObject["server_conversation_id"]?.jsonPrimitive?.intOrNull ?: 0
        }

        conversations.forEach { element ->
            val obj = element.jsonObject
            val clientId = obj["client_id"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
            val uuid = runCatching { Uuid.parse(clientId) }.getOrNull() ?: return@forEach
            val deleted = obj["deleted"]?.jsonPrimitive?.intOrNull == 1
            val existing = runCatching { repository.getConversationById(uuid) }.getOrNull()

            if (deleted) {                          // 云端已删除：本地同步删除
                if (existing != null) {
                    runCatching { repository.deleteConversation(existing) }
                }
                return@forEach
            }

            val serverId = obj["server_id"]?.jsonPrimitive?.intOrNull ?: 0
            val title = obj["title"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
            val nodes = messagesByConversation[serverId].orEmpty()
                .filter { it.jsonObject["deleted"]?.jsonPrimitive?.intOrNull != 1 }
                .mapNotNull { messageElement ->
                    val messageObj = messageElement.jsonObject
                    val role = messageObj["role"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
                    val content = messageObj["content"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
                    if (content.isEmpty()) null
                    else MessageNode(messages = listOf(toUiMessage(role, content)), selectIndex = 0)
                }

            if (existing == null) {                 // 本地不存在：直接落库
                runCatching {
                    repository.insertConversation(
                        Conversation(
                            id = uuid,
                            assistantId = DEFAULT_ASSISTANT_ID,
                            title = title,
                            messageNodes = nodes,
                            createAt = parseServerTime(obj["created_at"]) ?: Instant.now(),
                            updateAt = parseServerTime(obj["updated_at"]) ?: Instant.now(),
                        )
                    )
                }
            } else {                                // 本地已存在：云端较新时重建
                val remoteUpdatedAt = obj["updated_at"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
                if (remoteUpdatedAt > timeFormat.format(existing.updateAt)) {
                    val rebuilt = existing.copy(
                        title = title,
                        messageNodes = nodes,
                        updateAt = parseServerTime(obj["updated_at"]) ?: existing.updateAt,
                    )
                    runCatching { repository.updateConversation(rebuilt) }
                }
            }
        }

        if (serverTime.isNotEmpty()) {
            session.syncSince = serverTime
        }
    }

    private fun buildConversation(conversation: Conversation): JsonObject = buildJsonObject {
        put("client_id", conversation.id.toString())
        put("title", conversation.title)
        put("created_at", timeFormat.format(conversation.createAt))
        put("updated_at", timeFormat.format(conversation.updateAt))
        put("messages", buildJsonArray {
            val current = runCatching { conversation.currentMessages }.getOrElse { emptyList() }
            current.takeLast(300).forEach { message ->
                add(buildJsonObject {
                    put("client_id", message.id.toString())
                    put("role", message.role.name.lowercase())
                    put("content", message.toText().take(200000))
                    put("created_at", formatMessageTime(message))
                })
            }
        })
    }

    /** 解析服务端返回的 yyyy-MM-dd HH:mm:ss 时间为 Instant（按本机时区） */
    private fun parseServerTime(element: kotlinx.serialization.json.JsonElement?): Instant? {
        val text = element?.jsonPrimitive?.toString()?.trim('"').orEmpty()
        if (text.isEmpty()) return null
        return runCatching {
            java.time.LocalDateTime.parse(text, timePattern).atZone(ZoneId.systemDefault()).toInstant()
        }.getOrNull()
    }

    /** 格式化消息自带的创建时间（kotlinx LocalDateTime → 服务端时间字符串） */
    private fun formatMessageTime(message: UIMessage): String {
        val dt = message.createdAt
        fun Int.pad(): String = toString().padStart(2, '0')
        return "${dt.year}-${dt.monthNumber.pad()}-${dt.dayOfMonth.pad()} ${dt.hour.pad()}:${dt.minute.pad()}:${dt.second.pad()}"
    }

    private fun toUiMessage(role: String, content: String): UIMessage = when (role) {
        "assistant" -> UIMessage.assistant(content)
        "system" -> UIMessage.system(content)
        "tool" -> UIMessage(role = MessageRole.TOOL, parts = listOf(me.rerere.ai.ui.UIMessagePart.Text(content)))
        else -> UIMessage.user(content)
    }

    private companion object {
        const val PUSH_BATCH_SIZE = 20                          // 与后端单次限制一致
        const val SYNC_RETENTION_MILLIS = 7L * 24 * 3600 * 1000 // 云端只保留7天，本地也只推7天内的
    }
}
