<?php
// 挽屿AI 后端配置示例文件
// 使用方式：复制为 config.php 并填写实际值；config.php 不要提交到公开仓库
return [
    // ---------- 数据库 ----------
    'db' => [
        'host'     => '127.0.0.1',        // MySQL 主机
        'port'     => 3306,               // MySQL 端口
        'database' => 'wanyu_ai',         // 数据库名
        'username' => 'wanyu',            // 数据库账号
        'password' => 'change_me',        // 数据库密码
        'charset'  => 'utf8mb4',          // 字符集
    ],
    // ---------- 运行环境 ----------
    'debug'        => false,              // 生产环境必须为 false，避免泄露堆栈
    'timezone'     => 'Asia/Shanghai',    // 时区
    'app_name'     => '挽屿AI',            // 应用名
    'token_days'   => 90,                 // 登录令牌有效期（天）
    // ---------- 限流参数 ----------
    'rate_limit' => [
        'send_code_per_minute' => 1,      // 同一邮箱每分钟最多发送1次验证码
        'send_code_per_day'    => 10,     // 同一邮箱每天最多10次
        'verify_per_minute'    => 5,      // 同一IP每分钟最多5次验证
        'api_per_minute'       => 120,    // 同一IP每分钟通用接口上限
        'ai_per_minute'        => 30,     // 同一用户每分钟AI调用上限
    ],
    // ---------- 日志 ----------
    'log_path' => __DIR__ . '/../storage/logs', // 日志目录，需要写权限
    // ---------- 支付（川聊 im.si888.cn）----------
    // ⚠️ AppSecret 只允许存在于这个 config.php 文件，禁止写入 APK、禁止提交公开仓库、禁止打印日志
    // 付款人 uid 一律取自当前登录用户绑定的川聊 UID，禁止在配置里写死 uid
    'pay' => [
        'appid'     => 'apc7a4e2fcf5c9',           // 川聊分配的 AppID
        'secret'    => 'change_me',                // AppSecret（敏感！只放服务端，禁止进客户端与仓库）
        'base_url'  => 'http://im.si888.cn',        // 川聊支付网关根地址
        'notify_url'=> '',                          // 川聊异步回调地址，留空则用默认 /backend/public/index.php/pay/callback
    ],
    // ---------- 安全 ----------
    'force_https' => true,                // 强制HTTPS，反向代理时也生效
    'trust_proxy' => false,               // 是否信任 X-Forwarded-For（CDN后面可开启）
];
