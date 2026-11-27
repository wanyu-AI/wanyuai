# 挽屿AI 安卓端集成说明

本文档记录基于 RikkaHub 真实源码的全部改造点、QQ 官方 SDK 接入方式与安全配置。

> 所有路径均相对仓库内的 `android/` 目录。
> `android/` 目录是早期草稿，**不参与构建**，请忽略。

## 1. 新增文件（`me.rerere.rikkahub.wanyu` 包）

| 文件 | 作用 |
| --- | --- |
| `app/src/main/java/me/rerere/rikkahub/wanyu/WanyuConfig.kt` | 后端域名、签名密钥、QQ AppID、官方模型 ID、证书指纹 |
| `.../wanyu/WanyuModels.kt` | `WanyuUser` / `WanyuLoginResult` / `WanyuApiException` |
| `.../wanyu/WanyuDevice.kt` | 设备指纹（UUID）、设备名、版本号；代理/抓包检测 |
| `.../wanyu/WanyuSession.kt` | 登录令牌与用户资料（SharedPreferences） |
| `.../wanyu/WanyuSigner.kt` | 与后端 `Security::canonical` 一致的签名实现 |
| `.../wanyu/WanyuSigningInterceptor.kt` | 仅对后端域名追加时间戳/设备/签名/令牌，并做代理阻断 |
| `.../wanyu/WanyuApi.kt` | 全部后端接口封装（POST JSON） |
| `.../wanyu/WanyuQqAuth.kt` | QQ 官方 SDK 封装（登录、回调、退出） |
| `.../wanyu/WanyuSyncManager.kt` | 设置、会话、消息三类数据同步 |
| `.../wanyu/WanyuAuthRepository.kt` | 登录门禁、资料刷新、设备核验、设置变更上传 |
| `.../wanyu/di/WanyuModule.kt` | Koin 依赖注入 |
| `.../wanyu/ui/WanyuLoginPage.kt` | 邮箱验证码 + QQ 登录页 |
| `.../wanyu/ui/WanyuComponents.kt` | 积分徽标、官方模型确认框、账号面板、设备核验弹窗 |

## 2. 修改的真实源码文件

| 文件 | 改动内容 |
| --- | --- |
| `app/src/main/java/me/rerere/rikkahub/data/datastore/DefaultProviders.kt` | 官方 DeepSeek 提供商加入 `DEFAULT_PROVIDERS` 首位 |
| `.../data/datastore/SettingsNormalization.kt` | 归一整定时把官方提供商固定提到最顶部 |
| `.../ui/components/ai/ModelList.kt` | 模型选择页最顶部新增独立「官方DeepSeek模型」区块；位置计算与徽标行同步调整 |
| `.../di/DataSourceModule.kt` | 全局 OkHttp 增加 `CertificatePinner`、`WanyuSigningInterceptor`；日志脱敏 `Authorization` |
| `.../di/AppModule.kt` | `UpdateChecker` 注入 OkHttp `client`（与原版一致） |
| `.../utils/UpdateChecker.kt` | 沿用原版方式：GET 静态 JSON（地址指向 `WanyuConfig.UPDATE_URL`），客户端自行比较版本号 |
| `.../ui/components/ui/UpdateCard.kt` | 保留原生更新卡片；新增可选 `isForce`，为 true 时卡片不可关闭 |
| `app/src/main/java/me/rerere/rikkahub/RikkaHubApp.kt` | 注册 `wanyuModule`，启动后初始化 QQ SDK |
| `.../RouteActivity.kt` | 未登录显示登录页；陌生设备核验弹窗；转发 QQ `onActivityResult` |
| `.../ui/components/ai/ChatInput.kt` | 模型按钮旁显示积分徽标；点击打开账号面板 |
| `.../ui/pages/chat/ChatPage.kt` | 官方模型发送前弹积分确认框 |
| `app/src/main/AndroidManifest.xml` | 禁止明文流量、抓包工具 `<queries>`、QQ SDK Activity、网络状态权限 |
| `app/build.gradle.kts` | `manifestPlaceholders["qqAppId"]` |
| `app/src/main/res/values*/strings.xml` | `app_name` 全部改为「挽屿AI」（8 个语言目录） |
| `app/libs/open_sdk_lite.jar` | QQ 官方 SDK V3.5.19 |

## 3. QQ 官方 SDK 接入

### 3.1 准备依赖

1. 从 QQ 互联开放平台下载 **Android SDK V3.5.19**（文件 `open_sdk_lite.jar`）。
2. 放入 `app/libs/open_sdk_lite.jar`。项目已使用 `implementation(fileTree("libs"))`，无需额外声明依赖。

### 3.2 开放平台配置

在 QQ 互联控制台创建应用，记录：

- `AppID` → 后端 `system_configs.qq_app_id`，同时填入 `WanyuConfig.QQ_APP_ID`
- `AppKey` → 后端 `system_configs.qq_app_key`
- 包名：`me.rerere.rikkahub`（debug 为 `me.rerere.rikkahub.debug`）
- 签名：填写发布证书的 MD5（未填写会导致授权失败）

### 3.3 Manifest

```xml
<!-- 授权回调入口：scheme 必须是 tencent+AppID -->
<activity
  android:name="com.tencent.tauth.AuthActivity"
  android:exported="true"
  android:launchMode="singleTask">
  <intent-filter>
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="tencent${qqAppId}" />
  </intent-filter>
</activity>

<activity
  android:name="com.tencent.connect.common.AssistActivity"
  android:configChanges="orientation|keyboardHidden|screenSize"
  android:exported="false"
  android:theme="@android:style/Theme.Translucent.NoTitleBar" />
```

