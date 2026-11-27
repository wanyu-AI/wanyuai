<?php
// 后台统一布局：左侧固定菜单 + 顶部面包屑 + 内容区
$activePage = $activePage ?? 'dashboard';               // 当前菜单
$pageTitle = $pageTitle ?? '仪表盘';                     // 页面标题
$breadcrumb = $breadcrumb ?? ['仪表盘'];                 // 面包屑
$flash = get_flash();                                   // 一次性提示
$menu = [
    'dashboard'     => '仪表盘',                         // 仪表盘
    'users'         => '用户管理',                       // 用户管理
    'risk'          => '账号风控',                       // 账号风控
    'security_logs' => '安全日志',                       // 安全日志
    'mail'          => '邮箱配置',                       // 邮箱配置
    'points'        => '积分配置',                       // 积分配置
    'ai'            => 'AI参数配置',                     // AI参数
    'version'       => '版本更新管理',                   // 版本管理
    'point_logs'    => '积分流水日志',                   // 积分流水
    'conversations' => '会话记录',                       // 会话记录
    'image_review'  => '图片审核',                       // 图片审核
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> - 挽屿AI管理后台</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark">挽</div>
        <div class="brand-text">
            <strong>挽屿AI</strong>
            <span>管理后台</span>
        </div>
    </div>
    <nav class="menu">
        <?php foreach ($menu as $key => $label): ?>
            <a class="menu-item <?= $key === $activePage ? 'active' : '' ?>" href="index.php?page=<?= h($key) ?>">
                <span class="menu-dot"></span><?= h($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="admin-name"><?= h($admin['username'] ?? '') ?></div>
        <a href="logout.php">退出登录</a>
    </div>
</aside>
<main class="main">
    <header class="topbar">
        <div class="breadcrumb">
            <?php foreach ($breadcrumb as $index => $item): ?>
                <?php if ($index > 0): ?><span class="sep">/</span><?php endif; ?>
                <span><?= h($item) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="topbar-title">挽屿AI管理后台</div>
    </header>
    <section class="content">
        <?php if ($flash): ?>
            <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </section>
</main>
<script src="assets/admin.js"></script>
</body>
</html>
