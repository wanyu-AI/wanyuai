package me.rerere.rikkahub.wanyu.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.State
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonPrimitive
import me.rerere.rikkahub.ui.hooks.writeBooleanPreference
import me.rerere.rikkahub.wanyu.WanyuAuthRepository
import me.rerere.rikkahub.wanyu.WanyuConfig
import me.rerere.rikkahub.wanyu.WanyuQqAuth
import me.rerere.rikkahub.wanyu.toWanyuMessage
import org.koin.compose.koinInject

/**
 * 剩余积分徽标。
 * 未登录时不显示，登录后随资料刷新实时更新。
 */
@Composable
fun WanyuPointsChip(
    modifier: Modifier = Modifier,                    // 外部修饰符
    onClick: () -> Unit = {},                         // 点击回调（打开账号面板）
) {
    val auth: WanyuAuthRepository = koinInject()      // 注入登录仓库
    val profile by auth.profile.collectAsComposable() // 订阅用户资料
    val user = profile ?: return                      // 未登录不显示

    AssistChip(
        onClick = onClick,                            // 点击事件
        label = {
            Text(
                text = "积分 ${user.points}",         // 余额文案
                maxLines = 1,                     // 单行
                overflow = TextOverflow.Ellipsis, // 溢出省略
            )
        },
        leadingIcon = {
            Text(
                text = "分",                          // 积分标识
                style = MaterialTheme.typography.labelSmall, // 小字
                color = MaterialTheme.colorScheme.primary, // 主题色
            )
        },
        modifier = modifier,                          // 应用修饰符
    )
}

/** 让 StateFlow 在 Compose 中按生命周期收集的小工具，避免多处重复导入 */
@Composable
private fun <T> StateFlow<T>.collectAsComposable(): State<T> =
    collectAsStateWithLifecycle()

/**
 * 调用官方模型前的积分确认框。
 * 打开时向后端查询换算比例与余额，用户确认后才真正发送。
 */
@Composable
fun WanyuOfficialSendDialog(
    visible: Boolean,                                 // 是否展示
    onConfirm: () -> Unit,                            // 确认发送
    onDismiss: () -> Unit,                            // 取消
) {
    if (!visible) return                            // 未展示直接返回
    val auth: WanyuAuthRepository = koinInject()      // 登录仓库
    val context = LocalContext.current               // 上下文，用于持久化「不再提示」
    var skipNext by remember(visible) { mutableStateOf(false) } // 是否勾选「下次不再提示」
    var tip by remember { mutableStateOf("官方模型按实际 token 扣费") } // 提示文案
    var points by remember { mutableStateOf<Int?>(null) } // 当前余额
    var loading by remember { mutableStateOf(true) } // 查询中
    var error by remember { mutableStateOf<String?>(null) } // 错误

    // 每次打开都刷新一次提示信息
    LaunchedEffect(visible) {
        loading = true                                // 标记加载
        error = null                                  // 清空错误
        runCatching { auth.quote() }                  // 调后端 /ai/quote
            .onSuccess { data ->
                val perPoint = data["tokens_per_point"]?.jsonPrimitive?.intOrNull ?: 1000 // 比例
                val balance = data["points"]?.jsonPrimitive?.intOrNull ?: 0 // 余额
                val model = data["model"]?.jsonPrimitive?.toString()?.trim('"').orEmpty() // 模型
                tip = "模型：${model.ifEmpty { "deepseek-chat" }}\n" + // 模型行
                    "约 ${perPoint} token = 1 积分，按实际用量结算" // 比例说明
                points = balance                      // 记录余额
            }
            .onFailure { error = it.toWanyuMessage() } // 失败提示
        loading = false                               // 结束加载
    }

    AlertDialog(
        onDismissRequest = onDismiss,                 // 点击外部关闭
        title = { Text("使用官方 DeepSeek 模型") },    // 标题
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(tip, style = MaterialTheme.typography.bodyMedium) // 计费说明
                if (loading) {                        // 查询中
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp) // 小转圈
                        Spacer(modifier = Modifier.size(8.dp)) // 间隔
                        Text("正在查询余额", style = MaterialTheme.typography.bodySmall) // 文案
                    }
                } else if (error != null) {          // 查询失败
                    Text(
                        text = error.orEmpty(),       // 错误文案
                        color = MaterialTheme.colorScheme.error, // 错误色
                        style = MaterialTheme.typography.bodySmall, // 小字
                    )
                } else {                             // 查询成功
                    Text(
                        text = "当前剩余积分：${points ?: 0}", // 余额
                        style = MaterialTheme.typography.bodyMedium, // 正文
                    )
                    if ((points ?: 0) < 1) {          // 余额不足
                        Text(
                            text = "积分不足，请充值后再使用官方模型", // 提示
                            color = MaterialTheme.colorScheme.error, // 错误色
                            style = MaterialTheme.typography.bodySmall, // 小字
                        )
                    }
                }
                // 勾选后记住选择，之后官方模型发送不再弹窗确认
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(checked = skipNext, onCheckedChange = { skipNext = it }) // 勾选框
                    Text(
                        text = "下次不再提示，直接发送",           // 选项文案
                        style = MaterialTheme.typography.bodySmall, // 小字
                    )
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    if (skipNext) {                       // 勾选了不再提示
                        context.writeBooleanPreference(WanyuConfig.SKIP_POINTS_CONFIRM_KEY, true) // 持久化
                    }
                    onConfirm()                           // 确认发送
                },
                enabled = !loading && (points ?: 0) >= 1, // 查询完成且有积分
            ) { Text("继续发送") }                    // 按钮文案
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("取消") } // 取消
        },
    )
}

