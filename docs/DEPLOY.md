# 挽屿AI 部署说明

本文档覆盖 PHP 后端、MySQL、管理后台、安卓客户端的部署步骤。

## 1. 环境要求

| 组件 | 最低要求 | 说明 |
| --- | --- | --- |
| PHP | 8.1+ | 需要 `pdo_mysql`、`curl`、`mbstring`、`openssl` 扩展 |
| MySQL | 5.7+ / 8.0 | 字符集 `utf8mb4`，引擎 InnoDB |
| Nginx / Apache | 任意稳定版 | 必须配置 HTTPS，并支持 `try_files` 或 `.htaccess` |
| 安卓构建 | JDK 17 + Android SDK 36 | 与 RikkaHub 原项目一致 |

PHP 相关建议配置（`php.ini`）：

```ini
max_execution_time = 300        ; 官方模型流式转发需要较长执行时间
memory_limit = 256M
date.timezone = Asia/Shanghai
```

## 2. 目录结构

```text
.
├── android\                     # 安卓客户端源码（基于 RikkaHub，含挽屿定制模块）
├── backend\                     # API 后端（PHP，无框架）
│   ├── app\                     # 核心类与控制器
│   ├── bin\install.php          # 安装脚本（建表、建管理员、生成密钥）
│   ├── config\config.example.php
│   └── public\index.php         # 唯一入口（站点根目录指向 public）
├── admin\                       # 可视化后台（PHP）
│   └── public\                  # 后台站点根目录
├── database\schema.sql          # 建表脚本 + 初始配置
└── docs\                        # 部署 / 接口 / 集成文档
```



## 3. 数据库部署

### 3.1 新建数据库

```sql
CREATE DATABASE wanyu_ai DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wanyu'@'127.0.0.1' IDENTIFIED BY '你的数据库密码';
GRANT ALL PRIVILEGES ON wanyu_ai.* TO 'wanyu'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### 3.2 配置文件

```bash
cp backend/config/config.example.php backend/config/config.php
```

修改 `config.php` 中的 `db` 段，并确认：

- `debug` 生产必须为 `false`
- `force_https` 生产必须为 `true`
- 反向代理/ CDN 场景把 `trust_proxy` 设为 `true`
- `log_path` 目录必须存在且 PHP 有写权限

### 3.3 执行安装脚本

```bash
php backend/bin/install.php admin '你的管理员密码'
```

脚本会：

1. 导入 `database/schema.sql` 全部表结构
2. 创建/更新管理员账号（`admin_users`）
3. 若 `api_sign_key` / `admin_sign_key` 仍是占位值，自动生成 64 位随机密钥并打印

> 记下打印出的 `api_sign_key`，安卓端 `WanyuConfig.API_SIGN_KEY` 必须与它完全一致。

### 3.4 已有数据库升级（重要）

`schema.sql` 使用 `CREATE TABLE IF NOT EXISTS`，不会给已存在的表加列。
如果数据库是早期版本创建的，请手动补执行：

```sql
ALTER TABLE `users`
  ADD COLUMN `settings_json` LONGTEXT DEFAULT NULL AFTER `last_login_at`;
