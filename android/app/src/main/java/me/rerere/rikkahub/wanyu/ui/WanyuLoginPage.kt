package me.rerere.rikkahub.wanyu.ui

import android.app.Activity                                                                             // Activity 类型，QQ SDK 授权需要
import android.content.Context                                                                         // Context 类型
import android.content.ContextWrapper                                                                  // Context 包装类，用于向上查找 Activity
import androidx.compose.animation.animateColorAsState                                                  // 颜色过渡动画，输入框聚焦时更柔和
import androidx.compose.foundation.BorderStroke                                                         // 边框描边
import androidx.compose.foundation.Canvas                                                              // 自绘六边形 Logo 与企鹅图标
import androidx.compose.foundation.background                                                          // 背景绘制
import androidx.compose.foundation.clickable                                                           // 点击事件
import androidx.compose.foundation.layout.Arrangement                                                  // 布局排列方式
import androidx.compose.foundation.layout.Box                                                          // 层叠布局
import androidx.compose.foundation.layout.Column                                                       // 垂直布局
import androidx.compose.foundation.layout.Row                                                          // 水平布局
import androidx.compose.foundation.layout.Spacer                                                       // 占位间距
import androidx.compose.foundation.layout.fillMaxSize                                                  // 占满尺寸
import androidx.compose.foundation.layout.fillMaxWidth                                                 // 占满宽度
import androidx.compose.foundation.layout.height                                                       // 固定高度
import androidx.compose.foundation.layout.imePadding                                                   // 键盘弹出时自动上移，避免遮挡
import androidx.compose.foundation.layout.navigationBarsPadding                                         // 底部导航栏安全区
import androidx.compose.foundation.layout.padding                                                      // 内边距
import androidx.compose.foundation.layout.size                                                         // 固定尺寸
import androidx.compose.foundation.layout.statusBarsPadding                                            // 状态栏安全区
import androidx.compose.foundation.layout.width                                                        // 固定宽度
import androidx.compose.foundation.rememberScrollState                                                  // 记住滚动状态
import androidx.compose.foundation.shape.CircleShape                                                   // 圆形
import androidx.compose.foundation.shape.RoundedCornerShape                                            // 圆角矩形
import androidx.compose.foundation.text.BasicTextField                                                  // 自定义深色输入框
import androidx.compose.foundation.text.KeyboardActions                                                 // 键盘动作回调
import androidx.compose.foundation.text.KeyboardOptions                                                 // 键盘类型配置
import androidx.compose.foundation.verticalScroll                                                      // 垂直滚动
import androidx.compose.material3.Button                                                               // 主按钮
import androidx.compose.material3.ButtonDefaults                                                       // 按钮配色
import androidx.compose.material3.CircularProgressIndicator                                            // 加载转圈
import androidx.compose.material3.Icon                                                                 // 图标
import androidx.compose.material3.MaterialTheme                                                        // 主题
import androidx.compose.material3.RadioButton                                                          // 协议单选框
import androidx.compose.material3.Surface                                                              // 表面容器
import androidx.compose.material3.Text                                                                 // 文本
import androidx.compose.runtime.Composable                                                             // 组合函数注解
import androidx.compose.runtime.LaunchedEffect                                                         // 副作用：倒计时
import androidx.compose.runtime.getValue                                                               // 委托读取状态
import androidx.compose.runtime.mutableStateOf                                                         // 可变状态
import androidx.compose.runtime.remember                                                              // 记忆状态
import androidx.compose.runtime.rememberCoroutineScope                                                 // 组合作用域
import androidx.compose.runtime.saveable.rememberSaveable                                              // 可保存状态（旋转屏幕不丢）
import androidx.compose.runtime.setValue                                                               // 委托写入状态
import androidx.compose.ui.Alignment                                                                   // 对齐方式
import androidx.compose.ui.Modifier                                                                    // 修饰符
import androidx.compose.ui.draw.drawBehind                                                             // 背后绘制渐变
import androidx.compose.ui.focus.FocusRequester                                                        // 焦点请求者
import androidx.compose.ui.focus.focusRequester                                                        // 焦点修饰符
import androidx.compose.ui.focus.onFocusChanged                                                        // 焦点变化监听
import androidx.compose.ui.geometry.Offset                                                             // 坐标
import androidx.compose.ui.geometry.Size                                                               // 尺寸
import androidx.compose.ui.graphics.Brush                                                              // 渐变画刷
import androidx.compose.ui.graphics.Color                                                              // 颜色
import androidx.compose.ui.graphics.Path                                                               // 路径
import androidx.compose.ui.graphics.SolidColor                                                         // 纯色画刷
import androidx.compose.ui.graphics.drawscope.Stroke                                                  // 描边样式
import androidx.compose.ui.graphics.luminance                                                          // 亮度判断主题深浅
import androidx.compose.ui.graphics.vector.ImageVector                                                 // 矢量图标类型
import androidx.compose.ui.platform.LocalContext                                                       // 当前 Context
import androidx.compose.ui.text.TextStyle                                                              // 文本样式
import androidx.compose.ui.text.font.FontWeight                                                        // 字重
import androidx.compose.ui.text.input.ImeAction                                                        // 输入法动作
import androidx.compose.ui.text.input.KeyboardType                                                     // 键盘类型
import androidx.compose.ui.text.style.TextAlign                                                        // 文本对齐
import androidx.compose.ui.text.style.TextOverflow                                                     // 文本溢出
import androidx.compose.ui.unit.dp                                                                     // dp 单位
import androidx.compose.ui.unit.sp                                                                     // sp 单位
import kotlinx.coroutines.delay                                                                        // 延迟
import kotlinx.coroutines.launch                                                                       // 协程启动
import me.rerere.hugeicons.HugeIcons                                                                   // 图标集合入口
import me.rerere.hugeicons.stroke.Add01                                                                // 加号图标
import me.rerere.hugeicons.stroke.Mail01                                                               // 邮箱图标
import me.rerere.hugeicons.stroke.ShieldCheck                                                          // 验证码/安全图标
import me.rerere.hugeicons.stroke.UserCircle                                                           // 用户图标
import me.rerere.rikkahub.wanyu.WanyuAuthRepository                                                    // 登录仓库
import me.rerere.rikkahub.wanyu.WanyuConfig                                                            // 全局配置
import me.rerere.rikkahub.wanyu.WanyuQqAuth                                                             // QQ 官方 SDK 登录
import me.rerere.rikkahub.wanyu.toWanyuMessage                                                         // 错误信息转换
import androidx.compose.animation.core.animateFloatAsState                                            // Logo 按压缩放动画
import androidx.compose.foundation.LocalIndication                                                     // 点击涟漪
import androidx.compose.foundation.interaction.MutableInteractionSource                                 // 按压状态来源
import androidx.compose.foundation.interaction.collectIsPressedAsState                                  // 采集按压状态
import androidx.compose.runtime.DisposableEffect                                                       // 音效资源释放
import androidx.compose.runtime.mutableIntStateOf                                                      // 整数状态
import androidx.compose.ui.graphics.graphicsLayer                                                      // 图形变换
import androidx.compose.ui.layout.LayoutCoordinates                                                    // 布局坐标
import androidx.compose.ui.layout.onGloballyPositioned                                                 // 获取全局坐标
import androidx.compose.ui.unit.center                                                                 // 尺寸取中心
import androidx.compose.ui.unit.toOffset                                                               // 尺寸转偏移
import me.rerere.rikkahub.R                                                                            // 资源引用（音效）
import me.rerere.rikkahub.ui.components.ui.drawAppLogo                                                  // 绘制软件 Logo
import me.rerere.rikkahub.ui.components.ui.logoMorphShape                                               // Logo 轮廓变形
import me.rerere.rikkahub.ui.components.ui.rememberAppLogoLayers                                        // 软件 Logo 图层
import me.rerere.rikkahub.utils.SoundEffectPlayer                                                       // 点击音效
import me.rerere.ui.easteregg.EmojiBurstHost                                                           // 表情喷发容器

