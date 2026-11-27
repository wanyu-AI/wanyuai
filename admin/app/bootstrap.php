<?php
// 管理后台启动文件：复用后端类库，并改为 HTML 异常处理
declare(strict_types=1);                               // 严格类型
require_once dirname(__DIR__, 2) . '/backend/app/bootstrap.php'; // 加载后端公共库
Security::enforceHttps();                              // 后台强制HTTPS
if (!headers_sent()) {                                 // 设置安全头
    header('X-Frame-Options: DENY');                   // 禁止被嵌套
    header('X-Content-Type-Options: nosniff');         // 禁止嗅探
    header('Cache-Control: no-store, no-cache, must-revalidate'); // 禁止缓存
}
// 覆盖后端的JSON异常处理，后台使用HTML提示
set_exception_handler(function (Throwable $e): void {  // 捕获未处理异常
    $msg = Config::get('debug') ? $e->getMessage() : '服务器内部错误'; // 生产隐藏细节
    Log::error('admin_exception', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]); // 记录
    http_response_code(500);                            // 500状态
    echo '<!doctype html><meta charset="utf-8"><title>出错了</title>'; // 简易页面
    echo '<div style="font-family:sans-serif;padding:40px;">'; // 容器
    echo '<h3>操作失败</h3><p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p>'; // 错误信息
    echo '<p><a href="javascript:history.back()">返回上一页</a></p></div>'; // 返回
    exit;                                               // 结束
});

require_once __DIR__ . '/helpers.php';                  // 后台辅助函数
