<?php
// 请求处理类：统一读取 JSON、请求头、IP 与设备信息
class Req
{
    private static $body = null;                        // JSON 请求体缓存（PHP 7.2 兼容）

    public static function method(): string             // 请求方法
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); // 统一大写
    }

    public static function header(string $name): string // 读取请求头（兼容 Apache/Nginx）
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name)); // 转换为 $_SERVER 键
        if (isset($_SERVER[$key])) {                    // 常规请求头
            return trim((string)$_SERVER[$key]);        // 返回去空格值
        }
        if ($name === 'Content-Type') {                 // Content-Type 不是 HTTP_ 前缀
            return trim((string)($_SERVER['CONTENT_TYPE'] ?? '')); // 直接读取
        }
        if ($name === 'Authorization') {                // 兼容部分 FastCGI 环境
            return trim((string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))); // 返回授权头
        }
        if (function_exists('getallheaders')) {         // Apache 环境兜底
            $headers = getallheaders();                 // 获取全部头
            foreach ($headers as $k => $v) {            // 忽略大小写匹配
                if (strcasecmp($k, $name) === 0) {      // 找到目标头
                    return trim((string)$v);            // 返回
                }
            }
        }
        return '';                                      // 未找到返回空串
    }

    public static function body(): array                // 读取 JSON 请求体
    {
        if (self::$body !== null) {                     // 已解析则复用
            return self::$body;                         // 返回缓存
        }
        $raw = file_get_contents('php://input');        // 读取原始请求体
        if ($raw === false || $raw === '') {            // 空请求体
            self::$body = [];                           // 视为空数组
            return self::$body;                         // 返回
        }
        $decoded = json_decode($raw, true);             // 解析 JSON
        if (!is_array($decoded)) {                      // 必须是 JSON 对象
            throw new ApiException(40001, '请求体必须是合法JSON对象', 400); // 抛出业务异常
        }
        self::$body = $decoded;                         // 缓存
        return self::$body;                             // 返回
    }

    public static function rawBody(): string            // 获取原始请求体（用于流式转发）
    {
        $raw = file_get_contents('php://input');        // 再次读取
        return $raw === false ? '' : $raw;              // 返回字符串
    }

    public static function input(string $key, $default = null) // 读取字段
    {
        $body = self::body();                           // 获取请求体
        return $body[$key] ?? $default;                 // 返回字段或默认值
    }

    public static function string(string $key, string $default = ''): string // 读取字符串字段
    {
        $v = self::input($key, $default);               // 读取值
        return is_scalar($v) ? trim((string)$v) : $default; // 仅接受标量
    }

    public static function int(string $key, int $default = 0): int // 读取整数字段
    {
        $v = self::input($key, $default);               // 读取值
        return is_numeric($v) ? (int)$v : $default;     // 数值转换
    }

    public static function bool(string $key, bool $default = false): bool // 读取布尔字段
    {
        $v = self::input($key, $default);               // 读取值
        if (is_bool($v)) {                              // 原生布尔
            return $v;                                  // 直接返回
        }
        return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true); // 兼容字符串
    }

    public static function ip(): string                 // 获取客户端IP
    {
        if (Config::get('trust_proxy')) {               // 反向代理场景
            $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''; // 读取转发头
            if ($fwd !== '') {                          // 有值则取第一个
                return trim(explode(',', $fwd)[0]);     // 返回真实IP
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';    // 直接取远端IP
    }

    public static function deviceId(): string           // 客户端设备指纹
    {
        return self::header('X-Device-Id');             // 从请求头读取
    }

    public static function deviceName(): string         // 设备名称
    {
        $name = self::header('X-Device-Name');          // 从请求头读取
        return $name === '' ? '未知设备' : mb_substr($name, 0, 100); // 限制长度
    }

    public static function appVersion(): string         // 客户端版本号
    {
        return self::header('X-App-Version');           // 从请求头读取
    }

    public static function requirePostJson(): void      // 强制 POST JSON
    {
        if (self::method() !== 'POST') {                // 只允许 POST
            throw new ApiException(40501, '仅支持POST请求', 405); // 抛出异常
        }
        $type = strtolower(self::header('Content-Type')); // 读取内容类型
        if (strpos($type, 'application/json') === false) { // 必须是 JSON
            throw new ApiException(41501, 'Content-Type必须为application/json', 415); // 抛出异常
        }
    }
}
