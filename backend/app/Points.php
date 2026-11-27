<?php
// 积分服务类：余额查询、增减、按token扣费、流水
class Points
{
    public static function pointsForTokens(int $tokens): int // 按换算比例计算消耗积分
    {
        if ($tokens <= 0) {                             // 无token不扣费
            return 0;                                   // 返回0
        }
        $perPoint = Config::int('tokens_per_point', 1000); // 每积分对应token数
        if ($perPoint <= 0) {                           // 配置非法时兜底
            $perPoint = 1000;                           // 使用默认值
        }
        return max(1, (int)ceil($tokens / $perPoint));  // 向上取整，至少1积分
    }

    public static function balance(int $userId): int    // 查询余额
    {
        return (int)Db::scalar('SELECT points FROM users WHERE id = ?', [$userId]); // 返回积分
    }

    public static function add(int $userId, int $delta, string $type, string $remark = '', array $extra = []): int // 增减积分
    {
        Db::begin();                                    // 开启事务
        try {
            if ($delta < 0) {                           // 扣减时防止负数
                $affected = Db::exec('UPDATE users SET points = points + ? WHERE id = ? AND points >= ?', // 条件更新
                    [$delta, $userId, -$delta]);
                if ($affected === 0) {                  // 余额不足
                    throw new ApiException(40201, '积分不足', 402); // 抛出异常
                }
            } else {                                    // 增加积分
                Db::exec('UPDATE users SET points = points + ? WHERE id = ?', [$delta, $userId]); // 直接增加
            }
            $balance = self::balance($userId);          // 读取最新余额
            Db::exec('INSERT INTO point_transactions (user_id, type, points, input_tokens, output_tokens, model, request_id, balance_after, remark) ' . // 写流水
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $userId,                                // 用户
                $type,                                  // 类型
                $delta,                                 // 积分变动
                (int)($extra['input_tokens'] ?? 0),     // 输入token
                (int)($extra['output_tokens'] ?? 0),    // 输出token
                (string)($extra['model'] ?? ''),        // 模型
                (string)($extra['request_id'] ?? ''),   // 请求ID
                $balance,                               // 变动后余额
                mb_substr($remark, 0, 240),             // 备注
            ]);
            Db::commit();                               // 提交事务
            return $balance;                            // 返回余额
        } catch (Throwable $e) {                        // 任意失败
            Db::rollback();                             // 回滚
            throw $e;                                   // 继续抛出
        }
    }

    public static function chargeAi(int $userId, int $inputTokens, int $outputTokens, string $model, string $requestId): array // AI调用扣费
    {
        $cost = self::pointsForTokens($inputTokens + $outputTokens); // 计算费用
        if ($cost <= 0) {                               // 无消耗
            return ['charged' => 0, 'balance' => self::balance($userId)]; // 直接返回
        }
        $balance = self::add($userId, -$cost, 'ai_call', '官方模型调用', [ // 扣费
            'input_tokens'  => $inputTokens,            // 输入token
            'output_tokens' => $outputTokens,           // 输出token
            'model'         => $model,                  // 模型
            'request_id'    => $requestId,              // 请求ID
        ]);
        return ['charged' => $cost, 'balance' => $balance]; // 返回扣费结果
    }

    public static function logs(int $userId, int $page, int $pageSize): array // 查询积分流水
    {
        $page = max(1, $page);                          // 页码最小1
        $pageSize = min(50, max(1, $pageSize));         // 每页1-50条
        $offset = ($page - 1) * $pageSize;              // 偏移量
        $total = (int)Db::scalar('SELECT COUNT(*) FROM point_transactions WHERE user_id = ?', [$userId]); // 总数
        $rows = Db::fetchAll('SELECT id, type, points, input_tokens, output_tokens, model, balance_after, created_at ' . // 查询
            'FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$userId, $pageSize, $offset]);
        return ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'items' => $rows]; // 返回结果
    }
}
