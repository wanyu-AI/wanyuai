package me.rerere.rikkahub.wanyu.ui

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonPrimitive
import me.rerere.hugeicons.HugeIcons
import me.rerere.hugeicons.stroke.Brain02
import me.rerere.hugeicons.stroke.McpServer
import me.rerere.hugeicons.stroke.Package
import me.rerere.hugeicons.stroke.ServerStack01
import me.rerere.hugeicons.stroke.ShieldCheck
import me.rerere.hugeicons.stroke.Sparkles
import me.rerere.rikkahub.ui.components.nav.BackButton
import me.rerere.rikkahub.utils.openUrl
import me.rerere.rikkahub.wanyu.WanyuApi
import me.rerere.rikkahub.wanyu.WanyuApiException
import me.rerere.rikkahub.wanyu.WanyuAuthRepository
import me.rerere.rikkahub.wanyu.toWanyuMessage
import org.koin.compose.koinInject

/** 商品 SKU 定义：类型/SKU名/展示名/金额(元)/会员类型(月/年/永久)/积分 */
private sealed interface StoreProduct {
    val type: String     // "vip" | "points"
    val sku: String
    val name: String
    val amount: String   // 两位小数字符串，必须与后端完全一致
}

private data class VipProduct(
    override val sku: String,
    override val name: String,
    override val amount: String,
    val months: Int,                // 月数；9999=永久
    val badge: String = "",         // 角标文字（如 "HOT" / "限时出售"）
    val subDesc: String = "",       // 副标题（如 "每周只需¥2.0"）
) : StoreProduct {
    override val type: String get() = "vip"
}

private data class PointsProduct(
    override val sku: String,
    override val name: String,
    override val amount: String,
    val points: Int,
    val tag: String = "",
) : StoreProduct {
    override val type: String get() = "points"
}

/** 会员特权列表（参考图里那些；挽屿AI 版本用适配本应用的特权） */
private data class VipBenefit(
    val title: String,
    val subtitle: String,
    val icon: androidx.compose.ui.graphics.vector.ImageVector,
)

/** 全部商品 */
private val VIP_PRODUCTS = listOf(
    VipProduct("vip_permanent", "永久会员", "186.00", months = 9999, badge = "HOT", subDesc = "限时出售"),
    VipProduct("vip_year",      "年费会员", "106.00", months = 12,    subDesc = "每周只需¥2.0"),
    VipProduct("vip_quarter",   "季度会员", "28.00",  months = 3,     subDesc = "每天只需¥0.3"),
    VipProduct("vip_month",     "月度会员", "10.00",  months = 1),
)

private val POINTS_PRODUCTS = listOf(
    PointsProduct("points_200",   "200 积分",   "1.00",  points = 200),
    PointsProduct("points_1000",  "1000 积分",  "5.00",  points = 1000),
    PointsProduct("points_5000",  "5000 积分",  "25.00", points = 5000,  tag = "推荐"),
    PointsProduct("points_10000", "10000 积分", "48.00", points = 10000, tag = "热门"),
    PointsProduct("points_50000", "50000 积分", "228.00", points = 50000),
)

private val VIP_BENEFITS = listOf(
    VipBenefit("AI 思考加速",       "官方模型优先通道",    HugeIcons.Sparkles),
    VipBenefit("云端存储扩容",      "更多对话与设置备份",  HugeIcons.ServerStack01),
    VipBenefit("高级模型免积分",    "每月专属额度",        HugeIcons.Sparkles),
    VipBenefit("优先客户支持",      "问题快速响应",        HugeIcons.ShieldCheck),
    VipBenefit("MCP 工具免费",      "所有内置工具免积分",  HugeIcons.McpServer),
    VipBenefit("AI 助手更智能",    "更好的模型上下文理解", HugeIcons.Brain02),
)

/** 支付回跳 deep link 携带的订单号 extra（RouteActivity 与商城页共用同一 key） */
const val PAY_RETURN_ORDER_EXTRA = "wanyu_pay_order_no"

/** 订单状态枚举，与后端保持一致 */
private enum class OrderStatus(val code: Int) {
    PENDING(0), PAID(1), CLOSED(2), REFUNDED_FULL(3), REFUNDED_PART(4), FAILED(5);
    companion object { fun of(code: Int) = entries.firstOrNull { it.code == code } ?: PENDING }
}

