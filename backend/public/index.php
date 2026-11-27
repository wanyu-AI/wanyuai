<?php
// 后端统一入口：仅暴露 public/ 目录，所有 API 走这里
declare(strict_types=1);                               // 严格类型
require_once dirname(__DIR__) . '/app/bootstrap.php';  // 加载启动文件

Security::enforceHttps();                              // 强制HTTPS

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; // 取路径
// 兼容三种访问方式：伪静态 /api/v1/...、直接入口 index.php、根目录 api.php
$prefixes = ['/api/v1', '/backend/public/index.php', '/api.php']; // 可剥离的前缀
$route = $path;                                        // 默认不剥离
foreach ($prefixes as $prefix) {                       // 依次尝试
    if (strpos($path, $prefix) === 0) {                // 命中前缀
        $route = substr($path, strlen($prefix));       // 剥离前缀
        break;                                         // 结束匹配
    }
}
$route = '/' . trim($route, '/');                      // 规范化，去掉尾部斜杠

// 原版更新方式：GET 公开 JSON，不签名不登录（客户端启动时直接拉取）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $route === '/version/latest') {
    VersionController::latest();                       // 直接输出 UpdateInfo JSON（内部exit）
}

// 头像图片：公开 GET，不签名不登录（聊天界面直接按 URL 加载）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && strpos($route, '/uploads/avatars/') === 0) {
    UserController::serveAvatar(substr($route, strlen('/uploads/avatars/'))); // 输出图片（内部exit）
}

// 聊天图片（人工审核用）：公开 GET，不签名不登录，审核页直接按 URL 预览
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && strpos($route, '/uploads/chat_images/') === 0) {
    SyncController::serveImage(substr($route, strlen('/uploads/chat_images/'))); // 输出图片（内部exit）
}

// 川聊支付回调接收端：来自川聊服务器，没有客户端签名，必须在 Security::verify() 之前处理
// 川聊回调是 POST 原始请求体，所以放在 requirePostJson() 之前直接读 php://input
if (($route === '/pay/callback' || strpos($route, '/pay/callback') === 0)) {
    PayController::callback();                          // 内部直接 exit，不走后续签名校验
}

Req::requirePostJson();                                // 其余接口只接受POST JSON
Security::verify();                                    // 校验时间戳、设备、签名

$rate = Config::get('rate_limit');                     // 限流配置
RateLimiter::require('api_ip:' . Req::ip(), (int)$rate['api_per_minute'], 60, '接口访问过于频繁'); // 通用IP限流

// ---------- DeepSeek 兼容路径：支持 OpenAI 客户端自动拼接 /chat/completions ----------
$aiPaths = ['/ai/deepseek', '/ai/deepseek/chat/completions', '/ai/deepseek/v1/chat/completions']; // 允许的三种路径
if (in_array($route, $aiPaths, true)) {                // 命中AI转发
    AiController::chat();                              // 直接处理（内部exit）
}

// ---------- 路由表 ----------
$routes = [
    '/auth/email/send-code'  => [AuthController::class, 'sendCode'],   // 发送邮箱验证码
    '/auth/email/verify'     => [AuthController::class, 'verifyCode'], // 邮箱验证码登录/注册
    '/auth/qq'               => [AuthController::class, 'qqLogin'],    // QQ授权登录
    '/auth/bind-email'       => [AuthController::class, 'bindEmail'],  // 补充绑定邮箱
    '/auth/bind-qq'          => [AuthController::class, 'bindQq'],     // 已登录账号绑定QQ
    '/auth/device/send-code' => [AuthController::class, 'sendCode'],   // 新设备验证码（复用发送逻辑）
    '/auth/device/verify'    => [AuthController::class, 'deviceVerify'], // 新设备核验
    '/auth/logout'           => [AuthController::class, 'logout'],     // 退出登录
    '/user/profile'          => [UserController::class, 'profile'],    // 获取用户信息
    '/user/profile/update'   => [UserController::class, 'updateProfile'], // 更新昵称/头像
    '/user/avatar/upload'    => [UserController::class, 'uploadAvatar'], // 上传头像图片
    '/user/risk/status'      => [RiskController::class, 'status'],      // 查询风控状态
    '/user/risk/unfreeze'    => [RiskController::class, 'unfreeze'],    // 邮箱验证解冻
    '/user/risk/appeal'      => [RiskController::class, 'appeal'],      // 提交封禁申诉
    '/user/settings/save'    => [SettingsController::class, 'save'],   // 上传设置备份
    '/user/settings/load'    => [SettingsController::class, 'load'],   // 拉取设置备份
    '/sync/push'             => [SyncController::class, 'push'],       // 上传会话消息
    '/sync/pull'             => [SyncController::class, 'pull'],       // 拉取会话消息
    '/sync/live/push'        => [LiveSyncController::class, 'push'],   // 实时推送单会话变更
    '/sync/live/pull'        => [LiveSyncController::class, 'pull'],   // 实时拉取增量与生成状态
    '/sync/image/upload'     => [SyncController::class, 'uploadImage'], // 上传官方会话中的用户图片（人工审核）
    '/ai/quote'              => [AiController::class, 'quote'],        // 调用前积分提示
    '/points/logs'           => [PointsController::class, 'logs'],     // 积分流水
    '/version/check'         => [VersionController::class, 'check'],   // 版本更新检查
    '/security/report-proxy' => [MiscController::class, 'reportProxy'], // 抓包上报
    '/config/public'         => [MiscController::class, 'publicConfig'], // 客户端公共配置
    '/pay/create'            => [PayController::class, 'create'],       // 创建支付订单（积分 or 会员）
    '/pay/query'             => [PayController::class, 'query'],        // 查询订单状态（客户端每2秒轮询 + onResume补查）
    '/pay/vip/status'        => [PayController::class, 'vipStatus'],    // 查询当前用户会员状态（客户端「我的会员」展示）
];

if (!isset($routes[$route])) {                         // 未匹配路由
    Resp::error(40401, '接口不存在', 404);               // 返回404
}
[$class, $method] = $routes[$route];                   // 取出控制器与方法
$class::$method();                                      // 执行控制器（内部统一响应）
