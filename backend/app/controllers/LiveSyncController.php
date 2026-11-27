<?php
// 实时同步控制器：会话消息的秒级推送/拉取，并同步「AI 正在生成」状态
// 与 SyncController 的区别：不做批量全量同步，只处理单个会话的增量变更，供客户端 1~2 秒轮询
class LiveSyncController
{
    // 生成状态表是否已确认存在；进程内缓存，避免每次请求都执行 DDL
    private static $tableReady = false;

    /**
     * 推送本地变更（客户端本地会话变化后立即调用）
     * 入参：
     *   conversation: {client_id, title, created_at, updated_at}
     *   messages: [{client_id, role, content, model, meta, created_at}]
     *   generating: true|false   （可选，AI 是否正在生成）
     */
    public static function push(): void
    {
        $user = Auth::requireUser(true);                     // 必须登录；封禁用户不允许写入
        self::ensureTable();                                 // 确保生成状态表存在
        $userId = (int)$user['id'];                          // 当前用户ID
        $conv = Req::input('conversation');                  // 会话信息
        if (!is_array($conv) || empty($conv['client_id'])) {  // 会话必须有客户端ID
            throw new ApiException(40040, 'conversation格式错误'); // 拒绝非法请求
        }
        $clientId = mb_substr((string)$conv['client_id'], 0, 64); // 客户端会话ID
        $title = mb_substr((string)($conv['title'] ?? ''), 0, 240); // 会话标题
        $created = self::datetime($conv['created_at'] ?? ''); // 创建时间
        $updated = self::datetime($conv['updated_at'] ?? ''); // 更新时间

        $existing = Db::fetch('SELECT id FROM conversations WHERE user_id = ? AND client_id = ?', // 查询是否已存在
            [$userId, $clientId]);
        if ($existing) {                                     // 已存在则只更新标题与时间
            $convId = (int)$existing['id'];                  // 服务端会话ID
            Db::exec('UPDATE conversations SET title = ?, updated_at = ?, deleted = 0 WHERE id = ?', // 更新
                [$title, $updated, $convId]);
        } else {                                             // 不存在则新建
            $convId = Db::insert('INSERT INTO conversations (user_id, client_id, title, created_at, updated_at, deleted) VALUES (?, ?, ?, ?, ?, 0)', // 插入
                [$userId, $clientId, $title, $created, $updated]);
        }

        $messages = Req::input('messages', []);              // 消息数组
        $written = 0;                                        // 本次写入条数
        if (is_array($messages) && $messages !== []) {       // 有消息才处理
            if (count($messages) > 50) {                     // 单次上限，防止超大请求
                $messages = array_slice($messages, 0, 50);    // 截断
            }
            foreach ($messages as $msg) {                    // 逐条写入
                if (!is_array($msg) || empty($msg['client_id'])) { // 必须有消息ID
                    continue;                                // 跳过非法项
                }
                $msgClientId = mb_substr((string)$msg['client_id'], 0, 64); // 客户端消息ID
                $role = (string)($msg['role'] ?? 'user');     // 角色
                if (!in_array($role, ['user', 'assistant', 'system', 'tool'], true)) { // 白名单
                    $role = 'user';                          // 兜底为普通用户消息
                }
                $content = (string)($msg['content'] ?? '');   // 纯文本内容（兼容旧逻辑）
                if (mb_strlen($content) > 200000) {           // 限制长度
                    $content = mb_substr($content, 0, 200000); // 截断
                }
                $model = mb_substr((string)($msg['model'] ?? ''), 0, 64); // 模型ID/名称
                $meta = null;                                 // 扩展字段（完整消息JSON）
                if (isset($msg['meta'])) {                    // 客户端传了完整消息结构
                    $encoded = json_encode($msg['meta'], JSON_UNESCAPED_UNICODE); // 序列化
                    if (is_string($encoded) && strlen($encoded) <= 131072) { // 限制 128KB，避免超长
                        $meta = $encoded;                     // 保留
                    }
                }
                $msgCreated = self::datetime($msg['created_at'] ?? ''); // 消息创建时间
                Db::exec('INSERT INTO messages (user_id, conversation_id, client_id, role, content, model, meta, created_at, deleted) ' . // 插入
                    'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE content = VALUES(content), ' . // 冲突时更新
                    'role = VALUES(role), model = VALUES(model), meta = VALUES(meta), created_at = VALUES(created_at), deleted = 0',
                    [$userId, $convId, $msgClientId, $role, $content, $model, $meta, $msgCreated]);
                $written++;                                   // 计数
            }
        }

        $generating = Req::input('generating', null);         // 生成状态
        if (is_bool($generating)) {                           // 只接受布尔值
            self::setGenerating($userId, $clientId, $generating); // 写入/清除生成标记
        }

        Resp::ok([
            'server_id'   => $convId,                        // 服务端会话ID
            'written'     => $written,                       // 写入条数
            'server_time' => date('Y-m-d H:i:s'),            // 服务端时间，客户端作为下次 since
        ], '已同步');
    }