/**
 * 商城页面：会员 + 积分双 Tab，购买流程含 2 秒轮询 + onResume 补查 + 同意校验。
 */
@Composable
fun WanyuStorePage(
    api: WanyuApi = koinInject(),
    auth: WanyuAuthRepository = koinInject(),
) {
    val context = LocalContext.current
    val activity = context as? Activity
    val scope = rememberCoroutineScope()
    val lifecycleOwner = LocalLifecycleOwner.current

    // === 状态 ===
    var tab by remember { mutableIntStateOf(0) }                           // 0=会员 1=积分
    var selectedVipSku by remember { mutableStateOf(VIP_PRODUCTS.first().sku) } // 默认永久会员
    var selectedPointsSku by remember { mutableStateOf(POINTS_PRODUCTS[2].sku) } // 默认推荐档位
    var agreed by remember { mutableStateOf(false) }                       // 同意会员须知
    var paying by remember { mutableStateOf(false) }                       // 正在支付中
    var toastMsg by remember { mutableStateOf<String?>(null) }             // Toast 消息（Compose 自己管）
    var pollingOrderNo by remember { mutableStateOf<String?>(null) }       // 当前正在轮询的订单号
    var lastStatus by remember { mutableIntStateOf(0) }                    // 最近一次查询的订单状态
    var vipInfo by remember { mutableStateOf<VipUiInfo?>(null) }           // 当前会员状态

    val wanyuUser by auth.profile.collectAsStateWithLifecycle()

    // === 会员状态拉取（进入页面 + 支付成功后刷新） ===
    LaunchedEffect(Unit) {
        vipInfo = fetchVipInfo(api)
    }

    // === Toast 提示（由 LaunchedEffect 自动响应 toastMsg 变化）===
    LaunchedEffect(toastMsg) {
        val msg = toastMsg
        if (msg != null) {
            Toast.makeText(context, msg, Toast.LENGTH_SHORT).show()
            toastMsg = null
        }
    }

    // === 2 秒轮询订单状态（单笔最多 5 分钟） ===
    var pollJob by remember { mutableStateOf<Job?>(null) }
    fun startPolling(orderNo: String) {
        pollJob?.cancel()
        pollJob = scope.launch {
            val deadline = System.currentTimeMillis() + 5 * 60 * 1000L // 5分钟截止
            try {
                while (isActive && System.currentTimeMillis() < deadline) {
                    val data = runCatching { api.queryPayOrder(orderNo) }.getOrNull()
                    if (data != null) {
                        val paid = data["paid"]?.jsonPrimitive?.toString()?.trim('"')?.toBoolean() ?: false
                        val status = data["status"]?.jsonPrimitive?.intOrNull ?: 0
                        lastStatus = status
                        if (paid || status != 0) break                  // 终态退出
                    }
                    delay(2000L)                                        // 2秒一轮
                }
                // 退出后补一次最终状态查询
                val finalData = runCatching { api.queryPayOrder(orderNo) }.getOrNull()
                val finalPaid = finalData?.get("paid")?.jsonPrimitive?.toString()?.trim('"')?.toBoolean() ?: false
                val finalStatus = finalData?.get("status")?.jsonPrimitive?.intOrNull ?: lastStatus
                onOrderSettled(finalPaid, finalStatus, auth, api, context, orderNo)
                vipInfo = fetchVipInfo(api)                     // 支付结束后刷新会员状态（永久会员即时生效）
                // 流程结束：恢复按钮可点，清掉旧单号（防旧单空转）
                paying = false
                pollingOrderNo = null
            } catch (_: CancellationException) { /* 正常取消 */ }
        }
    }
    fun stopPolling() { pollJob?.cancel(); pollJob = null }

    // === 支付完成后从收银台/浏览器回跳（wanyu://pay/result?out_order_no=xxx）恢复轮询 ===
    LaunchedEffect(Unit) {
        val act = context as? Activity ?: return@LaunchedEffect
        val orderNo = act.intent?.getStringExtra(PAY_RETURN_ORDER_EXTRA)?.takeIf { it.isNotEmpty() }
        if (orderNo != null) {
            act.intent.removeExtra(PAY_RETURN_ORDER_EXTRA)   // 消费掉，避免下次进入页面重复查询
            paying = true                                     // 先锁按钮，等待查询结果
            pollingOrderNo = orderNo
            startPolling(orderNo)                             // 继续 2 秒轮询直至终态
        }
    }

    // === onResume 补查（用户从川聊切回 App） ===
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME && pollingOrderNo != null && paying) {
                scope.launch {
                    val data = runCatching { api.queryPayOrder(pollingOrderNo!!) }.getOrNull() ?: return@launch
                    val paid = data["paid"]?.jsonPrimitive?.toString()?.trim('"')?.toBoolean() ?: false
                    val status = data["status"]?.jsonPrimitive?.intOrNull ?: 0
                    lastStatus = status
                    if (status != 0) {                                     // 终态直接停轮询
                        stopPolling()
                        onOrderSettled(paid, status, auth, api, context, pollingOrderNo!!)
                        vipInfo = fetchVipInfo(api)                        // 支付成功后刷新会员状态
                        paying = false
                        pollingOrderNo = null
                    } else {
                        // 用户从收银台返回但还没付款：先放开按钮（可点重新支付），轮询继续
                        paying = false
                    }
                }
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
            stopPolling()
        }
    }

    // === 点击购买按钮 ===
    fun onBuy() {
        if (wanyuUser == null) { toastMsg = "请先登录"; return }
        if (!agreed)          { toastMsg = "请先阅读并同意会员须知"; return }
        if (paying)           { return }
        // 重复购买拦截：已是永久会员时不允许再买任何会员套餐（积分包不受影响）
        if (tab == 0 && vipInfo?.permanent == true) {
            toastMsg = "您已是永久会员，无需重复购买"
            return
        }

        paying = true
        val product = if (tab == 0) VIP_PRODUCTS.first { it.sku == selectedVipSku }
                      else POINTS_PRODUCTS.first { it.sku == selectedPointsSku }
        scope.launch {
            try {
                val data = api.createPayOrder(product.type, product.sku, payType = "APP")
                val payToken = data["pay_token"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
                val payUrl   = data["pay_url"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
                val orderNo  = data["out_order_no"]?.jsonPrimitive?.toString()?.trim('"').orEmpty()
                if (payToken.isEmpty() && payUrl.isEmpty()) {
                    toastMsg = "订单创建失败：未拿到支付信息"
                    return@launch
                }
                pollingOrderNo = orderNo
                // 1. 优先唤起川聊 APP（Intent 方式）
                val intent = Intent(Intent.ACTION_VIEW, android.net.Uri.parse("chuanliao://cashier?token=$payToken"))
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                try {
                    activity?.startActivity(intent)
                } catch (_: ActivityNotFoundException) {
                    // 2. 川聊未安装 → 浏览器打开 pay_url 降级
                    if (payUrl.isNotEmpty()) {
                        context.openUrl(payUrl)
                    } else {
                        toastMsg = "未检测到川聊客户端，且没有可用的网页支付链接"
                        return@launch
                    }
                }
                // 3. 启动 2 秒轮询
                startPolling(orderNo)
            } catch (e: WanyuApiException) {
                toastMsg = e.message
            } catch (e: Throwable) {
                toastMsg = e.message ?: "下单失败"
            }
        }
    }

    // === UI ===
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("商城") },
                navigationIcon = { BackButton() },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Color(0xFF1a1a1a),
                    titleContentColor = Color.White,
                    navigationIconContentColor = Color.White,
                )
            )
        },
        containerColor = MaterialTheme.colorScheme.background,
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
        ) {
            VipBanner(vipInfo = vipInfo, points = wanyuUser?.points ?: 0)

            TabRow(selectedTabIndex = tab, containerColor = Color.Transparent) {
                Tab(selected = tab == 0, onClick = { tab = 0 }, text = { Text("会员") })
                Tab(selected = tab == 1, onClick = { tab = 1 }, text = { Text("积分") })
            }
            Spacer(Modifier.height(12.dp))

            if (tab == 0 && vipInfo?.permanent == true) {
                Text(
                    text = "您已是永久会员，会员套餐无需重复购买",
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
                    fontSize = 12.sp,
                    color = Color(0xFFF5A623),
                )
            }

            if (tab == 0) {
                VipProductsGrid(
                    selectedSku = selectedVipSku,
                    onSelect = { selectedVipSku = it },
                )
                Spacer(Modifier.height(24.dp))
                VipBenefitsGrid()
            } else {
                PointsProductsGrid(
                    selectedSku = selectedPointsSku,
                    onSelect = { selectedPointsSku = it },
                )
                Spacer(Modifier.height(24.dp))
                Text(
                    text = "1 元 = 200 积分 · 仅用于官方 DeepSeek 模型调用",
                    modifier = Modifier.padding(horizontal = 16.dp),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            Spacer(Modifier.height(48.dp))

            PurchaseBar(
                agreed = agreed,
                onAgreeChange = { agreed = !agreed },
                paying = paying,
                product = if (tab == 0) VIP_PRODUCTS.first { it.sku == selectedVipSku }
                         else POINTS_PRODUCTS.first { it.sku == selectedPointsSku },
                disabled = tab == 0 && vipInfo?.permanent == true,   // 永久会员禁用会员套餐购买
                disabledText = "已是永久会员",                        // 禁用时的按钮文案
                onBuy = { onBuy() },
            )

            Spacer(Modifier.height(24.dp))
        }
    }

    // 支付结果全局监听（composable 作用域结束时 stopPolling 已处理）
}

