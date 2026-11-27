<?php
// AI 参数配置：DeepSeek 密钥、地址、模型与 QQ 开放平台参数
$pageTitle = 'AI参数配置';                               // 标题
$breadcrumb = ['AI参数配置'];                             // 面包屑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {             // 保存
    try {
        verify_post_csrf();                             // CSRF
        $apiKey = trim((string)($_POST['deepseek_api_key'] ?? '')); // 新密钥
        if ($apiKey !== '') {                           // 留空表示不修改
            Config::setDb('deepseek_api_key', $apiKey); // 保存密钥
        }
        $baseUrl = trim((string)($_POST['deepseek_base_url'] ?? '')); // 地址
        $model = trim((string)($_POST['deepseek_model'] ?? '')); // 模型
        $qqAppId = trim((string)($_POST['qq_app_id'] ?? '')); // QQ AppID
        $qqAppKey = trim((string)($_POST['qq_app_key'] ?? '')); // QQ AppKey
        if (!preg_match('#^https://#i', $baseUrl)) {    // 必须HTTPS
            throw new ApiException(40072, 'DeepSeek接口地址必须为HTTPS'); // 拒绝
        }
        if ($model === '') {                            // 模型必填
            throw new ApiException(40073, '模型版本不能为空'); // 拒绝
        }
        Config::setDb('deepseek_base_url', rtrim($baseUrl, '/')); // 保存地址
        Config::setDb('deepseek_model', $model);        // 保存模型
        Config::setDb('qq_app_id', $qqAppId);           // 保存QQ AppID
        Config::setDb('qq_app_key', $qqAppKey);         // 保存QQ AppKey
        flash('success', 'AI参数已保存');                // 提示
    } catch (Throwable $e) {                            // 异常
        flash('error', $e->getMessage());               // 提示
    }
    admin_redirect('ai');                               // 返回
}
$key = Config::db('deepseek_api_key');                  // 当前密钥
$keyDisplay = $key === '' ? '未配置' : ('已配置（尾号 ' . substr($key, -4) . '）'); // 脱敏显示
$baseUrl = Config::db('deepseek_base_url', 'https://api.deepseek.com'); // 地址
$model = Config::db('deepseek_model', 'deepseek-chat'); // 模型
$qqAppId = Config::db('qq_app_id');                     // QQ AppID
$qqAppKey = Config::db('qq_app_key');                   // QQ AppKey
$qqKeyDisplay = $qqAppKey === '' ? '未配置' : '已配置';  // QQ密钥显示
?>
<div class="page-head"><div><h1>AI参数配置</h1><p>密钥仅保存在服务端，客户端不可见</p></div></div>
<div class="card">
    <h2>DeepSeek 官方模型</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
        <div class="form-row">
            <div class="field">
                <label>DeepSeek API Key</label>
                <input type="password" name="deepseek_api_key" placeholder="<?= h($keyDisplay) ?>，留空不修改">
                <div class="hint"><?= h($keyDisplay) ?></div>
            </div>
            <div class="field">
                <label>接口地址</label>
                <input type="url" name="deepseek_base_url" value="<?= h($baseUrl) ?>" placeholder="https://api.deepseek.com">
                <div class="hint">默认 https://api.deepseek.com，转发时会自动拼接 /chat/completions</div>
            </div>
            <div class="field">
                <label>模型版本</label>
                <input type="text" name="deepseek_model" value="<?= h($model) ?>" placeholder="deepseek-chat">
                <div class="hint">强制使用该模型，客户端传入的模型会被覆盖</div>
            </div>
        </div>
        <h2 style="margin-top:20px;">QQ 开放平台</h2>
        <div class="form-row">
            <div class="field">
                <label>QQ AppID</label>
                <input type="text" name="qq_app_id" value="<?= h($qqAppId) ?>" placeholder="QQ开放平台申请的应用ID">
            </div>
            <div class="field">
                <label>QQ AppKey</label>
                <input type="password" name="qq_app_key" placeholder="<?= h($qqKeyDisplay) ?>，留空不修改">
                <div class="hint"><?= h($qqKeyDisplay) ?>，可选；QQ 登录与绑定校验只依赖 AppID</div>
            </div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存参数</button></div>
    </form>
</div>
<div class="code-note">安全提示：客户端只请求本后端，DeepSeek 密钥不会下发到 App；请勿在日志或界面中展示完整密钥。</div>