```

该列用于「模型配置」等客户端设置的跨设备同步。

## 4. Nginx 配置

### 4.1 API 站点 `api.wanyu-ai.com`

```nginx
server {
    listen 443 ssl http2;
    server_name api.wanyu-ai.com;

    ssl_certificate     /etc/ssl/wanyu/fullchain.pem;
    ssl_certificate_key /etc/ssl/wanyu/privkey.pem;

    root  /var/www/wanyu/backend/public;
    index index.php;

    # 所有请求交给入口文件，index.php 内部按 /api/v1/... 路由
    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include        fastcgi_params;
        fastcgi_pass   unix:/run/php/php8.1-fpm.sock;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
        # 流式响应必须关闭缓冲
        fastcgi_buffering off;
    }

    # 禁止访问配置与源码目录
    location ~ /(config|app|storage|bin)/ {
        deny all;
    }
}
```

### 4.2 后台站点 `admin.wanyu-ai.com`

```nginx
server {
    listen 443 ssl http2;
    server_name admin.wanyu-ai.com;
    root  /var/www/wanyu/admin/public;
    index index.php;

    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ {
        include       fastcgi_params;
        fastcgi_pass  unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~ /(app|pages)/ { deny all; }
}
```

Apache 场景直接启用 `backend/public/.htaccess`（需 `mod_rewrite`），后台同理。

## 5. 后台初始化配置

访问 `https://admin.wanyu-ai.com/login.php`，用安装脚本创建的管理员登录，然后依次配置：

| 菜单 | 需要填写 |
| --- | --- |
| AI 参数配置 | `deepseek_api_key`（官方模型密钥，仅存服务端）、`deepseek_base_url`、`deepseek_model`、`tokens_per_point` |
| 邮箱配置 | `smtp_host`、`smtp_port`、`smtp_user`、`smtp_pass`、`smtp_secure`（ssl/tls）、`smtp_from`、`smtp_from_name` |
| 积分配置 | 注册赠送积分 `register_bonus_points`、换算比例 |
| 版本更新管理 | 版本号、版本名、更新说明、HTTPS 下载地址、是否强制 |
| 安全相关 | `qq_app_id`、`qq_app_key`、`device_verify_enabled` |

## 6. 安卓客户端配置

打开 `android/app/src/main/java/me/rerere/rikkahub/wanyu/WanyuConfig.kt`，修改：

```kotlin
const val BASE_URL = "https://api.wanyu-ai.com"     // 后端域名（必须 HTTPS）
const val API_SIGN_KEY = "与后端 api_sign_key 一致"
const val QQ_APP_ID = "QQ 开放平台 AppID"
val CERT_PINS = listOf("sha256/你的证书公钥指纹")    // 发布前必须填写
```

同时修改 `app/build.gradle.kts` 中的 `manifestPlaceholders["qqAppId"]`，必须与 `WanyuConfig.QQ_APP_ID` 一致。

证书指纹获取方式见 [INTEGRATION.md](INTEGRATION.md#4-ssl-pinning-证书指纹)。

## 7. 定时清理（建议）

```cron
# 每天凌晨 3 点清理过期数据
0 3 * * * mysql -u wanyu -p'密码' wanyu_ai -e "\
DELETE FROM rate_limits WHERE window_start < UNIX_TIMESTAMP() - 86400;\
DELETE FROM verification_codes WHERE expires_at < NOW() - INTERVAL 7 DAY;\
DELETE FROM user_tokens WHERE expires_at < NOW();"
```

## 8. 部署自检清单

- [ ] `https://api.wanyu-ai.com/api/v1/config/public` 返回 `code=0`
- [ ] 后台能登录，9 个菜单可正常打开
- [ ] 后台已填 SMTP，App 能收到验证码
- [ ] 后台已填 DeepSeek Key，用官方模型能正常对话并扣积分
- [ ] 用户自定义模型仍可直连第三方（不经过本后端、不扣积分）
- [ ] `https://api.wanyu-ai.com/api/v1/version/latest` 返回版本 JSON（无需签名）
- [ ] 版本更新管理新增版本后，旧版 App 打开聊天抽屉能看到更新卡片（`UpdateCard`）
- [ ] 后台把版本设为强制时，更新卡片无法关闭
- [ ] `backend/config/config.php`、`backend/storage` 不可被公网访问

## 9. 安全注意事项

1. `backend/config/config.php` 内含数据库密码，禁止提交到公开仓库。
2. 生产环境 `debug` 必须为 `false`，避免异常堆栈泄露。
3. 官方 DeepSeek 密钥只存在 `system_configs.deepseek_api_key`，客户端永远拿不到。
4. 签名密钥 `api_sign_key` 泄露等于任何人都能调用接口，请定期轮换（轮换后需发版同步客户端）。
5. 安装完成后建议删除或移走 `backend/bin/install.php`，或确保站点根目录不包含 `bin/`。
