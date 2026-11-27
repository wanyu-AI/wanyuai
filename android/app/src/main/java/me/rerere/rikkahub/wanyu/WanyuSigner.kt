package me.rerere.rikkahub.wanyu

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.security.MessageDigest
import java.util.TreeMap

/**
 * 与后端 Security::canonical 完全一致的签名实现：
 * 1. 取请求体顶层标量字段（数组/对象跳过，null 转空串，布尔转 true/false）
 * 2. 加入 timestamp 与 device_id
 * 3. 按 key ASCII 升序排序
 * 4. 拼接 k=v&k=v... 再追加 &key=密钥
 * 5. SHA-256 小写十六进制
 */
object WanyuSigner {
    private val json = Json { ignoreUnknownKeys = true }

    fun sign(body: String, timestamp: Long, deviceId: String, secret: String): String {
        val fields = TreeMap<String, String>()
        val element = runCatching { json.parseToJsonElement(body) }.getOrNull()
        if (element is JsonObject) {
            element.forEach { (key, value) ->
                val primitive = value as? JsonPrimitive ?: return@forEach
                fields[key] = when {
                    primitive is JsonNull -> ""
                    primitive.isString -> primitive.content
                    // 数字统一按 PHP (string) 的规则处理：整数值去掉小数部分，
                    // 否则 1.0 在 Kotlin 是 "1.0"、在 PHP 是 "1"，签名会对不上
                    else -> normalizeNumber(primitive.content)
                }
            }
        }
        fields["timestamp"] = timestamp.toString()
        fields["device_id"] = deviceId

        val canonical = buildString {
            fields.forEach { (key, value) ->
                append(key).append('=').append(value).append('&')
            }
            append("key=").append(secret)
        }
        return sha256(canonical)
    }

    /**
     * 数字按 PHP (string) 的规则归一：
     * PHP 把 1.0 转字符串是 "1"，而 Kotlin 的 JsonPrimitive.content 是 "1.0"，
     * 直接把原始文本参与签名会导致两端结果不一致。
     */
    private fun normalizeNumber(text: String): String {
        val number = runCatching { text.toDouble() }.getOrNull() ?: return text
        return if (number % 1.0 == 0.0 && kotlin.math.abs(number) < 1e15) {
            number.toLong().toString()
        } else {
            text
        }
    }

    private fun sha256(text: String): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(text.toByteArray(Charsets.UTF_8))
        val out = StringBuilder(digest.size * 2)
        digest.forEach { byte ->
            val value = byte.toInt() and 0xFF
            out.append(HEX[value ushr 4]).append(HEX[value and 0x0F])
        }
        return out.toString()
    }

    private val HEX = charArrayOf(
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        'a', 'b', 'c', 'd', 'e', 'f',
    )
}
