<?php
// 账号风控：封禁/冻结列表、解封、申诉审核、AI 内容审核开关
$pageTitle = '账号风控';                                   // 页面标题
$breadcrumb = ['账号风控'];                                 // 面包屑
Risk::ensureSchema();                                      // 确保风控表结构存在
$filter = (string)($_GET['filter'] ?? '');                 // 状态筛选
$p = max(1, (int)($_GET['p'] ?? 1));                       // 页码
$size = 15;                                                // 每页数量

if ($_SERVER['REQUEST_METHOD'] === 'POST') {                // 处理操作
    try {
        verify_post_csrf();                                // 校验CSRF
        $action = admin_post_action();                     // 动作
        $userId = (int)($_POST['user_id'] ?? 0);           // 目标用户
        switch ($action) {
            case 'ban':                                    // 封禁账号
                if ($userId <= 0) {                        // 参数校验
                    throw new ApiException(40070, '请选择要封禁的用户'); // 拒绝
                }
                $reason = trim((string)($_POST['reason'] ?? '')); // 原因
                $custom = trim((string)($_POST['custom_reason'] ?? '')); // 自定义原因
                if ($custom !== '') {                      // 自定义优先
                    $reason = $custom;                     // 使用自定义
                }
                if ($reason === '') {                      // 必须有原因
                    throw new ApiException(40071, '请填写封禁原因'); // 拒绝
                }
                $days = (int)($_POST['days'] ?? 7);        // 封禁天数，0=永久
                Risk::ban($userId, mb_substr($reason, 0, 255), $days, 'admin:' . ($admin['username'] ?? '')); // 执行封禁
                flash('success', $days > 0 ? ('账号已封禁 ' . $days . ' 天') : '账号已永久封禁'); // 提示
                break;
            case 'freeze':                                 // 手动冻结
                if ($userId <= 0) {                        // 参数校验
                    throw new ApiException(40070, '请选择要冻结的用户'); // 拒绝
                }
                Risk::freeze($userId, '后台手动冻结', 'admin:' . ($admin['username'] ?? '')); // 冻结
                flash('success', '账号已冻结');              // 提示
                break;
            case 'release':                                // 解封/解冻
                Risk::release($userId, 'admin:' . ($admin['username'] ?? ''), '后台解除风控'); // 解除
                flash('success', '账号已解除风控');           // 提示
                break;
            case 'approve_appeal':                         // 申诉通过
                $appealId = (int)($_POST['appeal_id'] ?? 0); // 申诉ID
                $appeal = Db::fetch('SELECT * FROM ban_appeals WHERE id = ?', [$appealId]); // 查询
                if (!$appeal) {                            // 不存在
                    throw new ApiException(40072, '申诉记录不存在'); // 拒绝
                }
                Db::exec('UPDATE ban_appeals SET status = 1, reviewer = ?, reviewed_at = NOW() WHERE id = ?', // 标记通过
                    [$admin['username'] ?? 'admin', $appealId]);
                Risk::release((int)$appeal['user_id'], 'admin:' . ($admin['username'] ?? ''), '申诉审核通过'); // 解封
                flash('success', '申诉已通过，账号已解封');     // 提示
                break;
            case 'reject_appeal':                          // 申诉驳回
                $appealId = (int)($_POST['appeal_id'] ?? 0); // 申诉ID
                $reply = trim((string)($_POST['reply'] ?? '申诉未通过')); // 回复
                Db::exec('UPDATE ban_appeals SET status = 2, reply = ?, reviewer = ?, reviewed_at = NOW() WHERE id = ?', // 标记驳回
                    [mb_substr($reply, 0, 500), $admin['username'] ?? 'admin', $appealId]);
                flash('success', '申诉已驳回');               // 提示
                break;
            case 'toggle_violation':                       // AI 内容审核开关
                Config::setDb('violation_check_enabled', Config::db('violation_check_enabled', '1') === '1' ? '0' : '1'); // 切换
                flash('success', 'AI 内容审核已切换');         // 提示
                break;
            case 'toggle_foreign':                         // 异地登录检测开关
                Config::setDb('risk_foreign_login_enabled', Config::db('risk_foreign_login_enabled', '1') === '1' ? '0' : '1'); // 切换
                flash('success', '异地登录检测已切换');         // 提示
                break;
            default:                                       // 未知动作
                throw new ApiException(40074, '未知操作');  // 拒绝
        }
    } catch (Throwable $e) {                               // 捕获异常
        flash('error', $e->getMessage());                  // 错误提示
    }
    admin_redirect('risk', array_filter(['filter' => $filter, 'p' => $p], function ($v) { return $v !== ''; })); // 返回
}

