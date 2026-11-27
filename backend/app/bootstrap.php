<?php
// 挽屿AI 后端启动文件：加载配置、注册自动加载、统一异常与错误处理
declare(strict_types=1);                               // 开启严格类型，减少隐式类型错误

define('WANYU_ROOT', dirname(__DIR__));                // 后端根目录（backend/）
define('WANYU_APP', WANYU_ROOT . '/app');              // 应用类目录

// ---------- 加载配置文件 ----------
$configFile = WANYU_ROOT . '/config/config.php';       // 实际配置文件路径
if (!is_file($configFile)) {                           // 配置文件不存在时直接报错
    http_response_code(500);                           // 返回500
    exit('缺少 backend/config/config.php，请从 config.example.php 复制并填写'); // 提示部署者
}
$config = require $configFile;                         // 加载配置数组

// ---------- 基本运行设置 ----------
date_default_timezone_set($config['timezone'] ?? 'Asia/Shanghai'); // 设置时区
mb_internal_encoding('UTF-8');                         // 统一多字节编码
ini_set('display_errors', $config['debug'] ? '1' : '0'); // 调试开关
ini_set('log_errors', '1');                            // 始终记录错误日志
error_reporting(E_ALL);                                // 上报所有级别错误

// ---------- 自动加载 app/ 下的类 ----------
spl_autoload_register(function (string $class): void { // 注册自动加载器
    $file = WANYU_APP . '/' . $class . '.php';         // 按类名映射 app/ 下的文件
    if (is_file($file)) {                              // 文件存在才加载
        require_once $file;                            // 载入类文件
        return;                                        // 已加载，结束
    }
    $controller = WANYU_APP . '/controllers/' . $class . '.php'; // 控制器目录回退
    if (is_file($controller)) {                        // 控制器文件存在
        require_once $controller;                      // 载入控制器类
    }
});

// ---------- 载入异常类 ----------
require_once WANYU_APP . '/ApiException.php';          // 业务异常类
require_once WANYU_APP . '/Config.php';                // 配置读取类

Config::init($config);                                 // 初始化配置单例

// ---------- 统一异常处理 ----------
set_exception_handler(function (Throwable $e): void { // 捕获未处理异常
    if ($e instanceof ApiException) {                  // 业务异常按约定格式返回
        $extra = $e->data ? ['data' => $e->data] : [];  // 附加数据（风控详情）
        Resp::error($e->getCode(), $e->getMessage(), $e->httpStatus, $extra); // 输出业务错误
        return;                                        // 结束
    }
    Log::error('unhandled_exception', [                // 记录未知异常
        'message' => $e->getMessage(),                 // 异常信息
        'file'    => $e->getFile(),                    // 文件
        'line'    => $e->getLine(),                    // 行号
        'trace'   => $e->getTraceAsString(),           // 堆栈
    ]);
    $msg = Config::get('debug') ? $e->getMessage() : '服务器内部错误'; // 生产环境隐藏细节
    Resp::error(500, $msg, 500);                       // 返回500
});

// ---------- 统一错误转换 ----------
set_error_handler(function (int $no, string $str, string $file, int $line): bool { // 将PHP错误转异常
    if (!(error_reporting() & $no)) {                  // 被 @ 抑制的错误直接忽略
        return false;                                  // 交回PHP处理
    }
    throw new ErrorException($str, 0, $no, $file, $line); // 抛出异常
});

// ---------- 引入公共工具 ----------
require_once WANYU_APP . '/Db.php';                    // 数据库
require_once WANYU_APP . '/Resp.php';                  // 响应
require_once WANYU_APP . '/Req.php';                   // 请求
require_once WANYU_APP . '/Log.php';                   // 日志
require_once WANYU_APP . '/Security.php';              // 安全校验
require_once WANYU_APP . '/RateLimiter.php';           // 限流
require_once WANYU_APP . '/Mailer.php';                // 邮件
require_once WANYU_APP . '/Auth.php';                  // 用户鉴权
require_once WANYU_APP . '/Points.php';                // 积分
require_once WANYU_APP . '/DeepSeek.php';              // DeepSeek 中转
require_once WANYU_APP . '/AdminAuth.php';             // 后台管理员鉴权
