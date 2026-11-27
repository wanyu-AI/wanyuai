<?php
// 用户管理：列表、积分调整、禁用、邮箱/QQ/头像管理与可信设备
$pageTitle = '用户管理';                                 // 页面标题
$breadcrumb = ['用户管理'];                               // 面包屑
$q = trim((string)($_GET['q'] ?? ''));                  // 搜索词
$type = (string)($_GET['type'] ?? '');                  // 账号类型筛选
$p = max(1, (int)($_GET['p'] ?? 1));                     // 页码
$size = 15;                                             // 每页数量
$backQuery = array_filter(['page' => 'users', 'q' => $q, 'type' => $type, 'p' => $p], function ($v) { return $v !== ''; }); // 保留筛选

if ($_SERVER['REQUEST_METHOD'] === 'POST') {             // 处理用户操作
    try {
        verify_post_csrf();                             // 校验CSRF
        $action = admin_post_action();                  // 动作
        $userId = (int)($_POST['user_id'] ?? 0);        // 目标用户
        switch ($action) {
            case 'add_points':                          // 增减积分
                $delta = (int)($_POST['delta'] ?? 0);   // 变动值
                $remark = trim((string)($_POST['remark'] ?? '后台调整')); // 备注
                if ($delta === 0) {                     // 不能为0
                    throw new ApiException(40060, '积分变动值不能为0'); // 拒绝
                }
                Points::add($userId, $delta, $delta > 0 ? 'admin_add' : 'admin_reduce', $remark); // 调整积分
                flash('success', '积分已调整：' . ($delta > 0 ? '+' : '') . $delta); // 提示
                break;
            case 'toggle_status':                       // 启用/禁用
                Db::exec('UPDATE users SET status = 1 - status WHERE id = ?', [$userId]); // 切换状态
                flash('success', '账号状态已切换');        // 提示
                break;
            case 'set_email':                           // 绑定/修改邮箱
                $email = strtolower(trim((string)($_POST['email'] ?? ''))); // 邮箱
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // 校验
                    throw new ApiException(40061, '邮箱格式不正确'); // 拒绝
                }
                $exists = Db::fetch('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId]); // 占用检查
                if ($exists) {                          // 已被使用
                    throw new ApiException(40062, '该邮箱已绑定其他账号'); // 拒绝
                }
                Db::exec('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]); // 更新
                flash('success', '邮箱已更新');            // 提示
                break;
            case 'reset_email':                         // 重置（清空）邮箱
                Db::exec('UPDATE users SET email = NULL WHERE id = ?', [$userId]); // 清空
                flash('success', '邮箱已重置');            // 提示
                break;
            case 'unbind_qq':                           // 解绑QQ
                $row = Db::fetch('SELECT email FROM users WHERE id = ?', [$userId]); // 查询用户
                if (!$row || empty($row['email'])) {    // 无邮箱解绑会锁定账号
                    throw new ApiException(40063, '该用户未绑定邮箱，解绑QQ后将无法登录，请先补录邮箱'); // 拒绝
                }
                Db::exec('UPDATE users SET qq_openid = NULL, qq_number = NULL WHERE id = ?', [$userId]); // 解绑
                flash('success', 'QQ已解绑，用户仍可通过邮箱登录'); // 提示
                break;
            case 'set_avatar':                          // 修改头像
                $avatar = trim((string)($_POST['avatar'] ?? '')); // 地址
                if (!preg_match('#^https://#i', $avatar)) { // 只允许HTTPS
                    throw new ApiException(40064, '头像必须为HTTPS地址'); // 拒绝
                }
                Db::exec('UPDATE users SET avatar = ? WHERE id = ?', [mb_substr($avatar, 0, 500), $userId]); // 更新
                flash('success', '头像已更新');            // 提示
                break;
            case 'add_user':                            // 新增用户
                $email = strtolower(trim((string)($_POST['new_email'] ?? ''))); // 邮箱
                $nickname = trim((string)($_POST['new_nickname'] ?? '')); // 昵称
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // 校验
                    throw new ApiException(40065, '新增用户邮箱格式不正确'); // 拒绝
                }
                if (Auth::findUserByEmail($email)) {    // 已存在
                    throw new ApiException(40066, '该邮箱用户已存在'); // 拒绝
                }
                $newUser = Auth::createEmailUser($email); // 创建
                if ($nickname !== '') {                 // 有昵称则更新
                    Db::exec('UPDATE users SET nickname = ? WHERE id = ?', [mb_substr($nickname, 0, 60), (int)$newUser['id']]); // 更新
                }
                flash('success', '用户已创建');            // 提示
                break;
            default:                                    // 未知动作
                throw new ApiException(40067, '未知操作'); // 拒绝
        }
    } catch (Throwable $e) {                            // 捕获异常
        flash('error', $e->getMessage());               // 错误提示
    }
    admin_redirect('users', array_filter(['q' => $q, 'type' => $type, 'p' => $p], function ($v) { return $v !== ''; })); // 返回列表
}

