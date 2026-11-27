<?php
// 统一响应类：所有接口输出 JSON，禁止缓存
class Resp
{
    public static function json(array $data, int $httpStatus = 200): void // 输出JSON
    {
        if (!headers_sent()) {                          // 未发送头时才设置
            http_response_code($httpStatus);            // 设置状态码
            header('Content-Type: application/json; charset=utf-8'); // JSON类型
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); // 禁止缓存
            header('Pragma: no-cache');                 // 兼容旧客户端
            header('X-Content-Type-Options: nosniff');  // 禁止内容嗅探
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // 输出JSON
        exit;                                           // 结束请求
    }

    public static function ok($data = null, string $message = 'ok'): void // 成功响应
    {
        self::json(['code' => 0, 'message' => $message, 'data' => $data]); // code=0表示成功
    }

    public static function error(int $code, string $message, int $httpStatus = 400, array $extra = []): void // 失败响应
    {
        self::json(array_merge(['code' => $code, 'message' => $message, 'data' => null], $extra), $httpStatus); // 输出错误
    }

    public static function sseHeaders(): void           // 流式响应头
    {
        if (!headers_sent()) {                          // 未发送头时才设置
            header('Content-Type: text/event-stream; charset=utf-8'); // SSE类型
            header('Cache-Control: no-cache, no-store'); // 禁止缓存
            header('Connection: keep-alive');           // 长连接
            header('X-Accel-Buffering: no');            // 关闭Nginx缓冲
        }
        while (ob_get_level() > 0) {                    // 清理已有缓冲
            ob_end_flush();                             // 逐层刷新
        }
        ini_set('output_buffering', '0');               // 关闭PHP缓冲
        ini_set('zlib.output_compression', '0');        // 关闭压缩
        ob_implicit_flush(true);                        // 立即输出
    }
}