$where = 'risk_status IN (1, 2)';                          // 默认只看风控中账号
if ($filter === '1') {                                     // 只看封禁
    $where = 'risk_status = 1';
} elseif ($filter === '2') {                               // 只看冻结
    $where = 'risk_status = 2';
}
$total = (int)Db::scalar('SELECT COUNT(*) FROM users WHERE ' . $where); // 总数
$offset = ($p - 1) * $size;                                // 偏移
$users = Db::fetchAll('SELECT * FROM users WHERE ' . $where . ' ORDER BY risk_at DESC, id DESC LIMIT ? OFFSET ?', [$size, $offset]); // 风控账号
$appeals = Db::fetchAll('SELECT a.*, u.nickname, u.email FROM ban_appeals a LEFT JOIN users u ON u.id = a.user_id ' . // 待审核申诉
    'WHERE a.status = 0 ORDER BY a.id DESC LIMIT 20');
$events = Db::fetchAll('SELECT * FROM risk_events ORDER BY id DESC LIMIT 20'); // 最近事件
$violationOn = Config::db('violation_check_enabled', '1') === '1'; // AI 内容审核开关
$foreignOn = Config::db('risk_foreign_login_enabled', '1') === '1'; // 异地开关
?>
<div class="page-head">
    <div><h1>账号风控</h1><p>封禁、冻结、申诉审核与 AI 内容审核</p></div>
    <div class="hint">风控中账号：<?= $total ?> 个</div>
</div>

<div class="card">
    <div class="filter-bar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <form method="get" class="inline-form">
            <input type="hidden" name="page" value="risk">
            <select name="filter">
                <option value="">全部风控账号</option>
                <option value="1" <?= $filter === '1' ? 'selected' : '' ?>>仅封禁</option>
                <option value="2" <?= $filter === '2' ? 'selected' : '' ?>>仅冻结</option>
            </select>
            <button class="btn" type="submit">筛选</button>
        </form>
        <form method="post" class="inline-form">
            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
            <input type="hidden" name="action" value="toggle_violation">
            <button class="btn btn-sm <?= $violationOn ? '' : 'btn-danger' ?>" type="submit">AI 内容审核：<?= $violationOn ? '已开启' : '已关闭' ?></button>
        </form>
        <form method="post" class="inline-form">
            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
            <input type="hidden" name="action" value="toggle_foreign">
            <button class="btn btn-sm <?= $foreignOn ? '' : 'btn-danger' ?>" type="submit">异地登录检测：<?= $foreignOn ? '已开启' : '已关闭' ?></button>
        </form>
    </div>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>ID</th><th>用户</th><th>风控状态</th><th>警告次数</th><th>原因</th><th>解封时间</th><th>最后登录IP</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="8" class="empty">暂无风控中的账号</td></tr>
        <?php else: foreach ($users as $u): ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td>
                    <div><strong><?= h($u['nickname'] ?: '未设置') ?></strong></div>
                    <div class="hint"><?= h($u['email'] ?: '未绑定邮箱') ?></div>
                </td>
                <td><span class="badge <?= (int)$u['risk_status'] === 1 ? 'badge-red' : 'badge-amber' ?>"><?= (int)$u['risk_status'] === 1 ? '封禁' : '冻结' ?></span></td>
                <td><?= (int)($u['violation_warnings'] ?? 0) ?> / <?= Risk::WARNING_LIMIT ?></td>
                <td class="break" style="max-width:220px;"><?= h($u['risk_reason']) ?></td>
                <td><?= $u['risk_until'] ? h(format_time($u['risk_until'])) : ((int)$u['risk_status'] === 1 ? '永久' : '需邮箱解冻') ?></td>
                <td class="mono"><?= h($u['last_login_ip'] ?: '-') ?></td>
                <td class="op-cell">
                    <div class="op-row">
                        <form method="post" class="inline-form" onsubmit="return confirm('确认解除该账号风控？');">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="release">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm" type="submit">解除风控</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="ban">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <select name="reason" style="width:150px;">
                                <option value="消息内容不合法">消息内容不合法</option>
                                <option value="涉嫌违规">涉嫌违规</option>
                                <option value="头像涉嫌违规">头像涉嫌违规</option>
                                <option value="昵称涉嫌违规">昵称涉嫌违规</option>
                                <option value="其他违规行为">其他违规行为</option>
                            </select>
                            <select name="days" style="width:80px;">
                                <option value="7">7天</option>
                                <option value="30">30天</option>
                                <option value="0">永久</option>
                            </select>
                            <button class="btn btn-sm btn-danger" type="submit">封禁</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <?= render_pagination($p, $total, $size, ['page' => 'risk', 'filter' => $filter]) ?>
