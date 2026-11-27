<?php
// 会话记录：查看用户会话与完整消息内容
$pageTitle = '会话记录';                                 // 标题
$breadcrumb = ['会话记录'];                               // 面包屑
$q = trim((string)($_GET['q'] ?? ''));                  // 用户搜索
$p = max(1, (int)($_GET['p'] ?? 1));                     // 页码
$size = 20;                                             // 每页
$conversationId = (int)($_GET['conversation_id'] ?? 0);  // 查看详情
$detail = null;                                         // 会话详情
$messages = [];                                         // 消息列表
$images = [];                                           // 会话内聊天图片（官方接口会话上传）
if ($conversationId > 0) {                              // 查看单个会话
    $detail = Db::fetch('SELECT c.*, u.nickname, u.email, u.qq_number, u.account_type FROM conversations c ' . // 查询会话
        'LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$conversationId]);
    if ($detail) {                                      // 存在则查询消息
        $messages = Db::fetchAll('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 500', [$conversationId]);
        try {                                           // 图片审核表可能尚未创建
            $images = Db::fetchAll('SELECT * FROM chat_images WHERE user_id = ? AND conversation_client_id = ? ORDER BY id ASC LIMIT 200',
                [(int)$detail['user_id'], $detail['client_id']]);
        } catch (Throwable $e) {
            $images = [];
        }
    }
}
$where = [];                                            // 条件
$params = [];                                           // 参数
if ($q !== '') {                                        // 搜索
    $where[] = '(u.email LIKE ? OR u.nickname LIKE ? OR u.qq_number LIKE ? OR c.title LIKE ?)'; // 条件
    $like = '%' . $q . '%';                             // 通配
    array_push($params, $like, $like, $like, $like);    // 参数
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : ''; // WHERE
$total = (int)Db::scalar('SELECT COUNT(*) FROM conversations c LEFT JOIN users u ON u.id = c.user_id' . $sqlWhere, $params); // 总数
$offset = ($p - 1) * $size;                             // 偏移
$rows = Db::fetchAll('SELECT c.*, u.nickname, u.email, u.account_type, ' . // 会话列表
    '(SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.deleted = 0) AS message_count ' .
    'FROM conversations c LEFT JOIN users u ON u.id = c.user_id' . $sqlWhere .
    ' ORDER BY c.updated_at DESC LIMIT ? OFFSET ?', array_merge($params, [$size, $offset]));
?>
<div class="page-head"><div><h1>会话记录</h1><p>查看用户会话标题与消息内容</p></div></div>

<?php if ($detail): ?>
<div class="card">
    <h2>会话详情</h2>
    <div class="hint" style="margin-bottom:12px;">
        用户：<?= h($detail['nickname'] ?: '未知') ?>（<?= h($detail['email'] ?: 'QQ用户') ?>） ·
        标题：<?= h($detail['title'] ?: '未命名会话') ?> ·
        更新时间：<?= h(format_time($detail['updated_at'])) ?> ·
        <a href="index.php?page=conversations">返回列表</a>
    </div>
    <?php if ($images): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
            <?php foreach ($images as $img): ?>
                <a href="<?= h($img['url']) ?>" target="_blank" rel="noopener" title="<?= (int)$img['status'] === 2 ? '已判定违规' : ((int)$img['status'] === 0 ? '待审核' : '已通过') ?>">
                    <img src="<?= h($img['url']) ?>" loading="lazy" alt="聊天图片"
                         style="width:104px;height:104px;object-fit:cover;border-radius:8px;border:1px solid #eee;">
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!$messages): ?>
        <div class="empty-state">该会话暂无消息</div>
    <?php else: foreach ($messages as $msg): ?>
        <div class="msg-box">
            <div class="msg-head">
                <span><span class="badge badge-gray"><?= h($msg['role']) ?></span>
                    <?php if ($msg['model']): ?><span class="mono"><?= h($msg['model']) ?></span><?php endif; ?>
                    <?php if ((int)$msg['deleted'] === 1): ?><span class="badge badge-red">已删除</span><?php endif; ?>
                </span>
                <span><?= h(format_time($msg['created_at'])) ?></span>
            </div>
            <div class="msg-body"><?= h($msg['content']) ?></div>
            <?php $msgImages = array_values(array_filter($images, function ($img) use ($msg) {
                return $img['message_client_id'] === $msg['client_id'];
            })); ?>
            <?php if ($msgImages): ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
                    <?php foreach ($msgImages as $img): ?>
                        <a href="<?= h($img['url']) ?>" target="_blank" rel="noopener"
                           title="<?= (int)$img['status'] === 2 ? '已判定违规' : ((int)$img['status'] === 0 ? '待审核' : '已通过') ?>">
                            <img src="<?= h($img['url']) ?>" loading="lazy" alt="聊天图片"
                                 style="width:88px;height:88px;object-fit:cover;border-radius:6px;border:1px solid #eee;">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="filter-bar">
        <form method="get" class="inline-form" style="gap:10px;">
            <input type="hidden" name="page" value="conversations">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="邮箱 / 昵称 / 会话标题">
            <button class="btn" type="submit">搜索</button>
        </form>
        <div class="hint">共 <?= $total ?> 个会话</div>
    </div>
    <table class="table">
        <thead><tr><th>ID</th><th>用户</th><th>会话标题</th><th>消息数</th><th>创建时间</th><th>更新时间</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="empty">暂无会话</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= h($row['nickname'] ?: '未知') ?><div class="hint"><?= h($row['email'] ?: 'QQ用户') ?></div></td>
                <td><?= h($row['title'] ?: '未命名会话') ?></td>
                <td><?= (int)$row['message_count'] ?></td>
                <td><?= h(format_time($row['created_at'])) ?></td>
                <td><?= h(format_time($row['updated_at'])) ?></td>
                <td><span class="badge <?= (int)$row['deleted'] === 1 ? 'badge-red' : 'badge-green' ?>"><?= (int)$row['deleted'] === 1 ? '已删除' : '正常' ?></span></td>
                <td><a class="btn btn-sm" href="index.php?page=conversations&conversation_id=<?= (int)$row['id'] ?>">查看消息</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?= render_pagination($p, $total, $size, ['page' => 'conversations', 'q' => $q]) ?>
</div>
