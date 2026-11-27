package me.rerere.rikkahub.wanyu

import android.content.Context
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okio.Buffer
import java.net.URI

/**
 * 后端请求签名拦截器。
 * 只对挽屿AI后端域名生效，自定义模型（三方直连）不会被改动。
 * 同时完成代理检测、设备指纹、时间戳、签名、令牌注入。
 */
class WanyuSigningInterceptor(
    private val context: Context,
    private val device: WanyuDevice,
    private val tokenProvider: () -> String,
) : Interceptor {
    private val backendHost: String = runCatching {
        URI(WanyuConfig.BASE_URL).host
    }.getOrNull().orEmpty()

    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        // 非后端域名直接放行，自定义模型保持原版直连
        if (backendHost.isEmpty() || request.url.host != backendHost) {
            return chain.proceed(request)
        }

        // 代理/抓包环境直接阻断
        WanyuProxyDetector.requireSafe(context)

        val timestamp = System.currentTimeMillis() / 1000
        val token = tokenProvider()

        val builder = request.newBuilder()
            .header("X-Device-Id", device.deviceId)
            .header("X-Device-Name", device.deviceName)
            .header("X-App-Version", device.appVersion)
            .header("X-Timestamp", timestamp.toString())

        if (token.isNotEmpty()) {
            // 官方模型请求由 RikkaHub 自带 apiKey 占位，这里统一覆盖成用户令牌
            builder.header("Authorization", "Bearer $token")
        }

        val body = request.body
        if (body != null) {
            val buffer = Buffer()
            body.writeTo(buffer)
            val text = buffer.readUtf8()
            builder.header(
                "X-Sign",
                WanyuSigner.sign(text, timestamp, device.deviceId, WanyuConfig.API_SIGN_KEY),
            )
            builder.method(request.method, text.toRequestBody(body.contentType()))
        }

        return chain.proceed(builder.build())
    }
}

/** 解析后端统一响应：{code, message, data} */
internal fun Json.parseData(root: JsonObject): JsonObject {
    val code = root["code"]?.let { runCatching { it.toString().toInt() }.getOrNull() } ?: -1
    if (code != 0) {
        throw WanyuApiException(code, root["message"]?.toString()?.trim('"') ?: "请求失败")
    }
    return root["data"] as? JsonObject ?: JsonObject(emptyMap())
}
