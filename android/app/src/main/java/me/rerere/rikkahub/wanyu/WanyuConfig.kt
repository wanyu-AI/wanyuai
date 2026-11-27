package me.rerere.rikkahub.wanyu

import me.rerere.ai.provider.Model
import me.rerere.ai.provider.ProviderSetting
import kotlin.uuid.Uuid

/**
 * 挽屿AI 全局配置。
 *
 * 部署时必须把下面这些占位值改成自己的真实值，否则签名和登录都会失败。
 */
object WanyuConfig {
    /** 后端域名，必须 HTTPS */
    const val BASE_URL: String = "https://wan.si888.cn"

    /**
     * 后端接口前缀。
     * 服务器（宝塔 Nginx）未配置 /api/v1 伪静态，因此直接走入口文件的 PATH_INFO，
     * 形如：https://域名/backend/public/index.php/auth/email/send-code
     */
    const val API_BASE: String = "$BASE_URL/backend/public/index.php"

    /** 官网地址（关于页展示） */
    const val SITE_URL: String = "https://wan.si888.cn"

    /** 官方 QQ 群链接（关于页入口，点击后用浏览器打开） */
    const val QQ_GROUP_URL: String = "https://qm.qq.com/q/x8thOpRywg"

    /**
     * 跨设备云端同步总开关。
     * 关闭后不推送、不拉取、不轮询，对话与设置只保存在本机，
     * 换设备或退出登录都不会再下载云端历史；需要恢复时把它改回 true 即可。
     */
    const val CLOUD_SYNC_ENABLED: Boolean = false

    /**
     * 更新信息地址。
     * 沿用原版 RikkaHub 的更新方式：GET 一个静态 JSON，客户端自行比较版本号。
     * 后端 /version/latest 直接返回 UpdateInfo 结构，无需签名。
     */
    const val UPDATE_URL: String = "$API_BASE/version/latest"

    /** 接口签名密钥，必须与后端 system_configs.api_sign_key 完全一致 */
    const val API_SIGN_KEY: String = "change_me_to_your_own_key"

    /** 「不再提示积分扣除」标记的本地存储键，勾选后官方模型直接发送 */
    const val SKIP_POINTS_CONFIRM_KEY: String = "wanyu_skip_points_confirm"

    /** QQ 互联开放平台申请到的 AppID，必须与后端 system_configs.qq_app_id 一致 */
    const val QQ_APP_ID: String = "1906320054"

    /** QQ 登录申请的权限，只要基础资料 */
    const val QQ_SCOPE: String = "get_user_info"

    /**
     * 证书锁定指纹（sha256/BASE64），例如：
     * "sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
     * 留空表示不启用锁定；正式发布前必须填入，防止中间人抓包。
     */
    val CERT_PINS: List<String> = emptyList()

    /** App 展示名称 */
    const val APP_LABEL: String = "挽屿AI"

    /** 官方模型提供商 ID（固定，用于判断是否走后端扣积分） */
    val OFFICIAL_PROVIDER_ID: Uuid = Uuid.parse("0f1c2e3a-4b5d-4e6f-8a9b-0c1d2e3f4a5b")

    /** 官方模型 ID（固定，用于判断是否走后端扣积分） */
    val OFFICIAL_MODEL_ID: Uuid = Uuid.parse("1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d")

    /** 发给后端的模型名，后端会强制覆盖为自己的配置 */
    const val OFFICIAL_MODEL_NAME: String = "deepseek-chat"

    /** 模型选择页展示名称 */
    const val OFFICIAL_MODEL_DISPLAY: String = "官方 DeepSeek"

    /** 提供商展示名称 */
    const val OFFICIAL_PROVIDER_NAME: String = "官方DeepSeek模型"

    /**
     * 官方模型对应的 OpenAI 兼容地址。
     * RikkaHub 会自动拼接 /chat/completions，最终请求
     * https://域名/backend/public/index.php/ai/deepseek/v1/chat/completions
     */
    val OFFICIAL_PROVIDER_BASE_URL: String = "$API_BASE/ai/deepseek/v1"

    /** 是否官方模型（只认固定 ID，避免用户自定义模型误扣积分） */
    fun isOfficialModel(model: Model?): Boolean = model?.id == OFFICIAL_MODEL_ID

    /** 是否官方提供商 */
    fun isOfficialProvider(provider: ProviderSetting?): Boolean = provider?.id == OFFICIAL_PROVIDER_ID

    /** 构造官方模型 */
    fun officialModel(): Model = Model(
        modelId = OFFICIAL_MODEL_NAME,
        displayName = OFFICIAL_MODEL_DISPLAY,
        id = OFFICIAL_MODEL_ID,
        contextLength = 65536,
    )

    /** 构造官方提供商（放在模型列表最前面） */
    fun officialProvider(): ProviderSetting.OpenAI = ProviderSetting.OpenAI(
        id = OFFICIAL_PROVIDER_ID,
        enabled = true,
        name = OFFICIAL_PROVIDER_NAME,
        models = listOf(officialModel()),
        apiKey = "wanyu-official",
        baseUrl = OFFICIAL_PROVIDER_BASE_URL,
        builtIn = true,
    )
}
