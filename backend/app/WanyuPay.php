<?php
// 挽屿AI —— 川聊支付接口封装
// 签名：HMAC-SHA256(secret, appid + "\n" + timestamp + "\n" + nonce + "\n" + body)
// 密钥（AppSecret）只允许存在于后端 config.php，禁止出现在任何客户端
class WanyuPay
{
    /** 川聊支付网关根地址 */
    public const BASE_URL = 'http://im.si888.cn';

    /** 读取支付配置（来自 backend/config/config.php） */
    private static function cfg(): array
    {
        $cfg = Config::get('pay');
        if (!$cfg || empty($cfg['appid'])) {
            throw new ApiException(50010, '支付配置缺失（backend/config/config.php 中 pay.appid）', 500);
        }
        if (empty($cfg['secret'])) {
            throw new ApiException(50010, '支付密钥缺失（backend/config/config.php 中 pay.secret）', 500);
        }
        return $cfg;
    }

    /**
     * 计算川聊签名（HMAC-SHA256 小写十六进制）
     * 原文 = appid + "\n" + timestamp(秒级) + "\n" + nonce + "\n" + 原始请求体
     */
    public static function sign(string $body, int $timestamp, string $nonce): string
    {
        $cfg = self::cfg();                                 // 取配置（顺带校验密钥存在）
        $raw = $cfg['appid'] . "\n" . $timestamp . "\n" . $nonce . "\n" . $body; // 拼接原文
        return hash_hmac('sha256', $raw, $cfg['secret']);   // HMAC-SHA256 小写十六进制
    }

    /** 生成 32 位随机串（A-Za-z0-9） */
    private static function nonce(): string
    {
        return bin2hex(random_bytes(16));                   // 16 字节随机 = 32 位 hex
    }

    /**
     * 统一发起川聊 POST 请求
     * @param string $path 接口路径，如 /api/v1/pay/create_order.php
     * @param array $payload 原始请求体数组（将被 json_encode）
     * @return array 川聊返回的 JSON 数组
     * @throws ApiException 网络错误 / 川聊业务错误
     */
    private static function request(string $path, array $payload): array
    {
        $timestamp = time();                                // 秒级时间戳
        $nonce = self::nonce();                             // 随机串
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // 规范化JSON
        $sign = self::sign($body, $timestamp, $nonce);       // 计算签名
        $cfg = self::cfg();                                 // 取appid

        // 组装请求头
        $headers = [
            'X-App-Id: ' . $cfg['appid'],
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Sign: ' . $sign,
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: WanyuAI-Backend/1.0',
        ];

        $ch = curl_init();                                  // cURL 句柄
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::BASE_URL . $path,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,                  // 15秒超时
            CURLOPT_SSL_VERIFYPEER => false,              // 川聊网关无HTTPS证书？留false，待部署后改为true
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {                              // 网络层错误
            Log::error('chuanliao_pay_curl', ['path' => $path, 'error' => $err]);
            throw new ApiException(50011, '支付网关请求失败：' . $err, 502);
        }

        $data = json_decode($resp, true);                   // 解析响应
        if (!is_array($data)) {                             // 响应不是合法JSON
            Log::error('chuanliao_pay_bad_json', ['path' => $path, 'resp' => substr($resp, 0, 500)]);
            throw new ApiException(50012, '支付网关响应格式异常', 502);
        }

        // 川聊返回也是 HTTP 200，业务成败看 code 字段
        $code = (int)($data['code'] ?? -1);
        if ($code !== 0) {                                  // 业务失败
            Log::error('chuanliao_pay_biz_error', ['path' => $path, 'code' => $code, 'msg' => $data['msg'] ?? '', 'resp' => $data]);
            throw new ApiException(50013, '支付网关返回错误：' . ($data['msg'] ?? '未知错误') . " (code=$code)", 400);
        }
        return $data;                                       // 返回完整响应数组，调用方取 data 字段
    }

    // ---------------- 川聊四个接口封装 ----------------