/** 协议类型：用于区分用户协议与隐私政策的点击回调 */
enum class WanyuAgreementType {
    USER_AGREEMENT,                                                                                    // 用户协议
    PRIVACY_POLICY,                                                                                    // 隐私政策
}

/**
 * 挽屿AI 登录页（邮箱验证码 + QQ 一键登录）。
 *
 * 视觉：薄荷绿径向渐变背景、六边形品牌 Logo、深灰大圆角输入框、蓝色主按钮。
 * 交互：聚焦柔和反馈、键盘弹出自动上移、验证码 60 秒冷却、协议勾选后按钮才启用。
 *
 * @param auth 登录仓库，负责发码、登录和 QQ 换票
 * @param onLoggedIn 登录成功回调
 * @param onHelpClick 右上角「帮助」点击回调
 * @param onSendCodeClick 「获取验证码」触发回调（内部仍会调用后端发码）
 * @param onEmailLoginClick 「登录」触发回调（内部仍会调用后端登录）
 * @param onSwitchLoginMode 左侧「切换为QQ一键登录」点击回调
 * @param onQqLoginClick QQ 授权发起前回调
 * @param onAgreementClick 协议文字点击回调
 * @param onOtherLogin 底部「其他登录方式」点击回调
 * @param onBindAccount 底部「补充绑定」点击回调
 */
