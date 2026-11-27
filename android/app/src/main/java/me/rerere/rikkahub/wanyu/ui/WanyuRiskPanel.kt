package me.rerere.rikkahub.wanyu.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import me.rerere.rikkahub.wanyu.WanyuAuthRepository
import me.rerere.rikkahub.wanyu.WanyuUser
import me.rerere.rikkahub.wanyu.toWanyuMessage
import org.koin.compose.koinInject

/**
 * 账号风控面板：封禁/冻结时替换底部输入框，禁止输入与发送任何消息。
 * 冻结账号可通过邮箱验证码解冻，封禁账号可提交申诉等待后台审核。
 */
@Composable
fun WanyuRiskPanel(
    user: WanyuUser,                                  // 当前账号资料
    modifier: Modifier = Modifier,                    // 外部修饰符
) {
    var showUnfreeze by remember { mutableStateOf(false) } // 解冻弹窗
    var showAppeal by remember { mutableStateOf(false) }   // 申诉弹窗

    Column(
        modifier = modifier
            .fillMaxWidth()
            .navigationBarsPadding()
            .padding(horizontal = 16.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(0.dp),
    ) {
        Surface(
            shape = RoundedCornerShape(16.dp),        // 圆角卡片
            color = MaterialTheme.colorScheme.errorContainer, // 危险色底
            modifier = Modifier.fillMaxWidth(),
        ) {
            Column(modifier = Modifier.padding(14.dp)) {
                Text(
                    text = if (user.isBanned) "账号已被封禁" else "账号已被冻结", // 标题
                    style = MaterialTheme.typography.titleSmall, // 标题字号
                    fontWeight = FontWeight.Bold, // 加粗
                    color = MaterialTheme.colorScheme.onErrorContainer, // 文字色
                )
                Spacer(modifier = Modifier.height(6.dp)) // 间隔
                if (user.riskReason.isNotEmpty()) {   // 风控原因
                    Text(
                        text = user.riskReason,       // 原因
                        style = MaterialTheme.typography.bodySmall, // 小字
                        color = MaterialTheme.colorScheme.onErrorContainer, // 文字色
                    )
                }
                if (user.isBanned && user.riskUntil.isNotEmpty()) { // 封禁解封时间
                    Spacer(modifier = Modifier.height(4.dp)) // 间隔
                    Text(
                        text = "解封时间：${user.riskUntil}", // 时间
                        style = MaterialTheme.typography.bodySmall, // 小字
                        color = MaterialTheme.colorScheme.onErrorContainer, // 文字色
                    )
                }
                if (user.isBanned) {                  // 申诉进度提示
                    val appealTip = when (user.appealStatus) {
                        0 -> "申诉已提交，正在等待后台审核" // 审核中
                        1 -> "申诉已通过"                 // 通过
                        2 -> "申诉未通过" + if (user.appealReply.isNotEmpty()) "：${user.appealReply}" else "" // 驳回
                        else -> ""                        // 未申诉
                    }
                    if (appealTip.isNotEmpty()) {     // 有内容才显示
                        Spacer(modifier = Modifier.height(4.dp)) // 间隔
                        Text(
                            text = appealTip,         // 提示
                            style = MaterialTheme.typography.bodySmall, // 小字
                            color = MaterialTheme.colorScheme.onErrorContainer, // 文字色
                        )
                    }
                }
                if (user.isFrozen) {                  // 冻结补充说明
                    Spacer(modifier = Modifier.height(4.dp)) // 间隔
                    Text(
                        text = if (user.emailBound) {
                            "检测到异地登录，请通过绑定邮箱验证解冻" // 已绑邮箱
                        } else {
                            "账号未绑定邮箱，无法自助解冻，请联系客服" // 未绑邮箱
                        },
                        style = MaterialTheme.typography.bodySmall, // 小字
                        color = MaterialTheme.colorScheme.onErrorContainer, // 文字色
                    )
                }
                Row(
                    modifier = Modifier.fillMaxWidth(), // 占满宽度
                    horizontalArrangement = Arrangement.End, // 右下角对齐
                ) {
                    if (user.isFrozen) {              // 冻结：解冻按钮
                        TextButton(
                            onClick = { showUnfreeze = true }, // 打开解冻弹窗
                            enabled = user.emailBound,       // 未绑邮箱不可自助解冻
                        ) { Text("去解冻") }               // 按钮文案
                    } else if (user.appealStatus != 0) { // 封禁且无待审核申诉
                        TextButton(
                            onClick = { showAppeal = true }, // 打开申诉弹窗
                        ) { Text(if (user.appealStatus == 2) "重新申诉" else "我要申诉") } // 按钮文案
                    } else {                          // 申诉审核中
                        Text(
                            text = "申诉审核中",          // 审核中提示
                            style = MaterialTheme.typography.labelLarge, // 小按钮风格
                            color = MaterialTheme.colorScheme.onErrorContainer, // 文字色
                        )
                    }
                }
            }
        }
    }

    if (showUnfreeze) {                               // 解冻弹窗
        WanyuUnfreezeDialog(
            onDismiss = { showUnfreeze = false },     // 关闭
            onSuccess = {
                showUnfreeze = false                  // 关闭
                // 状态由外部定时刷新同步
            },
        )
    }

    if (showAppeal) {                                 // 申诉弹窗
        WanyuAppealDialog(
            onDismiss = { showAppeal = false },       // 关闭
            onSuccess = { showAppeal = false },       // 提交成功
        )
    }
}

/** 违规警告弹窗：AI 判定违规后该消息已被删除，展示当前警告次数 */
@Composable
fun WanyuWarningDialog(
    count: Int,                                       // 当前警告次数
    limit: Int,                                       // 警告上限
    reason: String,                                   // 违规原因
    onDismiss: () -> Unit,                            // 关闭
) {
    AlertDialog(
        onDismissRequest = onDismiss,                 // 关闭
        title = { Text("内容违规警告") },                 // 标题
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    text = "该消息包含违规内容，已被自动删除。", // 说明
                    style = MaterialTheme.typography.bodySmall, // 小字
                )
                Text(
                    text = "当前警告 $count/$limit 次，超过 $limit 次将封禁账号。", // 警告进度
                    style = MaterialTheme.typography.bodySmall, // 小字
                    fontWeight = FontWeight.Bold, // 强调
                )
                if (reason.isNotEmpty()) {            // 违规原因
                    Text(
                        text = "原因：$reason",        // 原因
                        style = MaterialTheme.typography.bodySmall, // 小字
                        color = MaterialTheme.colorScheme.onSurfaceVariant, // 次要色
                    )
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) { Text("我知道了") } // 确认
        },
    )
}

