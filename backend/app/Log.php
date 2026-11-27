<?php
// 日志类：文件日志 + 数据库安全日志
class Log
{
    public static function write(string $channel, array $context = []): void // 写文件日志
    {
        $dir = Config::get('log_path', WANYU_ROOT . '/storage/logs'); // 日志目录
        if (!is_dir($dir)) {                            // 目录不存在则创建
            @mkdir($dir, 0775, true);                   // 递归创建
        }
        $line = json_encode([                           // 组装JSON日志
            'time'    => date('Y-m-d H:i:s'),           // 时间
            'channel' => $channel,                      // 通道
            'ip'      => Req::ip(),                     // IP
            'device'  => Req::deviceId(),               // 设备
            'path'    => $_SERVER['REQUEST_URI'] ?? '', // 请求路径
            'context' => $context,                      // 上下文
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // 中文不转义
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX); // 追加写入
    }

    public static function info(string $channel, array $context = []): void // 普通日志
    {
        self::write($channel, $context);                // 写文件
    }

    public static function error(string $channel, array $context = []): void // 错误日志
    {
        self::write('ERROR.' . $channel, $context);     // 加错误前缀
    }

    public static function security(string $event, string $level, string $message, ?int $userId = null): void // 安全日志
    {
        self::write('SECURITY.' . $event, [             // 同时写文件
            'level'   => $level,                        // 等级
            'message' => $message,                      // 描述
            'user_id' => $userId,                       // 用户
        ]);
        try {                                           // 数据库失败不能影响主流程
            Db::exec('INSERT INTO security_logs (user_id, event_type, level, message, ip, device_id) ' . // 插入安全日志
                     'VALUES (?, ?, ?, ?, ?, ?)', [
                $userId,                                // 用户ID
                $event,                                 // 事件类型
                $level,                                 // 风险等级
                mb_substr($message, 0, 500),            // 限制长度
                Req::ip(),                              // IP
                mb_substr(Req::deviceId(), 0, 120),     // 设备
            ]);
        } catch (Throwable $e) {                        // 捕获数据库异常
            self::write('SECURITY_DB_FAIL', ['message' => $e->getMessage()]); // 记录失败原因
        }
    }
}
