<?php
// 仪表盘：核心统计与最近记录
$pageTitle = '仪表盘';                                   // 页面标题
$breadcrumb = ['仪表盘'];                                // 面包屑
$today = date('Y-m-d 00:00:00');                        // 今日起点

$totalUsers = (int)Db::scalar('SELECT COUNT(*) FROM users'); // 总用户数
$todayUsers = (int)Db::scalar('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today]); // 今日新增
$todayCalls = (int)Db::scalar('SELECT COUNT(*) FROM point_transactions WHERE type = "ai_call" AND created_at >= ?', [$today]); // 今日调用
$todayPoints = (int)Db::scalar('SELECT COALESCE(SUM(-points),0) FROM point_transactions WHERE type = "ai_call" AND created_at >= ?', [$today]); // 今日消耗积分
$secToday = (int)Db::scalar('SELECT COUNT(*) FROM security_logs WHERE created_at >= ?', [$today]); // 今日安全事件
$totalConversations = (int)Db::scalar('SELECT COUNT(*) FROM conversations WHERE deleted = 0'); // 会话总数
$recentCalls = Db::fetchAll('SELECT p.id, p.points, p.input_tokens, p.output_tokens, p.model, p.created_at, u.nickname, u.email ' . // 最近调用
    'FROM point_transactions p JOIN users u ON u.id = p.user_id WHERE p.type = "ai_call" ORDER BY p.id DESC LIMIT 8');
$recentSecurity = Db::fetchAll('SELECT event_type, level, message, ip, created_at FROM security_logs ORDER BY id DESC LIMIT 6'); // 最近安全事件
?>
<div class="page-head">
    <div>
        <h1>仪表盘</h1>
        <p>查看用户、调用、积分与安全概览</p>
    </div>
    <div class="hint">数据更新于 <?= h(date('Y-m-d H:i')) ?></div>
</div>

<div class="grid" style="margin-bottom:18px;">
    <div class="stat"><div class="label">总用户数</div><div class="value"><?= $totalUsers ?></div></div>
    <div class="stat"><div class="label">今日新增用户</div><div class="value"><?= $todayUsers ?></div></div>
    <div class="stat"><div class="label">今日官方模型调用</div><div class="value"><?= $todayCalls ?></div></div>
    <div class="stat"><div class="label">今日消耗积分</div><div class="value"><?= $todayPoints ?></div></div>
    <div class="stat"><div class="label">今日安全事件</div><div class="value"><?= $secToday ?></div></div>
    <div class="stat"><div class="label">会话总数</div><div class="value"><?= $totalConversations ?></div></div>
</div>

<div class="card">
    <h2>最近调用记录</h2>
    <table class="table">
        <thead><tr><th>用户</th><th>模型</th><th>输入/输出 Token</th><th>扣费</th><th>时间</th></tr></thead>
        <tbody>
        <?php if (!$recentCalls): ?>
            <tr><td colspan="5" class="empty">暂无调用记录</td></tr>
        <?php else: foreach ($recentCalls as $row): ?>
            <tr>
                <td><?= h($row['nickname'] ?: '未设置') ?><div class="hint"><?= h($row['email'] ? Security::maskEmail($row['email']) : 'QQ用户') ?></div></td>
                <td><?= h($row['model']) ?></td>
                <td><?= (int)$row['input_tokens'] ?> / <?= (int)$row['output_tokens'] ?></td>
                <td><span class="badge badge-red">-<?= abs((int)$row['points']) ?></span></td>
                <td><?= h(format_time($row['created_at'])) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>最近安全事件</h2>
    <table class="table">
        <thead><tr><th>类型</th><th>等级</th><th>描述</th><th>IP</th><th>时间</th></tr></thead>
        <tbody>
        <?php if (!$recentSecurity): ?>
            <tr><td colspan="5" class="empty">暂无安全事件</td></tr>
        <?php else: foreach ($recentSecurity as $row): ?>
            <tr>
                <td class="mono"><?= h($row['event_type']) ?></td>
                <td><span class="badge <?= $row['level'] === 'danger' ? 'badge-red' : ($row['level'] === 'warning' ? 'badge-amber' : 'badge-gray') ?>"><?= h($row['level']) ?></span></td>
                <td><?= h($row['message']) ?></td>
                <td class="mono"><?= h($row['ip']) ?></td>
                <td><?= h(format_time($row['created_at'])) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
