# 挽屿AI 接口文档

所有业务接口均为 **POST + JSON**，统一前缀 `https://api.wanyu-ai.com/api/v1`。

## 1. 统一响应格式

```json
{
  "code": 0,
  "message": "ok",
  "data": { }
}
```

- `code = 0` 表示成功，其余为业务错误码
- 失败时 HTTP 状态码通常为 400 / 401 / 402 / 403 / 404 / 405 / 415 / 500
- 响应头固定 `Cache-Control: no-store`，敏感数据不缓存

## 2. 请求头

| 请求头 | 必填 | 说明 |
| --- | --- | --- |
| `Content-Type` | 是 | 必须为 `application/json` |
| `X-Device-Id` | 是 | 设备指纹（客户端首次生成并持久化的 UUID） |
| `X-Device-Name` | 否 | 设备名称（品牌 + 型号） |
| `X-App-Version` | 否 | 客户端版本，形如 `1.0.0(1)` |
| `X-Timestamp` | 是 | 当前 Unix 秒级时间戳，与服务器偏差不得超过 60 秒 |
| `X-Sign` | 是 | 请求签名，64 位小写十六进制 SHA-256 |
| `Authorization` | 登录接口外必填 | `Bearer <64位令牌>` |

## 3. 签名算法

与后端 `Security::canonical()`、安卓端 `WanyuSigner` 完全一致：

1. 取 JSON 请求体**顶层标量字段**（数组/对象跳过；`null` 视为空串；布尔转为 `true`/`false`）
2. 追加 `timestamp = X-Timestamp`、`device_id = X-Device-Id`
3. 按 key 的 ASCII 升序排序
4. 拼接为 `k1=v1&k2=v2&...`
5. 末尾追加 `&key=<api_sign_key>`
6. 对整串做 SHA-256，输出小写十六进制作为 `X-Sign`

示例：

```text
请求体：{"email":"a@b.com","purpose":"login"}
X-Timestamp：1767225600
X-Device-Id：0f0e0d0c-...

签名原文（ksort 后）：
device_id=0f0e...&email=a@b.com&purpose=login&timestamp=1767225600&key=YOUR_SECRET

X-Sign = sha256(签名原文)
```

> 注意：服务端只对顶层标量签名。`/sync/push`、`/ai/deepseek/...` 请求中的数组/对象不参与签名，但 `timestamp` 与 `device_id` 始终参与。

## 4. 错误码

| code | HTTP | 含义 |
| --- | --- | --- |
| 40001 | 400 | 请求体不是合法 JSON 对象 |
| 40101 | 401 | 时间戳缺失或格式错误 |
| 40102 | 401 | 请求过期（超过 60 秒） |
| 40103 | 401 | 设备标识无效 |
| 40104 | 401 | 签名格式错误 |
| 40105 | 401 | 签名校验失败 |
| 40106 | 401 | 登录已失效 |
| 40301 | 403 | 必须使用 HTTPS |
| 40302 / 40311 / 40312 | 403 | 账号已被禁用 |
| 40303 | 403 | 新设备需先完成邮箱验证 |
| 40401 | 404 | 接口不存在 |
| 40501 | 405 | 仅支持 POST |
| 41501 | 415 | Content-Type 必须为 application/json |
| 40202 | 402 | 积分不足，无法调用官方模型 |
| 50002 | 500 | 服务端签名密钥未配置 |
| 50008 | 500 | 官方模型密钥未配置 |
| 50009 | 500 | 官方模型请求失败 |
| 50011 / 50012 / 50013 | 500 | QQ 登录未配置 / QQ 服务异常 |

其余 400xx 为参数校验类错误，`message` 中会说明具体原因。

---

## 5. 登录与账号

### 5.1 发送邮箱验证码

`POST /auth/email/send-code`

```json
{ "email": "user@example.com", "purpose": "login" }
```

`purpose` 取值：`login` / `register` / `bind` / `device_verify`。

响应：

```json
{ "code": 0, "message": "验证码已发送", "data": { "expires_in": 600, "email": "us***@example.com" } }
```

> `debug=true` 时会额外返回 `debug_code`，生产环境必须关闭 debug。

### 5.2 邮箱验证码登录/注册

