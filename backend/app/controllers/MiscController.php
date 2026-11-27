<?php
// 公共控制器：抓包上报、客户端公共配置
class MiscController
{
    public static function reportProxy(): void          // 客户端检测到抓包/代理时上报
    {
        $reason = mb_substr(Req::string('reason', 'unknown'), 0, 200); // 原因
        $userId = null;                                 // 关联用户
        try {
            $u = Auth::tokenUser();                     // 尝试识别用户
            if ($u) {                                   // 已登录
                $userId = (int)$u['id'];                // 记录用户
            }
        } catch (Throwable $e) {                        // 忽略异常
            $userId = null;                             // 保持空
        }
        RateLimiter::require('proxy_report:' . Req::ip(), 30, 300, '上报过于频繁'); // 限流
        Log::security('proxy_detected', 'danger', '客户端检测到代理/抓包环境：' . $reason, $userId); // 写安全日志
        Resp::ok(null, '已记录');                        // 返回
    }

    public static function publicConfig(): void         // 客户端启动配置
    {
        Resp::ok([
            'official_model'       => Config::db('deepseek_model', 'deepseek-chat'), // 官方模型名
            'official_model_label' => '官方DeepSeek模型', // 客户端展示名
            'tokens_per_point'     => max(1, Config::int('tokens_per_point', 1000)), // 换算比例
            'device_verify_enabled'=> Config::db('device_verify_enabled', '1') === '1', // 新设备核验
            'maintenance'          => Config::db('maintenance_mode', '0') === '1', // 维护模式
            'server_time'          => date('Y-m-d H:i:s'), // 服务端时间
        ]);                                             // 返回
    }
}