// ================== 订单终态处理 ==================

/** 拉取会员状态（进入页面、支付结束后刷新，失败返回 null 不覆盖已有状态） */
private suspend fun fetchVipInfo(api: WanyuApi): VipUiInfo? {
    val data = runCatching { api.vipStatus() }.getOrNull() ?: return null
    return VipUiInfo(
        isVip = data["is_vip"]?.jsonPrimitive?.toString()?.trim('"')?.toBoolean() ?: false,
        type  = data["type"]?.jsonPrimitive?.toString()?.trim('"') ?: "",
        permanent = data["permanent"]?.jsonPrimitive?.toString()?.trim('"')?.toBoolean() ?: false,
        daysLeft = data["days_left"]?.jsonPrimitive?.intOrNull ?: 0,
        vipName  = data["vip_name"]?.jsonPrimitive?.toString()?.trim('"') ?: "",
        expiresAt = data["expires_at"]?.jsonPrimitive?.toString()?.trim('"').orEmpty(),
    )
}

private suspend fun onOrderSettled(
    paid: Boolean,
    status: Int,
    auth: WanyuAuthRepository,
    api: WanyuApi,
    context: Context,
    orderNo: String,
) {
    // 刷新本地积分与会员状态
    runCatching { auth.refreshProfile() }
    runCatching { api.vipStatus() }
    val orderStatus = OrderStatus.of(status)
    val toast = when {
        paid || orderStatus == OrderStatus.PAID  -> "支付成功"
        orderStatus == OrderStatus.CLOSED        -> "订单已关闭"
        orderStatus == OrderStatus.REFUNDED_FULL -> "订单已全额退款"
        orderStatus == OrderStatus.REFUNDED_PART -> "订单已部分退款"
        orderStatus == OrderStatus.FAILED        -> "支付失败（余额不足等），请重新下单"
        else                                      -> "未支付"
    }
    Toast.makeText(context, toast, Toast.LENGTH_LONG).show()
}

