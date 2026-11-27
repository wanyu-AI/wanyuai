package me.rerere.rikkahub.wanyu

import kotlinx.serialization.Serializable

/** 登录/资料接口返回的用户信息 */
@Serializable
data class WanyuUser(
    val id: Int = 0,
    val nickname: String = "",
    val avatar: String = "",
    val email: String = "",
    val emailBound: Boolean = false,
    val qqNumber: String = "",
    val qqBound: Boolean = false,
    val accountType: String = "email",
    val points: Int = 0,
    val createdAt: String = "",
    // 风控状态：0正常 1封禁 2冻结
    val riskStatus: Int = 0,
    val riskReason: String = "",
    val riskUntil: String = "",
    val riskUntilTs: Long = 0,
    // 申诉状态：-1未申诉 0待审核 1通过 2驳回
    val appealStatus: Int = -1,
    val appealReply: String = "",
    // 违规警告：超过 warningLimit 次后封禁
    val warningCount: Int = 0,
    val warningLimit: Int = 5,
    val warningReason: String = "",
) {
    /** 是否被封禁 */
    val isBanned: Boolean get() = riskStatus == 1

    /** 是否被冻结（异地登录） */
    val isFrozen: Boolean get() = riskStatus == 2

    /** 是否处于封禁/冻结状态，此时禁止发送任何消息 */
    val isRestricted: Boolean get() = riskStatus == 1 || riskStatus == 2
}

/** 登录成功结果 */
data class WanyuLoginResult(
    val token: String,
    val user: WanyuUser,
    val isNewUser: Boolean,
)

/** 业务异常：code 对应后端返回的业务码 */
class WanyuApiException(
    val code: Int,
    override val message: String,
) : Exception(message) {
    /** 新设备需要邮箱二次核验 */
    val needDeviceVerify: Boolean get() = code == 40303

    /** 积分不足 */
    val pointsNotEnough: Boolean get() = code == 40321 || code == 40322

    /** 账号封禁/冻结/违规，需要刷新风控状态 */
    val riskRestricted: Boolean get() = code == 40331 || code == 40332 || code == 40333 || code == 40334 || code == 40335
}

/** 积分充值/消耗流水 */
@Serializable
data class WanyuPointLog(
    val id: Int = 0,
    val type: String = "",
    val amount: Int = 0,
    val balance: Int = 0,
    val note: String = "",
    val createdAt: String = "",
)
