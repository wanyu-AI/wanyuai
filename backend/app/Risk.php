<?php
// 风控中心：账号封禁、异地登录冻结、AI 内容审核、申诉处理
class Risk
{
    const NORMAL = 0;                                   // 正常
    const BANNED = 1;                                   // 封禁（不可使用，可申诉）
    const FROZEN = 2;                                   // 冻结（异地登录，可邮箱验证解冻）
    const WARNING_LIMIT = 5;                            // 警告上限，超过后封禁

    private static $ready = false;                      // 表结构是否已确认

    /** 确保风控所需表与字段存在（首次调用自动建表，后续只读配置） */
    public static function ensureSchema(): void
    {
        if (self::$ready) {                             // 已确认过
            return;                                     // 直接返回
        }
        if (Config::db('risk_schema_version', '') === '2') { // 后台已标记初始化
            self::$ready = true;                        // 标记完成
            return;                                     // 返回
        }
        $columns = [
            'risk_status'   => "ALTER TABLE users ADD COLUMN risk_status TINYINT NOT NULL DEFAULT 0 COMMENT '风控状态：0正常 1封禁 2冻结'",
            'risk_reason'   => "ALTER TABLE users ADD COLUMN risk_reason VARCHAR(255) NOT NULL DEFAULT '' COMMENT '风控原因'",
            'risk_until'    => "ALTER TABLE users ADD COLUMN risk_until DATETIME DEFAULT NULL COMMENT '封禁解封时间，空表示永久'",
            'risk_at'       => "ALTER TABLE users ADD COLUMN risk_at DATETIME DEFAULT NULL COMMENT '风控触发时间'",
            'last_login_ip' => "ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NOT NULL DEFAULT '' COMMENT '最后登录IP，用于异地登录识别'",
            'violation_warnings' => "ALTER TABLE users ADD COLUMN violation_warnings INT NOT NULL DEFAULT 0 COMMENT '违规警告次数'",
            'last_warning_reason' => "ALTER TABLE users ADD COLUMN last_warning_reason VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近一次违规原因'",
            'last_warning_at' => "ALTER TABLE users ADD COLUMN last_warning_at DATETIME DEFAULT NULL COMMENT '最近一次警告时间'",
        ];
        foreach ($columns as $name => $sql) {           // 逐列检查并补齐
            $exists = Db::fetch("SHOW COLUMNS FROM users LIKE '" . $name . "'"); // 查询列
            if (!$exists) {                             // 不存在才新增
                Db::exec($sql);                         // 执行新增
            }
        }
        Db::exec("CREATE TABLE IF NOT EXISTS risk_events (" .
            "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, " .
            "event_type VARCHAR(32) NOT NULL DEFAULT '', action VARCHAR(32) NOT NULL DEFAULT '', " .
            "reason VARCHAR(255) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '', " .
            "device_id VARCHAR(128) NOT NULL DEFAULT '', operator VARCHAR(64) NOT NULL DEFAULT 'system', " .
            "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), " .
            "KEY idx_risk_user (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='风控事件记录'");
        Db::exec("CREATE TABLE IF NOT EXISTS ban_appeals (" .
            "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, " .
            "content TEXT NOT NULL, status TINYINT NOT NULL DEFAULT 0 COMMENT '0待审核 1通过 2驳回', " .
            "reply VARCHAR(500) NOT NULL DEFAULT '', reviewer VARCHAR(64) NOT NULL DEFAULT '', " .
            "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at DATETIME DEFAULT NULL, " .
            "PRIMARY KEY (id), KEY idx_appeal_user (user_id, created_at), KEY idx_appeal_status (status)) " .
            "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='封禁申诉'");
        Config::setDb('risk_schema_version', '2');       // 标记已完成，避免每次请求做DDL检查
        self::$ready = true;                             // 本次请求内标记
    }