@Composable
fun WanyuLoginPage(
    auth: WanyuAuthRepository,                                                                          // 登录仓库
    onLoggedIn: () -> Unit,                                                                             // 登录成功回调
    modifier: Modifier = Modifier,                                                                      // 外部修饰符
    onHelpClick: () -> Unit = {},                                                                       // 帮助回调
    onSendCodeClick: (String) -> Unit = {},                                                             // 获取验证码回调
    onEmailLoginClick: (String, String) -> Unit = { _, _ -> },                                          // 邮箱登录回调
    onSwitchLoginMode: () -> Unit = {},                                                                 // 切换登录方式回调
    onQqLoginClick: () -> Unit = {},                                                                    // QQ 授权回调
    onAgreementClick: (WanyuAgreementType) -> Unit = {},                                                // 协议点击回调
    onOtherLogin: () -> Unit = {},                                                                      // 其他登录方式回调
    onBindAccount: () -> Unit = {},                                                                     // 补充绑定回调
) {
    val context = LocalContext.current                                                                  // 当前 Context
    val scope = rememberCoroutineScope()                                                                // 组合作用域协程
    var email by rememberSaveable { mutableStateOf("") }                                                // 邮箱输入
    var code by rememberSaveable { mutableStateOf("") }                                                 // 验证码输入
    var loading by remember { mutableStateOf(false) }                                                   // 登录请求中
    var sending by remember { mutableStateOf(false) }                                                   // 发码请求中
    var countdown by remember { mutableStateOf(0) }                                                     // 重发倒计时（秒）
    var error by remember { mutableStateOf<String?>(null) }                                             // 错误提示
    var agreed by rememberSaveable { mutableStateOf(false) }                                            // 是否同意协议
    val codeFocusRequester = remember { FocusRequester() }                                              // 验证码输入框焦点

    // 深色主题判断：背景渐变与文字配色随主题自适应
    val darkTheme = MaterialTheme.colorScheme.background.luminance() < 0.5f                             // 主题是否为深色
    val accentBlue = Color(0xFF2F7BFF)                                                                   // 主按钮蓝色
    val fieldBackground = Color(0xFF262A2F)                                                              // 输入框深灰底色
    val fieldText = Color(0xFFEDF1F5)                                                                    // 输入框文字色
    val fieldHint = Color(0xFF8C949E)                                                                    // 输入框提示色
    val mintStrong = if (darkTheme) Color(0xFF1E6B57) else Color(0xFF9FE8CC)                             // 渐变中心薄荷绿
    val mintSoft = if (darkTheme) Color(0xFF2A8A72) else Color(0xFFCFF4E4)                               // 渐变过渡薄荷绿

    // Logo 点击特效：与关于页一致（按压缩放 + 轮廓变形 + 音效 + 表情喷发）
    val logoLayers = rememberAppLogoLayers()                                                             // 软件 Logo 图层
    val soundOptions = remember { listOf(R.raw.bingbingbing, R.raw.gangguan) }                            // 点击音效
    val soundEffectPlayer = remember(context) { SoundEffectPlayer(context) }                              // 音效播放器
    DisposableEffect(soundEffectPlayer) {
        soundEffectPlayer.preload(*soundOptions.toIntArray())                                             // 预加载音效
        onDispose { soundEffectPlayer.release() }                                                         // 释放资源
    }
    val emojiOptions = remember {                                                                         // 喷发表情集合
        listOf("🎉", "✨", "🌟", "💫", "🎊", "🥳", "🎈", "🎆", "🎇", "🧨", "🌈", "🧧", "🎁", "🍬", "⭐", "🦄")
    }
    var hostCoordinates by remember { mutableStateOf<LayoutCoordinates?>(null) }                          // 喷发容器坐标
    var logoCoordinates by remember { mutableStateOf<LayoutCoordinates?>(null) }                          // Logo 坐标

    // 倒计时每秒递减，归零后允许重新获取
    LaunchedEffect(countdown) {
        if (countdown > 0) {
            delay(1_000L)                                                                                // 等待一秒
            countdown -= 1                                                                               // 递减
        }
    }

    // 发送验证码：校验邮箱 -> 回调 -> 调后端 -> 进入 60 秒冷却
    fun sendCode() {
        val target = email.trim()                                                                        // 去掉首尾空格
        if (target.isEmpty()) {                                                                          // 空值校验
            error = "请输入邮箱地址"                                                                       // 提示
            return                                                                                       // 中断
        }
        onSendCodeClick(target)                                                                           // 外部回调
        scope.launch {
            sending = true                                                                                // 标记发码中
            error = null                                                                                  // 清空错误
            runCatching { auth.sendCode(target) }                                                          // 调用后端发码
                .onSuccess { countdown = 60 }                                                              // 成功进入冷却
                .onFailure { error = it.toWanyuMessage() }                                                 // 失败提示
            sending = false                                                                                // 结束标记
        }
    }

    // 邮箱验证码登录：首次验证通过后端自动注册
    fun loginWithEmail() {
        val target = email.trim()                                                                         // 邮箱
        val inputCode = code.trim()                                                                        // 验证码
        if (target.isEmpty() || inputCode.isEmpty()) {                                                     // 必填校验
            error = "请填写邮箱和验证码"                                                                    // 提示
            return                                                                                         // 中断
        }
        onEmailLoginClick(target, inputCode)                                                               // 外部回调
        scope.launch {
            loading = true                                                                                 // 标记加载
            error = null                                                                                   // 清空错误
            runCatching { auth.loginWithEmail(target, inputCode) }                                          // 后端登录
                .onSuccess { onLoggedIn() }                                                                // 成功进入主界面
                .onFailure { error = it.toWanyuMessage() }                                                 // 失败提示
            loading = false                                                                                // 结束标记
        }
    }

    // QQ 官方 SDK 登录：客户端拿 openid + access_token 交后端校验
    fun loginWithQq() {
        val activity = context.findActivity()                                                              // 需要 Activity 承载授权页
        if (activity == null) {                                                                            // 找不到 Activity
            error = "当前页面无法发起 QQ 登录"                                                             // 提示
            return                                                                                         // 中断
        }
        onQqLoginClick()                                                                                   // 外部回调
        WanyuQqAuth.login(activity) { result ->                                                            // 调起官方授权
            val token = result.getOrNull()                                                                 // 授权成功数据
            if (token == null) {                                                                           // 授权失败
                error = result.exceptionOrNull()?.toWanyuMessage() ?: "QQ 登录失败"                        // 展示原因
                return@login                                                                               // 结束回调
            }
            scope.launch {
                loading = true                                                                             // 标记加载
                error = null                                                                               // 清空错误
                runCatching { auth.loginWithQq(token.openid, token.accessToken) }                          // 换取登录态
                    .onSuccess { onLoggedIn() }                                                            // 成功进入主界面
                    .onFailure { error = it.toWanyuMessage() }                                             // 失败提示
                loading = false                                                                            // 结束标记
            }
        }
    }

    // 输入框焦点弱化处理：点击输入框任意位置都能聚焦
    val sendEnabled = !sending && countdown == 0 && !loading                                                  // 是否允许获取验证码

    Box(
        modifier = modifier
            .fillMaxSize()                                                                                  // 占满整屏
            .background(MaterialTheme.colorScheme.background)                                               // 主题底色
            .drawBehind {                                                                                   // 绘制径向渐变
                drawRect(                                                                                   // 铺满画布
                    brush = Brush.radialGradient(                                                           // 径向渐变
                        colors = listOf(                                                                    // 颜色序列
                            mintStrong.copy(alpha = if (darkTheme) 0.55f else 0.95f),                       // 中心薄荷绿
                            mintSoft.copy(alpha = if (darkTheme) 0.28f else 0.55f),                         // 过渡薄荷绿
                            Color.Transparent,                                                              // 边缘透明露出主题底色
                        ),
                        center = Offset(size.width * 0.5f, size.height * 0.12f),                            // 渐变中心靠上
                        radius = size.maxDimension * 1.0f,                                                  // 覆盖整个页面
                    )
                )
            },
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()                                                                              // 占满整屏
                .statusBarsPadding()                                                                        // 避开状态栏
                .navigationBarsPadding()                                                                     // 避开导航栏
                .imePadding(),                                                                               // 键盘弹出时整体上移
        ) {
            Column(
                modifier = Modifier
                    .weight(1f)                                                                             // 占满剩余高度，底部操作栏固定在底部
                    .verticalScroll(rememberScrollState())                                                   // 小屏可滚动
                    .padding(horizontal = 28.dp),                                                            // 页面左右留白
            ) {
                // ---------- 1. 顶部区域 ---------- //
                Row(
                    modifier = Modifier
                        .fillMaxWidth()                                                                     // 占满宽度
                        .padding(top = 12.dp),                                                               // 顶部留白
                    horizontalArrangement = Arrangement.End,                                                 // 靠右对齐
                ) {
                    Text(
                        text = "帮助",                                                                      // 帮助文案
                        style = MaterialTheme.typography.bodyMedium,                                        // 正文样式
                        color = MaterialTheme.colorScheme.onSurfaceVariant,                                 // 次要色
                        modifier = Modifier
                            .clickable { onHelpClick() }                                                     // 点击回调
                            .padding(horizontal = 8.dp, vertical = 6.dp),                                     // 点击热区
                    )
                }

                Spacer(modifier = Modifier.height(16.dp))                                                   // 间距

                // 软件 Logo：与关于页同一个图标，并带同款点击特效
                EmojiBurstHost(
                    modifier = Modifier
                        .fillMaxWidth()
                        .onGloballyPositioned { hostCoordinates = it },                                      // 记录容器坐标
                    emojiOptions = emojiOptions,                                                            // 喷发素材
                    burstCount = 12,                                                                        // 每次喷发数量
                ) { onBurst ->
                    Column(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        val interactionSource = remember { MutableInteractionSource() }                      // 按压来源
                        val pressed by interactionSource.collectIsPressedAsState()                          // 是否按压
                        val logoScale by animateFloatAsState(
                            targetValue = if (pressed) 0.88f else 1f,                                        // 按压缩小
                            animationSpec = MaterialTheme.motionScheme.fastSpatialSpec(),
                        )
                        var shapeIndex by remember { mutableIntStateOf(0) }                                  // 轮廓变形序号
                        val shapePosition by animateFloatAsState(
                            targetValue = shapeIndex.toFloat(),                                             // 每次点击换形状
                            animationSpec = MaterialTheme.motionScheme.slowSpatialSpec(),
                        )
                        Box(
                            modifier = Modifier
                                .size(84.dp)                                                                 // Logo 尺寸
                                .onGloballyPositioned { logoCoordinates = it }                               // 记录 Logo 坐标
                                .graphicsLayer {
                                    scaleX = logoScale                                                       // 缩放
                                    scaleY = logoScale                                                       // 缩放
                                    shape = logoMorphShape(shapePosition)                                    // 轮廓变形
                                    clip = true                                                              // 裁剪
                                }
                                .clickable(
                                    interactionSource = interactionSource,
                                    indication = LocalIndication.current,
                                ) {
                                    val host = hostCoordinates
                                    val logo = logoCoordinates
                                    if (host != null && logo != null && host.isAttached && logo.isAttached) {
                                        onBurst(host.localPositionOf(logo, logo.size.center.toOffset()))
                                    }
                                    soundEffectPlayer.play(soundOptions.random())                        // 点击音效
                                    shapeIndex++
                                }
                                .drawAppLogo(logoLayers)                                                 // 绘制软件 Logo
                        )

                        Spacer(modifier = Modifier.height(12.dp))                                            // 间距

                        Text(
                            text = WanyuConfig.APP_LABEL,                                                    // 品牌名称
                            style = MaterialTheme.typography.titleLarge,                                     // 标题样式
                            fontWeight = FontWeight.Bold,                                                    // 加粗
                            color = MaterialTheme.colorScheme.onBackground,                                  // 主题前景色
                        )
                    }
                }

                Spacer(modifier = Modifier.height(44.dp))                                                   // 标题区间距

                // ---------- 2. 居中标题 ---------- //
                Text(
                    text = "邮箱验证码登录",                                                                  // 主标题
                    style = MaterialTheme.typography.headlineMedium,                                         // 大号标题
                    fontWeight = FontWeight.Bold,                                                            // 加粗
                    color = MaterialTheme.colorScheme.onBackground,                                          // 主题前景色
                    textAlign = TextAlign.Center,                                                            // 居中
                    modifier = Modifier.fillMaxWidth(),                                                      // 占满宽度
                )

                Spacer(modifier = Modifier.height(8.dp))                                                    // 间距

                Text(
                    text = "未注册邮箱验证通过后将自动注册账号",                                              // 副标题
                    style = MaterialTheme.typography.bodySmall,                                              // 小字
                    color = MaterialTheme.colorScheme.onSurfaceVariant,                                      // 次要色
                    textAlign = TextAlign.Center,                                                            // 居中
                    modifier = Modifier.fillMaxWidth(),                                                      // 占满宽度
                )

                Spacer(modifier = Modifier.height(28.dp))                                                   // 输入区间距

                // ---------- 3. 输入框区域 ---------- //
                DarkRoundedField(
                    value = email,                                                                           // 邮箱值
                    onValueChange = { email = it },                                                           // 更新邮箱
                    hint = "输入邮箱地址",                                                                     // 提示文字
                    icon = HugeIcons.Mail01,                                                                  // 邮箱图标
                    keyboardType = KeyboardType.Email,                                                        // 邮箱键盘
                    imeAction = ImeAction.Next,                                                               // 下一步
                    onImeAction = { codeFocusRequester.requestFocus() },                                       // 跳到验证码
                    enabled = !loading,                                                                        // 加载时禁用
                )

                Spacer(modifier = Modifier.height(14.dp))                                                   // 输入框间距

                DarkRoundedField(
                    value = code,                                                                            // 验证码值
                    onValueChange = { code = it },                                                            // 更新验证码
                    hint = "输入验证码",                                                                       // 提示文字
                    icon = HugeIcons.ShieldCheck,                                                             // 验证码图标
                    keyboardType = KeyboardType.Number,                                                       // 数字键盘
                    imeAction = ImeAction.Done,                                                               // 完成
                    onImeAction = { if (agreed && !loading) loginWithEmail() },                                // 键盘完成直接登录
                    enabled = !loading,                                                                        // 加载时禁用
                    modifier = Modifier.focusRequester(codeFocusRequester),                                    // 绑定焦点
                )

                Spacer(modifier = Modifier.height(14.dp))                                                   // 链接行间距

                // ---------- 4. 链接行 ---------- //
                Row(
                    modifier = Modifier.fillMaxWidth(),                                                       // 占满宽度
                    horizontalArrangement = Arrangement.SpaceBetween,                                          // 两端对齐
                    verticalAlignment = Alignment.CenterVertically,                                            // 垂直居中
                ) {
                    Text(
                        text = "切换为QQ一键登录",                                                              // 左侧切换链接
                        style = MaterialTheme.typography.bodySmall,                                            // 小字
                        color = accentBlue,                                                                    // 蓝色链接
                        modifier = Modifier
                            .clickable {
                                onSwitchLoginMode()                                                            // 外部切换回调
                                loginWithQq()                                                                  // 默认直接发起 QQ 授权
                            }
                            .padding(vertical = 6.dp),                                                     // 点击热区
                    )

                    Text(
                        text = when {                                                                          // 右侧验证码文案
                            sending -> "发送中"                                                                // 发码中
                            countdown > 0 -> "${countdown}s"                                                   // 倒计时
                            else -> "获取验证码"                                                                // 默认
                        },
                        style = MaterialTheme.typography.bodySmall,                                            // 小字
                        color = if (sendEnabled) accentBlue else MaterialTheme.colorScheme.outline,            // 冷却期置灰
                        modifier = Modifier
                            .clickable(enabled = sendEnabled) { sendCode() }                                  // 冷却期不可点击
                            .padding(vertical = 6.dp),                                                      // 点击热区
                    )
                }

                // 错误提示
                if (error != null) {
                    Spacer(modifier = Modifier.height(10.dp))                                              // 间距
                    Text(
                        text = error.orEmpty(),                                                                // 错误文案
                        style = MaterialTheme.typography.bodySmall,                                            // 小字
                        color = MaterialTheme.colorScheme.error,                                               // 错误色
                        modifier = Modifier.fillMaxWidth(),                                                    // 占满宽度
                    )
                }

                Spacer(modifier = Modifier.height(22.dp))                                                   // 按钮间距

                // ---------- 5. 蓝色主按钮 ---------- //
                Button(
                    onClick = { loginWithEmail() },                                                            // 点击登录
                    modifier = Modifier
                        .fillMaxWidth()                                                                        // 占满宽度
                        .height(54.dp),                                                                        // 加高按钮
                    enabled = agreed && !loading,                                                              // 勾选协议后才启用
                    shape = RoundedCornerShape(27.dp),                                                         // 宽圆角
                    colors = ButtonDefaults.buttonColors(
                        containerColor = accentBlue,                                                           // 默认蓝色
                        contentColor = Color.White,                                                            // 白色文字
                        disabledContainerColor = if (darkTheme) Color(0xFF3A3F46) else Color(0xFFBFC6CE),     // 置灰
                        disabledContentColor = if (darkTheme) Color(0xFF8C949E) else Color(0xFF6B737D),        // 置灰文字
                    ),
                ) {
                    if (loading) {                                                                             // 登录加载中
                        CircularProgressIndicator(
                            modifier = Modifier.size(18.dp),                                                   // 小转圈
                            strokeWidth = 2.dp,                                                                // 线宽
                            color = Color.White,                                                               // 白色
                        )
                        Spacer(modifier = Modifier.width(8.dp))                                                // 间隔
                    }
                    Text(text = "登录", style = MaterialTheme.typography.titleSmall)                          // 按钮文字
                }

                Spacer(modifier = Modifier.height(16.dp))                                                   // 协议行间距

                // ---------- 6. 协议单选框 ---------- //
                Row(
                    modifier = Modifier.fillMaxWidth(),                                                        // 占满宽度
                    verticalAlignment = Alignment.CenterVertically,                                            // 垂直居中
                ) {
                    RadioButton(
                        selected = agreed,                                                                      // 是否勾选
                        onClick = { agreed = !agreed },                                                         // 点击切换
                    )
                    Text(
                        text = "已阅读并同意",                                                                   // 前缀
                        style = MaterialTheme.typography.bodySmall,                                            // 小字
                        color = MaterialTheme.colorScheme.onSurfaceVariant,                                    // 次要色
                    )
                    Text(
                        text = "《用户协议》",                                                                   // 用户协议
                        style = MaterialTheme.typography.bodySmall,                                            // 小字
                        color = accentBlue,                                                                    // 链接色
                        modifier = Modifier.clickable { onAgreementClick(WanyuAgreementType.USER_AGREEMENT) },  // 点击回调
                    )
                    Text(
                        text = "和",                                                                            // 连接词
                        style = MaterialTheme.typography.bodySmall,                                            // 小字
                        color = MaterialTheme.colorScheme.onSurfaceVariant,                                    // 次要色
                    )
                    Text(
                        text = "《隐私政策》",                                                                   // 隐私政策
                        style = MaterialTheme.typography.bodySmall,                                            // 小字
                        color = accentBlue,                                                                    // 链接色
                        modifier = Modifier.clickable { onAgreementClick(WanyuAgreementType.PRIVACY_POLICY) }, // 点击回调
                    )
                }

                Spacer(modifier = Modifier.height(24.dp))                                                   // 底部呼吸间距
            }

            // ---------- 7. 底部圆形图标按钮 ---------- //
            Row(
                modifier = Modifier
                    .fillMaxWidth()                                                                             // 占满宽度
                    .padding(horizontal = 28.dp, vertical = 16.dp),                                            // 四周留白
                    horizontalArrangement = Arrangement.SpaceEvenly,                                            // 均匀排列
                    verticalAlignment = Alignment.CenterVertically,                                              // 垂直居中
            ) {
                CircleActionButton(
                    label = "QQ授权登录",                                                                        // 文字
                    onClick = { loginWithQq() },                                                                // 点击授权
                ) { tint ->
                    PenguinIcon(tint = tint, modifier = Modifier.size(24.dp))                                // 自绘企鹅图标
                }
                CircleActionButton(
                    label = "其他登录方式",                                                                      // 文字
                    onClick = onOtherLogin,                                                                     // 点击回调
                ) { tint ->
                    Icon(
                        imageVector = HugeIcons.UserCircle,                                                 // 用户图标
                        contentDescription = "其他登录方式",                                                  // 无障碍描述
                        tint = tint,                                                                          // 主题色
                        modifier = Modifier.size(24.dp),                                                      // 图标尺寸
                    )
                }
                CircleActionButton(
                    label = "补充绑定",                                                                          // 文字
                    onClick = onBindAccount,                                                                     // 点击回调
                ) { tint ->
                    Icon(
                        imageVector = HugeIcons.Add01,                                                      // 加号图标
                        contentDescription = "补充绑定",                                                      // 无障碍描述
                        tint = tint,                                                                          // 主题色
                        modifier = Modifier.size(24.dp),                                                      // 图标尺寸
                    )
                }
            }
        }
    }
}

