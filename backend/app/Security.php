<?php
// 安全校验类：HTTPS、时间戳、签名、设备指纹
class Security
{
    public static function enforceHttps(): void         // 强制 HTTPS
    {
        if (!Config::get('force_https', true)) {        // 未开启强制则跳过
            return;                                     // 结束
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'); // 常规HTTPS判断
        if (!$https && Config::get('trust_proxy')) {    // 反向代理判断
            $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''); // 读取协议头
            $https = $proto === 'https';                // 代理声明HTTPS
        }
        if (!$https) {                                  // 非HTTPS直接拒绝
            throw new ApiException(40301, '必须使用HTTPS访问', 403); // 抛出异常
        }
    }

    public static function canonical(array $params, string $timestamp, string $deviceId, string $secret): string // 生成签名原文
    {
        $data = [];                                     // 参与签名的标量参数
        foreach ($params as $k => $v) {                 // 遍历请求参数
            if (is_array($v) || is_object($v)) {        // 数组/对象不参与签名
                continue;                               // 跳过
            }
            if ($v === null) {                          // null 统一为空串
                $v = '';                                // 赋空串
            } elseif (is_bool($v)) {                    // 布尔统一小写字符串
                $v = $v ? 'true' : 'false';             // 转换
            }
            $data[(string)$k] = (string)$v;             // 记录标量值
        }
        $data['timestamp'] = $timestamp;                // 时间戳参与签名
        $data['device_id'] = $deviceId;                 // 设备指纹参与签名，防篡改
        ksort($data, SORT_STRING);                      // 按键名ASCII升序排序
        $pairs = [];                                    // 键值对列表
        foreach ($data as $k => $v) {                   // 逐项拼接
            $pairs[] = $k . '=' . $v;                   // 拼接 k=v
        }
        return implode('&', $pairs) . '&key=' . $secret; // 末尾追加服务端密钥
    }

    public static function sign(array $params, string $timestamp, string $deviceId, string $secret): string // 计算签名
    {
        return hash('sha256', self::canonical($params, $timestamp, $deviceId, $secret)); // SHA256小写十六进制
    }

    public static function verify(int $maxAge = 60): void // 校验时间戳与签名
    {
        $body = Req::body();                            // 读取请求体
        $timestamp = Req::header('X-Timestamp');        // 读取时间戳
        $sign = Req::header('X-Sign');                  // 读取签名
        $deviceId = Req::deviceId();                    // 读取设备指纹

        if ($timestamp === '' || !ctype_digit($timestamp)) { // 时间戳必须为数字
            Log::security('timestamp_fail', 'warning', '时间戳缺失或格式错误'); // 记录事件
            throw new ApiException(40101, '时间戳无效', 401); // 拒绝请求
        }
        $diff = abs(time() - (int)$timestamp);          // 计算时间差
        if ($diff > $maxAge) {                          // 超过60秒视为重放
            Log::security('timestamp_fail', 'warning', '请求时间差' . $diff . '秒'); // 记录
            throw new ApiException(40102, '请求已过期，请校准设备时间', 401); // 拒绝
        }
        if ($deviceId === '' || strlen($deviceId) > 120) { // 设备指纹必须存在
            Log::security('device_invalid', 'warning', '设备指纹缺失或过长'); // 记录
            throw new ApiException(40103, '设备标识无效', 401); // 拒绝
        }
        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($sign))) { // 签名格式校验
            Log::security('sign_fail', 'warning', '签名格式错误'); // 记录
            throw new ApiException(40104, '签名无效', 401); // 拒绝
        }
        $secret = Config::db('api_sign_key');           // 读取服务端签名密钥
        if ($secret === '' || strpos($secret, 'CHANGE_ME') !== false) { // 未配置密钥
            Log::error('sign_key_missing', ['message' => 'api_sign_key 未配置']); // 记录
            throw new ApiException(50002, '服务端签名密钥未配置', 500); // 拒绝
        }
        $expected = self::sign($body, $timestamp, $deviceId, $secret); // 计算期望签名
        if (!hash_equals($expected, strtolower($sign))) { // 恒定时间比较
            Log::security('sign_fail', 'danger', '签名校验失败'); // 记录事件
            throw new ApiException(40105, '签名校验失败', 401); // 拒绝
        }
    }

    public static function maskEmail(string $email): string // 邮箱脱敏
    {
        $pos = strpos($email, '@');                     // 找@位置
        if ($pos === false || $pos < 2) {               // 非法邮箱
            return $email;                              // 原样返回
        }
        return substr($email, 0, 2) . str_repeat('*', max(1, $pos - 2)) . substr($email, $pos); // a***@domain
    }

    public static function maskString(string $value, int $keep = 4): string // 通用脱敏
    {
        $len = mb_strlen($value);                       // 长度
        if ($len <= $keep) {                            // 太短不处理
            return $value;                              // 返回原值
        }
        return mb_substr($value, 0, $keep) . '****';    // 保留前几位
    }
}