    /** 读取用户风控信息，封禁到期则自动解封 */
    public static function info(array $user, bool $withAppeal = true): array
    {
        self::ensureSchema();                           // 兜底初始化表结构
        $status = (int)($user['risk_status'] ?? self::NORMAL); // 风控状态
        $until = $user['risk_until'] ?? null;           // 解封时间
        if ($status === self::BANNED && $until !== null && strtotime((string)$until) < time()) { // 已到期
            self::release((int)$user['id'], 'system', '封禁到期自动解封'); // 自动解封
            $status = self::NORMAL;                     // 更新内存状态
            $until = null;                              // 清空时间
        }
        $info = [
            'status'    => $status,                     // 0正常 1封禁 2冻结
            'reason'    => (string)($user['risk_reason'] ?? ''), // 原因
            'until'     => $until !== null ? date('Y-m-d H:i', strtotime((string)$until)) : '', // 展示时间
            'until_ts'  => $until !== null ? (int)strtotime((string)$until) : 0, // 时间戳，客户端倒计时
            'appeal_status' => -1,                      // -1未申诉 0待审核 1通过 2驳回
            'appeal_reply'  => '',                      // 审核回复
            'warning_count' => (int)($user['violation_warnings'] ?? 0), // 已警告次数
            'warning_limit' => self::WARNING_LIMIT,     // 警告上限
            'warning_reason'=> (string)($user['last_warning_reason'] ?? ''), // 最近违规原因
        ];
        if ($status === self::BANNED && $withAppeal) {  // 封禁时附带申诉状态
            $appeal = self::latestAppeal((int)$user['id']); // 最近一次申诉
            if ($appeal) {                              // 有申诉记录
                $info['appeal_status'] = (int)$appeal['status']; // 状态
                $info['appeal_reply'] = (string)$appeal['reply']; // 回复
            }
        }
        return $info;
    }

    /** 未封禁/未冻结才能继续发送消息或修改资料 */
    public static function assertUsable(array $user): void
    {
        $info = self::info($user);
        if ($info['status'] === self::BANNED) {          // 封禁
            throw new ApiException(40331, '账号因违规已被封禁，可在输入框提交申诉', 403, $info);
        }
        if ($info['status'] === self::FROZEN) {          // 冻结
            throw new ApiException(40332, '账号因异地登录已被冻结，请通过邮箱验证解冻', 403, $info);
        }
    }

    /** 封禁账号：days<=0 表示永久，否则到期自动解封 */
    public static function ban(int $userId, string $reason, int $days = 7, string $operator = 'system'): void
    {
        $until = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null; // 解封时间
        Db::exec('UPDATE users SET risk_status = 1, risk_reason = ?, risk_until = ?, risk_at = NOW() WHERE id = ?', // 写入封禁
            [$reason, $until, $userId]);
        self::logEvent($userId, 'ban', 'ban', $reason, $operator); // 记录事件
    }

    /** 冻结账号（异地登录） */
    public static function freeze(int $userId, string $reason, string $operator = 'system'): void
    {
        Db::exec('UPDATE users SET risk_status = 2, risk_reason = ?, risk_until = NULL, risk_at = NOW() WHERE id = ?', // 写入冻结
            [$reason, $userId]);
        self::logEvent($userId, 'freeze', 'foreign_login', $reason, $operator); // 记录事件
    }

    /** 解除封禁/冻结 */
    public static function release(int $userId, string $operator = 'system', string $note = ''): void
    {
        Db::exec('UPDATE users SET risk_status = 0, risk_reason = ?, risk_until = NULL, risk_at = NULL, violation_warnings = 0, last_warning_reason = \'\', last_warning_at = NULL WHERE id = ?', // 清空风控与警告
            [$note === '' ? '' : $note, $userId]);
        self::logEvent($userId, 'release', 'release', $note, $operator); // 记录事件
    }

    /** AI 内容审核：检查最后一条用户消息，违规先警告，超过5次封禁（天数由 AI 决定） */
    public static function checkChatContent(int $userId, array $messages): void
    {
        if (Config::db('violation_check_enabled', '1') !== '1') { // 后台可关闭
            return;                                     // 放行
        }
        $text = '';                                     // 只审核最后一条用户消息
        foreach ($messages as $msg) {                   // 遍历消息
            if (!is_array($msg) || (string)($msg['role'] ?? '') !== 'user') { // 跳过非用户
                continue;                               // 继续
            }
            $content = $msg['content'] ?? '';           // 内容
            if (is_array($content)) {                   // 多模态数组
                $content = json_encode($content, JSON_UNESCAPED_UNICODE); // 转字符串
            }
            $text = (string)$content;                   // 记录最后一条
        }
        $text = trim($text);                            // 去空格
        if ($text === '') {                             // 空消息
            return;                                     // 放行
        }
        self::judge($userId, $text, '消息', 40333);      // AI 审核
    }

