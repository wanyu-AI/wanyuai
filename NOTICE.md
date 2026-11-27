# NOTICE — 来源与修改声明

## 上游项目

- 名称：RikkaHub
- 仓库：https://github.com/rikkahub/rikkahub
- 版权：Copyright (C) RikkaHub 作者及贡献者
- 协议：GNU Affero General Public License v3.0（全文见 LICENSE）

本仓库的 `android/` 目录是在 RikkaHub 源码（3.x 主线，当前基线 versionName 3.5.0 / versionCode 218）基础上的修改版本。

## 本项目所做的修改

1. **品牌与包名**：应用名改为「挽屿AI」，`applicationId` 改为 `com.ai.wanyu`，替换图标与关于页信息。
2. **账号体系**（`app/src/main/java/me/rerere/rikkahub/wanyu/`）：
   - 邮箱验证码 + 密码登录、QQ 互联登录（`WanyuAuthRepository`、`WanyuQqAuth`）
   - 会话持久化与登出（`WanyuSession`、`AuthStore`）
   - 接口签名（`WanyuSigner`、`WanyuSigningInterceptor`）、设备指纹与代理检测（风控）
3. **官方模型与积分**：内置「官方 DeepSeek」提供商，走服务端转发并按积分计费（`WanyuConfig.officialProvider()`、`WanyuChatAudit`）。
4. **云端同步**：会话与设置的跨设备同步、实时同步（`WanyuSyncManager`、`WanyuLiveSyncManager`，默认关闭）。
5. **界面**：登录页、积分商城页、风控提示面板（`wanyu/ui/`），以及聊天页、设置页、抽屉等处的接入改动。
6. **配套服务端**（本仓库 `backend/`、`admin/`、`database/`）：PHP API、管理后台、MySQL 表结构，均为本项目原创，不使用上游代码。

上游代码的版权与协议声明未被移除；如与上游文件存在差异，以本仓库为准，差异原因如上。

## 使用限制提醒

AGPL-3.0 为强 copyleft 协议：修改后分发（包括作为网络服务对外提供）时，必须

- 保留本协议与版权声明；
- 向接收者提供完整的对应源码（本仓库即为对应源码）；
- 不得附加限制接收者行使协议所授予权利的条款。

若需闭源商用，应与上游版权方另行取得授权，或仅将上游代码作为独立进程/服务调用（是否构成衍生作品需自行评估）。