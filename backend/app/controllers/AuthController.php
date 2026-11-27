<?php
// 认证控制器：邮箱验证码、邮箱登录注册、QQ登录、绑定邮箱、设备核验、退出
class AuthController
{
    public static function sendCode(): void             // 发送验证码
    {
        $email = strtolower(Req::string('email'));      // 邮箱统一小写
        $purpose = Req::string('purpose', 'login');     // 用途
        if (!in_array($purpose, ['login', 'register', 'bind', 'device_verify', 'unfreeze'], true)) { // 用途白名单
            throw new ApiException(40010, '不支持的验证码用途'); // 拒绝
        }
        $user = null;                                   // 当前用户
        if (in_array($purpose, ['bind', 'device_verify', 'unfreeze'], true)) { // 需要登录的用途
            $user = Auth::requireUser(false, false);    // 校验令牌，但不受封禁/冻结拦截
        }
        if ($purpose === 'device_verify' || $purpose === 'unfreeze') { // 只能发给账号已绑定邮箱
            if (empty($user['email'])) {                 // QQ用户未绑定邮箱
                throw new ApiException(40012, '请先绑定邮箱后再进行验证'); // 拒绝
            }
            $email = strtolower((string)$user['email']); // 强制使用账号绑定邮箱
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // 邮箱格式
            throw new ApiException(40011, '邮箱格式不正确'); // 拒绝
        }
        $rate = Config::get('rate_limit');              // 限流配置
        RateLimiter::require('send_code_email_min:' . $email, (int)$rate['send_code_per_minute'], 60, '验证码发送过于频繁'); // 每分钟
        RateLimiter::require('send_code_email_day:' . $email, (int)$rate['send_code_per_day'], 86400, '今日验证码发送次数已达上限'); // 每天
        RateLimiter::require('send_code_ip:' . Req::ip(), 20, 3600, '当前网络请求验证码过多'); // IP每小时

        $code = (string)random_int(100000, 999999);      // 6位数字验证码
        $expires = date('Y-m-d H:i:s', time() + 600);    // 10分钟有效
        Db::exec('UPDATE verification_codes SET used_at = NOW() WHERE email = ? AND purpose = ? AND used_at IS NULL', // 作废旧验证码
            [$email, $purpose]);
        Db::insert('INSERT INTO verification_codes (email, code, purpose, expires_at, ip) VALUES (?, ?, ?, ?, ?)', // 写入新验证码
            [$email, $code, $purpose, $expires, Req::ip()]);
        Mailer::sendCode($email, $code, $purpose);       // 发送邮件
        Log::info('send_code', ['email' => Security::maskEmail($email), 'purpose' => $purpose]); // 记录（脱敏）
        $data = ['expires_in' => 600, 'email' => Security::maskEmail($email)]; // 返回信息
        if (Config::get('debug')) {                      // 调试环境
            $data['debug_code'] = $code;                 // 方便本地联调，生产必须关闭debug
        }
        Resp::ok($data, '验证码已发送');                  // 返回
    }

    public static function verifyCode(): void           // 邮箱验证码登录/注册
    {
        $email = strtolower(Req::string('email'));      // 邮箱
        $code = Req::string('code');                     // 验证码
        $deviceId = Req::deviceId();                     // 设备指纹
        $deviceName = Req::deviceName();                 // 设备名称
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // 校验邮箱
            throw new ApiException(40013, '邮箱格式不正确'); // 拒绝
        }
        if (!preg_match('/^\d{6}$/', $code)) {           // 校验验证码格式
            throw new ApiException(40014, '验证码应为6位数字'); // 拒绝
        }
        RateLimiter::require('verify_ip:' . Req::ip(), 10, 300, '验证尝试过于频繁'); // IP限流
        self::consumeCode($email, $code, 'login');       // 校验并消费验证码

