<?php
// 同步控制器：会话与消息的跨设备同步（增量推送/拉取）
class SyncController
{
    public static function push(): void                 // 上传本地会话与消息
    {
        $user = Auth::requireUser(true);                // 必须登录
        $audit = (bool)Req::input('audit', false);       // 审计上传：官方会话的完整记录，不参与7天清理
        if (!$audit) {                                  // 普通同步才清理旧数据
            self::cleanup((int)$user['id']);             // 惰性清理7天前的云端对话
        }
        $conversations = Req::input('conversations', []); // 会话数组
        if (!is_array($conversations)) {                // 类型校验
            throw new ApiException(40030, 'conversations格式错误'); // 拒绝
        }
        if (count($conversations) > 20) {               // 单次限制
            throw new ApiException(40031, '单次最多同步20个会话'); // 拒绝
        }
        $mapping = [];                                  // 客户端会话ID => 服务端ID
        Db::begin();                                    // 开启事务
        try {
            foreach ($conversations as $conv) {         // 逐会话处理
                if (!is_array($conv) || empty($conv['client_id'])) { // 必须有client_id
                    throw new ApiException(40032, '会话缺少client_id'); // 拒绝
                }
                $clientId = mb_substr((string)$conv['client_id'], 0, 64); // 客户端ID
                $title = mb_substr((string)($conv['title'] ?? ''), 0, 240); // 标题
                $created = self::datetime($conv['created_at'] ?? ''); // 创建时间
                $updated = self::datetime($conv['updated_at'] ?? ''); // 更新时间
                $deleted = !empty($conv['deleted']) ? 1 : 0; // 删除标记
                $existing = Db::fetch('SELECT id FROM conversations WHERE user_id = ? AND client_id = ?', // 查询
                    [(int)$user['id'], $clientId]);
                if ($existing) {                        // 已存在则更新
                    $serverId = (int)$existing['id'];   // 服务端ID
                    Db::exec('UPDATE conversations SET title = ?, updated_at = ?, deleted = ? WHERE id = ?', // 更新
                        [$title, $updated, $deleted, $serverId]);
                } else {                                // 新建
                    $serverId = Db::insert('INSERT INTO conversations (user_id, client_id, title, created_at, updated_at, deleted) ' . // 插入
                        'VALUES (?, ?, ?, ?, ?, ?)',
                        [(int)$user['id'], $clientId, $title, $created, $updated, $deleted]);
                }
                $mapping[$clientId] = $serverId;        // 记录映射

                $messages = $conv['messages'] ?? [];    // 消息数组
                if (!is_array($messages)) {             // 类型校验
                    continue;                           // 跳过非法会话
                }
                if (count($messages) > 300) {           // 单会话限制
                    $messages = array_slice($messages, 0, 300); // 截断
                }
                foreach ($messages as $msg) {           // 逐条消息
                    if (!is_array($msg) || empty($msg['client_id'])) { // 必须带客户端ID
                        continue;                       // 跳过
                    }
                    $msgClientId = mb_substr((string)$msg['client_id'], 0, 64); // 消息ID
                    $role = (string)($msg['role'] ?? 'user'); // 角色
                    if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) { // 白名单
                        $role = 'user';                 // 兜底
                    }
                    $content = (string)($msg['content'] ?? ''); // 内容
                    if (mb_strlen($content) > 200000) { // 限制长度
                        $content = mb_substr($content, 0, 200000); // 截断
                    }
                    $model = mb_substr((string)($msg['model'] ?? ''), 0, 64); // 模型
                    $meta = isset($msg['meta']) ? json_encode($msg['meta'], JSON_UNESCAPED_UNICODE) : null; // 扩展字段
                    $msgCreated = self::datetime($msg['created_at'] ?? ''); // 时间
                    $msgDeleted = !empty($msg['deleted']) ? 1 : 0; // 删除
                    Db::exec('INSERT INTO messages (user_id, conversation_id, client_id, role, content, model, meta, created_at, deleted) ' . // 插入或更新
                        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content), ' . // 更新内容
                        'role = VALUES(role), model = VALUES(model), meta = VALUES(meta), deleted = VALUES(deleted)',
                        [(int)$user['id'], $serverId, $msgClientId, $role, $content, $model, $meta, $msgCreated, $msgDeleted]);
                }
            }
            Db::commit();                               // 提交事务
        } catch (Throwable $e) {                        // 失败
            Db::rollback();                             // 回滚
            throw $e;                                   // 继续抛出
        }
        Resp::ok(['mapping' => $mapping, 'server_time' => date('Y-m-d H:i:s')], '同步完成'); // 返回
    }

    public static function pull(): void                 // 拉取云端增量
    {
        $user = Auth::requireUser(true, false);         // 查看历史不拦截封禁/冻结
        self::cleanup((int)$user['id']);                // 惰性清理7天前的云端对话
        $sinceRaw = Req::string('since');               // 原始增量起点
        $sinceWhere = $sinceRaw === '' ? '' : self::datetime($sinceRaw); // 空起点表示全量
        $convRows = Db::fetchAll('SELECT id, client_id, title, created_at, updated_at, deleted FROM conversations ' . // 查询会话
            'WHERE user_id = ?' . ($sinceWhere ? ' AND updated_at >= ?' : '') . ' ORDER BY updated_at DESC LIMIT 100',
            $sinceWhere ? [(int)$user['id'], $sinceWhere] : [(int)$user['id']]);
        $conversations = [];                            // 输出会话
        $convIds = [];                                  // 会话ID集合
        foreach ($convRows as $row) {                   // 逐个转换
            $conversations[] = [
                'server_id' => (int)$row['id'],         // 服务端ID
                'client_id' => $row['client_id'],       // 客户端ID
                'title'     => $row['title'],           // 标题
                'created_at'=> $row['created_at'],      // 创建时间
                'updated_at'=> $row['updated_at'],      // 更新时间
                'deleted'   => (int)$row['deleted'],    // 删除标记
            ];
            $convIds[] = (int)$row['id'];               // 收集ID
        }
        $messages = [];                                 // 输出消息
        if (count($convIds) > 0) {                      // 有会话才查消息
            $placeholders = implode(',', array_fill(0, count($convIds), '?')); // 占位符
            $sql = 'SELECT conversation_id, client_id, role, content, model, created_at, deleted FROM messages ' . // 查询消息
                'WHERE user_id = ? AND conversation_id IN (' . $placeholders . ')' .
                ($sinceWhere ? ' AND updated_at >= ?' : '') .
                ' ORDER BY id ASC LIMIT 2000';
            $params = array_merge([(int)$user['id']], $convIds, $sinceWhere ? [$sinceWhere] : []); // 参数
            foreach (Db::fetchAll($sql, $params) as $row) { // 转换消息
                $messages[] = [
                    'server_conversation_id' => (int)$row['conversation_id'], // 服务端会话ID
                    'client_id'  => $row['client_id'], // 消息ID
                    'role'       => $row['role'], // 角色
                    'content'    => $row['content'], // 内容
                    'model'      => $row['model'], // 模型
                    'created_at' => $row['created_at'], // 时间
                    'deleted'    => (int)$row['deleted'], // 删除
                ];
            }
        }
        Resp::ok([
            'conversations' => $conversations,          // 会话列表
            'messages'      => $messages,               // 消息列表
            'server_time'   => date('Y-m-d H:i:s'),     // 服务端时间，下次作为since
        ]);                                             // 返回
    }

    // 聊天图片上传：官方会话中用户发送的图片，保存后供后台人工审核
    public static function uploadImage(): void
    {
        $user = Auth::requireUser(true);                              // 必须登录且设备可信
        $userId = (int)$user['id'];                                   // 用户ID
        RateLimiter::require('chat_image:' . $userId, 30, 60, '图片上传过于频繁'); // 每用户每分钟最多30张
        self::ensureImageTable();                                     // 确保审核表存在
        $convId = mb_substr(trim(Req::string('conversation_client_id')), 0, 64); // 客户端会话ID
        $msgId = mb_substr(trim(Req::string('message_client_id')), 0, 64);       // 客户端消息ID
        $index = max(0, (int)Req::input('index', 0));                 // 图片在消息中的序号
        if ($convId === '' || $msgId === '') {                        // 必须有定位信息
            throw new ApiException(40041, '缺少会话或消息ID');
        }
        $exists = Db::fetch('SELECT id, url, status FROM chat_images WHERE user_id = ? AND message_client_id = ? AND image_index = ?',
            [$userId, $msgId, $index]);                               // 已上传过则直接返回
        if ($exists) {
            Resp::ok(['id' => (int)$exists['id'], 'url' => $exists['url'], 'status' => (int)$exists['status'], 'duplicated' => true]);
            return;
        }
        $raw = Req::string('image');                                  // data URL 或纯 base64
        if ($raw === '') {
            throw new ApiException(40022, '图片内容不能为空');
        }
        if (preg_match('#^data:[^;,]+;base64,#i', $raw, $m)) {        // 去掉 data URL 前缀
            $raw = substr($raw, strlen($m[0]));
        }
        if (strlen($raw) > 8 * 1024 * 1024) {                         // base64 过大先拦截
            throw new ApiException(40023, '图片过大');
        }
        $bytes = base64_decode($raw, true);                           // 严格模式解码
        if ($bytes === false || $bytes === '') {
            throw new ApiException(40024, '图片数据格式错误');
        }
        if (strlen($bytes) > 6 * 1024 * 1024) {                       // 解码后大小限制
            throw new ApiException(40023, '图片过大');
        }
        $info = @getimagesizefromstring($bytes);                      // 读取真实图片信息
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if ($info === false || !isset($types[$info[2]])) {            // 非图片或格式不支持
            throw new ApiException(40025, '仅支持 JPG/PNG/WebP 图片');
        }
        $ext = $types[$info[2]];                                      // 按真实格式取扩展名
        $dir = WANYU_ROOT . '/storage/chat_images/' . $userId;        // 按用户分目录存放
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException(50003, '图片目录不可写', 500);
        }
        $safeMsgId = preg_match('/^[A-Za-z0-9_-]+$/', $msgId) ? $msgId : md5($msgId); // 文件名安全化
        $name = $safeMsgId . '_' . $index . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) === false) { // 写入文件
            throw new ApiException(50003, '图片写入失败', 500);
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');                // 当前域名
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {        // 防止 Host 头注入
            throw new ApiException(50003, '无法生成图片地址', 500);
        }
        $base = rtrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/'); // 入口路径
        $url = 'https://' . $host . $base . '/uploads/chat_images/' . $userId . '/' . $name; // 公网地址
        $id = Db::insert('INSERT INTO chat_images (user_id, conversation_client_id, message_client_id, image_index, url, status, created_at) ' .
            'VALUES (?, ?, ?, ?, ?, 0, NOW())', [$userId, $convId, $msgId, $index, $url]);
        Resp::ok(['id' => (int)$id, 'url' => $url, 'status' => 0], '已上传');
    }

    // 聊天图片公开读取（后台审核页直接按 URL 预览）
    public static function serveImage(string $path): void
    {
        $parts = explode('/', trim($path, '/'));                      // 路径：用户ID/文件名
        if (count($parts) !== 2) {
            http_response_code(404);
            exit;
        }
        $userId = (int)$parts[0];                                     // 用户ID
        $name = basename($parts[1]);                                  // 只取文件名，防止穿越
        if ($userId <= 0 || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            http_response_code(404);
            exit;
        }
        $file = WANYU_ROOT . '/storage/chat_images/' . $userId . '/' . $name; // 实际路径
        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));       // 扩展名
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
        header('Content-Type: ' . $mime);                             // 图片类型
        header('Content-Length: ' . filesize($file));                 // 长度
        header('Cache-Control: private, max-age=3600');               // 私有缓存一小时
        readfile($file);                                              // 输出
        exit;
    }

    // 聊天图片审核表：惰性建表，老库不需要手动迁移
    private static function ensureImageTable(): void
    {
        static $ready = false;                                        // 进程内只建一次
        if ($ready) {
            return;
        }
        Db::exec("CREATE TABLE IF NOT EXISTS `chat_images` (" .
            "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, " .
            "`user_id` BIGINT UNSIGNED NOT NULL, " .
            "`conversation_client_id` VARCHAR(64) NOT NULL DEFAULT '', " .
            "`message_client_id` VARCHAR(64) NOT NULL DEFAULT '', " .
            "`image_index` INT NOT NULL DEFAULT 0, " .
            "`url` VARCHAR(500) NOT NULL DEFAULT '', " .
            "`status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待审 1通过 2违规', " .
            "`reviewer` VARCHAR(64) NOT NULL DEFAULT '', " .
            "`review_reason` VARCHAR(255) NOT NULL DEFAULT '', " .
            "`reviewed_at` DATETIME DEFAULT NULL, " .
            "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, " .
            "PRIMARY KEY (`id`), " .
            "UNIQUE KEY `uk_msg_image` (`user_id`, `message_client_id`, `image_index`), " .
            "KEY `idx_status` (`status`, `id`), " .
            "KEY `idx_user` (`user_id`, `id`), " .
            "KEY `idx_conv` (`user_id`, `conversation_client_id`)" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='聊天图片人工审核'");
        $ready = true;
    }

    // 云端对话只保留7天：每次同步时顺带删除该用户7天未活跃的会话及其消息
    private static function cleanup(int $userId): void
    {
        Db::exec('DELETE m FROM messages m INNER JOIN conversations c ON m.conversation_id = c.id ' . // 先删过期会话的消息
            'WHERE c.user_id = ? AND c.updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)', [$userId]);
        Db::exec('DELETE FROM conversations WHERE user_id = ? AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)', // 再删过期会话
            [$userId]);
    }

    private static function datetime($value): string    // 规范化时间
    {
        $text = is_string($value) ? trim($value) : '';  // 转字符串
        if ($text !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text)) { // 标准格式
            $ts = strtotime($text);                     // 解析
            if ($ts !== false && $ts > 0) {              // 有效
                return date('Y-m-d H:i:s', $ts);        // 返回
            }
        }
        return date('Y-m-d H:i:s');                     // 无效则用当前时间
    }
}
