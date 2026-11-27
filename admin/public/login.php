<?php
// 后台登录页：密码 + 时间戳签名 + CSRF + 限流
require_once dirname(__DIR__) . '/app/bootstrap.php';  // 加载后台启动文件
AdminAuth::startSession();                              // 启动会话
if (AdminAuth::current()) {                             // 已登录
    header('Location: index.php');                      // 跳转首页
    exit;                                               // 结束
}
$error = '';                                            // 错误提示
if ($_SERVER['REQUEST_METHOD'] === 'POST') {            // 处理登录
    try {
        AdminAuth::verifyCsrf($_POST['csrf'] ?? null);  // 校验CSRF
        $username = trim((string)($_POST['username'] ?? '')); // 用户名
        $password = (string)($_POST['password'] ?? ''); // 密码
        if ($username === '' || $password === '') {     // 必填校验
            throw new ApiException(40050, '请输入账号和密码'); // 拒绝
        }
        AdminAuth::login($username, $password);         // 登录
        header('Location: index.php');                  // 成功跳转
        exit;                                           // 结束
    } catch (ApiException $e) {                         // 业务异常
        $error = $e->getMessage();                      // 显示错误
    } catch (Throwable $e) {                            // 其他异常
        Log::error('admin_login_exception', ['message' => $e->getMessage()]); // 记录
        $error = '登录失败，请稍后重试';                    // 提示
    }
}
$csrf = AdminAuth::csrf();                              // CSRF令牌
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 - 挽屿AI管理后台</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
<div class="login-card">
    <h1>挽屿AI管理后台</h1>
    <p class="sub">请使用管理员账号登录</p>
    <?php if ($error !== ''): ?><div class="login-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div class="field">
            <label>管理员账号</label>
            <input type="text" name="username" autocomplete="username" required>
        </div>
        <div class="field">
            <label>密码</label>
            <input type="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary" style="width:100%;height:40px;" type="submit">登录</button>
    </form>
    <div class="login-foot">挽屿AI · 官方网站管理后台</div>
</div>
</body>
</html>
