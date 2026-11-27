<?php
// 安全日志：签名失败、异常设备、抓包、限流等事件
$pageTitle = '安全日志';                                 // 标题
$breadcrumb = ['安全日志'];                               // 面包屑
$type = trim((string)($_GET['event_type'] ?? ''));      // 事件类型
$level = trim((string)($_GET['level'] ?? ''));          // 风险等级
$p = max(1, (int)($_GET['p'] ?? 1));                     // 页码
$size = 25;                                             // 每页数量
$where = [];                                            // 条件
$params = [];                                           // 参数
if ($type !== '') { $where[] = 'event_type = ?'; $params[] = $type; } // 类型筛选
if (in_array($level, ['info', 'warning', 'danger'], true)) { $where[] = 'level = ?'; $params[] = $level; } // 等级筛选
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : ''; // WHERE
$total = (int)Db::scalar('SELECT COUNT(*) FROM security_logs' . $sqlWhere, $params); // 总数
$offset = ($p - 1) * $size;                             // 偏移
$rows = Db::fetchAll('SELECT * FROM security_logs' . $sqlWhere . ' ORDER BY id DESC LIMIT ? OFFSET ?', array_merge($params, [$size, $offset])); // 列表
$types = Db::fetchAll('SELECT DISTINCT event_type FROM security_logs ORDER BY event_type'); // 类型选项
$todayCount = (int)Db::scalar('SELECT COUNT(*) FROM security_logs WHERE created_at >= ?', [date('Y-m-d 00:00:00')]); // 今日事件
?>
<div class="page-head">
    <div><h1>安全日志</h1><p>签名失败、异常设备、抓包拦截与高频请求记录</p></div>
    <div class="hint">今日事件 <?= $todayCount ?> 条</div>
</div>
<div class="card">
    <div class="filter-bar">
        <form method="get" class="inline-form" style="gap:10px;">
            <input type="hidden" name="page" value="security_logs">
            <select name="event_type">
                <option value="">全部类型</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= h($t['event_type']) ?>" <?= $type === $t['event_type'] ? 'selected' : '' ?>><?= h($t['event_type']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="level">
                <option value="">全部等级</option>
                <option value="danger" <?= $level === 'danger' ? 'selected' : '' ?>>danger</option>
                <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>warning</option>
                <option value="info" <?= $level === 'info' ? 'selected' : '' ?>>info</option>
            </select>
            <button class="btn" type="submit">筛选</button>
        </form>
    </div>
    <table class="table">
        <thead><tr><th>ID</th><th>类型</th><th>等级</th><th>描述</th><th>用户</th><th>IP</th><th>设备</th><th>时间</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="empty">暂无安全日志</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td class="mono"><?= h($row['event_type']) ?></td>
                <td><span class="badge <?= $row['level'] === 'danger' ? 'badge-red' : ($row['level'] === 'warning' ? 'badge-amber' : 'badge-gray') ?>"><?= h($row['level']) ?></span></td>
                <td><?= h($row['message']) ?></td>
                <td><?= $row['user_id'] ? ('#' . (int)$row['user_id']) : '-' ?></td>
                <td class="mono"><?= h($row['ip']) ?></td>
                <td class="mono break" style="max-width:220px;"><?= h(Security::maskString($row['device_id'], 8)) ?></td>
                <td><?= h(format_time($row['created_at'])) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?= render_pagination($p, $total, $size, ['page' => 'security_logs', 'event_type' => $type, 'level' => $level]) ?>
</div>