/**
 * 账号面板：展示昵称、邮箱、积分，支持补充绑定邮箱与退出登录。
 */
@Composable
fun WanyuAccountDialog(
    onDismiss: () -> Unit,                            // 关闭面板
    onLogout: () -> Unit,                             // 退出登录
) {
    val auth: WanyuAuthRepository = koinInject()      // 登录仓库
    val context = LocalContext.current               // 上下文
    val scope = rememberCoroutineScope()             // 协程作用域
    val profile by auth.profile.collectAsComposable() // 用户资料
    val user = profile                               // 快捷引用

    var email by rememberSaveable { mutableStateOf("") } // 绑定邮箱输入
    var code by rememberSaveable { mutableStateOf("") }   // 验证码输入
    var sending by remember { mutableStateOf(false) }     // 发码中
    var binding by remember { mutableStateOf(false) }     // 绑定中
    var bindingQq by remember { mutableStateOf(false) }   // QQ 绑定中
    var message by remember { mutableStateOf<String?>(null) } // 结果提示

    AlertDialog(
        onDismissRequest = onDismiss,                 // 关闭
        title = { Text("我的账号") },                  // 标题
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("昵称：${user?.nickname.orEmpty().ifEmpty { "未设置" }}", style = MaterialTheme.typography.bodyMedium) // 昵称
                Text("账号类型：${if (user?.accountType == "qq") "QQ" else "邮箱"}", style = MaterialTheme.typography.bodyMedium) // 类型
                if (user?.emailBound == true) {       // 已绑邮箱
                    Text("邮箱：${user?.email.orEmpty()}", style = MaterialTheme.typography.bodyMedium) // 脱敏邮箱
                }
                Text("积分：${user?.points ?: 0}", style = MaterialTheme.typography.bodyMedium) // 积分
                Text("QQ：${if (user?.qqBound == true) "已绑定" else "未绑定"}", style = MaterialTheme.typography.bodyMedium) // QQ 绑定状态

                // 邮箱账号未绑定 QQ 时提供绑定入口
                if (user != null && !user.qqBound && user.accountType != "qq") {
                    TextButton(
                        onClick = {
                            val activity = context.findActivity()          // QQ SDK 需要 Activity
                            if (activity == null) { message = "当前页面无法发起 QQ 绑定"; return@TextButton } // 兜底
                            bindingQq = true                               // 标记绑定中
                            message = null                                 // 清空提示
                            WanyuQqAuth.login(activity) { result ->        // 发起官方授权
                                result.onSuccess { token ->                // 授权成功
                                    scope.launch {
                                        runCatching { auth.bindQq(token.openid, token.accessToken) } // 后端校验并绑定
                                            .onSuccess { message = "QQ 绑定成功" } // 成功提示
                                            .onFailure { message = it.toWanyuMessage() } // 失败原因
                                        bindingQq = false                  // 结束
                                    }
                                }.onFailure {                              // 授权失败
                                    message = it.toWanyuMessage()          // 展示原因
                                    bindingQq = false                      // 结束
                                }
                            }
                        },
                        enabled = !bindingQq,                              // 防重复点击
                    ) { Text(if (bindingQq) "绑定中" else "绑定QQ") } // 按钮文案
                }

                // QQ 用户未绑邮箱时提供补充绑定入口
                if (user != null && !user.emailBound) {
                    OutlinedTextField(
                        value = email,             // 邮箱输入
                        onValueChange = { email = it }, // 更新
                        label = { Text("绑定邮箱") }, // 标签
                        singleLine = true,         // 单行
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), // 邮箱键盘
                        modifier = Modifier.fillMaxWidth(), // 占满宽度
                    )
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        OutlinedTextField(
                            value = code,          // 验证码
                            onValueChange = { code = it }, // 更新
                            label = { Text("验证码") }, // 标签
                            singleLine = true,     // 单行
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), // 数字键盘
                            modifier = Modifier.weight(1f), // 占剩余宽度
                        )
                        TextButton(
                            onClick = {
                                val target = email.trim() // 邮箱
                                if (target.isEmpty()) { message = "请输入邮箱"; return@TextButton } // 校验
                                scope.launch {
                                    sending = true // 标记发送
                                    message = null // 清空提示
                                    runCatching { auth.sendCode(target, "bind") } // 发送绑定验证码
                                        .onSuccess { message = "验证码已发送" } // 成功
                                        .onFailure { message = it.toWanyuMessage() } // 失败
                                    sending = false // 结束
                                }
                            },
                            enabled = !sending,    // 防重复点击
                        ) { Text(if (sending) "发送中" else "获取验证码") } // 按钮文案
                    }
                    TextButton(
                        onClick = {
                            val target = email.trim() // 邮箱
                            val input = code.trim()   // 验证码
                            if (target.isEmpty() || input.isEmpty()) { message = "请填写邮箱和验证码"; return@TextButton } // 校验
                            scope.launch {
                                binding = true // 标记绑定
                                message = null // 清空提示
                                runCatching { auth.bindEmail(target, input) } // 绑定邮箱
                                    .onSuccess { message = "绑定成功" } // 成功
                                    .onFailure { message = it.toWanyuMessage() } // 失败
                                binding = false // 结束
                            }
                        },
                        enabled = !binding,        // 防重复点击
                    ) { Text(if (binding) "绑定中" else "确认绑定") } // 按钮文案
                }

                if (message != null) {                 // 结果提示
                    Text(
                        text = message.orEmpty(),      // 文案
                        style = MaterialTheme.typography.bodySmall, // 小字
                        color = MaterialTheme.colorScheme.primary, // 主题色
                    )
                }

                TextButton(
                    onClick = {
                        scope.launch {
                            auth.logout()          // 清空令牌
                            WanyuQqAuth.logout(context) // 清理 QQ 会话
                            onDismiss()            // 关闭面板
                            onLogout()             // 回到登录页
                        }
                    },
                ) {
                    Text("退出登录", color = MaterialTheme.colorScheme.error) // 危险操作
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) { Text("关闭") } // 关闭按钮
        },
    )
}

