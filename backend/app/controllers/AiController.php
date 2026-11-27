<?php
// 官方模型控制器：调用前提示信息 + DeepSeek 转发入口
class AiController
{
    public static function quote(): void                // 调用前查询费用提示
    {
        $user = Auth::requireUser(true, false);         // 必须登录（仅查询，不受风控拦截）
        Resp::ok(DeepSeek::quote((int)$user['id']));    // 返回模型、比例、余额
    }

    public static function chat(): void                 // 转发DeepSeek对话请求
    {
        $user = Auth::requireUser(true);                // 必须登录且设备可信
        $limit = (int)Config::get('rate_limit')['ai_per_minute']; // 每分钟AI上限
        RateLimiter::require('ai_user:' . (int)$user['id'], $limit, 60, 'AI调用过于频繁'); // 按用户限流
        $stream = Req::bool('stream', false);           // 是否流式
        DeepSeek::chat($user, $stream);                 // 执行转发（内部会exit）
    }
}