        $user = Auth::findUserByEmail($email);           // 查询用户
        $isNew = false;                                  // 是否新用户
        if (!$user) {                                    // 不存在则注册
            $user = Auth::createEmailUser($email);       // 创建用户
            $isNew = true;                               // 标记新用户
        }
        if ((int)$user['status'] !== 1) {                // 账号禁用
            throw new ApiException(40311, '账号已被禁用', 403); // 拒绝
        }
        Risk::ensureSchema();                            // 确保风控表已初始化
        Risk::foreignLoginCheck($user, Req::ip(), $deviceId); // 异地登录检测（命中则冻结）
        $known = Db::fetch('SELECT id FROM user_devices WHERE user_id = ? AND device_id = ?', // 查询设备
            [(int)$user['id'], $deviceId]);
        if (!$known && !$isNew) {                        // 老用户新设备
            Log::security('new_device_login', 'info', '邮箱账号在新设备登录', (int)$user['id']); // 记录
        }
        Auth::touchDevice((int)$user['id'], $deviceId, $deviceName, true); // 登录设备默认信任
        Db::exec('UPDATE users SET last_login_at = NOW(), account_type = ? WHERE id = ?', ['email', (int)$user['id']]); // 更新登录时间
        Risk::recordLogin((int)$user['id'], Req::ip());    // 记录本次登录IP
        $user = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 重新查询，带上风控状态
        $token = Auth::issueToken((int)$user['id'], $deviceId); // 签发令牌
        Log::info('email_login', ['user_id' => (int)$user['id'], 'is_new' => $isNew]); // 记录
        Resp::ok([
            'token'          => $token,                 // 令牌
            'user'           => Auth::publicUser($user), // 用户信息
            'is_new_user'    => $isNew,                 // 是否新注册
            'device_trusted' => true,                   // 登录设备已信任
        ], '登录成功');                                   // 返回
    }

    public static function qqLogin(): void              // QQ授权登录
    {
        $accessToken = Req::string('access_token');      // QQ SDK返回的access_token
        $openidInput = Req::string('openid');            // 客户端拿到的openid
        $deviceId = Req::deviceId();                     // 设备
        $deviceName = Req::deviceName();                 // 设备名
        if ($accessToken === '' || $openidInput === '') { // 参数必填
            throw new ApiException(40015, 'QQ授权参数缺失'); // 拒绝
        }
        $appId = Config::db('qq_app_id');                // QQ AppID（令牌校验只需要 AppID）
        if ($appId === '') {                             // 未配置
            throw new ApiException(50011, 'QQ登录未配置', 500); // 拒绝
        }
        RateLimiter::require('qq_login_ip:' . Req::ip(), 20, 300, 'QQ登录请求过多'); // IP限流

        $me = self::qqApi('https://graph.qq.com/oauth2.0/me?access_token=' . urlencode($accessToken) . '&fmt=json'); // 校验令牌
        $openid = (string)($me['openid'] ?? '');         // 服务端返回openid
        $clientId = (string)($me['client_id'] ?? '');    // 返回AppID
        if ($openid === '' || $clientId !== $appId) {    // openid必须存在且AppID匹配
            Log::security('qq_login_fail', 'warning', 'QQ令牌校验失败'); // 记录
            throw new ApiException(40114, 'QQ授权已失效，请重新授权', 401); // 拒绝
        }
        if ($openidInput !== '' && !hash_equals($openid, $openidInput)) { // 防止客户端伪造openid
            Log::security('qq_login_fail', 'danger', 'openid不一致'); // 记录
            throw new ApiException(40115, 'QQ授权信息校验失败', 401); // 拒绝
        }

        $user = Db::fetch('SELECT * FROM users WHERE qq_openid = ?', [$openid]); // 按openid查询
        $isNew = false;                                  // 是否新用户
        if (!$user) {                                    // 不存在则创建
            // 仅新用户需要拉取QQ头像/昵称，已有用户登录不再覆盖其自定义资料
            $nickname = 'QQ用户' . substr($openid, -6);   // 默认昵称兜底
            $avatar = '';                                 // 头像
            $qqNumber = '';                               // QQ号
            try {
                $info = self::qqApi('https://graph.qq.com/user/get_user_info?access_token=' . urlencode($accessToken) . // 获取用户资料
                    '&oauth_consumer_key=' . urlencode($appId) . '&openid=' . urlencode($openid) . '&fmt=json', 6); // 短超时
                if ((int)($info['ret'] ?? -1) === 0) {   // 获取成功
                    $qqNick = mb_substr(trim((string)($info['nickname'] ?? '')), 0, 60); // 昵称
                    if ($qqNick !== '') { $nickname = $qqNick; } // 有昵称则使用
                    $avatar = (string)($info['figureurl_qq_2'] ?? ($info['figureurl_qq_1'] ?? ($info['figureurl_2'] ?? ''))); // 头像
                    if (!empty($info['qqnumber']) && preg_match('/^\d{5,12}$/', (string)$info['qqnumber'])) { // QQ号
                        $qqNumber = (string)$info['qqnumber']; // 保存QQ号
                    }
                }
            } catch (Throwable $e) {                      // 资料获取失败不阻塞注册
                Log::info('qq_login_profile', ['error' => $e->getMessage()]); // 记录
            }
            $user = Auth::createQqUser($openid, $qqNumber, $nickname, $avatar); // 创建
            $isNew = true;                               // 标记
        } else {                                         // 已存在则不覆盖用户自定义的昵称/头像
            $updates = ['last_login_at = NOW()'];        // 只更新登录时间
            $params = [];                                // 参数
            $qqNumber = '';                              // QQ号占位
            try {
                $info = self::qqApi('https://graph.qq.com/user/get_user_info?access_token=' . urlencode($accessToken) . // 仅尝试更新QQ号
                    '&oauth_consumer_key=' . urlencode($appId) . '&openid=' . urlencode($openid) . '&fmt=json', 5); // 短超时
                if ((int)($info['ret'] ?? -1) === 0 && !empty($info['qqnumber']) && preg_match('/^\d{5,12}$/', (string)$info['qqnumber'])) { // 获取QQ号
                    $qqNumber = (string)$info['qqnumber']; // 保存QQ号
                }
            } catch (Throwable $e) {                      // 失败忽略
                // 不影响登录
            }
            if ($qqNumber !== '') {                      // 有QQ号才更新
                $updates[] = 'qq_number = ?';            // 增加字段
                $params[] = $qqNumber;                   // 增加参数
            }
            $params[] = (int)$user['id'];                // 用户ID
            Db::exec('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?', $params); // 更新
            $user = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 重新查询
        }
        if ((int)$user['status'] !== 1) {                // 账号禁用
            throw new ApiException(40312, '账号已被禁用', 403); // 拒绝
        }
        Risk::ensureSchema();                            // 确保风控表已初始化
        Risk::foreignLoginCheck($user, Req::ip(), $deviceId); // 异地登录检测（命中则冻结）
        Auth::touchDevice((int)$user['id'], $deviceId, $deviceName, true); // QQ授权本身即身份证明，设备直接信任
        Risk::recordLogin((int)$user['id'], Req::ip());    // 记录本次登录IP
        $user = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 重新查询，带上风控状态
        $token = Auth::issueToken((int)$user['id'], $deviceId); // 签发令牌
        Log::info('qq_login', ['user_id' => (int)$user['id'], 'is_new' => $isNew]); // 记录
        Resp::ok([
            'token'          => $token,                 // 令牌
            'user'           => Auth::publicUser($user), // 用户信息
            'is_new_user'    => $isNew,                 // 是否新注册
            'device_trusted' => true,                   // 已信任
        ], '登录成功');                                   // 返回
    }

    public static function bindEmail(): void           // QQ用户补充绑定邮箱
    {
        $user = Auth::requireUser(false, false);        // 允许待核验/冻结设备操作
        $email = strtolower(Req::string('email'));      // 邮箱
        $code = Req::string('code');                    // 验证码
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { // 校验
            throw new ApiException(40016, '邮箱格式不正确'); // 拒绝
        }
        $exists = Db::fetch('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int)$user['id']]); // 是否被占用
        if ($exists) {                                   // 已绑定其他账号
            throw new ApiException(40017, '该邮箱已被其他账号使用'); // 拒绝
        }
        RateLimiter::require('bind_ip:' . Req::ip(), 10, 300, '绑定操作过于频繁'); // 限流
        self::consumeCode($email, $code, 'bind');        // 校验验证码
        Db::exec('UPDATE users SET email = ? WHERE id = ?', [$email, (int)$user['id']]); // 绑定邮箱
        Auth::trustDevice((int)$user['id'], Req::deviceId()); // 绑定成功视为身份核验，信任当前设备
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 重新查询
        Log::info('bind_email', ['user_id' => (int)$user['id']]); // 记录
        Resp::ok(['user' => Auth::publicUser($fresh)], '绑定成功'); // 返回
    }

    public static function bindQq(): void               // 已登录账号绑定QQ
    {
        $user = Auth::requireUser(false, false);        // 允许待核验/冻结设备操作
        $accessToken = Req::string('access_token');      // QQ SDK返回的access_token
        $openidInput = Req::string('openid');            // 客户端拿到的openid
        if ($accessToken === '' || $openidInput === '') { // 参数必填
            throw new ApiException(40015, 'QQ授权参数缺失'); // 拒绝
        }
        $appId = Config::db('qq_app_id');                // QQ AppID
        if ($appId === '') {                             // 未配置
            throw new ApiException(50011, 'QQ登录未配置', 500); // 拒绝
        }
        RateLimiter::require('qq_bind_ip:' . Req::ip(), 20, 300, '绑定操作过于频繁'); // IP限流
        $me = self::qqApi('https://graph.qq.com/oauth2.0/me?access_token=' . urlencode($accessToken) . '&fmt=json'); // 校验令牌
        $openid = (string)($me['openid'] ?? '');         // 服务端返回openid
        $clientId = (string)($me['client_id'] ?? '');    // 返回AppID
        if ($openid === '' || $clientId !== $appId || !hash_equals($openid, $openidInput)) { // 令牌与AppID必须匹配
            Log::security('qq_bind_fail', 'warning', 'QQ令牌校验失败'); // 记录
            throw new ApiException(40114, 'QQ授权已失效，请重新授权', 401); // 拒绝
        }
        $exists = Db::fetch('SELECT id FROM users WHERE qq_openid = ? AND id <> ?', [$openid, (int)$user['id']]); // 是否被占用
        if ($exists) {                                   // 已绑定其他账号
            throw new ApiException(40911, '该QQ已绑定其他账号'); // 拒绝
        }
        // 绑定只需校验 openid 归属，get_user_info 改为尽力获取（见下方），失败不影响绑定
        Db::exec('UPDATE users SET qq_openid = ? WHERE id = ?', [$openid, (int)$user['id']]); // 绑定QQ
        // 尽力获取QQ头像与昵称：成功则更新资料，失败不影响绑定
        try {
            $info = self::qqApi('https://graph.qq.com/user/get_user_info?access_token=' . urlencode($accessToken) . // 获取用户资料
                '&oauth_consumer_key=' . urlencode($appId) . '&openid=' . urlencode($openid) . '&fmt=json', 6); // 短超时，避免拖累绑定速度
            if ((int)($info['ret'] ?? -1) === 0) {       // 获取成功
                $nickname = mb_substr(trim((string)($info['nickname'] ?? '')), 0, 60); // 昵称
                $avatar = (string)($info['figureurl_qq_2'] ?? ($info['figureurl_qq_1'] ?? ($info['figureurl_2'] ?? ''))); // 头像
                if ($nickname !== '' || $avatar !== '') { // 有内容才更新
                    $sets = [];                           // 待更新字段
                    $params = [];                         // 参数
                    if ($nickname !== '') { $sets[] = 'nickname = ?'; $params[] = $nickname; }
                    if ($avatar !== '') { $sets[] = 'avatar = ?'; $params[] = $avatar; }
                    $params[] = (int)$user['id'];         // 用户ID
                    Db::exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params); // 更新
                }
            }
        } catch (Throwable $e) {                          // 失败忽略，不影响绑定
            Log::info('qq_bind_profile', ['error' => $e->getMessage()]); // 记录
        }
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 重新查询
        Log::info('bind_qq', ['user_id' => (int)$user['id']]); // 记录
        Resp::ok(['user' => Auth::publicUser($fresh)], '绑定成功'); // 返回
    }

    public static function deviceVerify(): void         // 新设备二次邮箱核验
    {
        $user = Auth::requireUser(false, false);        // 令牌有效即可（风控状态不影响核验）
        if (empty($user['email'])) {                    // 未绑定邮箱
            throw new ApiException(40018, '请先绑定邮箱后再验证设备'); // 拒绝
        }
        $code = Req::string('code');                     // 验证码
        if (!preg_match('/^\d{6}$/', $code)) {           // 格式校验
            throw new ApiException(40019, '验证码应为6位数字'); // 拒绝
        }
        RateLimiter::require('device_verify:' . (int)$user['id'], 10, 300, '设备核验过于频繁'); // 限流
        self::consumeCode((string)$user['email'], $code, 'device_verify'); // 消费验证码
        Auth::trustDevice((int)$user['id'], Req::deviceId()); // 信任设备
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 查询用户
        Resp::ok(['user' => Auth::publicUser($fresh), 'device_trusted' => true], '设备验证成功'); // 返回
    }

    public static function logout(): void               // 退出登录
    {
        $user = Auth::requireUser(false, false);        // 令牌有效即可（封禁用户也可退出）
        $header = Req::header('Authorization');         // 读取令牌
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) { // 解析
            Db::exec('DELETE FROM user_tokens WHERE token_hash = ?', [hash('sha256', strtolower($m[1]))]); // 删除令牌
        }
        Resp::ok(null, '已退出登录');                      // 返回
    }

    public static function consumeCode(string $email, string $code, string $purpose): array // 校验并消费验证码
    {
        $row = Db::fetch('SELECT * FROM verification_codes WHERE email = ? AND purpose = ? ' . // 查询最新可用验证码
            'AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1', [$email, $purpose]);
        if (!$row) {                                    // 不存在/过期
            throw new ApiException(40117, '验证码已过期，请重新获取', 401); // 拒绝
        }
        if (!hash_equals((string)$row['code'], $code)) { // 恒定时间比较
            throw new ApiException(40118, '验证码错误', 401); // 拒绝
        }
        Db::exec('UPDATE verification_codes SET used_at = NOW() WHERE id = ?', [(int)$row['id']]); // 标记已用
        return $row;                                    // 返回记录
    }

    private static function qqApi(string $url, int $timeout = 15): array   // 调用QQ开放平台接口
    {
        $ch = curl_init($url);                          // 初始化
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,             // 返回内容
            CURLOPT_TIMEOUT        => $timeout,         // 超时（绑定场景用短超时，避免拖累速度）
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout), // 连接超时
            CURLOPT_SSL_VERIFYPEER => true,             // 证书校验
            CURLOPT_SSL_VERIFYHOST => 2,                // 域名校验
            CURLOPT_USERAGENT      => 'WanyuAI/1.0',    // 标识
        ]);
        $body = curl_exec($ch);                         // 执行
        $err = curl_error($ch);                         // 错误
        curl_close($ch);                                // 关闭
        if ($body === false) {                          // 网络失败
            Log::error('qq_api_curl', ['error' => $err]); // 记录
            throw new ApiException(50012, 'QQ服务暂时不可用', 500); // 拒绝
        }
        $text = trim((string)$body);                    // 去空格
        if (substr($text, 0, 9) === 'callback(') {      // JSONP 兼容（兼容PHP 7.2）
            $text = substr($text, 9);                   // 去掉callback(
            $text = rtrim($text, ');');                 // 去掉结尾
        }
        $json = json_decode($text, true);               // 解析
        if (!is_array($json)) {                         // 非法响应
            Log::error('qq_api_json', ['body' => mb_substr($text, 0, 300)]); // 记录
            throw new ApiException(50013, 'QQ服务返回异常', 500); // 拒绝
        }
        return $json;                                   // 返回
    }
}
