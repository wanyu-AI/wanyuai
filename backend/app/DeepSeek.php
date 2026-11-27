<?php
// DeepSeek 转发类：服务端持有密钥，转发请求并按 token 扣积分
class DeepSeek
{
    public static function quote(int $userId): array    // 返回调用前提示所需信息
    {
        $perPoint = max(1, Config::int('tokens_per_point', 1000)); // 每积分token数
        return [
            'model'           => Config::db('deepseek_model', 'deepseek-chat'), // 官方模型名
            'tokens_per_point'=> $perPoint,             // 换算比例
            'points'          => Points::balance($userId), // 当前余额
            'min_points'      => 1,                     // 最低启动积分
            'tip'             => '官方模型按实际token扣费，约' . $perPoint . 'token=1积分', // 提示文案
        ];
    }

    /** AI 内容审核：返回 ['violation'=>bool, 'reason'=>string, 'ban_days'=>int] */
    public static function moderate(string $text): array
    {
        $fail = ['violation' => false, 'reason' => '', 'ban_days' => 0]; // 审核失败时放行
        $key = Config::db('deepseek_api_key');          // 服务端密钥
        if ($key === '' || trim($text) === '') {        // 无密钥或空文本
            return $fail;                               // 放行
        }
        $base = rtrim(Config::db('deepseek_base_url', 'https://api.deepseek.com'), '/'); // 根地址
        $suffix = '/chat/completions';                  // 固定后缀
        $url = (strlen($base) >= strlen($suffix) && substr($base, -strlen($suffix)) === $suffix) ? $base : $base . $suffix; // 拼接
        $prompt = '你是中文内容安全审核员。判断用户输入是否包含违法违规内容，包括但不限于：违法犯罪、黑客攻击或破解他人账号、诈骗、赌博、色情、毒品与枪支、暴力恐怖、侵犯隐私、仇恨歧视、买卖违禁品。'
            . '仅输出JSON，不要输出任何解释：{"violation":true或false,"reason":"简短中文原因","ban_days":整数}。'
            . '若违规，按严重程度给出封禁天数：轻微1-3天，一般4-7天，严重8-30天；若不违规，ban_days为0。';
        $payload = [
            'model'       => 'deepseek-chat',           // 固定审核模型
            'messages'    => [
                ['role' => 'system', 'content' => $prompt], // 审核规则
                ['role' => 'user', 'content' => mb_substr($text, 0, 2000)], // 待审文本
            ],
            'temperature' => 0,                         // 稳定输出
            'max_tokens'  => 120,                       // 只输出JSON
            'stream'      => false,                     // 非流式
            'response_format' => ['type' => 'json_object'], // 要求JSON
        ];
        try {
            $result = self::normalRequest($url, $key, $payload, 20); // 审核请求，20秒超时
        } catch (Throwable $e) {                        // 请求失败
            Log::error('ai_moderate_request', ['message' => $e->getMessage()]); // 记录
            return $fail;                               // 放行
        }
        if ($result['http_code'] >= 400) {              // 上游异常
            Log::error('ai_moderate_upstream', ['status' => $result['http_code'], 'body' => mb_substr($result['body'], 0, 300)]); // 记录
            return $fail;                               // 放行
        }
        $json = json_decode($result['body'], true);     // 解析响应
        $content = is_array($json) ? (string)($json['choices'][0]['message']['content'] ?? '') : ''; // 取内容
        $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content)); // 去掉代码块
        $verdict = json_decode($content, true);         // 解析JSON
        if (!is_array($verdict) && preg_match('/\{.*\}/s', $content, $m)) { // 兜底提取JSON
            $verdict = json_decode($m[0], true);        // 再次解析
        }
        if (!is_array($verdict)) {                       // 无法解析
            Log::error('ai_moderate_parse', ['content' => mb_substr($content, 0, 200)]); // 记录
            return $fail;                               // 放行
        }
        $days = (int)($verdict['ban_days'] ?? 0);       // 封禁天数
        if ($days <= 0) {                               // 未给出合法天数
            $days = 7;                                  // 默认7天
        }
        return [
            'violation' => !empty($verdict['violation']), // 是否违规
            'reason'    => trim((string)($verdict['reason'] ?? '')), // 原因
            'ban_days'  => max(1, min(30, $days)),       // 限制1-30天
        ];
    }

    public static function chat(array $user, bool $stream): void // 转发对话请求
    {
        $body = Req::body();                            // 读取请求体
        $messages = $body['messages'] ?? null;          // 消息列表
        if (!is_array($messages) || count($messages) === 0) { // 必须提供消息
            throw new ApiException(40003, 'messages不能为空'); // 抛出异常
        }
        if (count($messages) > 100) {                   // 限制上下文条数
            throw new ApiException(40004, '上下文消息过多，最多100条'); // 抛出异常
        }
        $cleanMessages = [];                            // 清洗后的消息
        foreach ($messages as $msg) {                   // 逐条校验
            if (!is_array($msg)) {                      // 非法结构
                throw new ApiException(40005, 'messages格式错误'); // 抛出异常
            }
            $role = (string)($msg['role'] ?? 'user');   // 角色
            if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) { // 角色白名单
                throw new ApiException(40006, '不支持的消息角色'); // 抛出异常
            }
            $content = $msg['content'] ?? '';           // 内容
            if (is_array($content)) {                   // 多模态数组
                $content = json_encode($content, JSON_UNESCAPED_UNICODE); // 转字符串
            }
            if (mb_strlen((string)$content) > 50000) {  // 单条过长
                throw new ApiException(40007, '单条消息过长'); // 抛出异常
            }
            $cleanMessages[] = ['role' => $role, 'content' => (string)$content]; // 只保留安全字段
        }

        $key = Config::db('deepseek_api_key');          // 读取服务端密钥
        Risk::ensureSchema();                           // 确保风控表已初始化
        Risk::checkChatContent((int)$user['id'], $cleanMessages); // 违禁词检测（仅官方模型走这里）
        if ($key === '') {                              // 未配置密钥
            throw new ApiException(50008, '官方模型密钥未配置', 500); // 抛出异常
        }
        $balance = Points::balance((int)$user['id']);   // 当前积分
        if ($balance < 1) {                             // 积分不足禁止发起
            throw new ApiException(40202, '积分不足，无法调用官方模型', 402); // 抛出异常
        }

        $model = Config::db('deepseek_model', 'deepseek-chat'); // 强制使用后台模型
        $payload = [
            'model'       => $model,                    // 覆盖客户端模型
            'messages'    => $cleanMessages,            // 清洗后的消息
            'stream'      => $stream,                   // 是否流式
        ];
        if (isset($body['temperature']) && is_numeric($body['temperature'])) { // 温度
            $payload['temperature'] = max(0, min(2, (float)$body['temperature'])); // 限制0-2
        }
        if (isset($body['max_tokens']) && is_numeric($body['max_tokens'])) { // 最大输出
            $payload['max_tokens'] = max(1, min(8192, (int)$body['max_tokens'])); // 限制1-8192
        }
        if (isset($body['top_p']) && is_numeric($body['top_p'])) { // top_p
            $payload['top_p'] = max(0, min(1, (float)$body['top_p'])); // 限制0-1
        }
        if ($stream) {                                  // 流式时要求返回用量
            $payload['stream_options'] = ['include_usage' => true]; // DeepSeek支持用量统计
        }

        $base = rtrim(Config::db('deepseek_base_url', 'https://api.deepseek.com'), '/'); // 接口根地址
        $suffix = '/chat/completions';                 // 固定后缀
        $url = (strlen($base) >= strlen($suffix) && substr($base, -strlen($suffix)) === $suffix) ? $base : $base . $suffix; // 拼接地址（兼容PHP 7.2）
        $requestId = bin2hex(random_bytes(12));         // 本次请求ID
        @set_time_limit(300);                           // 延长执行时间
        ignore_user_abort(true);                        // 客户端断开后仍完成扣费

        if ($stream) {                                  // 流式响应
            Resp::sseHeaders();                         // 发送SSE头
            $result = self::streamRequest($url, $key, $payload, $cleanMessages); // 执行流式请求
            self::settle((int)$user['id'], $result, $model, $requestId); // 结算积分
            exit;                                       // 结束
        }

        $result = self::normalRequest($url, $key, $payload); // 非流式请求
        if ($result['http_code'] >= 400) {              // 上游错误
            Log::error('deepseek_upstream', ['status' => $result['http_code'], 'body' => mb_substr($result['body'], 0, 500)]); // 记录
            http_response_code($result['http_code']);   // 透传状态码
            header('Content-Type: application/json; charset=utf-8'); // JSON类型
            echo $result['body'];                       // 原样返回错误
            exit;                                       // 结束
        }
        $json = json_decode($result['body'], true);     // 解析响应
        $usage = is_array($json) ? ($json['usage'] ?? []) : []; // 用量
        $tokens = [
            'prompt_tokens'     => (int)($usage['prompt_tokens'] ?? self::estimateInput($cleanMessages)), // 输入token
            'completion_tokens' => (int)($usage['completion_tokens'] ?? 0), // 输出token
        ];
        $settle = self::settle((int)$user['id'], $tokens, $model, $requestId); // 结算
        header('Content-Type: application/json; charset=utf-8'); // JSON类型
        header('Cache-Control: no-store');              // 禁止缓存
        header('X-Wanyu-Points-Charged: ' . $settle['charged']); // 本次扣费
        header('X-Wanyu-Points-Balance: ' . $settle['balance']); // 剩余积分
        echo $result['body'];                           // 返回OpenAI兼容响应
        exit;                                           // 结束
    }

    private static function normalRequest(string $url, string $key, array $payload, int $timeout = 300): array // 非流式请求
    {
        $ch = curl_init($url);                          // 初始化
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,             // POST
            CURLOPT_RETURNTRANSFER => true,             // 返回内容
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE), // JSON体
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',       // JSON
                'Authorization: Bearer ' . $key,        // 服务端密钥
                'Accept: application/json',             // 期望JSON
            ],
            CURLOPT_CONNECTTIMEOUT => 10,               // 连接超时
            CURLOPT_TIMEOUT        => $timeout,         // 总超时
            CURLOPT_SSL_VERIFYPEER => true,             // 校验证书
            CURLOPT_SSL_VERIFYHOST => 2,                // 校验域名
        ]);
        $body = curl_exec($ch);                         // 执行
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); // 状态码
        $err = curl_error($ch);                         // 错误
        curl_close($ch);                                // 关闭
        if ($body === false) {                          // 请求失败
            Log::error('deepseek_curl', ['error' => $err]); // 记录
            throw new ApiException(50009, '官方模型请求失败', 500); // 抛出异常
        }
        return ['http_code' => $status, 'body' => (string)$body]; // 返回结果
    }

    private static function streamRequest(string $url, string $key, array $payload, array $messages): array // 流式请求
    {
        $usage = [];                                    // 用量
        $output = '';                                   // 输出文本
        $buffer = '';                                   // SSE行缓冲
        $ch = curl_init($url);                          // 初始化
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,             // POST
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE), // JSON体
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',       // JSON
                'Authorization: Bearer ' . $key,        // 密钥
                'Accept: text/event-stream',            // SSE
            ],
            CURLOPT_CONNECTTIMEOUT => 15,               // 连接超时
            CURLOPT_TIMEOUT        => 300,              // 总超时
            CURLOPT_SSL_VERIFYPEER => true,             // 证书校验
            CURLOPT_SSL_VERIFYHOST => 2,                // 域名校验
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$usage, &$output, &$buffer): int { // 逐块处理
                echo $chunk;                            // 原样转发给客户端
                @flush();                               // 立即刷新
                $buffer .= $chunk;                      // 追加解析缓冲
                while (($pos = strpos($buffer, "\n")) !== false) { // 按行解析
                    $line = trim(substr($buffer, 0, $pos)); // 当前行
                    $buffer = substr($buffer, $pos + 1); // 剩余缓冲
                    if (substr($line, 0, 5) !== 'data:') { // 只处理data行（兼容PHP 7.2）
                        continue;                       // 跳过
                    }
                    $data = trim(substr($line, 5));     // 去掉data:
                    if ($data === '' || $data === '[DONE]') { // 结束标记
                        continue;                       // 跳过
                    }
                    $json = json_decode($data, true);   // 解析JSON
                    if (!is_array($json)) {             // 非法数据
                        continue;                       // 跳过
                    }
                    if (isset($json['usage']) && is_array($json['usage'])) { // 用量块
                        $usage = $json['usage'];        // 保存用量
                    }
                    $delta = $json['choices'][0]['delta']['content'] ?? ''; // 增量内容
                    if (is_string($delta)) {            // 文本增量
                        $output .= $delta;              // 累计输出
                    }
                }
                return strlen($chunk);                  // 必须返回长度
            },
        ]);
        curl_exec($ch);                                 // 执行
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); // 状态码
        $err = curl_error($ch);                         // 错误
        curl_close($ch);                                // 关闭
        if ($status >= 400) {                           // 上游错误
            Log::error('deepseek_stream_upstream', ['status' => $status]); // 记录
        } elseif ($err !== '') {                        // 网络错误
            Log::error('deepseek_stream_curl', ['error' => $err]); // 记录
        }
        return [
            'prompt_tokens'     => (int)($usage['prompt_tokens'] ?? self::estimateInput($messages)), // 输入
            'completion_tokens' => (int)($usage['completion_tokens'] ?? self::estimateOutput($output)), // 输出
            'status'            => $status,             // 状态
        ];
    }

    private static function settle(int $userId, array $tokens, string $model, string $requestId): array // 结算积分
    {
        $input = max(0, (int)($tokens['prompt_tokens'] ?? 0)); // 输入token
        $output = max(0, (int)($tokens['completion_tokens'] ?? 0)); // 输出token
        if ($input + $output <= 0) {                    // 没有用量
            return ['charged' => 0, 'balance' => Points::balance($userId)]; // 不扣费
        }
        $cost = Points::pointsForTokens($input + $output); // 应扣积分
        $balance = Points::balance($userId);            // 当前余额
        if ($cost > $balance) {                         // 余额不足以支付全部
            Log::error('points_negative_guard', ['user_id' => $userId, 'need' => $cost, 'balance' => $balance]); // 记录应扣与实有
            $cost = max(0, $balance);                   // 扣到0为止
        }
        if ($cost <= 0) {                               // 无可扣积分
            return ['charged' => 0, 'balance' => $balance]; // 返回
        }
        $newBalance = Points::add($userId, -$cost, 'ai_call', '官方模型调用', [ // 扣费
            'input_tokens'  => $input,                  // 输入
            'output_tokens' => $output,                 // 输出
            'model'         => $model,                  // 模型
            'request_id'    => $requestId,              // 请求ID
        ]);
        return ['charged' => $cost, 'balance' => $newBalance]; // 返回结果
    }

    private static function estimateInput(array $messages): int // 粗略估算输入token
    {
        $text = json_encode($messages, JSON_UNESCAPED_UNICODE); // 合并文本
        return max(1, (int)ceil(mb_strlen($text) / 2)); // 中文约1字1token，粗略折半
    }

    private static function estimateOutput(string $text): int // 粗略估算输出token
    {
        return (int)ceil(mb_strlen($text) / 2);         // 粗略折半
    }
}