`POST /auth/email/verify`

```json
{ "email": "user@example.com", "code": "123456" }
```

响应：

```json
{
  "code": 0,
  "data": {
    "token": "64位十六进制令牌",
    "user": {
      "id": 1,
      "account_type": "email",
      "email": "us***@example.com",
      "email_bound": true,
      "qq_number": "",
      "nickname": "用户xxxxxx",
      "avatar": "",
      "points": 100,
      "created_at": "2026-01-01 10:00:00"
    },
    "is_new_user": false,
    "device_trusted": true
  }
}
```

### 5.3 QQ 授权登录

`POST /auth/qq`

```json
{ "openid": "QQ SDK 返回的 openid", "access_token": "QQ SDK 返回的 access_token" }
```

后端会向 `https://graph.qq.com/oauth2.0/me` 校验令牌，并校验 AppID 与 openid 一致性。

响应结构同 5.2。QQ 用户 `account_type = "qq"`，`email` 可能为空。

### 5.4 补充绑定邮箱

`POST /auth/bind-email`

```json
{ "email": "user@example.com", "code": "123456" }
```

响应 `data.user`，绑定成功后当前设备自动信任。

### 5.5 新设备发送核验码

`POST /auth/device/send-code`

```json
{ "purpose": "device_verify" }
```

后端强制使用账号已绑定邮箱发送。QQ 用户未绑定邮箱时返回错误。

### 5.6 新设备核验

`POST /auth/device/verify`

```json
{ "code": "123456" }
```

响应：

```json
{ "code": 0, "data": { "user": { }, "device_trusted": true } }
```

### 5.7 退出登录

`POST /auth/logout`

```json
{ }
```

删除当前 Bearer 令牌。

---

## 6. 用户资料

### 6.1 获取用户信息

`POST /user/profile`

```json
{ }
```

响应：

```json
{
  "code": 0,
  "data": {
    "user": { },
    "tokens_per_point": 1000,
    "official_model": "deepseek-chat"
  }
}
```

### 6.2 更新昵称/头像

`POST /user/profile/update`

```json
{ "nickname": "新昵称", "avatar": "https://example.com/a.png" }
```

两项至少填一项；`avatar` 必须为 HTTPS。

---

## 7. 设置同步（模型配置）

### 7.1 上传设置

`POST /user/settings/save`

```json
{ "settings": "{\"providers\":[...],\"chatModelId\":\"...\"}" }
```

- `settings` 为客户端序列化后的 **JSON 文本字符串**，不是对象
- 体积上限 2MB，必须是合法 JSON

响应：

```json
{ "code": 0, "data": { "saved_at": "2026-01-01T10:00:00+08:00" } }
```

### 7.2 拉取设置

`POST /user/settings/load`

```json
{ }
```

响应：

```json
{ "code": 0, "data": { "settings": "…JSON 文本…", "has_data": true } }
```

`has_data=false` 表示云端从未备份过，客户端应改为上传本地设置。

---

## 8. 会话与消息同步

### 8.1 上传

`POST /sync/push`

```json
{
  "conversations": [
    {
      "client_id": "会话UUID",
      "title": "标题",
      "created_at": "2026-01-01 10:00:00",
      "updated_at": "2026-01-01 10:05:00",
      "deleted": 0,
      "messages": [
        {
          "client_id": "消息UUID",
          "role": "user",
          "content": "你好",
          "model": "",
          "created_at": "2026-01-01 10:00:01"
        }
      ]
    }
  ]
}
```

限制：单次最多 20 个会话；每个会话最多 300 条消息；单条内容最多 200000 字符。

响应：

```json
{ "code": 0, "data": { "mapping": { "会话UUID": 12 }, "server_time": "2026-01-01 10:06:00" } }
```

### 8.2 拉取增量

`POST /sync/pull`

```json
{ "since": "2026-01-01 10:06:00" }
```

`since` 留空表示全量拉取。响应：

```json
{
  "code": 0,
  "data": {
    "conversations": [
      { "server_id": 12, "client_id": "会话UUID", "title": "标题", "created_at": "…", "updated_at": "…", "deleted": 0 }
    ],
    "messages": [
      { "server_conversation_id": 12, "client_id": "消息UUID", "role": "user", "content": "你好", "model": "", "created_at": "…", "deleted": 0 }
    ],
    "server_time": "2026-01-01 10:10:00"
  }
}
```