    /** 1) 下单：POST /api/v1/pay/create_order.php */
    public static function createOrder(
        string $outOrderNo,    // 我方订单号（≤64字符）
        string $amount,        // 金额字符串，0.01~999999.99，最多两位小数
        int $uid,              // 付款用户的川聊UID
        string $subject,       // 商品标题（≤64字符）
        string $payType,       // 'APP' | 'H5'
        string $notifyUrl = '',// 回调地址（当前留空，轮询兜底）
        string $returnUrl = '',// 成功跳转页
        string $failUrl = '',  // 失败跳转页
        string $attach = ''    // 透传字段（PHP 7.2 不允许参数列表尾逗号）
    ): array {
        $payload = [
            'out_order_no' => $outOrderNo,
            'amount'       => $amount,
            'uid'          => $uid,
            'subject'      => mb_substr($subject, 0, 64),
            'pay_type'     => $payType === 'H5' ? 'H5' : 'APP',
        ];
        if ($notifyUrl !== '') $payload['notify_url'] = $notifyUrl;
        if ($returnUrl !== '') $payload['return_url'] = $returnUrl;
        if ($failUrl !== '')   $payload['fail_url']   = $failUrl;
        if ($attach !== '')    $payload['attach']      = $attach;

        $resp = self::request('/api/v1/pay/create_order.php', $payload);
        return $resp['data'] ?? [];                         // 返回 data 子对象
    }

    /** 2) 查单：POST /api/v1/pay/query_order.php（用我方订单号优先） */
    public static function queryOrder(string $outOrderNo = '', string $platformOrderNo = ''): array
    {
        if ($outOrderNo === '' && $platformOrderNo === '') {
            throw new ApiException(50014, '查单需要 out_order_no 或 platform_order_no', 400);
        }
        $payload = [];
        if ($platformOrderNo !== '') $payload['platform_order_no'] = $platformOrderNo;
        if ($outOrderNo !== '')      $payload['out_order_no']      = $outOrderNo;

        $resp = self::request('/api/v1/pay/query_order.php', $payload);
        return $resp['data'] ?? [];
    }

    /** 3) 退款：POST /api/v1/pay/refund.php（应用未开通退款权限时返回 403） */
    public static function refund(string $platformOrderNo, string $amount, string $reason): array
    {
        $payload = [
            'platform_order_no' => $platformOrderNo,
            'amount'            => $amount,
            'reason'            => mb_substr($reason, 0, 200),
        ];
        $resp = self::request('/api/v1/pay/refund.php', $payload);
        return $resp['data'] ?? [];
    }

    /** 4) 查川聊用户余额：POST 或 GET /api/v1/user/balance.php */
    public static function userBalance(int $uid): array
    {
        $payload = ['uid' => $uid];
        $resp = self::request('/api/v1/user/balance.php', $payload);
        return $resp['data'] ?? [];
    }

    // ---------------- 回调验签（幂等接收端用） ----------------

    /**
     * 川聊回调验签：
     * 从请求头取 X-App-Id / X-Timestamp / X-Nonce / X-Sign，
     * 用原始请求体（php://input）重新计算签名，恒定时间比较。
     */
    public static function verifyCallbackSign(string $rawBody): bool
    {
        $headers = self::allHeaders();                      // 取全部请求头（兼容大小写）
        $cfg = self::cfg();
        $appId = $headers['X-App-Id'] ?? '';
        $ts    = $headers['X-Timestamp'] ?? 0;
        $nonce = $headers['X-Nonce'] ?? '';
        $sign  = $headers['X-Sign'] ?? '';

        if ($appId !== $cfg['appid']) return false;         // AppID 必须一致
        if (empty($sign) || empty($nonce) || !is_numeric($ts)) return false; // 必填项
        if (abs(time() - (int)$ts) > 300) return false;     // 时间偏差超过300秒视为无效请求

        $expected = self::sign($rawBody, (int)$ts, $nonce); // 重算签名
        // 恒定时间比较（防时序攻击）
        return hash_equals(strtolower($expected), strtolower($sign));
    }

    /** 兼容大小写读取请求头（Apache/nginx 大小写处理不一致） */
    private static function allHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $raw = getallheaders() ?: [];
            $out = [];
            foreach ($raw as $k => $v) {
                $out[ucwords(strtolower($k), '-')] = $v;    // 统一首字母大写
            }
            return $out;
        }
        // 回退方案：从 $_SERVER 前缀解析
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $out[$name] = $v;
            }
        }
        return $out;
    }
}