`app/build.gradle.kts`：

```kotlin
defaultConfig {
    manifestPlaceholders["qqAppId"] = "你的QQ AppID"
}
```

### 3.4 初始化与回调

- `RikkaHubApp` 在 `startKoin` 之后调用 `WanyuQqAuth.init(this)`
- `RouteActivity.onActivityResult()` 必须转发：

```kotlin
override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
    super.onActivityResult(requestCode, resultCode, data)
    WanyuQqAuth.onActivityResult(requestCode, resultCode, data)
}
```

- 登录成功拿到 `openid` + `access_token` 后调用后端 `/auth/qq`，由服务端二次校验，客户端不直接信任 SDK 返回值。
- 退出登录时调用 `WanyuQqAuth.logout(context)` 清理 QQ 会话。

## 4. SSL Pinning 证书指纹

1. 在服务器上获取证书公钥指纹：

```bash
openssl s_client -servername api.wanyu-ai.com -connect api.wanyu-ai.com:443 </dev/null 2>/dev/null \
  | openssl x509 -pubkey -noout \
  | openssl pkey -pubin -outform der \
  | openssl dgst -sha256 -binary \
  | openssl enc -base64
```

2. 在 `WanyuConfig.CERT_PINS` 中填写：

```kotlin
val CERT_PINS = listOf("sha256/上面输出的Base64")
```

3. 建议同时保留备用指纹（证书轮换时用），例如中间 CA 公钥指纹。

> 留空表示不启用锁定。正式发布前必须填写，否则不满足「强制 HTTPS + 防中间人」要求。

## 5. 官方模型与积分的判断逻辑

- 官方提供商 ID：`WanyuConfig.OFFICIAL_PROVIDER_ID`
- 官方模型 ID：`WanyuConfig.OFFICIAL_MODEL_ID`
- 只有 `model.id == OFFICIAL_MODEL_ID` 才走后端并扣积分
- 用户自定义模型保持 RikkaHub 原版行为：直接请求第三方地址，不经过后端、不扣积分
- 官方提供商的 `baseUrl` 指向 `/api/v1/ai/deepseek/v1`，RikkaHub 自动拼接 `/chat/completions`
- 官方模型 `apiKey` 只是占位符，真实密钥仅存在后端，客户端拿不到

## 6. 设置（模型配置）跨设备同步

1. 登录成功后 `WanyuSyncManager.syncNow()` 先调用 `reconcileSettings()`
2. 云端为空 → 上传本地 `Settings` 序列化结果
3. 云端有数据 → 反序列化后写回 `SettingsStore`
4. 登录期间持续监听 `settingsFlow`，2 秒防抖后上传，保证换设备能拿到最新模型配置

> `Settings` 包含第三方模型的 API Key，属于用户自己的配置；官方 DeepSeek 密钥不在其中。

## 7. 安全机制清单

| 机制 | 实现位置 |
| --- | --- |
| 全 HTTPS | `WanyuConfig.BASE_URL` 强制 https；Manifest `usesCleartextTraffic=false` |
| SSL Pinning | `di/DataSourceModule.kt` 的 `CertificatePinner` |
| 设备指纹 | `WanyuDevice.deviceId`，随请求头发送 |
| 时间戳校验 | 请求头 `X-Timestamp`，后端 60 秒窗口 |
| 签名校验 | `WanyuSigner` + 后端 `Security::verify` |
| 代理/抓包检测 | `WanyuProxyDetector`，命中即抛异常阻断 |
| 抓包工具检测 | `<queries>` 声明 + `PackageManager` 查询 |
| 敏感接口禁缓存 | 后端统一 `Cache-Control: no-store` |
| 陌生设备核验 | `WanyuDeviceVerifyDialog` + 后端 `40303` |
| 令牌本地存储 | SharedPreferences，退出登录立即清空 |

> **注意（明文流量）**：Manifest 中 `android:usesCleartextTraffic="false"` 是防抓包要求的一部分，
> 因此用户自定义的 `http://`（非 HTTPS）模型或搜索服务会被系统直接拦截。
> 这是预期行为，请引导用户使用 `https://` 的自定义接口；
> 如确需放开，只能针对特定域名配置 `network_security_config.xml` 白名单，不要整体放开 `usesCleartextTraffic`。

> **更新机制（沿用原版）**：`UpdateChecker` 启动时 GET `WanyuConfig.UPDATE_URL`
> （默认 `https://api.wanyu-ai.com/api/v1/version/latest`），后端直接返回原版
> `UpdateInfo` 结构（`version` / `publishedAt` / `changelog` / `downloads`，可选 `isForce`）。
> 客户端用内置 `Version` 比较版本号，仅在聊天抽屉内的原生 `UpdateCard` 展示并打开底部弹窗，
> 点击下载交给系统 `DownloadManager`。该接口为公开 GET，不签名、不登录、不扣积分。
> 后端无启用版本时会返回 `version = 0.0.0`，客户端不会提示更新。

## 8. AGPL 3.0 说明

本项目基于 RikkaHub（AGPL 3.0）二次开发，名称改为「挽屿AI」。
根据 AGPL 3.0：

- 修改后的源码必须以 AGPL 3.0 协议开源
- 通过网络向用户提供服务时，必须向用户提供获取对应源码的方式
- 必须保留原项目的版权与协议声明

发布前请在「关于」页面或项目仓库补充源码获取地址与协议文本。
