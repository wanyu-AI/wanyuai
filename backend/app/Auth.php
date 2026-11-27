<?php
// 用户鉴权类：令牌签发、校验、设备信任、邮箱/QQ账号创建
class Auth
{
    public static function issueToken(int $userId, string $deviceId): string // 签发登录令牌
    {
        $token = bin2hex(random_bytes(32));             // 生成64位随机令牌
        $days = (int)Config::get('token_days', 90);     // 有效期
        $expires = date('Y-m-d H:i:s', time() + 86400 * $days); // 过期时间
        Db::exec('INSERT INTO user_tokens (user_id, token_hash, device_id, expires_at) VALUES (?, ?, ?, ?)', // 写入令牌
            [$userId, hash('sha256', $token), $deviceId, $expires]);
        Db::exec('DELETE FROM user_tokens WHERE user_id = ? AND expires_at < NOW()', [$userId]); // 顺带清理过期令牌
        return $token;                                  // 返回明文令牌（仅此一次）
    }

    public static function tokenUser(): ?array          // 根据 Bearer 令牌取用户
    {
        $header = Req::header('Authorization');         // 读取授权头
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) { // 校验格式
            return null;                                // 格式错误
        }
        $hash = hash('sha256', strtolower($m[1]));      // 计算令牌哈希
        $row = Db::fetch('SELECT t.user_id, t.device_id, t.expires_at, u.* FROM user_tokens t ' . // 联表查询
            'JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?', [$hash]);
        if (!$row) {                                    // 令牌不存在
            return null;                                // 返回空
        }
        if (strtotime($row['expires_at']) < time()) {   // 令牌过期
            return null;                                // 返回空
        }
        if ((int)$row['status'] !== 1) {                // 账号被禁用
            throw new ApiException(40302, '账号已被禁用', 403); // 拒绝
        }
        return $row;                                    // 返回用户与令牌信息
    }

    public static function requireUser(bool $requireTrust = true, bool $enforceRisk = true): array // 必须登录
    {
        $user = self::tokenUser();                      // 取用户
        if (!$user) {                                   // 未登录
            throw new ApiException(40106, '登录已失效，请重新登录', 401); // 拒绝
        }
        if ($enforceRisk) {                             // 是否需要风控拦截
            Risk::assertUsable($user);                  // 封禁/冻结时拒绝
        }
        if ($requireTrust) {                            // 是否需要设备信任
            self::assertDeviceTrusted((int)$user['id'], Req::deviceId()); // 校验设备
        }
        return $user;                                   // 返回用户
    }

    public static function assertDeviceTrusted(int $userId, string $deviceId): void // 校验设备可信
    {
        if (Config::db('device_verify_enabled', '1') !== '1') { // 后台可关闭该策略
            return;                                     // 直接放行
        }
        $row = Db::fetch('SELECT is_trusted FROM user_devices WHERE user_id = ? AND device_id = ?', // 查询设备
            [$userId, $deviceId]);
        if (!$row) {                                    // 设备未登记
            Log::security('device_untrusted', 'warning', '未登记设备访问', $userId); // 记录
            throw new ApiException(40303, '新设备需完成邮箱验证后使用', 403); // 拒绝
        }
        if ((int)$row['is_trusted'] !== 1) {            // 待核验设备
            Log::security('device_untrusted', 'warning', '待核验设备访问', $userId); // 记录
            throw new ApiException(40303, '新设备需完成邮箱验证后使用', 403); // 拒绝
        }
    }

    public static function touchDevice(int $userId, string $deviceId, string $deviceName, bool $trusted): void // 登记/更新设备
    {
        Db::exec('INSERT INTO user_devices (user_id, device_id, device_name, is_trusted, last_login_at) ' . // 插入或更新
                 'VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), ' . // 更新名称
                 'last_login_at = NOW(), is_trusted = GREATEST(is_trusted, VALUES(is_trusted))', // 保持已信任状态
            [$userId, $deviceId, $deviceName, $trusted ? 1 : 0]);
    }

    public static function trustDevice(int $userId, string $deviceId): void // 标记设备可信
    {
        Db::exec('UPDATE user_devices SET is_trusted = 1, last_login_at = NOW() WHERE user_id = ? AND device_id = ?', // 更新
            [$userId, $deviceId]);
    }

    public static function findUserByEmail(string $email): ?array // 按邮箱查用户
    {
        return Db::fetch('SELECT * FROM users WHERE email = ?', [$email]); // 查询
    }

    public static function createEmailUser(string $email): array // 创建邮箱用户
    {
        $bonus = Config::int('register_bonus_points', 0); // 注册赠送积分
        $id = Db::insert('INSERT INTO users (account_type, email, nickname, points, status, last_login_at) ' . // 插入用户
            'VALUES (?, ?, ?, ?, 1, NOW())', ['email', $email, '用户' . substr(md5($email), 0, 6), $bonus]);
        if ($bonus > 0) {                               // 有赠送积分则写流水
            Points::add($id, $bonus, 'register_bonus', '注册赠送'); // 记录流水
        }
        return self::findUserByEmail($email);           // 返回完整用户
    }

    public static function createQqUser(string $openid, string $qqNumber, string $nickname, string $avatar): array // 创建QQ用户
    {
        $bonus = Config::int('register_bonus_points', 0); // 注册赠送积分
        $id = Db::insert('INSERT INTO users (account_type, qq_openid, qq_number, nickname, avatar, points, status, last_login_at) ' . // 插入
            'VALUES (?, ?, ?, ?, ?, ?, 1, NOW())', ['qq', $openid, $qqNumber, $nickname, $avatar, $bonus]);
        if ($bonus > 0) {                               // 有赠送积分
            Points::add($id, $bonus, 'register_bonus', '注册赠送'); // 写流水
        }
        return Db::fetch('SELECT * FROM users WHERE id = ?', [$id]); // 返回用户
    }

    public static function publicUser(array $user): array // 输出给客户端的用户信息
    {
        $risk = Risk::info($user);                      // 风控状态（封禁/冻结/申诉）
        return [
            'id'           => (int)$user['id'],         // 用户ID
            'account_type' => $user['account_type'],     // 注册类型
            'email'        => $user['email'] ? Security::maskEmail($user['email']) : '', // 邮箱脱敏展示
            'email_bound'  => $user['email'] ? true : false, // 是否已绑定邮箱
            'qq_number'    => $user['qq_number'] ?: '',  // QQ号，可能为空
            'qq_bound'     => !empty($user['qq_openid']), // 是否已绑定QQ
            'nickname'     => $user['nickname'],         // 昵称
            'avatar'       => $user['avatar'],           // 头像
            'points'       => (int)$user['points'],      // 剩余积分
            'created_at'   => $user['created_at'],       // 注册时间
            'risk_status'  => $risk['status'],           // 风控状态：0正常 1封禁 2冻结
            'risk_reason'  => $risk['reason'],           // 风控原因
            'risk_until'   => $risk['until'],            // 解封时间（展示）
            'risk_until_ts'=> $risk['until_ts'],         // 解封时间戳（倒计时）
            'appeal_status'=> $risk['appeal_status'],    // 申诉状态：-1未申诉 0待审核 1通过 2驳回
            'appeal_reply' => $risk['appeal_reply'],    // 申诉回复
            'warning_count'=> $risk['warning_count'],    // 违规警告次数
            'warning_limit'=> $risk['warning_limit'],    // 警告上限
            'warning_reason' => $risk['warning_reason'], // 最近一次违规原因
        ];
    }
}
