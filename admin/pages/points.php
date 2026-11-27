<?php
// 积分配置：token 与积分换算、注册赠送、新设备核验
$pageTitle = '积分配置';                                 // 标题
$breadcrumb = ['积分配置'];                               // 面包屑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {             // 保存配置
    try {
        verify_post_csrf();                             // CSRF
        $perPoint = (int)($_POST['tokens_per_point'] ?? 0); // 每积分token
        $bonus = (int)($_POST['register_bonus_points'] ?? 0); // 注册赠送
        $device = (string)($_POST['device_verify_enabled'] ?? '0'); // 设备核验开关
        if ($perPoint <= 0) {                           // 必须大于0
            throw new ApiException(40070, '每个积分对应的token数必须大于0'); // 拒绝
        }
        if ($bonus < 0) {                               // 不能为负
            throw new ApiException(40071, '注册赠送积分不能为负数'); // 拒绝
        }
        Config::setDb('tokens_per_point', (string)$perPoint); // 保存比例
        Config::setDb('register_bonus_points', (string)$bonus); // 保存赠送
        Config::setDb('device_verify_enabled', $device === '1' ? '1' : '0'); // 保存开关
        flash('success', '积分配置已保存');                // 提示
    } catch (Throwable $e) {                            // 异常
        flash('error', $e->getMessage());               // 提示
    }
    admin_redirect('points');                           // 返回
}
$perPoint = Config::int('tokens_per_point', 1000);      // 当前比例
$bonus = Config::int('register_bonus_points', 0);       // 当前赠送
$device = Config::db('device_verify_enabled', '1');     // 当前开关
$example = $perPoint > 0 ? 1000 / $perPoint : 0;        // 示例：1000token消耗积分
?>
<div class="page-head"><div><h1>积分配置</h1><p>配置官方模型计费规则</p></div></div>
<div class="card">
    <h2>换算规则</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
        <div class="form-row">
            <div class="field">
                <label>多少 token 消耗 1 积分</label>
                <input type="number" name="tokens_per_point" value="<?= h($perPoint) ?>" min="1" required>
                <div class="hint">当前规则：<?= $perPoint ?> token = 1 积分；1000 token 约消耗 <?= rtrim(rtrim(number_format($example, 2, '.', ''), '0'), '.') ?> 积分</div>
            </div>
            <div class="field">
                <label>注册赠送积分</label>
                <input type="number" name="register_bonus_points" value="<?= h($bonus) ?>" min="0">
                <div class="hint">新用户注册时自动到账，0 表示不赠送</div>
            </div>
            <div class="field">
                <label>新设备邮箱核验</label>
                <select name="device_verify_enabled">
                    <option value="1" <?= $device === '1' ? 'selected' : '' ?>>开启（推荐）</option>
                    <option value="0" <?= $device === '0' ? 'selected' : '' ?>>关闭</option>
                </select>
                <div class="hint">开启后，令牌在陌生设备使用时需邮箱验证码核验</div>
            </div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存配置</button></div>
    </form>
</div>
<div class="code-note">扣费公式：消耗积分 = max(1, ceil((输入token + 输出token) / 每积分token数))。仅官方DeepSeek模型扣费，自定义模型不经过后端、不扣积分。</div>
