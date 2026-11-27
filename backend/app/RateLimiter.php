<?php
// 固定窗口限流类：基于数据库计数，避免多进程内存不共享
class RateLimiter
{
    public static function hit(string $key, int $limit, int $windowSeconds): bool // 记录一次访问
    {
        $windowStart = (int)(floor(time() / $windowSeconds) * $windowSeconds); // 计算窗口起点
        $rateKey = mb_substr($key, 0, 180);             // 限制键长度
        Db::exec('INSERT INTO rate_limits (rate_key, window_start, counter) VALUES (?, ?, 1) ' . // 原子递增
                 'ON DUPLICATE KEY UPDATE counter = counter + 1', [$rateKey, $windowStart]);
        $count = (int)Db::scalar('SELECT counter FROM rate_limits WHERE rate_key = ? AND window_start = ?', // 查询当前计数
            [$rateKey, $windowStart]);
        if (random_int(1, 100) === 1) {                 // 1%概率清理旧窗口
            Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [$windowStart - 86400]); // 删除一天前数据
        }
        return $count <= $limit;                        // 未超限返回true
    }

    public static function require(string $key, int $limit, int $windowSeconds, string $message): void // 超限抛异常
    {
        if (!self::hit($key, $limit, $windowSeconds)) { // 记录并判断
            Log::security('rate_limit', 'warning', $message . ' key=' . $key); // 写安全日志
            throw new ApiException(42901, '请求过于频繁，请稍后再试', 429); // 抛出异常
        }
    }

    public static function reset(string $key): void     // 清除某键限流（测试/后台解封）
    {
        Db::exec('DELETE FROM rate_limits WHERE rate_key = ?', [$key]); // 删除记录
    }
}
