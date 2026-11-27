<?php
// 邮箱配置：SMTP 发件参数与测试
$pageTitle = '邮箱配置';                                 // 标题
$breadcrumb = ['邮箱配置'];                               // 面包屑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {             // 保存/测试
    try {
        verify_post_csrf();                             // CSRF
        $action = admin_post_action();                  // 当前动作
        if ($action === 'test') {                       // 发送测试邮件（不回写配置，避免空字段覆盖已保存的设置）
            $to = trim((string)($_POST['test_email'] ?? '')); // 收件地址
            Mailer::send($to, '【挽屿AI】SMTP配置测试', '<p>这是一封测试邮件，您的SMTP配置已生效。</p>'); // 发送
            flash('success', '测试邮件已发送至 ' . $to);    // 提示
        } else {                                        // 保存配置
            $fields = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', 'smtp_from', 'smtp_from_name']; // 字段
            foreach ($fields as $key) {                 // 逐项保存
                $value = trim((string)($_POST[$key] ?? '')); // 取表单值
                if ($key === 'smtp_pass' && $value === '') { // 密码留空表示不修改
                    continue;                           // 跳过
                }
                Config::setDb($key, $value);            // 写入数据库
            }
            flash('success', 'SMTP配置已保存');            // 提示
        }
    } catch (Throwable $e) {                            // 异常
        flash('error', $e->getMessage());               // 提示
    }
    admin_redirect('mail');                             // 返回
}
$host = Config::db('smtp_host');                        // 主机
$port = Config::db('smtp_port', '465');                 // 端口
$user = Config::db('smtp_user');                        // 账号
$secure = Config::db('smtp_secure', 'ssl');             // 加密
$from = Config::db('smtp_from');                        // 发件地址
$fromName = Config::db('smtp_from_name', '挽屿AI');       // 发件名
$hasPass = Config::db('smtp_pass') !== '';              // 是否已设置密码
?>
<div class="page-head"><div><h1>邮箱配置</h1><p>用于发送登录、绑定与设备核验验证码</p></div></div>
<div class="card">
    <h2>SMTP 发件配置</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="form-row">
            <div class="field"><label>SMTP 主机</label><input type="text" name="smtp_host" value="<?= h($host) ?>" placeholder="smtp.qq.com" required></div>
            <div class="field"><label>端口</label><input type="number" name="smtp_port" value="<?= h($port) ?>" placeholder="465"></div>
            <div class="field"><label>加密方式</label>
                <select name="smtp_secure">
                    <option value="ssl" <?= $secure === 'ssl' ? 'selected' : '' ?>>SSL（通常465）</option>
                    <option value="tls" <?= $secure === 'tls' ? 'selected' : '' ?>>STARTTLS（通常587）</option>
                    <option value="none" <?= $secure === 'none' ? 'selected' : '' ?>>不加密（不推荐）</option>
                </select>
            </div>
            <div class="field"><label>SMTP 账号</label><input type="text" name="smtp_user" value="<?= h($user) ?>" placeholder="noreply@example.com"></div>
            <div class="field"><label>SMTP 密码</label><input type="password" name="smtp_pass" placeholder="<?= $hasPass ? '已设置，留空不修改' : '请输入授权码' ?>"></div>
            <div class="field"><label>发件人地址</label><input type="email" name="smtp_from" value="<?= h($from) ?>" placeholder="留空则使用SMTP账号"></div>
            <div class="field"><label>发件人名称</label><input type="text" name="smtp_from_name" value="<?= h($fromName) ?>"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存配置</button></div>
    </form>
</div>
<div class="card">
    <h2>发送测试邮件</h2>
    <form method="post" class="inline-form">
        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
        <input type="hidden" name="action" value="test">
        <input type="email" name="test_email" placeholder="收件邮箱" required style="width:240px;">
        <button class="btn" type="submit">发送测试</button>
    </form>
    <div class="hint">提示：QQ邮箱需先在邮箱设置中开启 SMTP 服务并使用授权码作为密码。</div>
</div>