$where = [];                                            // 查询条件
$params = [];                                           // 参数
if ($q !== '') {                                        // 搜索
    $where[] = '(email LIKE ? OR nickname LIKE ? OR qq_number LIKE ? OR qq_openid LIKE ?)'; // 模糊匹配
    $like = '%' . $q . '%';                             // 通配
    array_push($params, $like, $like, $like, $like);    // 追加参数
}
if (in_array($type, ['email', 'qq'], true)) {           // 类型筛选
    $where[] = 'account_type = ?';                      // 条件
    $params[] = $type;                                  // 参数
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : ''; // 拼接WHERE
$total = (int)Db::scalar('SELECT COUNT(*) FROM users' . $sqlWhere, $params); // 总数
$offset = ($p - 1) * $size;                             // 偏移
$users = Db::fetchAll('SELECT * FROM users' . $sqlWhere . ' ORDER BY id DESC LIMIT ? OFFSET ?', array_merge($params, [$size, $offset])); // 用户列表
$deviceUserId = (int)($_GET['devices'] ?? 0);           // 查看设备用户
$devices = $deviceUserId ? Db::fetchAll('SELECT * FROM user_devices WHERE user_id = ? ORDER BY last_login_at DESC', [$deviceUserId]) : []; // 设备列表
?>
<div class="page-head">
    <div><h1>用户管理</h1><p>管理账号、积分、邮箱、QQ授权与可信设备</p></div>
    <div class="hint">共 <?= $total ?> 位用户</div>
</div>

<div class="card">
    <h2>新增用户</h2>
    <form method="post" class="form-row">
        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
        <input type="hidden" name="action" value="add_user">
        <div><label>邮箱</label><input type="email" name="new_email" placeholder="user@example.com" required></div>
        <div><label>昵称（可选）</label><input type="text" name="new_nickname" placeholder="昵称"></div>
        <div style="display:flex;align-items:flex-end;"><button class="btn btn-primary" type="submit">创建用户</button></div>
    </form>
</div>

<div class="card">
    <div class="filter-bar">
        <form method="get" class="inline-form" style="gap:10px;">
            <input type="hidden" name="page" value="users">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="邮箱 / 昵称 / QQ号">
            <select name="type">
                <option value="">全部来源</option>
                <option value="email" <?= $type === 'email' ? 'selected' : '' ?>>邮箱注册</option>
                <option value="qq" <?= $type === 'qq' ? 'selected' : '' ?>>QQ注册</option>
            </select>
            <button class="btn" type="submit">筛选</button>
        </form>
    </div>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>ID</th><th>账号</th><th>来源</th><th>邮箱 / QQ</th><th>积分</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="8" class="empty">暂无用户</td></tr>
        <?php else: foreach ($users as $u): ?>
            <tr>
                <td><?= (int)$u['id'] ?></td>
                <td>
                    <div><strong><?= h($u['nickname'] ?: '未设置') ?></strong></div>
                    <?php if ($u['avatar']): ?><a class="hint" href="<?= h($u['avatar']) ?>" target="_blank" rel="noopener">头像</a><?php endif; ?>
                </td>
                <td><span class="badge <?= $u['account_type'] === 'qq' ? 'badge-blue' : 'badge-gray' ?>"><?= $u['account_type'] === 'qq' ? 'QQ' : '邮箱' ?></span></td>
                <td>
                    <div class="break"><?= h($u['email'] ?: '未绑定邮箱') ?></div>
                    <div class="hint">
                        <?php if ($u['qq_number']): ?>QQ号：<?= h($u['qq_number']) ?>
                        <?php elseif ($u['qq_openid']): ?>QQ号为空（应用权限限制）<span class="mono">openid:<?= h(Security::maskString($u['qq_openid'], 6)) ?></span>
                        <?php else: ?>-<?php endif; ?>
                    </div>
                </td>
                <td><strong><?= (int)$u['points'] ?></strong></td>
                <td><span class="badge <?= (int)$u['status'] === 1 ? 'badge-green' : 'badge-red' ?>"><?= (int)$u['status'] === 1 ? '正常' : '禁用' ?></span></td>
                <td><?= h(format_time($u['created_at'])) ?></td>
                <td class="op-cell">
                    <div class="op-row">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="add_points">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <input type="number" name="delta" placeholder="+/-积分" style="width:86px;" required>
                            <input type="text" name="remark" placeholder="备注" style="width:96px;">
                            <button class="btn btn-sm" type="submit">调整</button>
                        </form>
                        <form method="post" class="inline-form" onsubmit="return confirm('确认切换该用户状态？');">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm <?= (int)$u['status'] === 1 ? 'btn-danger' : '' ?>" type="submit"><?= (int)$u['status'] === 1 ? '禁用' : '启用' ?></button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="set_email">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <input type="email" name="email" placeholder="补录/修改邮箱" style="width:150px;">
                            <button class="btn btn-sm" type="submit">保存邮箱</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                            <input type="hidden" name="action" value="set_avatar">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <input type="url" name="avatar" placeholder="头像HTTPS地址" style="width:150px;">
                            <button class="btn btn-sm" type="submit">改头像</button>
                        </form>
                        <div class="op-group">
                            <form method="post" onsubmit="return confirm('确认重置该用户邮箱？');">
                                <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                                <input type="hidden" name="action" value="reset_email">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit">重置邮箱</button>
                            </form>
                            <?php if ($u['qq_openid']): ?>
                                <form method="post" onsubmit="return confirm('确认解绑该用户QQ？');">
                                    <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
                                    <input type="hidden" name="action" value="unbind_qq">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit">解绑QQ</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn-sm" href="index.php?page=users&devices=<?= (int)$u['id'] ?>">可信设备</a>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <?= render_pagination($p, $total, $size, ['page' => 'users', 'q' => $q, 'type' => $type]) ?>
</div>

<?php if ($deviceUserId): ?>
<div class="card">
    <h2>用户 #<?= $deviceUserId ?> 可信设备</h2>
    <table class="table">
        <thead><tr><th>设备指纹</th><th>设备名称</th><th>首次登录</th><th>最后登录</th><th>状态</th></tr></thead>
        <tbody>
        <?php if (!$devices): ?>
            <tr><td colspan="5" class="empty">暂无设备记录</td></tr>
        <?php else: foreach ($devices as $d): ?>
            <tr>
                <td class="mono break"><?= h($d['device_id']) ?></td>
                <td><?= h($d['device_name']) ?></td>
                <td><?= h(format_time($d['first_login_at'])) ?></td>
                <td><?= h(format_time($d['last_login_at'])) ?></td>
                <td><span class="badge <?= (int)$d['is_trusted'] === 1 ? 'badge-green' : 'badge-amber' ?>"><?= (int)$d['is_trusted'] === 1 ? '可信' : '待核验' ?></span></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
