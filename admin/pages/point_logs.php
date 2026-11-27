<?php
// 积分流水日志：所有官方模型扣费与后台调整记录
$pageTitle = '积分流水日志';                              // 标题
$breadcrumb = ['积分流水日志'];                            // 面包屑
$q = trim((string)($_GET['q'] ?? ''));                  // 用户搜索
$type = trim((string)($_GET['type'] ?? ''));            // 类型
$date = trim((string)($_GET['date'] ?? ''));            // 日期
$p = max(1, (int)($_GET['p'] ?? 1));                     // 页码
$size = 25;                                             // 每页
$where = [];                                            // 条件
$params = [];                                           // 参数
if ($q !== '') {                                        // 用户模糊搜索
    $where[] = '(u.email LIKE ? OR u.nickname LIKE ? OR u.qq_number LIKE ?)'; // 条件
    $like = '%' . $q . '%';                             // 通配
    array_push($params, $like, $like, $like);           // 参数
}
if ($type !== '') { $where[] = 'p.type = ?'; $params[] = $type; } // 类型
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { // 日期
    $where[] = 'p.created_at >= ? AND p.created_at < ?'; // 当天范围
    $params[] = $date . ' 00:00:00';                    // 起点
    $params[] = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00'; // 终点
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : ''; // WHERE
$total = (int)Db::scalar('SELECT COUNT(*) FROM point_transactions p LEFT JOIN users u ON u.id = p.user_id' . $sqlWhere, $params); // 总数
$sumToday = (int)Db::scalar('SELECT COALESCE(SUM(-points),0) FROM point_transactions WHERE type = "ai_call" AND created_at >= ?', [date('Y-m-d 00:00:00')]); // 今日消耗
$offset = ($p - 1) * $size;                             // 偏移
$rows = Db::fetchAll('SELECT p.*, u.nickname, u.email, u.account_type FROM point_transactions p LEFT JOIN users u ON u.id = p.user_id ' . // 查询
    $sqlWhere . ' ORDER BY p.id DESC LIMIT ? OFFSET ?', array_merge($params, [$size, $offset]));
$types = ['ai_call', 'admin_add', 'admin_reduce', 'register_bonus']; // 类型选项
?>
<div class="page-head">
    <div><h1>积分流水日志</h1><p>官方模型扣费与后台积分调整记录</p></div>
    <div class="hint">今日消耗 <?= $sumToday ?> 积分</div>
</div>
<div class="card">
    <div class="filter-bar">
        <form method="get" class="inline-form" style="gap:10px;">
            <input type="hidden" name="page" value="point_logs">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="邮箱 / 昵称 / QQ号">
            <select name="type">
                <option value="">全部类型</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= h($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" value="<?= h($date) ?>">
            <button class="btn" type="submit">筛选</button>
        </form>
    </div>
    <table class="table">
        <thead><tr><th>ID</th><th>用户</th><th>类型</th><th>积分变动</th><th>输入/输出 Token</th><th>模型</th><th>余额</th><th>备注</th><th>时间</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="empty">暂无流水记录</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= h($row['nickname'] ?: '未知用户') ?><div class="hint"><?= h($row['email'] ?: 'QQ用户') ?></div></td>
                <td><span class="badge <?= $row['type'] === 'ai_call' ? 'badge-red' : 'badge-blue' ?>"><?= h($row['type']) ?></span></td>
                <td><strong style="color:<?= (int)$row['points'] < 0 ? '#dc2626' : '#16a34a' ?>;"><?= (int)$row['points'] > 0 ? '+' : '' ?><?= (int)$row['points'] ?></strong></td>
                <td><?= (int)$row['input_tokens'] ?> / <?= (int)$row['output_tokens'] ?></td>
                <td><?= h($row['model'] ?: '-') ?></td>
                <td><?= (int)$row['balance_after'] ?></td>
                <td><?= h($row['remark']) ?></td>
                <td><?= h(format_time($row['created_at'])) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?= render_pagination($p, $total, $size, ['page' => 'point_logs', 'q' => $q, 'type' => $type, 'date' => $date]) ?>
</div>
