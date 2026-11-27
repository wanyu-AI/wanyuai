package me.rerere.rikkahub.wanyu

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toLocalDateTime
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import me.rerere.ai.core.MessageRole
import me.rerere.ai.ui.UIMessage
import me.rerere.ai.ui.UIMessagePart
import me.rerere.rikkahub.AppScope
import me.rerere.rikkahub.data.db.dao.ConversationDAO
import me.rerere.rikkahub.data.model.MessageNode
import me.rerere.rikkahub.data.repository.ConversationRepository
import me.rerere.rikkahub.service.ChatService
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlin.time.Clock
import kotlin.uuid.Uuid

/**
 * 实时同步：让同一账号的手机与平板在同一个会话里保持一致（含「正在思考」过程）。
 *
 * 完全复用线上已有的 /sync/push 与 /sync/pull，不需要后端升级：
 * 1. 每 1.6 秒把「本机正在生成的会话」和「数据库里刚变化的会话」推送到 /sync/push，
 *    生成中的内容直接从 ChatService 的内存会话读取，所以半截文本和空文本都能实时同步；
 * 2. 每 1.8 秒拉一次 /sync/pull，把对端新增/正在输出的消息按消息ID合并进本地数据库；
 * 3. 「正在思考」：只要最近几秒内看到对端该会话有消息更新，就判定对端正在生成，
 *    由聊天页显示为思考中；超过活跃窗口没有更新即视为生成结束。
 *
 * 服务端 messages.updated_at 在每次 ON DUPLICATE KEY UPDATE 时自动刷新，
 * 因此同一条助手消息的流式内容可以靠 since 增量持续拉回来。
 */