// ================== 会员状态 UI 数据 ==================

private data class VipUiInfo(
    val isVip: Boolean,
    val type: String,
    val permanent: Boolean,
    val daysLeft: Int,
    val vipName: String,
    val expiresAt: String,
)

// ================== UI 组件 ==================

@Composable
private fun VipBanner(vipInfo: VipUiInfo?, points: Int) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .background(Color(0xFF1a1a1a))
            .padding(horizontal = 20.dp, vertical = 24.dp)
    ) {
        Column {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column {
                    Text(
                        text = if (vipInfo?.isVip == true) vipInfo?.vipName.orEmpty() else "点击开通",
                        color = Color.White,
                        fontSize = 22.sp,
                        fontWeight = FontWeight.Bold,
                    )
                    val sub = when {
                        vipInfo?.isVip != true -> "享受全部会员特权"
                        vipInfo?.permanent == true -> "永久会员"
                        else -> "剩余 ${vipInfo?.daysLeft ?: 0} 天 · 到期 ${vipInfo?.expiresAt.orEmpty()}"
                    }
                    Text(text = sub, color = Color(0xFFB0B0B0), fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
                }
                Text(
                    text = "VIP",
                    color = Color(0xFFF5C542),
                    fontSize = 40.sp,
                    fontWeight = FontWeight.Black,
                )
            }
            Text(
                text = "当前积分：$points",
                color = Color(0xFFD0D0D0),
                fontSize = 13.sp,
                modifier = Modifier.padding(top = 8.dp),
            )
        }
    }
}

