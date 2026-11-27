<?php
// 后台路由入口：校验登录与角色，加载页面并套用统一布局
require_once dirname(__DIR__) . '/app/bootstrap.php';  // 加载后台启动
$admin = AdminAuth::require();                          // 必须为管理员
$page = preg_replace('/[^a-z_]/', '', (string)($_GET['page'] ?? 'dashboard')); // 清洗页面名
$allowed = ['dashboard', 'users', 'risk', 'security_logs', 'mail', 'points', 'ai', 'version', 'point_logs', 'conversations', 'image_review']; // 允许页面
if (!in_array($page, $allowed, true)) {                 // 非法页面
    $page = 'dashboard';                                // 回到仪表盘
}
$activePage = $page;                                    // 当前菜单
$pageTitle = '仪表盘';                                   // 默认标题
$breadcrumb = ['仪表盘'];                                // 默认面包屑
ob_start();                                             // 开启输出缓冲
require dirname(__DIR__) . '/pages/' . $page . '.php';  // 加载页面逻辑
$content = ob_get_clean();                              // 取得页面内容
require dirname(__DIR__) . '/app/layout.php';           // 渲染布局