/**
 * 深灰大圆角输入框：前置图标 + 提示文字 + 柔和聚焦反馈。
 */
@Composable
private fun DarkRoundedField(
    value: String,                                                                                       // 当前输入
    onValueChange: (String) -> Unit,                                                                     // 输入变化
    hint: String,                                                                                        // 提示文字
    icon: ImageVector,                                                                                   // 前置图标
    keyboardType: KeyboardType,                                                                          // 键盘类型
    imeAction: ImeAction,                                                                                // 输入法动作
    onImeAction: () -> Unit,                                                                             // 输入法动作回调
    enabled: Boolean,                                                                                    // 是否可输入
    modifier: Modifier = Modifier,                                                                       // 外部修饰符
) {
    val focusRequester = remember { FocusRequester() }                                                   // 内部焦点请求者
    var focused by remember { mutableStateOf(false) }                                                     // 是否聚焦
    val borderColor by animateColorAsState(                                                              // 边框颜色过渡
        targetValue = if (focused) Color(0xFF2F7BFF) else Color.Transparent,                              // 聚焦变蓝
        label = "fieldBorder",                                                                           // 动画标签
    )

    Surface(
        shape = RoundedCornerShape(18.dp),                                                                // 大圆角
        color = Color(0xFF262A2F),                                                                        // 深灰底色
        border = BorderStroke(1.5.dp, borderColor),                                                       // 细边框
        modifier = modifier
            .fillMaxWidth()                                                                                // 占满宽度
            .height(58.dp)                                                                                 // 固定高度
            .clickable(enabled = enabled) { focusRequester.requestFocus() },                               // 点击聚焦
    ) {
        Row(
            modifier = Modifier
                .fillMaxSize()                                                                             // 占满
                .padding(horizontal = 18.dp),                                                              // 左右内边距
            verticalAlignment = Alignment.CenterVertically,                                                 // 垂直居中
            horizontalArrangement = Arrangement.spacedBy(12.dp),                                           // 图标与输入间隔
        ) {
            Icon(
                imageVector = icon,                                                                        // 前置图标
                contentDescription = null,                                                                 // 装饰性图标
                tint = if (focused) Color(0xFF2F7BFF) else Color(0xFF8C949E),                             // 聚焦变蓝
                modifier = Modifier.size(22.dp),                                                           // 图标尺寸
            )
            Box(modifier = Modifier.weight(1f)) {                                                          // 输入区域
                if (value.isEmpty()) {                                                                     // 空值时显示提示
                    Text(
                        text = hint,                                                                       // 提示文字
                        style = MaterialTheme.typography.bodyMedium,                                       // 正文样式
                        color = Color(0xFF8C949E),                                                         // 提示色
                    )
                }
                BasicTextField(
                    value = value,                                                                          // 绑定值
                    onValueChange = onValueChange,                                                          // 值变化
                    enabled = enabled,                                                                      // 禁用控制
                    singleLine = true,                                                                      // 单行
                    textStyle = TextStyle(
                        color = Color(0xFFEDF1F5),                                                          // 白色文字
                        fontSize = 16.sp,                                                                   // 字号
                    ),
                    cursorBrush = SolidColor(Color(0xFF2F7BFF)),                                           // 蓝色光标
                    keyboardOptions = KeyboardOptions(
                        keyboardType = keyboardType,                                                        // 键盘类型
                        imeAction = imeAction,                                                              // 动作按钮
                    ),
                    keyboardActions = KeyboardActions(
                        onNext = { onImeAction() },                                                         // 下一步
                        onDone = { onImeAction() },                                                         // 完成
                    ),
                    modifier = Modifier
                        .fillMaxWidth()                                                                     // 占满宽度
                        .focusRequester(focusRequester)                                                     // 焦点控制
                        .onFocusChanged { focused = it.isFocused },                                         // 记录聚焦
                )
            }
        }
    }
}