@Composable
private fun VipProductsGrid(selectedSku: String, onSelect: (String) -> Unit) {
    LazyVerticalGrid(
        columns = GridCells.Fixed(3),
        modifier = Modifier
            .fillMaxWidth()
            .height(108.dp * ((VIP_PRODUCTS.size + 2) / 3) + 36.dp)
            .padding(horizontal = 16.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
        userScrollEnabled = false,
    ) {
        items(VIP_PRODUCTS) { p ->
            VipProductCard(p, selected = p.sku == selectedSku, onClick = { onSelect(p.sku) })
        }
    }
}

@Composable
private fun VipProductCard(product: VipProduct, selected: Boolean, onClick: () -> Unit) {
    val borderColor = if (selected) Color(0xFFF5C542) else Color(0xFFE0E0E0)
    val bgColor     = if (selected) Color(0xFFFFF9E6) else Color.White
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(bgColor)
            .border(width = if (selected) 2.dp else 1.dp, color = borderColor, shape = RoundedCornerShape(12.dp))
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (product.badge.isNotEmpty()) {
            Text(
                text = product.badge,
                color = Color.White,
                fontSize = 9.sp,
                fontWeight = FontWeight.Bold,
                modifier = Modifier
                    .clip(RoundedCornerShape(4.dp))
                    .background(Color(0xFFFF6B6B))
                    .padding(horizontal = 6.dp, vertical = 1.dp),
            )
            Spacer(Modifier.height(4.dp))
        }
        Text(text = "¥${product.amount}", fontSize = 20.sp, fontWeight = FontWeight.Bold, color = Color(0xFF333333))
        Spacer(Modifier.height(2.dp))
        Text(text = product.name, fontSize = 12.sp, color = Color(0xFF555555))
        if (product.subDesc.isNotEmpty()) {
            Spacer(Modifier.height(2.dp))
            Text(text = product.subDesc, fontSize = 10.sp, color = Color(0xFF999999))
        }
    }
}

@Composable
private fun PointsProductsGrid(selectedSku: String, onSelect: (String) -> Unit) {
    LazyVerticalGrid(
        columns = GridCells.Fixed(3),
        modifier = Modifier
            .fillMaxWidth()
            .height(108.dp * ((POINTS_PRODUCTS.size + 2) / 3) + 36.dp)
            .padding(horizontal = 16.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
        userScrollEnabled = false,
    ) {
        items(POINTS_PRODUCTS) { p ->
            PointsProductCard(p, selected = p.sku == selectedSku, onClick = { onSelect(p.sku) })
        }
    }
}

@Composable
private fun PointsProductCard(product: PointsProduct, selected: Boolean, onClick: () -> Unit) {
    val borderColor = if (selected) Color(0xFFF5C542) else Color(0xFFE0E0E0)
    val bgColor     = if (selected) Color(0xFFFFF9E6) else Color.White
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(bgColor)
            .border(width = if (selected) 2.dp else 1.dp, color = borderColor, shape = RoundedCornerShape(12.dp))
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (product.tag.isNotEmpty()) {
            Text(
                text = product.tag,
                color = Color.White,
                fontSize = 9.sp,
                fontWeight = FontWeight.Bold,
                modifier = Modifier
                    .clip(RoundedCornerShape(4.dp))
                    .background(Color(0xFFFF6B6B))
                    .padding(horizontal = 6.dp, vertical = 1.dp),
            )
            Spacer(Modifier.height(4.dp))
        }
        Text(text = "¥${product.amount}", fontSize = 20.sp, fontWeight = FontWeight.Bold, color = Color(0xFF333333))
        Spacer(Modifier.height(2.dp))
        Text(text = product.points.toString() + " 积分", fontSize = 12.sp, color = Color(0xFF555555))
    }
}

