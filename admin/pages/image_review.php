<?php
// 图片审核：官方会话中用户发送的图片，人工判定是否违规并警告用户
$pageTitle = '图片审核';                                   // 标题
$breadcrumb = ['图片审核'];                                 // 面包屑
$p = max(1, (int)($_GET['p'] ?? 1));                       // 页码
$size = 24;                                                // 每页数量
$status = isset($_GET['status']) ? (int)$_GET['status'] : 0; // 状态筛选：-1全部 0待审 1通过 2违规
$q = trim((string)($_GET['q'] ?? ''));                     // 用户搜索

// 审核操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {               // 处理通过/违规
    try {
        verify_post_csrf();                                // 校验CSRF
        $action = admin_post_action();                     // 动作
        $id = (int)($_POST['image_id'] ?? 0);              // 图片记录
        $image = $id > 0 ? Db::fetch('SELECT * FROM chat_images WHERE id = ?', [$id]) : null; // 查询
        if (!$image) {                                     // 不存在
            throw new ApiException(40080, '图片记录不存在');
        }
        $reviewer = 'admin:' . ($admin['username'] ?? 'admin'); // 审核人
        if ($action === 'approve') {                       // 通过
            Db::exec('UPDATE chat_images SET status = 1, reviewer = ?, review_reason = \'\', reviewed_at = NOW() WHERE id = ?',
                [$reviewer, $id]);
            flash('success', '图片已通过审核');
        } elseif ($action === 'reject') {                  // 违规：警告用户，超过5次自动封禁
            $reason = trim((string)($_POST['reason'] ?? '')); // 违规原因
            if ($reason === '') {                          // 必须有原因
                $reason = '图片内容违规';
            }
            $result = Risk::warnUser((int)$image['user_id'], $reason, $reviewer); // 记一次警告
            Db::exec('UPDATE chat_images SET status = 2, reviewer = ?, review_reason = ?, reviewed_at = NOW() WHERE id = ?',
                [$reviewer, mb_substr($reason, 0, 255), $id]);
            if (!empty($result['banned'])) {               // 达到上限已封禁
                flash('success', '已判定违规，该用户累计警告超过上限，已封禁 7 天');
            } else {                                       // 仅警告
                flash('success', '已判定违规并警告用户（' . (int)($result['warnings'] ?? 0) . '/5）');
            }
        }
        admin_redirect('image_review', array_filter(['status' => $status, 'p' => $p, 'q' => $q], function ($v) {
            return $v !== '' && $v !== -1;
        }));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        admin_redirect('image_review', ['status' => $status, 'p' => $p]);
    }
}

$ready = true;                                             // 表是否存在
$rows = [];                                                // 图片记录
$total = 0;                                                // 总数
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0]; // 各状态数量
try {
    foreach ([0 => 'pending', 1 => 'approved', 2 => 'rejected'] as $code => $key) {
        $counts[$key] = (int)Db::scalar('SELECT COUNT(*) FROM chat_images WHERE status = ?', [$code]); // 统计
    }
    $where = [];                                           // 条件
    $params = [];                                          // 参数
    if ($status >= 0) {                                    // 指定状态
        $where[] = 'i.status = ?';
        $params[] = $status;
    }
    if ($q !== '') {                                       // 用户搜索
        $where[] = '(u.email LIKE ? OR u.nickname LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like);
    }
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)Db::scalar('SELECT COUNT(*) FROM chat_images i LEFT JOIN users u ON u.id = i.user_id' . $sqlWhere, $params);
    $offset = ($p - 1) * $size;
    $rows = Db::fetchAll('SELECT i.*, u.nickname, u.email, u.violation_warnings, c.title AS conversation_title ' .
        'FROM chat_images i LEFT JOIN users u ON u.id = i.user_id ' .
        'LEFT JOIN conversations c ON c.user_id = i.user_id AND c.client_id = i.conversation_client_id' .
        $sqlWhere . ' ORDER BY i.id DESC LIMIT ? OFFSET ?', array_merge($params, [$size, $offset]));
} catch (Throwable $e) {                                   // 表还没建（尚无图片上传）
    $ready = false;
}
$statusTabs = [-1 => '全部', 0 => '待审核', 1 => '已通过', 2 => '已违规']; // 状态标签
?>
<div class="page-head"><div><h1>图片审核</h1><p>官方接口会话中用户发送的图片，人工审核不影响前端正常聊天</p></div></div>

