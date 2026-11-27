package me.rerere.rikkahub.wanyu

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.longOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException

/**
 * 挽屿AI 后端接口封装。
 * 所有请求都是 POST JSON，签名与设备头由 WanyuSigningInterceptor 统一追加。
 */
class WanyuApi(
    private val client: OkHttpClient,
    private val json: Json,
) {
    private val jsonMedia = "application/json; charset=utf-8".toMediaType()

    // ---------------- 登录相关 ---------------------------- //

    /** 发送邮箱验证码，purpose: login / register / bind / device_verify */
    suspend fun sendCode(email: String, purpose: String = "login") {
        post("/auth/email/send-code", buildJsonObject {
            put("email", email)
            put("purpose", purpose)
        })
    }

    /** 邮箱验证码登录/注册 */
    suspend fun verifyCode(email: String, code: String): WanyuLoginResult {
        val data = post("/auth/email/verify", buildJsonObject {
            put("email", email)
            put("code", code)
        })
        return parseLogin(data)
    }

    /** QQ 官方 SDK 授权结果换取登录态 */
    suspend fun qqLogin(openid: String, accessToken: String): WanyuLoginResult {
        val data = post("/auth/qq", buildJsonObject {
            put("openid", openid)
            put("access_token", accessToken)
        })
        return parseLogin(data)
    }

    /** 新设备二次邮箱核验 */
    suspend fun deviceVerify(code: String): WanyuUser {
        val data = post("/auth/device/verify", buildJsonObject { put("code", code) })
        return parseUser(data["user"]?.jsonObject ?: JsonObject(emptyMap()))
    }

    /** 补充绑定邮箱 */
    suspend fun bindEmail(email: String, code: String): WanyuUser {
        val data = post("/auth/bind-email", buildJsonObject {
            put("email", email)
            put("code", code)
        })
        return parseUser(data["user"]?.jsonObject ?: JsonObject(emptyMap()))
    }

    /** 已登录账号绑定 QQ */
    suspend fun bindQq(openid: String, accessToken: String): WanyuUser {
        val data = post("/auth/bind-qq", buildJsonObject {
            put("openid", openid)
            put("access_token", accessToken)
        })
        return parseUser(data["user"]?.jsonObject ?: JsonObject(emptyMap()))
    }

    suspend fun logout() {
        post("/auth/logout", buildJsonObject {})
    }

    // ---------------- 用户信息 ---------------- //

    suspend fun profile(): JsonObject = post("/user/profile", buildJsonObject {})

    suspend fun updateProfile(nickname: String, avatar: String, clearAvatar: Boolean = false): WanyuUser {
        val data = post("/user/profile/update", buildJsonObject {
            if (nickname.isNotEmpty()) put("nickname", nickname)
            if (avatar.isNotEmpty()) put("avatar", avatar)
            if (clearAvatar) put("clear_avatar", true)
        })
        return parseUser(data["user"]?.jsonObject ?: JsonObject(emptyMap()))
    }

    /** 上传头像图片（base64 data URL），后台保存文件并直接写入资料 */
    suspend fun uploadAvatar(dataUrl: String): JsonObject =
        post("/user/avatar/upload", buildJsonObject { put("image", dataUrl) })

    // ---------------- 风控 ---------------- //

    /** 查询账号风控状态（封禁/冻结） */
    suspend fun riskStatus(): JsonObject = post("/user/risk/status", buildJsonObject {})

    /** 冻结账号通过邮箱验证码自助解冻 */
    suspend fun unfreeze(code: String): JsonObject =
        post("/user/risk/unfreeze", buildJsonObject { put("code", code) })

    /** 封禁账号提交申诉 */
    suspend fun submitAppeal(content: String): JsonObject =
        post("/user/risk/appeal", buildJsonObject { put("content", content) })

    // ---------------- 设置同步 ---------------- //

    /** 上传本地设置（含模型配置）到云端 */
    suspend fun saveSettings(settings: String): JsonObject =
        post("/user/settings/save", buildJsonObject { put("settings", settings) })

    /** 拉取云端设置，settings 为空表示从未备份过 */
    suspend fun loadSettings(): JsonObject = post("/user/settings/load", buildJsonObject {})

    // ---------------- 同步 ---------------- //

    suspend fun syncPush(conversations: JsonArray): JsonObject =
        post("/sync/push", buildJsonObject { put("conversations", conversations) })

    suspend fun syncPull(since: String): JsonObject =
        post("/sync/pull", buildJsonObject { if (since.isNotEmpty()) put("since", since) })

    /** 实时推送单个会话的消息增量与「正在生成」状态 */
    suspend fun livePush(conversation: JsonObject, messages: JsonArray, generating: Boolean): JsonObject =
        post("/sync/live/push", buildJsonObject {
            put("conversation", conversation)
            put("messages", messages)
            put("generating", generating)
        })

    /** 实时拉取 since 之后的增量消息与对端正在生成的会话 */
    suspend fun livePull(since: String): JsonObject =
        post("/sync/live/pull", buildJsonObject { if (since.isNotEmpty()) put("since", since) })

    /** 上传官方会话中的用户图片（base64 data URL），后台保存后供人工审核 */
    suspend fun uploadChatImage(
        conversationClientId: String,
        messageClientId: String,
        index: Int,
        image: String,
        createdAt: String = "",
    ): JsonObject = post("/sync/image/upload", buildJsonObject {
        put("conversation_client_id", conversationClientId)
        put("message_client_id", messageClientId)
        put("index", index)
        put("image", image)
        if (createdAt.isNotEmpty()) put("created_at", createdAt)
    })

    // ---------------- AI / 积分 / 版本 ---------------- //

    /** 调用前的积分提示 */
    suspend fun aiQuote(): JsonObject = post("/ai/quote", buildJsonObject {})

    /** 积分流水 */
    suspend fun pointLogs(page: Int = 1): JsonObject =
        post("/points/logs", buildJsonObject { put("page", page) })

    /** 版本检查 */
    suspend fun versionCheck(versionCode: Int): JsonObject =
        post("/version/check", buildJsonObject { put("version_code", versionCode) })

    /** 上报抓包/代理事件 */
    suspend fun reportProxy(reason: String) {
        runCatching {
            post("/security/report-proxy", buildJsonObject { put("reason", reason) })
        }
    }

    /** 公共配置 */
    suspend fun publicConfig(): JsonObject = post("/config/public", buildJsonObject {})

    // ---------------- 支付 ---------------- //

    /**
     * 创建支付订单（积分 or 会员）。
     * 金额由服务端按商品定价生成，客户端传入的金额一律忽略。
     * 返回 pay_token（APP唤起川聊收银台用）和 pay_url（APP不可用时H5降级）。
     */
    suspend fun createPayOrder(
        productType: String,
        productSku: String,
        payType: String = "APP",
        returnUrl: String = "",
        failUrl: String = "",
    ): JsonObject = post("/pay/create", buildJsonObject {
        put("product_type", productType)
        put("product_sku", productSku)
        put("pay_type", payType)
        if (returnUrl.isNotEmpty()) put("return_url", returnUrl)
        if (failUrl.isNotEmpty())   put("fail_url", failUrl)
    })

    /**
     * 查询订单状态。App 每 2 秒轮询一次，onResume 补查一次。
     * 只有服务端返回 paid=true 才发货。
     */
    suspend fun queryPayOrder(outOrderNo: String): JsonObject =
        post("/pay/query", buildJsonObject { put("out_order_no", outOrderNo) })

    /** 查询当前用户会员状态（客户端「我的会员」展示用） */
    suspend fun vipStatus(): JsonObject = post("/pay/vip/status", buildJsonObject {})

    // ---------------- 内部实现 ---------------- //

    private suspend fun post(path: String, payload: JsonObject): JsonObject = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url(WanyuConfig.API_BASE + path)
            .post(payload.toString().toRequestBody(jsonMedia))
            .build()

        client.newCall(request).execute().use { response ->
            val text = response.body.string()
            val root = runCatching { json.parseToJsonElement(text) as? JsonObject }.getOrNull()
                ?: throw WanyuApiException(-1, "服务端返回格式异常（HTTP ${response.code}）")
            json.parseData(root)
        }
    }

    private fun parseLogin(data: JsonObject): WanyuLoginResult {
        val token = data["token"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
        if (token.isEmpty()) throw WanyuApiException(-1, "登录失败：缺少令牌")
        val user = parseUser(data["user"]?.jsonObject ?: JsonObject(emptyMap()))
        val isNew = runCatching { data["is_new_user"]?.jsonPrimitive?.toString() == "true" }.getOrDefault(false)
        return WanyuLoginResult(token = token, user = user, isNewUser = isNew)
    }

    private fun parseUser(obj: JsonObject): WanyuUser {
        fun text(key: String) = obj[key]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
        fun bool(key: String) = obj[key]?.jsonPrimitive?.toString()?.trim('"') == "true"
        val points = runCatching { obj["points"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0)
        return WanyuUser(
            id = runCatching { obj["id"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0),
            nickname = text("nickname"),
            avatar = text("avatar"),
            email = text("email"),
            emailBound = bool("email_bound"),
            qqNumber = text("qq_number"),
            qqBound = bool("qq_bound"),
            accountType = text("account_type"),
            points = points,
            createdAt = text("created_at"),
            riskStatus = runCatching { obj["risk_status"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0),
            riskReason = text("risk_reason"),
            riskUntil = text("risk_until"),
            riskUntilTs = runCatching { obj["risk_until_ts"]?.jsonPrimitive?.longOrNull ?: 0L }.getOrDefault(0L),
            appealStatus = runCatching { obj["appeal_status"]?.jsonPrimitive?.intOrNull ?: -1 }.getOrDefault(-1),
            appealReply = text("appeal_reply"),
            warningCount = runCatching { obj["warning_count"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0),
            warningLimit = runCatching { obj["warning_limit"]?.jsonPrimitive?.intOrNull ?: 5 }.getOrDefault(5),
            warningReason = text("warning_reason"),
        )
    }
}

/** 统一解析用户资料的辅助，供仓库层复用 */
internal fun parseUserObject(obj: JsonObject): WanyuUser {
    fun text(key: String) = obj[key]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
    fun bool(key: String) = obj[key]?.jsonPrimitive?.toString()?.trim('"') == "true"
    return WanyuUser(
        id = runCatching { obj["id"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0),
        nickname = text("nickname"),
        avatar = text("avatar"),
        email = text("email"),
        emailBound = bool("email_bound"),
        qqNumber = text("qq_number"),
        qqBound = bool("qq_bound"),
        accountType = text("account_type"),
        points = runCatching { obj["points"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0),
        createdAt = text("created_at"),
        riskStatus = runCatching { obj["risk_status"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0),
        riskReason = text("risk_reason"),
        riskUntil = text("risk_until"),
        riskUntilTs = runCatching { obj["risk_until_ts"]?.jsonPrimitive?.longOrNull ?: 0L }.getOrDefault(0L),
        appealStatus = runCatching { obj["appeal_status"]?.jsonPrimitive?.intOrNull ?: -1 }.getOrDefault(-1),
        appealReply = text("appeal_reply"),
        warningCount = runCatching { obj["warning_count"]?.jsonPrimitive?.intOrNull ?: 0 }.getOrDefault(0),
        warningLimit = runCatching { obj["warning_limit"]?.jsonPrimitive?.intOrNull ?: 5 }.getOrDefault(5),
        warningReason = text("warning_reason"),
    )
}

/** 网络异常友好提示 */
internal fun Throwable.toWanyuMessage(): String = when (this) {
    is WanyuApiException -> message
    is WanyuProxyException -> message ?: "操作失败"
    is IOException -> "网络异常，请检查网络后重试"
    else -> message ?: "操作失败"
}
