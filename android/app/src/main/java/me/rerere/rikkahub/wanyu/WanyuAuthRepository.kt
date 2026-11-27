package me.rerere.rikkahub.wanyu

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonPrimitive
import me.rerere.rikkahub.AppScope
import me.rerere.rikkahub.data.datastore.SettingsStore
import kotlinx.serialization.json.jsonObject

/**
 * 登录仓库：统一处理邮箱/QQ 登录、资料刷新、设备核验、绑定邮箱、退出登录。
 */
class WanyuAuthRepository(
    private val api: WanyuApi,
    private val session: WanyuSession,
    private val syncManager: WanyuSyncManager,
    private val liveSyncManager: WanyuLiveSyncManager,
    private val chatAudit: WanyuChatAudit,
    private val settingsStore: SettingsStore,
    private val appScope: AppScope,
) {
    /** 设置变更监听任务，退出登录时取消 */
    private var settingsWatchJob: Job? = null
    /** 会话变更监听任务，退出登录时取消 */
    private var conversationWatchJob: Job? = null
    /** 当前用户资料，直接暴露给界面 */
    val profile = session.profile

    private val _needDeviceVerify = MutableStateFlow(false)

    /** 令牌有效但设备未信任时置为 true，界面弹二次邮箱核验 */
    val needDeviceVerify = _needDeviceVerify.asStateFlow()

    /** 发送验证码 */
    suspend fun sendCode(email: String, purpose: String = "login") = api.sendCode(email, purpose)

    /** 邮箱验证码登录/注册 */
    suspend fun loginWithEmail(email: String, code: String): WanyuLoginResult {
        val result = api.verifyCode(email, code)
        session.saveLogin(result)
        _needDeviceVerify.value = false
        startSync()
        return result
    }

    /** QQ 官方 SDK 授权后登录 */
    suspend fun loginWithQq(openid: String, accessToken: String): WanyuLoginResult {
        val result = api.qqLogin(openid, accessToken)
        session.saveLogin(result)
        _needDeviceVerify.value = false
        startSync()
        return result
    }

    /** 拉取最新资料与积分 */
    suspend fun refreshProfile() {
        try {
            val data = api.profile()
            val user = parseUserObject(data["user"]?.jsonObject ?: return)
            session.updateUser(user)
            _needDeviceVerify.value = false
        } catch (e: WanyuApiException) {
            if (e.needDeviceVerify) {
                _needDeviceVerify.value = true
            } else {
                throw e
            }
        }
    }

    /** 官方模型调用前的计费提示，同时刷新本地积分 */
    suspend fun quote(): JsonObject {
        val data = api.aiQuote()                          // 请求后端
        data["points"]?.jsonPrimitive?.intOrNull?.let { session.updatePoints(it) } // 同步余额
        return data                                       // 返回原始数据
    }

    /** 新设备邮箱核验 */
    suspend fun verifyDevice(code: String) {
        val user = api.deviceVerify(code)
        session.updateUser(user)
        _needDeviceVerify.value = false
    }

    /** 补充绑定邮箱 */
    suspend fun bindEmail(email: String, code: String) {
        val user = api.bindEmail(email, code)
        session.updateUser(user)
    }

    /** 绑定 QQ：官方 SDK 授权结果交给后端校验并写入账号 */
    suspend fun bindQq(openid: String, accessToken: String) {
        val user = api.bindQq(openid, accessToken)
        session.updateUser(user)
    }

    /** 修改昵称/头像，并以后台返回的数据为准更新本地资料 */
    suspend fun updateProfile(nickname: String, avatar: String, clearAvatar: Boolean = false) {
        val user = api.updateProfile(nickname, avatar, clearAvatar)
        session.updateUser(user)
    }

    /** 上传头像图片并同步资料，返回可直接展示的 HTTPS 地址 */
    suspend fun uploadAvatar(dataUrl: String): String {
        val data = api.uploadAvatar(dataUrl)
        data["user"]?.jsonObject?.let { session.updateUser(parseUserObject(it)) }
        return data["url"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
    }

    /** 刷新风控状态（封禁/冻结/申诉进度），并同步最新资料 */
    suspend fun refreshRisk(): WanyuUser? {        val data = api.riskStatus()                      // 查询风控状态
        val user = data["user"]?.jsonObject?.let { parseUserObject(it) } // 解析资料
        user?.let { session.updateUser(it) }             // 写回本地
        return user                                      // 返回最新资料
    }

    /** 已展示过的警告次数（本地记录，避免同一次警告重复弹窗） */
    fun lastAckWarning(): Int = session.lastAckWarning

    /** 记录已展示的警告次数 */
    fun ackWarning(count: Int) {
        session.lastAckWarning = count
    }

    /** 冻结账号：向账号绑定邮箱发送解冻验证码 */
    suspend fun sendUnfreezeCode() {
        api.sendCode("", "unfreeze")                     // 后端使用绑定邮箱
    }

    /** 冻结账号：邮箱验证码解冻 */
    suspend fun unfreezeWithCode(code: String): WanyuUser {
        val data = api.unfreeze(code)                    // 提交验证码
        val user = data["user"]?.jsonObject?.let { parseUserObject(it) }
            ?: throw WanyuApiException(-1, "解冻失败：缺少用户信息") // 异常兜底
        session.updateUser(user)                         // 更新本地资料
        return user                                      // 返回
    }

    /** 封禁账号：提交申诉 */
    suspend fun submitAppeal(content: String): WanyuUser? {
        api.submitAppeal(content)                        // 提交申诉
        return refreshRisk()                             // 拉取最新申诉状态
    }

    /** 退出登录并清空本地令牌 */
    suspend fun logout() {
        chatAudit.stop()                                 // 停止官方会话审计上传
        liveSyncManager.stop()                           // 停止实时同步轮询
        settingsWatchJob?.cancel()                       // 停止设置变更监听
        settingsWatchJob = null                          // 清空引用
        conversationWatchJob?.cancel()                   // 停止会话变更监听
        conversationWatchJob = null                      // 清空引用
        runCatching { api.logout() }
        session.clear()
        _needDeviceVerify.value = false
    }

    /** 登录成功或启动时已登录：按开关决定是否启动云端同步；官方会话审计上传始终开启 */
    fun startSync() {
        if (!session.isLoggedIn()) return                     // 未登录不启动
        chatAudit.start()                                     // 官方会话消息与图片上传审计（后台人工审核用）
        appScope.launch(Dispatchers.IO) {                     // 拉一次最新资料，及时拿到后台的人工警告
            runCatching { refreshProfile() }
        }
        if (!WanyuConfig.CLOUD_SYNC_ENABLED) return           // 同步总开关已关闭：不推不拉，消息只留本机
        appScope.launch(Dispatchers.IO) {
            runCatching { syncManager.syncNow() }             // 首次整包同步
        }
        liveSyncManager.start()                               // 开启实时同步（推送 + 轮询）
        startSettingsWatch()                                  // 持续监听设置变化并上传
        startConversationWatch()                              // 低频全量兜底
    }

    /** 监听本地会话变化：60 秒兜底一次全量上传，实时部分由 WanyuLiveSyncManager 负责 */
    private fun startConversationWatch() {
        conversationWatchJob?.cancel()                   // 避免重复监听
        conversationWatchJob = appScope.launch(Dispatchers.IO) {
            runCatching {
                syncManager.observeConversations()
                    .drop(1)                             // 跳过当前值，只处理后续变更
                    .collectLatest {                     // 新变更到来时取消上一次等待
                        delay(60_000L)                   // 60 秒兜底，不再与实时推送争抢
                        runCatching { syncManager.pushLocal() } // 上传失败不影响监听
                    }
            }
        }
    }

    /** 监听本地设置变化，2 秒防抖后上传云端，实现模型配置跨设备同步 */
    private fun startSettingsWatch() {
        settingsWatchJob?.cancel()                       // 避免重复监听
        settingsWatchJob = appScope.launch(Dispatchers.IO) {
            runCatching {
                settingsStore.awaitLoaded()              // 等启动读盘完成
                settingsStore.settingsFlow
                    .drop(1)                             // 跳过当前值，只处理后续变更
                    .collectLatest {                     // 新变更到来时取消上一次等待
                        delay(2000L)                     // 2 秒防抖
                        runCatching { syncManager.uploadSettings() } // 上传失败不影响监听
                    }
            }
        }
    }
}
