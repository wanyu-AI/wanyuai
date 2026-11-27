package me.rerere.rikkahub.wanyu

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.os.Build
import com.tencent.tauth.IUiListener
import com.tencent.tauth.Tencent
import com.tencent.tauth.UiError
import org.json.JSONObject

/** QQ 官方 SDK 返回的登录凭据 */
data class WanyuQqToken(
    val openid: String,
    val accessToken: String,
)

/**
 * QQ 官方 SDK 封装。
 * 必须使用腾讯官方 open_sdk_lite.jar，禁止任何网页跳转或伪装授权。
 */
object WanyuQqAuth {
    private var tencent: Tencent? = null
    private var pendingListener: IUiListener? = null

    /** Application 启动时初始化一次 */
    fun init(context: Context) {
        if (tencent != null) return
        runCatching {
            tencent = Tencent.createInstance(
                WanyuConfig.QQ_APP_ID,
                context.applicationContext,
                "${context.packageName}.fileprovider",
            )
            // 3.5.7+ 必须先声明用户已授权获取设备信息，否则接口直接失败
            Tencent.setIsPermissionGranted(true, Build.MODEL)
        }
    }

    /** 发起 QQ 登录，结果通过 RouteActivity.onActivityResult 回传 */
    fun login(activity: Activity, onResult: (Result<WanyuQqToken>) -> Unit) {
        init(activity)
        val api = tencent ?: run {
            onResult(Result.failure(WanyuApiException(-1, "QQ SDK 初始化失败")))
            return
        }
        val listener = object : IUiListener {
            override fun onComplete(response: Any?) {
                pendingListener = null
                val json = response as? JSONObject
                val openid = json?.optString("openid").orEmpty()
                val token = json?.optString("access_token").orEmpty()
                if (openid.isEmpty() || token.isEmpty()) {
                    onResult(Result.failure(WanyuApiException(-1, "QQ 授权信息不完整")))
                } else {
                    onResult(Result.success(WanyuQqToken(openid, token)))
                }
            }

            override fun onWarning(warningCode: Int) {
                // SDK 的非致命警告，不影响登录流程
            }

            override fun onError(error: UiError?) {
                pendingListener = null
                onResult(
                    Result.failure(
                        WanyuApiException(-1, error?.errorMessage ?: "QQ 登录失败")
                    )
                )
            }

            override fun onCancel() {
                pendingListener = null
                onResult(Result.failure(WanyuApiException(-1, "已取消 QQ 登录")))
            }
        }
        pendingListener = listener
        api.login(activity, WanyuConfig.QQ_SCOPE, listener)
    }

    /** RouteActivity 必须转发 onActivityResult，否则 SDK 收不到授权结果 */
    fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        Tencent.onActivityResultData(requestCode, resultCode, data, pendingListener)
    }

    /** 退出登录时同步清理 QQ SDK 会话 */
    fun logout(context: Context) {
        runCatching { tencent?.logout(context) }
    }
}