/**
 * 底部圆形空心图标按钮：圆形描边 + 下方文字。
 */
@Composable
private fun CircleActionButton(
    label: String,                                                                                        // 下方文字
    onClick: () -> Unit,                                                                                  // 点击回调
    icon: @Composable (tint: Color) -> Unit,                                                              // 图标内容
) {
    val tint = MaterialTheme.colorScheme.onBackground                                                     // 图标颜色
    Column(horizontalAlignment = Alignment.CenterHorizontally) {                                          // 垂直排列
        Surface(
            shape = CircleShape,                                                                          // 圆形
            color = Color.Transparent,                                                                    // 透明底
            border = BorderStroke(1.dp, tint.copy(alpha = 0.35f)),                                        // 空心描边
            modifier = Modifier
                .size(52.dp)                                                                              // 圆形尺寸
                .clickable { onClick() },                                                                 // 点击回调
        ) {
            Box(contentAlignment = Alignment.Center) {                                                    // 居中内容
                icon(tint)                                                                                // 绘制图标
            }
        }
        Spacer(modifier = Modifier.height(6.dp))                                                          // 间距
        Text(
            text = label,                                                                                 // 文字
            style = MaterialTheme.typography.labelSmall,                                                  // 小标签
            color = MaterialTheme.colorScheme.onSurfaceVariant,                                           // 次要色
            maxLines = 1,                                                                                 // 单行
            overflow = TextOverflow.Ellipsis,                                                             // 溢出省略
        )
    }
}

