<?php
// 后台管理员鉴权类：会话、角色、签名、CSRF、限流
class AdminAuth
{
    public static function startSession(): void         // 启动安全会话
    {
        if (session_status() === PHP_SESSION_ACTIVE) {  // 已启动则跳过
            return;                                     // 结束
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); // 仅HTTPS
        if (PHP_VERSION_ID >= 70300) {                  // PHP 7.3+ 支持数组参数与 SameSite
            session_set_cookie_params([
                'lifetime' => 0,                        // 浏览器会话周期
                'path'     => '/',                      // 全站有效
                'secure'   => $secure,                  // 仅HTTPS
                'httponly' => true,                     // 禁止JS读取
                'samesite' => 'Lax',                    // 防跨站提交
            ]);
        } else {                                        // PHP 7.2 兼容写法
            session_set_cookie_params(0, '/', '', $secure, true); // 传统参数形式
        }
        session_name('WANYU_ADMIN');                    // 自定义会话名
        session_start();                                // 启动
    }

    public static function current(): ?array            // 当前登录管理员
    {
        self::startSession();                           // 确保会话启动
        $id = (int)($_SESSION['admin_id'] ?? 0);         // 读取会话ID
        if ($id <= 0) {                                 // 未登录
            return null;                                // 返回空
        }
        $row = Db::fetch('SELECT * FROM admin_users WHERE id = ? AND status = 1', [$id]); // 查询管理员
        if (!$row || $row['role'] !== 'admin') {        // 必须是有效管理员
            return null;                                // 返回空
        }
        return $row;                                    // 返回管理员
    }

    public static function login(string $username, string $password): array // 登录校验
    {
        self::startSession();                           // 启动会话
        RateLimiter::require('admin_login:' . Req::ip() . ':' . md5($username), 5, 600, '后台登录尝试过多'); // 10分钟5次
        $row = Db::fetch('SELECT * FROM admin_users WHERE username = ?', [$username]); // 查询账号
        if (!$row || (int)$row['status'] !== 1 || $row['role'] !== 'admin') { // 账号不存在/禁用/非管理员
            Log::security('admin_login_fail', 'warning', '后台登录失败：' . $username); // 记录
            throw new ApiException(40110, '账号或密码错误', 401); // 统一提示
        }
        if (!password_verify($password, $row['password_hash'])) { // 校验密码
            Log::security('admin_login_fail', 'warning', '后台密码错误：' . $username); // 记录
            throw new ApiException(40111, '账号或密码错误', 401); // 统一提示
        }
        session_regenerate_id(true);                    // 防会话固定
        $_SESSION['admin_id'] = (int)$row['id'];        // 写入会话
        $_SESSION['admin_name'] = $row['username'];     // 写入名称
        $_SESSION['csrf'] = bin2hex(random_bytes(24));  // 生成CSRF令牌
        Log::security('admin_login', 'info', '管理员登录：' . $username, null); // 记录成功
        return $row;                                    // 返回管理员
    }

    public static function logout(): void               // 退出登录
    {
        self::startSession();                           // 启动会话
        $_SESSION = [];                                 // 清空数据
        if (ini_get('session.use_cookies')) {           // 使用Cookie时
            $p = session_get_cookie_params();           // 读取参数
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); // 删除Cookie
        }
        session_destroy();                              // 销毁会话
    }

    public static function require(): array             // 必须登录
    {
        $admin = self::current();                       // 取当前管理员
        if (!$admin) {                                  // 未登录/无权限
            header('Location: login.php');              // 跳转登录页
            exit;                                       // 结束
        }
        return $admin;                                  // 返回管理员
    }

    public static function csrf(): string               // 获取CSRF令牌
    {
        self::startSession();                           // 启动会话
        if (empty($_SESSION['csrf'])) {                 // 不存在则生成
            $_SESSION['csrf'] = bin2hex(random_bytes(24)); // 生成
        }
        return $_SESSION['csrf'];                       // 返回
    }

    public static function verifyCsrf(?string $token): void // 校验CSRF
    {
        self::startSession();                           // 启动会话
        if (!$token || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) { // 对比
            throw new ApiException(40310, '表单已过期，请刷新页面', 403); // 拒绝
        }
    }
}