/** 冻结账号解冻弹窗：发送邮箱验证码并输入 6 位验证码 */
@Composable
private fun WanyuUnfreezeDialog(
    onDismiss: () -> Unit,                            // 关闭
    onSuccess: () -> Unit,                            // 解冻成功
) {
    val auth: WanyuAuthRepository = koinInject()      // 登录仓库
    val scope = rememberCoroutineScope()             // 协程
    var code by rememberSaveable { mutableStateOf("") } // 验证码
    var sending by remember { mutableStateOf(false) } // 发送中
    var submitting by remember { mutableStateOf(false) } // 提交中
    var message by remember { mutableStateOf<String?>(null) } // 提示

    AlertDialog(
        onDismissRequest = onDismiss,                 // 关闭
        title = { Text("账号解冻") },                  // 标题
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    text = "检测到异地登录，已冻结账号。请获取邮箱验证码完成解冻", // 说明
                    style = MaterialTheme.typography.bodySmall, // 小字
                )
                OutlinedTextField(
                    value = code,                    // 验证码
                    onValueChange = { code = it.filter { c -> c.isDigit() }.take(6) }, // 只保留数字
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
                    if (input.length != 6) { message = "请输入6位验证码"; return@TextButton } // 校验
                    scope.launch {
                        submitting = true            // 标记提交
                        message = null               // 清空
                        runCatching { auth.unfreezeWithCode(input) } // 提交解冻
                            .onSuccess { onSuccess() } // 成功
                            .onFailure { message = it.toWanyuMessage() } // 失败
                        submitting = false           // 结束
                    }
                },
                enabled = !submitting,               // 防重复
            ) { Text(if (submitting) "解冻中" else "立即解冻") } // 文案
        },
        dismissButton = {
            Row {
                TextButton(
                    onClick = {
                        scope.launch {
                            sending = true            // 标记发送
                            message = null            // 清空
                            runCatching { auth.sendUnfreezeCode() } // 发送验证码
                                .onSuccess { message = "验证码已发送至绑定邮箱" } // 成功
                                .onFailure { message = it.toWanyuMessage() } // 失败
                            sending = false           // 结束
                        }
                    },
                    enabled = !sending,               // 防重复
                ) { Text(if (sending) "发送中" else "发送验证码") } // 文案
                TextButton(onClick = onDismiss) { Text("取消") } // 取消
            }
        },
    )
}

/** 封禁账号申诉弹窗：填写 5-500 字申诉内容，提交后等待后台审核 */
@Composable
private fun WanyuAppealDialog(
    onDismiss: () -> Unit,                            // 关闭
    onSuccess: () -> Unit,                            // 提交成功
) {
    val auth: WanyuAuthRepository = koinInject()      // 登录仓库
    val scope = rememberCoroutineScope()             // 协程
    var content by rememberSaveable { mutableStateOf("") } // 申诉内容
    var submitting by remember { mutableStateOf(false) } // 提交中
    var message by remember { mutableStateOf<String?>(null) } // 提示

    AlertDialog(
        onDismissRequest = onDismiss,                 // 关闭
        title = { Text("账号申诉") },                   // 标题
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    text = "请说明情况并承诺遵守平台规范，提交后由后台审核", // 说明
                    style = MaterialTheme.typography.bodySmall, // 小字
                )
                OutlinedTextField(
                    value = content,                 // 内容
                    onValueChange = { content = it.take(500) }, // 限长
                    label = { Text("申诉内容（5-500字）") }, // 标签
                    minLines = 4,                    // 多行
                    modifier = Modifier.fillMaxWidth(), // 占满宽度
                )
                Text(
                    text = "${content.length}/500",  // 字数
                    style = MaterialTheme.typography.bodySmall, // 小字
                    color = MaterialTheme.colorScheme.onSurfaceVariant, // 次要色
                    modifier = Modifier.fillMaxWidth(), // 占满
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
                    val input = content.trim()       // 去空格
                    if (input.length < 5) { message = "申诉内容至少5个字"; return@TextButton } // 校验
                    scope.launch {
                        submitting = true            // 标记提交
                        message = null               // 清空
                        runCatching { auth.submitAppeal(input) } // 提交申诉
                            .onSuccess { onSuccess() } // 成功
                            .onFailure { message = it.toWanyuMessage() } // 失败
                        submitting = false           // 结束
                    }
                },
                enabled = !submitting,               // 防重复
            ) { Text(if (submitting) "提交中" else "提交申诉") } // 文案
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("取消") } // 取消
        },
    )
}