    /** 审核任意文本（如昵称），违规先警告，超过5次封禁 */
    public static function checkText(int $userId, string $text, string $label = '内容', int $banCode = 40333): void
    {
        if (Config::db('violation_check_enabled', '1') !== '1') { // 后台可关闭
            return;                                     // 放行
        }
        self::judge($userId, trim($text), $label, $banCode); // AI 审核
    }

    /** AI 审核核心：违规先警告，警告超过5次封禁，封禁天数由 AI 决定 */
    private static function judge(int $userId, string $text, string $label, int $banCode): void
    {
        if ($text === '') {                             // 空内容
            return;                                     // 放行
        }
        $verdict = DeepSeek::moderate($text);           // AI 内容审核
        if (empty($verdict['violation'])) {             // 未违规
            return;                                     // 放行
        }
        $reason = trim((string)($verdict['reason'] ?? '')); // AI 判定原因
        if ($reason === '') {                           // 兜底原因
            $reason = '涉嫌违规内容';
        }
        $days = max(1, min(30, (int)($verdict['ban_days'] ?? 7))); // 天数由 AI 决定，限制1-30
        $row = Db::fetch('SELECT violation_warnings FROM users WHERE id = ?', [$userId]); // 当前警告次数
        $warnings = (int)($row['violation_warnings'] ?? 0) + 1; // 本次计入
        if ($warnings > self::WARNING_LIMIT) {          // 超过5次警告
            $banReason = $label . '内容不合法：' . $reason; // 封禁原因
            self::ban($userId, $banReason, $days, 'ai'); // 封禁，天数由 AI 决定
            $user = Db::fetch('SELECT * FROM users WHERE id = ?', [$userId]); // 最新资料
            throw new ApiException($banCode, $label . '包含违规内容，账号已被封禁' . $days . '天', 403, // 中断请求
                $user ? self::info($user) : ['status' => self::BANNED, 'reason' => $banReason]);
        }
        Db::exec('UPDATE users SET violation_warnings = ?, last_warning_reason = ?, last_warning_at = NOW() WHERE id = ?', // 记录警告
            [$warnings, mb_substr($reason, 0, 255), $userId]);
        Log::security('ai_moderation_warning', 'warning', $label . '违规警告 ' . $warnings . '/' . self::WARNING_LIMIT . '：' . $reason, $userId); // 安全日志
        $user = Db::fetch('SELECT * FROM users WHERE id = ?', [$userId]); // 最新资料
        throw new ApiException(40335, $label . '包含违规内容，已警告 ' . $warnings . '/' . self::WARNING_LIMIT . ' 次，超过' . self::WARNING_LIMIT . '次将封禁账号', 403, // 警告响应
            $user ? self::info($user) : ['status' => self::NORMAL, 'warning_count' => $warnings]);
    }

    /** 人工图片审核：记一次违规警告，超过上限自动封禁；不抛异常，返回处理结果 */
    public static function warnUser(int $userId, string $reason, string $operator = 'admin'): array
    {
        self::ensureSchema();                           // 兜底初始化表结构
        $row = Db::fetch('SELECT violation_warnings FROM users WHERE id = ?', [$userId]); // 当前警告次数
        if (!$row) {                                    // 用户不存在
            return ['ok' => false, 'warnings' => 0, 'banned' => false, 'reason' => ''];
        }
        $text = trim($reason) !== '' ? trim($reason) : '图片内容违规'; // 原因兜底
        $text = '[图片审核] ' . mb_substr($text, 0, 240); // 带前缀，客户端据此区分人工审核警告
        $warnings = (int)$row['violation_warnings'] + 1; // 本次计入
        if ($warnings > self::WARNING_LIMIT) {          // 超过警告上限
            self::ban($userId, $text, 7, $operator);    // 封禁 7 天
            Log::security('image_review_ban', 'warning', '图片人工审核封禁：' . $text, $userId); // 安全日志
            return ['ok' => true, 'warnings' => $warnings, 'banned' => true, 'reason' => $text];
        }
        Db::exec('UPDATE users SET violation_warnings = ?, last_warning_reason = ?, last_warning_at = NOW() WHERE id = ?', // 记录警告
            [$warnings, $text, $userId]);
        Log::security('image_review_warning', 'warning', '图片人工审核警告 ' . $warnings . '/' . self::WARNING_LIMIT . '：' . $text, $userId); // 安全日志
        return ['ok' => true, 'warnings' => $warnings, 'banned' => false, 'reason' => $text];
    }