/**
 * 新设备二次邮箱核验弹窗。
 * 后端返回 40303 时由 RouteActivity 触发。
 */
@Composable
fun WanyuDeviceVerifyDialog(
    onVerified: () -> Unit,                           // 核验成功
    onDismiss: () -> Unit,                            // 取消
) {
    val auth: WanyuAuthRepository = koinInject()      // 登录仓库
    val profile by auth.profile.collectAsComposable() // 用户资料
    val scope = rememberCoroutineScope()             // 协程作用域
    var sending by remember { mutableStateOf(false) } // 发码中
    var verifying by remember { mutableStateOf(false) } // 核验中
    var code by rememberSaveable { mutableStateOf("") } // 验证码
    var message by remember { mutableStateOf<String?>(null) } // 提示

    AlertDialog(
        onDismissRequest = onDismiss,                 // 关闭
        title = { Text("新设备安全核验") },             // 标题
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    text = if (profile?.emailBound == true) {
                        "检测到陌生设备登录，请通过账号邮箱完成核验" // 已绑邮箱
                    } else {
                        "请先在“我的账号”中绑定邮箱，再完成设备核验" // 未绑邮箱
                    },
                    style = MaterialTheme.typography.bodySmall, // 小字
                )
                OutlinedTextField(
                    value = code,                    // 验证码
                    onValueChange = { code = it },   // 更新
                    label = { Text("邮箱验证码") },  // 标签
                    singleLine = true,               // 单行
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), // 数字键盘
                    modifier = Modifier.fillMaxWidth(), // 占满宽度
                )
                if (message != null) {               // 提示
                    Text(
                        text = message.orEmpty(),    // 文案
                        style = MaterialTheme.typography.bodySmall, // 小字
                        color = MaterialTheme.colorScheme.primary, // 主题色
                    )
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    val input = code.trim()          // 验证码
                    if (input.isEmpty()) { message = "请输入验证码"; return@TextButton } // 校验
                    scope.launch {
                        verifying = true             // 标记核验
                        message = null               // 清空
                        runCatching { auth.verifyDevice(input) } // 核验
                            .onSuccess { onVerified() } // 成功
                            .onFailure { message = it.toWanyuMessage() } // 失败
                        verifying = false            // 结束
                    }
                },
                enabled = !verifying,                // 防重复
            ) { Text(if (verifying) "核验中" else "立即核验") } // 文案
        },
        dismissButton = {
            TextButton(
                onClick = {
                    scope.launch {
                        sending = true               // 标记发送
                        message = null               // 清空
                        runCatching { auth.sendCode("", "device_verify") } // 后端使用账号绑定邮箱
                            .onSuccess { message = "验证码已发送至绑定邮箱" } // 成功
                            .onFailure { message = it.toWanyuMessage() } // 失败
                        sending = false                  // 结束
                    }
                },
                enabled = !sending && profile?.emailBound == true, // 必须已绑邮箱
            ) { Text(if (sending) "发送中" else "发送验证码") } // 文案
        },
    )
}
