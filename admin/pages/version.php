<?php
// 版本更新管理：新增版本、启停、强制更新开关
$pageTitle = '版本更新管理';                              // 标题
$breadcrumb = ['版本更新管理'];                            // 面包屑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {             // 处理操作
    try {
        verify_post_csrf();                             // CSRF
        $action = admin_post_action();                  // 动作
        if ($action === 'add') {                        // 新增版本
            $code = (int)($_POST['version_code'] ?? 0); // 版本号
            $name = trim((string)($_POST['version_name'] ?? '')); // 版本名
            $changelog = trim((string)($_POST['changelog'] ?? '')); // 更新说明
            $url = trim((string)($_POST['download_url'] ?? '')); // 下载地址
            $force = isset($_POST['is_force']) ? 1 : 0; // 强制
            $enabled = isset($_POST['is_enabled']) ? 1 : 0; // 启用
            if ($code <= 0 || $name === '') {           // 校验
                throw new ApiException(40080, '版本号与版本名必填'); // 拒绝
            }
            if (!preg_match('#^https://#i', $url)) {   // APK地址必须HTTPS
                throw new ApiException(40081, 'APK下载地址必须为HTTPS'); // 拒绝
            }
            Db::insert('INSERT INTO app_versions (version_code, version_name, changelog, download_url, is_force, is_enabled) ' . // 插入
                'VALUES (?, ?, ?, ?, ?, ?)', [$code, $name, $changelog, $url, $force, $enabled]);
            flash('success', '版本已发布');                // 提示
        } elseif ($action === 'toggle') {               // 启停
            $id = (int)($_POST['id'] ?? 0);             // ID
            Db::exec('UPDATE app_versions SET is_enabled = 1 - is_enabled WHERE id = ?', [$id]); // 切换
            flash('success', '启用状态已切换');            // 提示
        } elseif ($action === 'toggle_force') {         // 强制开关
            $id = (int)($_POST['id'] ?? 0);             // ID
            Db::exec('UPDATE app_versions SET is_force = 1 - is_force WHERE id = ?', [$id]); // 切换
            flash('success', '强制更新状态已切换');        // 提示
        } elseif ($action === 'delete') {               // 删除
            $id = (int)($_POST['id'] ?? 0);             // ID
            Db::exec('DELETE FROM app_versions WHERE id = ?', [$id]); // 删除
            flash('success', '版本记录已删除');            // 提示
        }
    } catch (Throwable $e) {                            // 异常
        flash('error', $e->getMessage());               // 提示
    }
    admin_redirect('version');                          // 返回
}
$rows = Db::fetchAll('SELECT * FROM app_versions ORDER BY version_code DESC, id DESC'); // 版本列表
?>
<div class="page-head"><div><h1>版本更新管理</h1><p>App 启动时按版本号检测更新</p></div></div>
<div class="card">
    <h2>发布新版本</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <div class="field"><label>版本号（整数）</label><input type="number" name="version_code" placeholder="例如 12" min="1" required></div>
            <div class="field"><label>版本名</label><input type="text" name="version_name" placeholder="例如 1.2.0" required></div>
            <div class="field"><label>APK 下载地址（HTTPS）</label><input type="url" name="download_url" placeholder="https://example.com/app.apk" required></div>
        </div>
        <div class="field"><label>更新说明</label><textarea name="changelog" placeholder="每行一条更新内容"></textarea></div>
        <div class="form-actions">
            <label style="display:flex;align-items:center;gap:6px;margin:0;"><input type="checkbox" name="is_force" value="1" style="width:auto;"> 强制更新</label>
            <label style="display:flex;align-items:center;gap:6px;margin:0;"><input type="checkbox" name="is_enabled" value="1" style="width:auto;" checked> 立即启用</label>
            <button class="btn btn-primary" type="submit">发布</button>
        </div>
    </form>
</div>
<div class="card">
    <h2>版本列表</h2>
    <table class="table">
        <thead><tr><th>版本号</th><th>版本名</th><th>更新说明</th><th>下载地址</th><th>强制</th><th>启用</th><th>发布时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="empty">暂无版本记录</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><?= (int)$row['version_code'] ?></td>
                <td><strong><?= h($row['version_name']) ?></strong></td>
                <td style="max-width:260px;"><div class="msg-body"><?= h($row['changelog']) ?></div></td>
                <td class="break" style="max-width:240px;"><a href="<?= h($row['download_url']) ?>" target="_blank" rel="noopener"><?= h($row['download_url']) ?></a></td>
                <td><span class="badge <?= (int)$row['is_force'] === 1 ? 'badge-red' : 'badge-gray' ?>"><?= (int)$row['is_force'] === 1 ? '强制' : '可选' ?></span></td>
                <td><span class="badge <?= (int)$row['is_enabled'] === 1 ? 'badge-green' : 'badge-gray' ?>"><?= (int)$row['is_enabled'] === 1 ? '启用' : '停用' ?></span></td>
                <td><?= h(format_time($row['created_at'])) ?></td>
                <td>
                    <div class="inline-form">
                        <form method="post"><input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm" type="submit">启停</button></form>
                        <form method="post"><input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>"><input type="hidden" name="action" value="toggle_force"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm" type="submit">强制/可选</button></form>
                        <form method="post" onsubmit="return confirm('确认删除该版本记录？');"><input type="hidden" name="csrf" value="<?= h(AdminAuth::csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">删除</button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
