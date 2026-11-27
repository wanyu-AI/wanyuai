<?php
// 用户控制器：资料查询、资料更新
class UserController
{
    public static function profile(): void              // 获取用户信息
    {
        $user = Auth::requireUser(true, false);         // 必须登录且设备可信（资料查询不受风控拦截）
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 重新查询最新积分
        Resp::ok([
            'user'          => Auth::publicUser($fresh), // 用户资料（含风控状态）
            'tokens_per_point' => max(1, Config::int('tokens_per_point', 1000)), // 积分比例
            'official_model' => Config::db('deepseek_model', 'deepseek-chat'), // 官方模型
        ]);                                             // 返回
    }

    public static function updateProfile(): void        // 更新昵称/头像
    {
        $user = Auth::requireUser(true);                // 校验登录
        $nickname = Req::string('nickname');            // 昵称
        $avatar = Req::string('avatar');                // 头像URL
        $clearAvatar = Req::bool('clear_avatar');       // 是否清空头像
        if ($nickname === '' && $avatar === '' && !$clearAvatar) { // 至少一项
            throw new ApiException(40020, '没有需要更新的内容'); // 拒绝
        }
        $sets = [];                                     // 更新字段
        $params = [];                                   // 参数
        if ($nickname !== '') {                         // 更新昵称
            $nickname = mb_substr($nickname, 0, 60);    // 限长
            Risk::ensureSchema();                       // 确保风控表已初始化
            Risk::checkText((int)$user['id'], $nickname, '昵称', 40334); // AI 审核昵称：违规先警告，超过5次封禁
            $sets[] = 'nickname = ?';                   // 字段
            $params[] = $nickname;                      // 参数
        }
        if ($clearAvatar) {                             // 清空头像（改用本地表情等）
            $sets[] = 'avatar = ?';                     // 字段
            $params[] = '';                             // 空串
        } elseif ($avatar !== '') {                     // 更新头像
            if (!preg_match('#^https://#i', $avatar)) { // 只允许HTTPS
                throw new ApiException(40021, '头像地址必须为HTTPS'); // 拒绝
            }
            $sets[] = 'avatar = ?';                     // 字段
            $params[] = mb_substr($avatar, 0, 500);     // 限长
        }
        $params[] = (int)$user['id'];                   // 用户ID
        Db::exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params); // 更新
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 查询
        Resp::ok(['user' => Auth::publicUser($fresh)], '资料已更新'); // 返回
    }

    public static function uploadAvatar(): void        // 上传头像图片并写入资料
    {
        $user = Auth::requireUser(true);                // 必须登录且设备可信
        RateLimiter::require('avatar_upload:' . (int)$user['id'], 5, 60, '头像上传过于频繁'); // 每用户每分钟最多5次
        $raw = Req::string('image');                    // data URL 或纯 base64
        if ($raw === '') {                              // 空内容
            throw new ApiException(40022, '头像内容不能为空'); // 拒绝
        }
        if (preg_match('#^data:[^;,]+;base64,#i', $raw, $m)) { // 去掉 data URL 前缀
            $raw = substr($raw, strlen($m[0]));         // 只保留 base64 部分
        }
        if (strlen($raw) > 4 * 1024 * 1024) {           // base64 过大先拦截，避免内存占用
            throw new ApiException(40023, '头像文件过大（最大2MB）'); // 拒绝
        }
        $bytes = base64_decode($raw, true);             // 严格模式解码
        if ($bytes === false || $bytes === '') {        // 解码失败
            throw new ApiException(40024, '头像数据格式错误'); // 拒绝
        }
        if (strlen($bytes) > 2 * 1024 * 1024) {         // 解码后大小限制
            throw new ApiException(40023, '头像文件过大（最大2MB）'); // 拒绝
        }
        $info = @getimagesizefromstring($bytes);        // 读取真实图片信息
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp']; // 允许的格式
        if ($info === false || !isset($types[$info[2]])) { // 非图片或格式不支持
            throw new ApiException(40025, '仅支持 JPG/PNG/WebP 图片'); // 拒绝
        }
        $ext = $types[$info[2]];                        // 按真实格式取扩展名
        $dir = WANYU_ROOT . '/storage/avatars';         // 头像存储目录（storage 已具备写权限）
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { // 创建目录
            throw new ApiException(50003, '头像目录不可写', 500); // 部署问题
        }
        $oldFiles = glob($dir . '/u' . (int)$user['id'] . '_*'); // 旧头像文件
        if (is_array($oldFiles)) {                      // 清理旧头像，避免占用空间
            foreach ($oldFiles as $old) {               // 逐个删除
                @unlink($old);                          // 失败忽略
            }
        }
        $name = 'u' . (int)$user['id'] . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext; // 随机文件名
        if (@file_put_contents($dir . '/' . $name, $bytes, LOCK_EX) === false) { // 写入文件
            throw new ApiException(50003, '头像写入失败', 500); // 写入失败
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');  // 当前域名
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) { // 防止 Host 头注入
            throw new ApiException(50003, '无法生成头像地址', 500); // 拒绝
        }
        $base = rtrim(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/'); // 入口文件路径（含 index.php）
        $url = 'https://' . $host . $base . '/uploads/avatars/' . $name; // 公网 HTTPS 地址，走入口的 PATH_INFO
        Db::exec('UPDATE users SET avatar = ? WHERE id = ?', [$url, (int)$user['id']]); // 写入资料
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 查询最新资料
        Resp::ok(['url' => $url, 'user' => Auth::publicUser($fresh)], '头像已更新'); // 返回地址与资料
    }

    public static function serveAvatar(string $name): void // 输出头像文件（公开GET，无需登录）
    {
        $name = basename($name);                        // 只取文件名，防止目录穿越
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) { // 文件名白名单
            http_response_code(404);                    // 非法文件名
            exit;                                       // 结束
        }
        $path = WANYU_ROOT . '/storage/avatars/' . $name; // 文件实际路径
        if (!is_file($path)) {                          // 文件不存在
            http_response_code(404);                    // 返回404
            exit;                                       // 结束
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); // 扩展名
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'); // 类型
        header('Content-Type: ' . $mime);               // 图片类型
        header('Content-Length: ' . filesize($path));   // 文件大小
        header('Cache-Control: public, max-age=86400'); // 允许客户端缓存一天
        readfile($path);                                // 输出文件
        exit;                                           // 结束
    }
}