    /** 异地登录检测：IP网段不同且设备未信任时冻结账号 */
    public static function foreignLoginCheck(array $user, string $ip, string $deviceId): void
    {
        if (Config::db('risk_foreign_login_enabled', '1') !== '1') { // 后台可关闭
            return;                                     // 放行
        }
        if ((int)($user['risk_status'] ?? self::NORMAL) !== self::NORMAL) { // 已封禁/已冻结
            return;                                     // 不覆盖已有风控状态，避免封禁被冻结绕过
        }
        $lastIp = (string)($user['last_login_ip'] ?? ''); // 上次登录IP
        if ($lastIp === '' || $ip === '' || $lastIp === $ip) { // 首次登录或同IP
            return;                                     // 放行
        }
        $a = explode('.', $lastIp);                     // 旧IP段
        $b = explode('.', $ip);                         // 新IP段
        if (count($a) >= 2 && count($b) >= 2 && $a[0] === $b[0] && $a[1] === $b[1]) { // 同/16网段视为同城
            return;                                     // 放行
        }
        $trusted = Db::fetch('SELECT id FROM user_devices WHERE user_id = ? AND device_id = ? AND is_trusted = 1', // 已信任设备
            [(int)$user['id'], $deviceId]);
        if ($trusted) {                                 // 信任设备换网络不冻结
            self::logEvent((int)$user['id'], 'login_ip_change', 'notice', '信任设备登录IP变化：' . $ip, 'system'); // 仅记录
            return;                                     // 放行
        }
        self::freeze((int)$user['id'], '检测到异地登录（IP ' . $ip . '），请通过邮箱验证解冻'); // 冻结
    }

    /** 记录本次登录IP，供下次异地检测使用 */
    public static function recordLogin(int $userId, string $ip): void
    {
        if ($ip !== '') {                               // 有IP才记录
            Db::exec('UPDATE users SET last_login_ip = ? WHERE id = ?', [$ip, $userId]);
        }
    }

    /** 提交申诉 */
    public static function submitAppeal(int $userId, string $content): array
    {
        $pending = Db::fetch('SELECT id FROM ban_appeals WHERE user_id = ? AND status = 0 ORDER BY id DESC LIMIT 1', [$userId]); // 是否有待审核
        if ($pending) {                                 // 已有待审核申诉
            throw new ApiException(40061, '已有申诉正在审核中，请耐心等待'); // 拒绝重复提交
        }
        $id = Db::insert('INSERT INTO ban_appeals (user_id, content, status) VALUES (?, ?, 0)', [$userId, $content]); // 写入
        self::logEvent($userId, 'appeal', 'submit', $content, 'user'); // 记录
        return ['id' => $id, 'status' => 0];            // 返回
    }

    /** 最近一次申诉 */
    public static function latestAppeal(int $userId): ?array
    {
        return Db::fetch('SELECT * FROM ban_appeals WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
    }

    /** 写入风控事件 */
    public static function logEvent(int $userId, string $eventType, string $action, string $reason, string $operator): void
    {
        try {
            Db::insert('INSERT INTO risk_events (user_id, event_type, action, reason, ip, device_id, operator) VALUES (?, ?, ?, ?, ?, ?, ?)', // 记录
                [$userId, mb_substr($eventType, 0, 32), mb_substr($action, 0, 32), mb_substr($reason, 0, 255), Req::ip(), Req::deviceId(), mb_substr($operator, 0, 64)]);
        } catch (Throwable $e) {
            Log::error('risk_event_fail', ['message' => $e->getMessage()]); // 记录失败不影响主流程
        }
    }
}