/**
 * 自绘 QQ 企鹅图标（纯 Compose，不引入额外图片资源）。
 */
@Composable
private fun PenguinIcon(
    tint: Color,                                                                                          // 描边颜色
    modifier: Modifier = Modifier,                                                                        // 外部修饰符
) {
    Canvas(modifier = modifier) {                                                                         // 画布
        val w = size.width                                                                                // 宽
        val h = size.height                                                                               // 高
        val stroke = w * 0.07f                                                                            // 线宽
        drawOval(
            color = tint,                                                                                 // 身体描边
            topLeft = Offset(w * 0.20f, h * 0.05f),                                                       // 左上角
            size = Size(w * 0.60f, h * 0.90f),                                                            // 身体尺寸
            style = Stroke(width = stroke),                                                               // 空心描边
        )
        drawCircle(color = tint, radius = w * 0.045f, center = Offset(w * 0.42f, h * 0.38f))              // 左眼
        drawCircle(color = tint, radius = w * 0.045f, center = Offset(w * 0.58f, h * 0.38f))              // 右眼
        val beak = Path().apply {                                                                         // 嘴巴路径
            moveTo(w * 0.50f, h * 0.45f)                                                                   // 顶点
            lineTo(w * 0.43f, h * 0.55f)                                                                   // 左下
            lineTo(w * 0.57f, h * 0.55f)                                                                   // 右下
            close()                                                                                       // 闭合
        }
        drawPath(path = beak, color = Color(0xFFFFA726))                                                  // 橙色嘴巴
    }
}

/** 从 Context 逐层取到 Activity，QQ SDK 必须用 Activity 发起授权 */
internal fun Context.findActivity(): Activity? {
    var current: Context? = this                                                                          // 当前上下文
    while (current is ContextWrapper) {                                                                   // 逐层包裹
        if (current is Activity) return current                                                           // 命中直接返回
        current = current.baseContext                                                                     // 继续向上
    }
    return current as? Activity                                                                           // 最终判定
}