<?php if (!$ready): ?>
    <div class="card"><div class="empty-state">还没有用户上传图片（首次上传后会自动创建审核表）</div></div>
<?php else: ?>
<div class="card">
    <div class="filter-bar">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($statusTabs as $code => $label): ?>
                <?php $active = $code === $status; ?>
                <a class="btn btn-sm <?= $active ? 'btn-primary' : '' ?>"
                   href="index.php?page=image_review&status=<?= (int)$code ?>">
                    <?= h($label) ?>
                    <?php if ($code === 0): ?>(<?= (int)$counts['pending'] ?>)
                    <?php elseif ($code === 1): ?>(<?= (int)$counts['approved'] ?>)
                    <?php elseif ($code === 2): ?>(<?= (int)$counts['rejected'] ?>)<?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="get" class="inline-form" style="gap:10px;">
            <input type="hidden" name="page" value="image_review">
            <input type="hidden" name="status" value="<?= (int)$status ?>">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="邮箱 / 昵称">
            <button class="btn" type="submit">搜索</button>
        </form>
    </div>
</div>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state">没有符合条件的图片</div></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;">
    <?php foreach ($rows as $row): ?>
        <div class="card" style="padding:10px;">
            <a href="<?= h($row['url']) ?>" target="_blank" rel="noopener">
                <img src="<?= h($row['url']) ?>" loading="lazy" alt="聊天图片"
                     style="width:100%;height:200px;object-fit:cover;border-radius:10px;background:#f2f2f2;">
            </a>
            <div style="margin-top:8px;font-size:13px;">
                <div>
                    <?= h($row['nickname'] ?: '未知用户') ?>
                    <span class="hint">(<?= h($row['email'] ?: 'QQ用户') ?>)</span>
                </div>
                <div class="hint" style="margin-top:2px;">
                    会话：<?= h($row['conversation_title'] ?: '未命名会话') ?>
                </div>
                <div class="hint" style="margin-top:2px;">
                    <?= h(format_time($row['created_at'])) ?>
                    <?php if ((int)$row['status'] === 0): ?>
                        <span class="badge badge-gray">待审核</span>
                    <?php elseif ((int)$row['status'] === 1): ?>
                        <span class="badge badge-green">已通过</span>
                    <?php else: ?>
                        <span class="badge badge-red">已违规</span>
                    <?php endif; ?>
                </div>
                <?php if ((int)$row['status'] === 2 && $row['review_reason'] !== ''): ?>
                    <div class="hint" style="margin-top:2px;color:#c0392b;"><?= h($row['review_reason']) ?></div>
                <?php endif; ?>
            </div>
            <?php if ((int)$row['status'] === 0): ?>
                <div style="display:flex;gap:8px;margin-top:10px;">
                    <form method="post" style="flex:1;">
                        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="image_id" value="<?= (int)$row['id'] ?>">
                        <button class="btn btn-sm" type="submit" style="width:100%;">通过</button>
                    </form>
                    <form method="post" style="flex:1.4;" onsubmit="return confirm('判定违规并警告该用户？');">
                        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="image_id" value="<?= (int)$row['id'] ?>">
                        <input type="text" name="reason" placeholder="违规原因（可选）" style="width:100%;margin-bottom:6px;">
                        <button class="btn btn-sm btn-danger" type="submit" style="width:100%;">违规</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?= render_pagination($p, $total, $size, array_filter(['page' => 'image_review', 'status' => $status, 'q' => $q], function ($v) {
    return $v !== '' && $v !== -1;
})) ?>
<?php endif; ?>
<?php endif; ?>
