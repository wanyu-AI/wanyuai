<?php
// 邮件类：不依赖 Composer，直接基于 socket 实现 SMTP 发信
class Mailer
{
    public static function isConfigured(): bool         // 判断SMTP是否已配置
    {
        return Config::db('smtp_host') !== '' && Config::db('smtp_user') !== '' && Config::db('smtp_pass') !== ''; // 三项必填
    }

    public static function sendCode(string $email, string $code, string $purpose): void // 发送验证码邮件
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // 校验邮箱格式
            throw new ApiException(40002, '邮箱格式不正确'); // 抛出异常
        }
        $purposeText = [                                // 用途文案
            'login'         => '登录',                  // 登录
            'register'      => '注册',                  // 注册
            'bind'          => '绑定邮箱',               // 绑定
            'device_verify' => '新设备验证',              // 设备核验
            'reset'         => '重置邮箱',               // 重置
        ][$purpose] ?? '身份验证';                       // 默认文案
        $subject = '【挽屿AI】' . $purposeText . '验证码'; // 邮件标题
        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;max-width:520px;margin:0 auto;padding:24px;">' . // 邮件正文
            '<h2 style="color:#1f2937;font-size:18px;">挽屿AI ' . htmlspecialchars($purposeText, ENT_QUOTES) . '验证码</h2>' . // 标题
            '<p style="color:#4b5563;font-size:14px;">您正在进行' . htmlspecialchars($purposeText, ENT_QUOTES) . '操作，验证码如下：</p>' . // 说明
            '<div style="margin:20px 0;padding:16px;background:#f3f4f6;border-radius:12px;text-align:center;font-size:30px;letter-spacing:8px;font-weight:700;color:#111827;">' . htmlspecialchars($code, ENT_QUOTES) . '</div>' . // 验证码
            '<p style="color:#9ca3af;font-size:12px;">验证码10分钟内有效，请勿泄露给他人。若非本人操作请忽略本邮件。</p>' . // 安全提示
            '</div>';                                   // 结束
        self::send($email, $subject, $html);            // 调用SMTP发送
    }

    public static function send(string $to, string $subject, string $html): void // 发送HTML邮件
    {
        if (!self::isConfigured()) {                    // 未配置SMTP
            throw new ApiException(50003, '邮箱服务未配置，请联系管理员', 500); // 抛出异常
        }
        $host = Config::db('smtp_host');                // SMTP主机
        $port = Config::int('smtp_port', 465);          // SMTP端口
        $secure = strtolower(Config::db('smtp_secure', 'ssl')); // 加密方式
        $user = Config::db('smtp_user');                // 账号
        $pass = Config::db('smtp_pass');                // 密码
        $from = Config::db('smtp_from') ?: $user;       // 发件地址
        $fromName = Config::db('smtp_from_name', '挽屿AI'); // 发件名称
        $timeout = 15;                                  // 超时秒数

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port; // 远程地址
        $context = stream_context_create([              // SSL上下文
            'ssl' => [
                'verify_peer'      => true,             // 校验服务端证书
                'verify_peer_name' => true,             // 校验证书域名
                'allow_self_signed'=> false,            // 禁止自签名
                'SNI_enabled'      => true,             // 开启SNI
            ],
        ]);
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context); // 建立连接
        if (!$fp) {                                     // 连接失败
            Log::error('smtp_connect', ['error' => $errstr, 'errno' => $errno]); // 记录
            throw new ApiException(50004, '邮件服务连接失败', 500); // 抛出异常
        }
        stream_set_timeout($fp, $timeout);              // 设置读超时
        try {
            self::expect($fp, [220]);                   // 等待欢迎语
            $ehlo = 'EHLO ' . (gethostname() ?: 'wanyu.local'); // EHLO 主机名
            self::command($fp, $ehlo, [250]);           // 发送EHLO
            if ($secure === 'tls') {                    // STARTTLS模式
                self::command($fp, 'STARTTLS', [220]);  // 请求升级TLS
                $ok = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); // 启用TLS
                if (!$ok) {                             // 升级失败
                    throw new ApiException(50005, 'TLS加密握手失败', 500); // 抛出异常
                }
                self::command($fp, 'EHLO ' . (gethostname() ?: 'wanyu.local'), [250]); // 重新EHLO
            }
            self::command($fp, 'AUTH LOGIN', [334]);    // 登录认证
            self::command($fp, base64_encode($user), [334]); // 发送账号
            self::command($fp, base64_encode($pass), [235]); // 发送密码
            self::command($fp, 'MAIL FROM:<' . $from . '>', [250]); // 发件人
            self::command($fp, 'RCPT TO:<' . $to . '>', [250, 251]); // 收件人
            self::command($fp, 'DATA', [354]);          // 开始正文
            $headers = [                                // 邮件头
                'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>', // 发件人
                'To: <' . $to . '>',                    // 收件人
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=', // 标题编码
                'MIME-Version: 1.0',                    // MIME版本
                'Content-Type: text/html; charset=UTF-8', // HTML正文
                'Content-Transfer-Encoding: base64',    // Base64编码
                'Date: ' . date('r'),                    // 日期
            ];
            $body = chunk_split(base64_encode($html), 76, "\r\n"); // 正文编码
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n."; // 组装报文
            fwrite($fp, $data . "\r\n");                // 写入正文
            self::expect($fp, [250]);                   // 等待接收确认
            self::command($fp, 'QUIT', [221]);          // 退出
        } finally {                                     // 无论成功失败
            @fclose($fp);                               // 关闭连接
        }
    }

    private static function command($fp, string $cmd, array $codes): void // 发送命令并校验响应
    {
        fwrite($fp, $cmd . "\r\n");                     // 写入命令
        self::expect($fp, $codes);                      // 校验响应码
    }

    private static function expect($fp, array $codes): void // 校验SMTP响应码
    {
        $line = '';                                     // 响应行
        $code = 0;                                      // 响应码
        do {
            $line = fgets($fp, 512);                    // 读取一行
            if ($line === false) {                      // 读取失败/超时
                throw new ApiException(50006, '邮件服务响应超时', 500); // 抛出异常
            }
            $code = (int)substr(trim($line), 0, 3);     // 取三位响应码
        } while (isset($line[3]) && $line[3] === '-');  // 多行响应继续读
        if (!in_array($code, $codes, true)) {           // 响应码不符合预期
            Log::error('smtp_response', ['code' => $code, 'line' => trim($line)]); // 记录
            throw new ApiException(50007, '邮件服务返回异常：' . $code, 500); // 抛出异常
        }
    }
}