    /**
     * 拉取增量：返回 since 之后有新内容的会话与消息，以及仍在生成中的会话
     * 入参：since（服务端上次返回的 server_time，首次为空）
     */
    public static function pull(): void
    {
        $user = Auth::requireUser(true, false);               // 查看历史不因封禁拦截
        self::ensureTable();                                 // 确保生成状态表存在
        $userId = (int)$user['id'];                          // 当前用户ID
        $sinceRaw = trim(Req::string('since'));              // 增量起点
        if ($sinceRaw !== '') {                              // 有起点则规范化
            $sinceMessage = self::datetime($sinceRaw);        // 消息增量起点
        } else {                                             // 首次拉取
            $sinceMessage = date('Y-m-d H:i:s', time() - 30); // 只回传最近 30 秒，避免首包过大
        }

        // 会话增量：首次给最近 30 个，之后只给变更过的
        if ($sinceRaw !== '') {
            $convRows = Db::fetchAll('SELECT client_id, title, created_at, updated_at FROM conversations ' . // 查询会话
                'WHERE user_id = ? AND deleted = 0 AND updated_at >= ? ORDER BY updated_at DESC LIMIT 30',
                [$userId, self::datetime($sinceRaw)]);
        } else {
            $convRows = Db::fetchAll('SELECT client_id, title, created_at, updated_at FROM conversations ' . // 首轮
                'WHERE user_id = ? AND deleted = 0 ORDER BY updated_at DESC LIMIT 30', [$userId]);
        }
        $conversations = [];                                 // 输出会话
        foreach ($convRows as $row) {                        // 逐个转换
            $conversations[] = [
                'client_id'  => $row['client_id'],           // 客户端会话ID
                'title'      => $row['title'],               // 标题
                'created_at' => $row['created_at'],          // 创建时间
                'updated_at' => $row['updated_at'],          // 更新时间
            ];
        }

        $messageRows = Db::fetchAll('SELECT c.client_id AS conv_client_id, m.client_id, m.role, m.content, m.model, m.meta, m.created_at, m.updated_at ' . // 查询消息
            'FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id ' . // 关联会话拿客户端ID
            'WHERE c.user_id = ? AND m.deleted = 0 AND m.updated_at >= ? ORDER BY m.id ASC LIMIT 200', // 只取增量
            [$userId, $sinceMessage]);
        $messages = [];                                      // 输出消息
        foreach ($messageRows as $row) {                     // 逐个转换
            $meta = null;                                    // 扩展字段
            if (!empty($row['meta'])) {                      // 有完整消息JSON
                $decoded = json_decode((string)$row['meta'], true); // 解码为数组
                if (is_array($decoded)) {                    // 解码成功
                    $meta = $decoded;                        // 返回给客户端
                }
            }
            $messages[] = [
                'conversation_client_id' => $row['conv_client_id'], // 所属会话（客户端ID）
                'client_id'  => $row['client_id'],           // 消息ID
                'role'       => $row['role'],                // 角色
                'content'    => $row['content'],             // 纯文本
                'model'      => $row['model'],               // 模型
                'meta'       => $meta,                       // 完整消息结构
                'created_at' => $row['created_at'],          // 创建时间
                'updated_at' => $row['updated_at'],          // 更新时间
            ];
        }

        // 生成状态：只保留 60 秒内的记录，客户端崩溃后不会永久显示「正在思考」
        Db::exec('DELETE FROM user_generating WHERE user_id = ? AND updated_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)', [$userId]);
        $generating = [];                                    // 输出生成中的会话
        foreach (Db::fetchAll('SELECT conversation_client_id FROM user_generating WHERE user_id = ?', [$userId]) as $row) {
            $generating[] = $row['conversation_client_id'];   // 会话客户端ID
        }

        Resp::ok([
            'conversations' => $conversations,               // 会话增量
            'messages'      => $messages,                    // 消息增量
            'generating'    => $generating,                  // 正在生成的会话
            'server_time'   => date('Y-m-d H:i:s'),          // 下次 since
        ]);
    }

    // 写入或清除生成标记
    private static function setGenerating(int $userId, string $clientId, bool $generating): void
    {
        if ($generating) {                                   // 开始生成：写入并刷新时间
            Db::exec('INSERT INTO user_generating (user_id, conversation_client_id, updated_at) VALUES (?, ?, NOW()) ' . // 插入
                'ON DUPLICATE KEY UPDATE updated_at = NOW()', [$userId, $clientId]);
        } else {                                             // 生成结束：删除标记
            Db::exec('DELETE FROM user_generating WHERE user_id = ? AND conversation_client_id = ?', [$userId, $clientId]);
        }
    }

    // 惰性建表：老库未执行迁移时也能直接使用
    private static function ensureTable(): void
    {
        if (self::$tableReady) {                             // 已确认过
            return;                                          // 直接返回
        }
        Db::exec('CREATE TABLE IF NOT EXISTS `user_generating` (' . // 生成状态表
            '`user_id` BIGINT UNSIGNED NOT NULL, ' .         // 用户
            '`conversation_client_id` VARCHAR(64) NOT NULL, ' . // 客户端会话ID
            '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ' . // 最后刷新时间
            'PRIMARY KEY (`user_id`, `conversation_client_id`), ' . // 每会话唯一
            'KEY `idx_gen_user_time` (`user_id`, `updated_at`)' . // 按时间清理
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        self::$tableReady = true;                            // 标记完成
    }

    // 规范化时间字符串，非法值退回当前时间
    private static function datetime($value): string
    {
        $text = is_string($value) ? trim($value) : '';       // 转字符串
        if ($text !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text)) { // 标准格式
            $ts = strtotime($text);                          // 解析
            if ($ts !== false && $ts > 0) {                   // 有效
                return date('Y-m-d H:i:s', $ts);              // 返回规范化结果
            }
        }
        return date('Y-m-d H:i:s');                          // 无效则用当前时间
    }
}
