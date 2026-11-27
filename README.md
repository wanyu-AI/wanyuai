# 挽屿AI

安卓 AI 聊天客户端 + PHP 后端 + 可视化管理后台的完整实现。

客户端基于开源项目 **RikkaHub** 二次开发，增加了账号体系、官方模型积分、跨设备同步、风控与后台管理；后端与后台为独立编写的 PHP 程序（无框架、无 Composer 依赖）。

## 目录结构

```text
.
├── android/          # 安卓客户端源码（Kotlin + Jetpack Compose，Gradle 多模块）
│   ├── app/          # 主模块；挽屿定制代码在 app/src/main/java/me/rerere/rikkahub/wanyu/
│   ├── ai/ common/ ui/ web/ workspace/ terminal/ ...   # 上游模块
│   └── build.gradle.kts / settings.gradle.kts / gradlew
├── backend/          # PHP API 后端
│   ├── app/          # 核心类 + controllers（auth / pay / sync / ai / risk / version）
│   ├── bin/install.php
│   ├── config/config.example.php
│   └── public/index.php        # 唯一入口，站点根目录指向 public
├── admin/            # PHP 管理后台（用户、积分、风控、版本、邮件、图片审核）
├── database/schema.sql
├── docs/             # API.md / DEPLOY.md / INTEGRATION.md
├── LICENSE           # AGPL-3.0
└── NOTICE.md         # 上游来源与修改声明
```

## 技术栈

| 层 | 技术 |
| --- | --- |
| 客户端 | Kotlin、Jetpack Compose、Koin、Room/ObjectBox、OkHttp，minSdk 26 / targetSdk 37 |
| 后端 | PHP 8.1+（pdo_mysql / curl / mbstring / openssl），原生路由，无框架 |
| 数据库 | MySQL 5.7 / 8.0，utf8mb4 |
| 后台 | PHP + 原生 HTML/CSS/JS，TOTP 二次验证 |

## 快速开始

### 1. 后端

```bash
mysql -uroot -p < database/schema.sql
cp backend/config/config.example.php backend/config/config.php   # 填数据库、支付、邮件等
php backend/bin/install.php                                       # 建表、建管理员、生成密钥
```

站点根目录指向 `backend/public`，Nginx 需把 `/api/v1/*` 之类路径交给 `index.php`（详见 `docs/DEPLOY.md`）。

### 2. 管理后台

站点根目录指向 `admin/public`，首次登录用 `install.php` 生成的管理员账号；登录需 TOTP。

### 3. 安卓客户端

```bash
cd android
cp local.properties.example local.properties   # 填 sdk.dir 与签名信息
./gradlew :app:assembleDebug                   # 或 assembleRelease
```

构建前必须自备以下文件（仓库中不包含，也不应提交）：

- `android/local.properties`：SDK 路径与签名口令
- `android/keystore/*.jks`：发布签名证书
- `android/app/google-services.json`：仓库内已是**占位配置**（能编译，但 Firebase 不生效）；正式发布请替换为自己的 Firebase 项目文件

## 必须自己替换的配置

| 位置 | 常量 / 字段 | 说明 |
| --- | --- | --- |
| `android/.../wanyu/WanyuConfig.kt` | `BASE_URL`、`API_BASE`、`SITE_URL` | 后端域名，必须 HTTPS |
| 同上 | `API_SIGN_KEY` | 接口签名密钥，需与后端 `system_configs.api_sign_key` 一致 |
| 同上 | `QQ_APP_ID` | QQ 互联 AppID，需与后端配置一致 |
| `backend/config/config.php` | `db`、`pay.secret`、SMTP | 数据库、支付 AppSecret、邮件账号 |

> `API_SIGN_KEY` 与支付 `secret` 属于敏感信息：本仓库中一律为占位值，部署时自行生成并只放在服务端 / 本地配置，**不要提交回仓库**。

## 文档

- [docs/DEPLOY.md](docs/DEPLOY.md) — 部署步骤（PHP、MySQL、Nginx、安卓打包）
- [docs/API.md](docs/API.md) — 后端接口清单与请求/响应结构
- [docs/INTEGRATION.md](docs/INTEGRATION.md) — 客户端改造点与后端对接说明

## 开源协议

本项目基于 **RikkaHub**（<https://github.com/rikkahub/rikkahub>）二次开发，上游采用 **GNU AGPL-3.0**。因此本仓库整体同样以 **AGPL-3.0** 发布，详见 [LICENSE](LICENSE) 与 [NOTICE.md](NOTICE.md)。

按 AGPL-3.0 要求：分发本软件（含以网络服务形式提供）时，必须向使用者提供完整对应源码，并保留原有版权与协议声明。