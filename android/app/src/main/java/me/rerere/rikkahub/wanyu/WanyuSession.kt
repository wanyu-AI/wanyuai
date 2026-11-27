package me.rerere.rikkahub.wanyu

import android.content.Context
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.Json

/**
 * 登录态存储：令牌与用户资料。
 * 令牌为后端下发的 64 位 hex，本地只存这一份，退出登录立即清空。
 */
class WanyuSession(context: Context) {
    private val prefs = context.getSharedPreferences("wanyu_session", Context.MODE_PRIVATE)
    private val json = Json { ignoreUnknownKeys = true }

    private val _profile = MutableStateFlow(readUser())

    /** 当前登录用户，null 表示未登录 */
    val profile: StateFlow<WanyuUser?> = _profile.asStateFlow()

    /** 接口令牌 */
    val token: String
        get() = prefs.getString(KEY_TOKEN, "").orEmpty()

    /** 是否已登录 */
    fun isLoggedIn(): Boolean = token.isNotEmpty()

    /** 保存登录结果 */
    fun saveLogin(result: WanyuLoginResult) {
        prefs.edit()
            .putString(KEY_TOKEN, result.token)
            .putString(KEY_USER, json.encodeToString(WanyuUser.serializer(), result.user))
            .apply()
        _profile.value = result.user
    }

    /** 更新用户资料（刷新接口返回） */
    fun updateUser(user: WanyuUser) {
        prefs.edit()
            .putString(KEY_USER, json.encodeToString(WanyuUser.serializer(), user))
            .apply()
        _profile.value = user
    }

    /** 只更新积分，避免整页刷新闪烁 */
    fun updatePoints(points: Int) {
        val current = _profile.value ?: return
        updateUser(current.copy(points = points))
    }

    /** 同步增量时间点 */
    var syncSince: String
        get() = prefs.getString(KEY_SYNC_SINCE, "").orEmpty()
        set(value) {
            prefs.edit().putString(KEY_SYNC_SINCE, value).apply()
        }

    /** 已展示过的违规警告次数，避免同一次警告重复弹窗 */
    var lastAckWarning: Int
        get() = prefs.getInt(KEY_ACK_WARNING, 0)
        set(value) {
            prefs.edit().putInt(KEY_ACK_WARNING, value).apply()
        }

    /** 退出登录 */
    fun clear() {
        prefs.edit().remove(KEY_TOKEN).remove(KEY_USER).remove(KEY_SYNC_SINCE).remove(KEY_ACK_WARNING).apply()
        _profile.value = null
    }

    private fun readUser(): WanyuUser? {
        val raw = prefs.getString(KEY_USER, null) ?: return null
        return runCatching {
            json.decodeFromString(WanyuUser.serializer(), raw)
        }.getOrNull()
    }

    private companion object {
        const val KEY_TOKEN = "token"
        const val KEY_USER = "user"
        const val KEY_SYNC_SINCE = "sync_since"
        const val KEY_ACK_WARNING = "ack_warning"
    }
}