@Composable
private fun VipBenefitsGrid() {
    Column(modifier = Modifier.padding(horizontal = 16.dp)) {
        Text(
            text = "会员特权",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.height(12.dp))
        LazyVerticalGrid(
            columns = GridCells.Fixed(3),
            modifier = Modifier.height(88.dp * ((VIP_BENEFITS.size + 2) / 3) + 36.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            userScrollEnabled = false,
        ) {
            items(VIP_BENEFITS) { b ->
                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Box(
                        modifier = Modifier
                            .size(48.dp)
                            .clip(RoundedCornerShape(14.dp))
                            .background(MaterialTheme.colorScheme.secondaryContainer),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(b.icon, contentDescription = null, tint = MaterialTheme.colorScheme.onSecondaryContainer, modifier = Modifier.size(26.dp))
                    }
                    Spacer(Modifier.height(6.dp))
                    Text(text = b.title, fontSize = 12.sp, fontWeight = FontWeight.Medium, color = MaterialTheme.colorScheme.onSurface)
                    Text(text = b.subtitle, fontSize = 10.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(top = 1.dp))
                }
            }
        }
    }
}

@Composable
private fun PurchaseBar(
    agreed: Boolean,
    onAgreeChange: () -> Unit,
    paying: Boolean,
    product: StoreProduct,
    disabled: Boolean,          // 是否禁用购买（如已是永久会员）
    disabledText: String,       // 禁用时按钮文案
    onBuy: () -> Unit,
) {
    Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp)) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Box(
                modifier = Modifier
                    .size(20.dp)
                    .clip(RoundedCornerShape(4.dp))
                    .background(if (agreed) Color(0xFFF5C542) else Color.Transparent)
                    .border(width = 1.5.dp, color = if (agreed) Color(0xFFF5C542) else Color(0xFFCCCCCC), shape = RoundedCornerShape(4.dp))
                    .clickable { if (!paying) onAgreeChange() },
                contentAlignment = Alignment.Center,
            ) {
                if (agreed) Text("✓", color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold)
            }
            Text(
                text = "我已阅读并同意《会员须知》与《隐私政策》",
                fontSize = 12.sp,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.clickable { if (!paying) onAgreeChange() },
            )
        }
        Spacer(Modifier.height(12.dp))
        val canClick = agreed && !paying && !disabled
        val bgColor = when {
            disabled      -> Color(0xFFE0E0E0)      // 不可购买时置灰
            paying        -> Color(0xFFF5C542)      // 支付中保持金色
            agreed        -> Color(0xFFF5C542)
            else          -> Color(0xFFE8C878)      // 未同意时变淡，提示点击
        }
        val textColor = if (disabled) Color(0xFF999999) else Color(0xFF1a1a1a)
        Button(
            onClick = { if (canClick) onBuy() },
            modifier = Modifier
                .fillMaxWidth()
                .height(54.dp),
            shape = RoundedCornerShape(16.dp),
            colors = ButtonDefaults.buttonColors(
                containerColor = bgColor,
                contentColor = textColor,
                disabledContainerColor = bgColor,       // 我们自己控制颜色，disabled 也用同色
                disabledContentColor = textColor,
            ),
            enabled = true,                              // 始终 enabled，点击回调里自己判断
        ) {
            if (paying) {
                CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp, color = Color(0xFF1a1a1a))
                Spacer(Modifier.width(8.dp))
                Text("正在打开收银台...", fontSize = 16.sp, fontWeight = FontWeight.Bold)
            } else {
                Text(
                    text = if (disabled) disabledText else "立即购买 ¥${product.amount}",
                    fontSize = 18.sp,
                    fontWeight = FontWeight.Bold,
                )
            }
        }
    }
}