客户端应把 `server_time` 保存为下一次的 `since`。

---

## 9. 官方模型与积分

### 9.1 调用前费用提示

`POST /ai/quote`

```json
{ }
```

响应：

```json
{
  "code": 0,
  "data": {
    "model": "deepseek-chat",
    "tokens_per_point": 1000,
    "points": 120,
    "min_points": 1,
    "tip": "官方模型按实际token扣费，约1000token=1积分"
  }
}
```

### 9.2 官方模型对话（OpenAI 兼容）

`POST /ai/deepseek/v1/chat/completions`

请求体与 OpenAI Chat Completions 兼容：

```json
{
  "model": "deepseek-chat",
  "messages": [ { "role": "user", "content": "你好" } ],
  "stream": true,
  "temperature": 0.7,
  "max_tokens": 2048
}
```

后端行为：

- 强制使用后台配置的 `deepseek_model` 与 `deepseek_api_key`
- 清洗消息，仅允许 `system/user/assistant/tool`，单条最多 50000 字符，最多 100 条
- 余额不足 1 积分直接拒绝（`40202`）
- 按 `input_tokens + output_tokens` 折算扣积分，超出余额时扣到 0 为止
- 流式响应为 `text/event-stream`，并在末尾附带 `usage`

响应头：

```text
X-Wanyu-Points-Charged: 本次扣除积分
X-Wanyu-Points-Balance: 扣除后余额
```

> 客户端也可以使用 `/ai/deepseek` 或 `/ai/deepseek/chat/completions`，三者等价。

### 9.3 积分流水

`POST /points/logs`

```json
{ "page": 1, "page_size": 20 }
```

响应：

```json
{
  "code": 0,
  "data": {
    "total": 35,
    "page": 1,
    "page_size": 20,
    "items": [
      { "id": 35, "type": "ai_call", "points": -3, "input_tokens": 800, "output_tokens": 1200, "model": "deepseek-chat", "balance_after": 117, "created_at": "2026-01-01 10:00:00" }
    ]
  }
}
```

---

## 10. 版本更新

### 10.1 原版更新方式（默认使用）

`GET /version/latest`

公开接口，**不需要签名、不需要登录**，直接返回客户端 `UpdateInfo` 结构：

```json
{
  "version": "1.2.0",
  "publishedAt": "2026-01-01T10:00:00+08:00",
  "changelog": "更新说明",
  "downloads": [
    { "name": "wanyu-1.2.0.apk", "url": "https://example.com/app.apk", "size": "" }
  ],
  "isForce": false
}
```

客户端启动时拉取该 JSON，自行比较版本号；仅当新版本高于当前版本时，在聊天抽屉内展示 `UpdateCard`。
`isForce=true` 时更新卡片与底部弹窗不可关闭。后台没有启用版本时返回 `version = "0.0.0"`。

### 10.2 旧版检查接口（保留兼容）

`POST /version/check`

```json
{ "version_code": 12 }
```

无更新：

```json
{ "code": 0, "data": { "has_update": false } }
```

有更新：

```json
{
  "code": 0,
  "data": {
    "has_update": true,
    "version_code": 15,
    "version_name": "1.2.0",
    "changelog": "更新说明",
    "download_url": "https://example.com/app.apk",
    "is_force": true,
    "published_at": "2026-01-01T10:00:00+08:00"
  }
}
```

`is_force=true` 时客户端更新弹窗不可关闭。

---

## 11. 安全与公共配置

### 11.1 抓包/代理上报

`POST /security/report-proxy`

```json
{ "reason": "检测到代理环境" }
```

响应 `{ "code": 0, "data": null }`，同时写入后台「安全日志」。

### 11.2 公共配置

`POST /config/public`

```json
{ }
```

响应：

```json
{
  "code": 0,
  "data": {
    "official_model": "deepseek-chat",
    "official_model_label": "官方DeepSeek模型",
    "tokens_per_point": 1000,
    "device_verify_enabled": true,
    "maintenance": false,
    "server_time": "2026-01-01 10:00:00"
  }
}
```
