package me.rerere.rikkahub.wanyu

import android.content.Context
import android.os.Build
import me.rerere.rikkahub.BuildConfig
import java.io.IOException
import kotlin.uuid.Uuid

/** 设备指纹：首次生成 UUID 后持久化，卸载重装会变 */
class WanyuDevice(context: Context) {
    private val prefs = context.getSharedPreferences("wanyu_device", Context.MODE_PRIVATE)

    val deviceId: String by lazy {
        prefs.getString("device_id", null) ?: Uuid.random().toString().also {
            prefs.edit().putString("device_id", it).apply()
        }
    }

    /** 设备名称，最长 100 字符，后端会截断 */
    val deviceName: String by lazy {
        "${Build.MANUFACTURER} ${Build.MODEL}".take(100)
    }

    /** 客户端版本，供后端记录 */
    val appVersion: String by lazy {
        "${BuildConfig.VERSION_NAME}(${BuildConfig.VERSION_CODE})"
    }
}

/** 检测到代理/抓包环境时抛出，阻断请求 */
class WanyuProxyException(message: String) : IOException(message)

/**
 * 代理与抓包检测。
 * 命中后直接阻断，不给抓包工具解密流量的机会。
 */
object WanyuProxyDetector {
    // 常见抓包 App 包名
    private val CAPTURE_PACKAGES = listOf(
        "com.guoshi.httpcanary",
        "com.guoshi.httpcanary.premium",
        "org.mitmproxy.android",
        "com.emanuelef.remote_capture",
        "com.minhui.networkcapture",
        "com.minhui.networkcapture.pro",
        "com.paranoiaworks.unicus",
        "com.zhangzheng.httpcanary",
        "com.ryuunoakaihitomi.forceproxy",
    )

    /** 是否开启系统代理 */
    fun hasSystemProxy(context: Context): Boolean {
        // JVM 层代理
        val host = System.getProperty("http.proxyHost")
        if (!host.isNullOrBlank()) return true

        // 系统 ProxySelector
        val selector = java.net.ProxySelector.getDefault()
        if (selector != null) {
            runCatching {
                val proxies = selector.select(java.net.URI(WanyuConfig.BASE_URL))
                if (proxies.any { it.type() != java.net.Proxy.Type.DIRECT }) return true
            }
        }

        // WiFi 代理设置
        return runCatching {
            val proxyHost = android.net.Proxy.getDefaultHost()
            !proxyHost.isNullOrBlank()
        }.getOrDefault(false)
    }

    /** 是否安装了抓包工具 */
    fun hasCaptureApp(context: Context): Boolean {
        val pm = context.packageManager
        return CAPTURE_PACKAGES.any { pkg ->
            runCatching { pm.getPackageInfo(pkg, 0); true }.getOrDefault(false)
        }
    }

    /** 命中任意一项就阻断 */
    fun requireSafe(context: Context) {
        if (hasSystemProxy(context)) {
            throw WanyuProxyException("检测到代理环境，已阻断网络请求")
        }
        if (hasCaptureApp(context)) {
            throw WanyuProxyException("检测到抓包工具，已阻断网络请求")
        }
    }
}