class WanyuLiveSyncManager(
    private val api: WanyuApi,
    private val session: WanyuSession,
    private val conversationDao: ConversationDAO,
    private val repository: ConversationRepository,
    private val chatService: ChatService,
    private val json: Json,
    private val appScope: AppScope,
) {
    private val timePattern = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss") // 服务端时间格式
    private val timeFormat = timePattern.withZone(ZoneId.systemDefault())        // Instant → 文本

    private val _remoteGenerating = MutableStateFlow<Set<String>>(emptySet())    // 对端正在生成的会话
    val remoteGenerating: StateFlow<Set<String>> = _remoteGenerating.asStateFlow()

    private var pollJob: Job? = null                                             // 拉取循环
    private var pushJob: Job? = null                                             // 推送循环
    private var since: String = ""                                               // 增量时间点（服务端时间）
    private var lastPushAt = 0L                                                  // 上次推送时间，做节流
    private var suppressPushUntil = 0L                                           // 合并后短暂抑制自身上传
    private var pendingFullPull = false                                          // 是否需要下一轮做一次全量拉取
    private val localGenerating = mutableSetOf<String>()                         // 本机正在生成的会话
    private val remoteActivity = mutableMapOf<String, Long>()                    // 对端会话最近活动时间
    private val serverIdToClient = mutableMapOf<Int, String>()                   // 服务端会话ID → 客户端会话UUID
    private val pushedSignature = mutableMapOf<String, String>()                 // 会话最近一次推送的内容签名

    /** 登录后启动；重复调用会先取消旧任务 */
    fun start() {
        if (!session.isLoggedIn()) return                                        // 未登录不启动
        since = session.syncSince                                                // 接续上次同步时间点
        pollJob?.cancel()                                                        // 清理旧任务
        pushJob?.cancel()                                                        // 清理旧任务
        pollJob = appScope.launch(Dispatchers.IO) { pollLoop() }                 // 开启拉取循环
        pushJob = appScope.launch(Dispatchers.IO) { pushLoop() }                 // 开启推送循环
    }

    /** 退出登录时停止全部任务 */
    fun stop() {
        pollJob?.cancel()                                                        // 停止拉取
        pushJob?.cancel()                                                        // 停止推送
        pollJob = null                                                           // 释放引用
        pushJob = null                                                           // 释放引用
        _remoteGenerating.value = emptySet()                                     // 清空对端生成状态
        localGenerating.clear()                                                  // 清空本地生成标记
        remoteActivity.clear()                                                   // 清空活动时间
        serverIdToClient.clear()                                                 // 清空会话映射
        pushedSignature.clear()                                                  // 清空推送签名
    }

    // ---------------- 拉取 -----------------------------

    private suspend fun pollLoop() {
        while (true) {                                                           // 常驻循环
            val ok = runCatching { pullOnce() }.isSuccess                         // 单次拉取，失败不抛出
            refreshRemoteGenerating()                                            // 刷新「对端正在思考」
            delay(if (ok) POLL_INTERVAL_MILLIS else RETRY_INTERVAL_MILLIS)       // 成功快轮询，失败退避
        }
    }

    /** 拉一次并把增量合并进本地 */
    private suspend fun pullOnce() {
        if (!session.isLoggedIn()) return                                        // 已退出登录
        val full = pendingFullPull                                               // 本轮是否整包拉取
        val requestSince = if (full) "" else requestSince()                      // 整包时不带 since
        val data = api.syncPull(requestSince)                                    // 请求后端
        if (full) pendingFullPull = false                                        // 用掉这次全量机会

        val serverTime = (data["server_time"] as? JsonPrimitive)?.content.orEmpty() // 服务端时间（content 已解转义）
        val conversations = data["conversations"] as? JsonArray ?: JsonArray(emptyList())    // 会话增量
        val messages = data["messages"] as? JsonArray ?: JsonArray(emptyList())             // 消息增量

        val convInfo = mutableMapOf<String, JsonObject>()                        // 客户端会话ID → 会话对象
        conversations.forEach { element ->
            val obj = element as? JsonObject ?: return@forEach                   // 只处理对象
            val clientId = obj.stringOrEmpty("client_id")                        // 客户端会话UUID
            val serverId = obj["server_id"]?.jsonPrimitive?.intOrNull            // 服务端会话ID
            if (clientId.isNotEmpty()) {
                convInfo[clientId] = obj                                         // 缓存会话信息
                if (serverId != null) serverIdToClient[serverId] = clientId       // 记录映射，供消息归属使用
            }
        }

        if (messages.isNotEmpty()) {
            applyRemoteMessages(messages, convInfo)                               // 合并消息
        }
        if (serverTime.isNotEmpty()) {                                            // 更新增量起点
            since = serverTime                                                    // 下次只取更新的
            session.syncSince = serverTime                                        // 持久化，重启后接着拉
        }
    }

    /** 把云端消息合并进本地会话（按消息ID对齐，流式内容逐次覆盖） */
    private suspend fun applyRemoteMessages(elements: JsonArray, convInfo: Map<String, JsonObject>) {
        val grouped = mutableMapOf<String, MutableList<JsonObject>>()             // 按客户端会话ID分组
        elements.forEach { element ->
            val obj = element as? JsonObject ?: return@forEach                    // 只处理对象
            val serverConvId = obj["server_conversation_id"]?.jsonPrimitive?.intOrNull ?: return@forEach // 所属会话
            val clientId = serverIdToClient[serverConvId] ?: return@forEach        // 映射不到就跳过
            grouped.getOrPut(clientId) { mutableListOf() }.add(obj)                // 收集该会话的消息
            remoteActivity[clientId] = System.currentTimeMillis()                  // 记录对端活动时间
        }

        grouped.forEach { (convId, list) ->
            if (convId in localGenerating) return@forEach                          // 本机正在生成：不覆盖
            val uuid = runCatching { Uuid.parse(convId) }.getOrNull() ?: return@forEach // 非法ID
            val parsed = list.mapNotNull { toUiMessage(it) }                       // 解析消息（空文本只当「思考中」信号）
            val local = runCatching { repository.getConversationById(uuid) }.getOrNull() // 本地会话

            if (local == null) {                                                   // 本地还没有这个会话
                pendingFullPull = true                                             // 下一轮整包拉一次，取全该会话历史
                return@forEach                                                     // 本轮先不建
            }

            var changed = false                                                    // 是否有实际变化
            val nodes = local.messageNodes.toMutableList()                          // 复制节点列表
            parsed.forEach { message ->
                val nodeIndex = nodes.indexOfFirst { node -> node.messages.any { it.id == message.id } } // 按消息ID定位
                if (nodeIndex >= 0) {                                              // 已存在：覆盖内容（流式更新）
                    val old = nodes[nodeIndex]                                     // 旧节点
                    val oldMessage = old.messages.firstOrNull { it.id == message.id } // 旧消息
                    if (signature(oldMessage) != signature(message)) {              // 内容确实变了
                        nodes[nodeIndex] = old.copy(
                            messages = old.messages.map { if (it.id == message.id) message else it } // 替换同ID消息
                        )
                        changed = true                                             // 标记有变化
                    }
                } else {                                                           // 不存在：追加新节点
                    nodes.add(MessageNode(messages = listOf(message), selectIndex = 0)) // 新消息
                    changed = true                                                 // 标记有变化
                }
            }
            if (!changed) return@forEach                                           // 无变化不写库

            val remoteUpdated = parseInstant(convInfo[convId]?.get("updated_at")) ?: local.updateAt // 云端更新时间
            val newUpdated = if (remoteUpdated > local.updateAt) remoteUpdated else local.updateAt // 取较新
            runCatching {
                repository.updateConversation(local.copy(messageNodes = nodes, updateAt = newUpdated)) // 落库
            }
            pushedSignature[convId] = signatureOf(nodes.lastOrNull()?.messages?.lastOrNull()) // 视为已同步，避免回推
            suppressPushUntil = System.currentTimeMillis() + SUPPRESS_PUSH_MILLIS   // 抑制回环
        }
    }

    /** 刷新「对端正在生成」集合：最近几秒内有消息更新的会话即视为生成中 */
    private fun refreshRemoteGenerating() {
        val now = System.currentTimeMillis()                                       // 当前时间
        val active = remoteActivity.entries
            .filter { now - it.value <= REMOTE_ACTIVE_MILLIS }                      // 只保留活跃窗口内的
            .map { it.key }
            .filter { it !in localGenerating }                                      // 本机自己在生成的不算对端
            .toSet()
        if (active != _remoteGenerating.value) {                                    // 有变化才更新
            _remoteGenerating.value = active                                         // 发布状态
        }
        remoteActivity.entries.removeAll { now - it.value > REMOTE_ACTIVE_MILLIS * 3 } // 清理过期记录
    }

    // ---------------- 推送 -----------------------------

    private suspend fun pushLoop() {
        while (true) {                                                            // 常驻循环
            runCatching { pushOnce() }                                            // 单次推送，失败不抛出
            delay(PUSH_INTERVAL_MILLIS)                                           // 固定节流
        }
    }

    /** 挑出需要推送的会话：本机正在生成的优先，其次是数据库里刚变化的 */
    private suspend fun pushOnce() {
        if (!session.isLoggedIn()) return                                         // 未登录
        if (System.currentTimeMillis() < suppressPushUntil) return                // 刚合并云端数据，先不推

        val generatingIds = runCatching {
            chatService.getConversationJobs().first()                             // 内存中的生成任务
        }.getOrDefault(emptyMap())
            .filterValues { it?.isActive == true }                                // 只取仍在运行的任务
            .keys.map { it.toString() }                                           // 会话UUID

        val recentIds = runCatching {
            conversationDao.getAll().first()                                      // 数据库中最近变化的会话
        }.getOrDefault(emptyList())
            .filter { it.updateAt >= Instant.now().minusSeconds(RECENT_WINDOW_SECONDS).toEpochMilli() }
            .sortedByDescending { it.updateAt }
            .map { it.id }                                                        // 会话UUID

        val targets = (generatingIds + recentIds).distinct().take(PUSH_TARGET_LIMIT) // 优先生成中的，最多两个
        if (targets.isEmpty()) return                                             // 无目标

        val now = System.currentTimeMillis()                                       // 当前时间
        lastPushAt = now                                                          // 记录推送时间
        targets.forEach { id ->
            runCatching { pushConversation(Uuid.parse(id)) }                       // 单个失败不影响另一个
        }
    }

    /** 推送单个会话的最近几条消息；助手消息未完成时按当前文本推送，空文本即代表「刚开始思考」 */
    private suspend fun pushConversation(uuid: Uuid) {
        val conversation = runCatching { chatService.getConversation(uuid) }.getOrNull() ?: return // 内存优先，否则读库
        val messages = conversation.currentMessages                              // 当前展示的消息（流式时是半截内容）
        val last = messages.lastOrNull()                                         // 最后一条
        val generating = last?.role == MessageRole.ASSISTANT && last.finishedAt == null // 助手未完成 = 生成中
        val convId = conversation.id.toString()                                   // 会话UUID文本
        if (generating) localGenerating.add(convId) else localGenerating.remove(convId) // 维护本地生成标记

        val recent = messages.takeLast(PUSH_MESSAGE_COUNT)                       // 只推最近几条，控制流量
        val signature = signatureOf(recent.lastOrNull()) + "|" + recent.size    // 内容签名
        if (!generating && pushedSignature[convId] == signature) return          // 非生成态且内容没变：不重复推
        pushedSignature[convId] = signature                                      // 记录本次签名

        val nowText = timeFormat.format(Instant.now())                            // 每次推送都刷新会话时间
        val payload = buildJsonArray {
            add(buildJsonObject {
                put("client_id", convId)                                         // 会话UUID
                put("title", conversation.title)                                 // 会话标题
                put("created_at", timeFormat.format(conversation.createAt))       // 创建时间
                put("updated_at", nowText)                                       // 更新时间（保证对端能取到该会话）
                put("messages", buildJsonArray {
                    recent.forEach { message ->
                        add(buildJsonObject {
                            put("client_id", message.id.toString())              // 消息UUID，两端靠它对齐
                            put("role", message.role.name.lowercase())           // 角色
                            put("content", unescapeLegacy(message.toText()).take(200000)) // 纯文本（推送前先修复旧转义残留）
                            put("created_at", formatMessageTime(message))        // 消息创建时间
                        })
                    }
                })
            })
        }
        api.syncPush(payload)                                                     // 提交到 /sync/push
        _remoteGenerating.value = _remoteGenerating.value - convId                // 自己不再是「对端生成中」
    }

    // ---------------- 工具 -----------------------------

    /** 把后端消息还原为 UIMessage：空文本返回 null（只作为「思考中」信号，不写入本地） */
    private fun toUiMessage(obj: JsonObject): UIMessage? {
        val idText = obj.stringOrEmpty("client_id")                               // 消息ID
        val id = runCatching { Uuid.parse(idText) }.getOrNull() ?: return null    // 解析失败则丢弃
        val role = obj.stringOrEmpty("role")                                      // 角色
        val content = unescapeLegacy(obj.stringOrEmpty("content"))                // 文本内容（并修复旧版转义残留）
        if (content.isEmpty()) return null                                         // 空文本只在活动时间里体现
        val createdAt = parseLocalDateTime(obj["created_at"])                     // 消息时间
        return UIMessage(
            id = id,
            role = when (role) {
                "assistant" -> MessageRole.ASSISTANT
                "system" -> MessageRole.SYSTEM
                "tool" -> MessageRole.TOOL
                else -> MessageRole.USER
            },
            parts = listOf(UIMessagePart.Text(content)),
            createdAt = createdAt,
            finishedAt = createdAt,                                               // 拉回来的消息按已完成处理，避免被误判为本机生成
        )
    }

    /** 内容签名：用于判断云端消息是否与本地一致，避免无意义写库/推送 */
    private fun signature(message: UIMessage?): String = signatureOf(message)

    /** 内容签名实现：ID + 文本长度 + 文本哈希（用还原后的文本，避免转义差异被当成新内容） */
    private fun signatureOf(message: UIMessage?): String {
        if (message == null) return ""
        val text = unescapeLegacy(message.toText())
        return "${message.id}|${text.length}|${text.hashCode()}"
    }

    /** 拉取用的 since：回看一分钟，抵消客户端与服务器时间的细小偏差 */
    private fun requestSince(): String {
        if (since.isEmpty()) return ""
        val parsed = runCatching { java.time.LocalDateTime.parse(since, timePattern) }.getOrNull() ?: return since
        return timeFormat.format(parsed.minusSeconds(LOOKBACK_SECONDS))          // 回看窗口
    }

    /** Instant → 服务端时间文本 */
    @Suppress("DEPRECATION") // monthNumber/dayOfMonth 已弃用但取值稳定，保持与原工程一致
    private fun formatMessageTime(message: UIMessage): String {
        val dt = message.createdAt
        fun Int.pad(): String = toString().padStart(2, '0')
        return "${dt.year}-${dt.monthNumber.pad()}-${dt.dayOfMonth.pad()} ${dt.hour.pad()}:${dt.minute.pad()}:${dt.second.pad()}"
    }

    /** 服务端时间文本 → Instant */
    private fun parseInstant(element: JsonElement?): Instant? {
        val text = (element as? JsonPrimitive)?.content.orEmpty()                // 直接取解转义后的值
        if (text.isEmpty()) return null
        return runCatching {
            java.time.LocalDateTime.parse(text, timePattern).atZone(ZoneId.systemDefault()).toInstant()
        }.getOrNull()
    }

    /** 服务端时间文本 → kotlinx LocalDateTime（消息时间） */
    private fun parseLocalDateTime(element: JsonElement?): LocalDateTime {
        val text = (element as? JsonPrimitive)?.content.orEmpty()                // 直接取解转义后的值
        if (text.isEmpty()) return Clock.System.now().toLocalDateTime(TimeZone.currentSystemDefault())
        return runCatching { LocalDateTime.parse(text.replace(' ', 'T')) }
            .getOrElse { Clock.System.now().toLocalDateTime(TimeZone.currentSystemDefault()) }
    }

    /** 读取 JSON 字段的字符串值，缺省为空串（JsonPrimitive.content 已解转义） */
    private fun JsonObject.stringOrEmpty(key: String): String {
        val element = this[key] ?: return ""                                      // 字段不存在
        if (element is JsonNull) return ""                                        // null 视为空
        if (element !is JsonPrimitive) return ""                                  // 非标量视为空
        return element.content                                                    // 未转义的原始字符串
    }

    /**
     * 兼容旧版本造成的转义残留：早期代码把 JSON 转义形式当成正文写进了库，
     * 表现为整段文字里出现字面量「\n」「\\\\」「\"」而没有真实换行。
     * 这里做一次反向还原，正常文本（含真实换行）会原样返回。
     */
    private fun unescapeLegacy(text: String): String {
        if (!text.contains("\\n") && !text.contains("\\\\") && !text.contains("\\\"")) return text // 无转义特征直接返回
        return runCatching {
            json.decodeFromString<JsonElement>("\"$text\"").jsonPrimitive.content  // 反向解码一次
        }.getOrElse { text }                                                       // 失败保持原样
    }

    private companion object {
        const val POLL_INTERVAL_MILLIS = 1800L                                  // 正常拉取间隔
        const val RETRY_INTERVAL_MILLIS = 6000L                                 // 失败后退避
        const val PUSH_INTERVAL_MILLIS = 1600L                                  // 推送节流
        const val SUPPRESS_PUSH_MILLIS = 1500L                                   // 合并云端后的抑制时间
        const val REMOTE_ACTIVE_MILLIS = 4500L                                   // 对端多少毫秒内有更新算「正在生成」
        const val LOOKBACK_SECONDS = 60L                                         // 拉取回看窗口
        const val RECENT_WINDOW_SECONDS = 300L                                   // 只推最近5分钟变化的会话
        const val PUSH_MESSAGE_COUNT = 8                                         // 单次推送携带的最近消息条数
        const val PUSH_TARGET_LIMIT = 2                                          // 单次最多推送两个会话
    }
}