</div>

<div class="card">
    <h2>手动封禁 / 冻结</h2>
    <form method="post" class="form-row">
        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
        <input type="hidden" name="action" value="ban">
        <div><label>用户 ID</label><input type="number" name="user_id" min="1" required></div>
        <div><label>封禁原因</label>
            <select name="reason">
                <option value="消息内容不合法">消息内容不合法</option>
                <option value="涉嫌违规">涉嫌违规</option>
                <option value="头像涉嫌违规">头像涉嫌违规</option>
                <option value="昵称涉嫌违规">昵称涉嫌违规</option>
                <option value="其他违规行为">其他违规行为</option>
            </select>
        </div>
        <div><label>封禁天数</label>
            <select name="days">
                <option value="7">7天</option>
                <option value="30">30天</option>
                <option value="0">永久</option>
            </select>
        </div>
        <div><label>自定义原因（可选）</label><input type="text" name="custom_reason" placeholder="填写后覆盖上面的原因"></div>
        <div style="display:flex;align-items:flex-end;"><button class="btn btn-primary" type="submit">确认封禁</button></div>
    </form>
    <p class="hint">封禁后用户仍可登录查看提示，并可在输入框提交申诉；冻结需用户通过绑定邮箱验证码解冻。</p>
</div>

<div class="card">
    <h2>申诉审核（待处理 <?= count($appeals) ?> 条）</h2>
    <table class="table">
        <thead><tr><th>ID</th><th>用户</th><th>申诉内容</th><th>提交时间</th><th>审核</th></tr></thead>
        <tbody>
        <?php if (!$appeals): ?>
            <tr><td colspan="5" class="empty">暂无待审核申诉</td></tr>
        <?php else: foreach ($appeals as $a): ?>
            <tr>
                <td><?= (int)$a['id'] ?></td>
                <td>
                    <div><strong><?= h($a['nickname'] ?: ('#' . (int)$a['user_id'])) ?></strong></div>
                    <div class="hint"><?= h($a['email'] ?: '未绑定邮箱') ?></div>
                </td>
                <td class="break" style="max-width:320px;"><?= h($a['content']) ?></td>
                <td><?= h(format_time($a['created_at'])) ?></td>
                <td class="op-cell">
                    <div class="op-row">
                        <form method="post" class="inline-form" onsubmit="return confirm('通过申诉并解封该账号？');">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="approve_appeal">
                            <input type="hidden" name="appeal_id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-sm" type="submit">通过并解封</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="reject_appeal">
                            <input type="hidden" name="appeal_id" value="<?= (int)$a['id'] ?>">
                            <input type="text" name="reply" placeholder="驳回原因" style="width:160px;">
                            <button class="btn btn-sm btn-danger" type="submit">驳回</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>AI 内容审核</h2>
    <p class="hint">官方 DeepSeek 模型发送前由 AI 审核内容：违规时自动删除该条消息并警告，累计警告超过 <?= Risk::WARNING_LIMIT ?> 次后封禁，封禁天数由 AI 按严重程度判定（1-30 天）。用户自填的第三方提供商走客户端直连，不经过后端审核。</p>
</div>

<div class="card">
    <h2>最近风控事件</h2>
    <table class="table">
        <thead><tr><th>时间</th><th>用户</th><th>类型</th><th>动作</th><th>原因</th><th>操作者</th><th>IP</th></tr></thead>
        <tbody>
        <?php if (!$events): ?>
            <tr><td colspan="7" class="empty">暂无事件</td></tr>
        <?php else: foreach ($events as $e): ?>
            <tr>
                <td><?= h(format_time($e['created_at'])) ?></td>
                <td><?= (int)$e['user_id'] ?></td>
                <td><?= h($e['event_type']) ?></td>
                <td><?= h($e['action']) ?></td>
                <td class="break" style="max-width:260px;"><?= h($e['reason']) ?></td>
                <td><?= h($e['operator']) ?></td>
                <td class="mono"><?= h($e['ip']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
