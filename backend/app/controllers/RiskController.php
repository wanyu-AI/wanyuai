<?php
// 风控控制器：状态查询、邮箱验证解冻、封禁申诉
class RiskController
{
    public static function status(): void               // 查询当前账号风控状态
    {
        $user = Auth::requireUser(true, false);         // 必须登录（不受风控拦截）
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 最新资料
        if (!$fresh) {                                  // 用户不存在
            throw new ApiException(40106, '登录已失效，请重新登录', 401); // 拒绝
        }
        $info = Risk::info($fresh);                     // 风控信息
        Resp::ok([
            'risk' => $info,                            // 风控状态
            'user' => Auth::publicUser($fresh),          // 用户资料
        ]);
    }

    public static function unfreeze(): void             // 冻结账号：邮箱验证码解冻
    {
        $user = Auth::requireUser(false, false);        // 必须登录，但允许冻结状态操作
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 最新状态
        if (!$fresh) {                                  // 不存在
            throw new ApiException(40106, '登录已失效，请重新登录', 401); // 拒绝
        }
        $info = Risk::info($fresh);                     // 风控信息
        if ($info['status'] !== Risk::FROZEN) {         // 只有冻结账号需要解冻
            throw new ApiException(40062, '当前账号无需解冻'); // 拒绝
        }
        if (empty($fresh['email'])) {                   // 必须有绑定邮箱
            throw new ApiException(40063, '账号未绑定邮箱，无法自助解冻，请联系客服'); // 拒绝
        }
        $code = Req::string('code');                    // 验证码
        if (!preg_match('/^\d{6}$/', $code)) {          // 格式校验
            throw new ApiException(40019, '验证码应为6位数字'); // 拒绝
        }
        RateLimiter::require('unfreeze:' . (int)$user['id'], 10, 300, '解冻操作过于频繁'); // 限流
        AuthController::consumeCode((string)$fresh['email'], $code, 'unfreeze'); // 校验验证码
        Risk::release((int)$user['id'], 'user', '用户通过邮箱验证自助解冻'); // 解除冻结
        $latest = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 最新资料
        Resp::ok([
            'risk' => Risk::info($latest),              // 解冻后的状态
            'user' => Auth::publicUser($latest),         // 用户资料
        ], '账号已解冻');
    }

    public static function appeal(): void               // 封禁账号提交申诉
    {
        $user = Auth::requireUser(false, false);        // 必须登录，允许封禁状态提交
        $fresh = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 最新状态
        $info = Risk::info($fresh ? $fresh : []);       // 风控信息
        if ($info['status'] !== Risk::BANNED) {         // 只有封禁才能申诉
            throw new ApiException(40064, '当前账号无需申诉'); // 拒绝
        }
        $content = Req::string('content');              // 申诉内容
        $length = mb_strlen($content);                  // 长度
        if ($length < 5 || $length > 500) {             // 长度限制
            throw new ApiException(40065, '申诉内容需在5-500字之间'); // 拒绝
        }
        RateLimiter::require('appeal:' . (int)$user['id'], 2, 86400, '今日申诉次数已达上限'); // 每天最多2次
        $result = Risk::submitAppeal((int)$user['id'], $content); // 写入申诉
        $latest = Db::fetch('SELECT * FROM users WHERE id = ?', [(int)$user['id']]); // 最新资料
        Resp::ok([
            'appeal' => $result,                        // 申诉ID与状态
            'risk'   => Risk::info($latest),             // 最新风控信息
        ], '申诉已提交，请等待后台审核');
    }
}
