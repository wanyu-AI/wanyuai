<?php
// 积分控制器：积分流水查询
class PointsController
{
    public static function logs(): void                 // 查询当前用户积分流水
    {
        $user = Auth::requireUser(true, false);         // 必须登录（查看流水不受风控拦截）
        $page = Req::int('page', 1);                    // 页码
        $size = Req::int('page_size', 20);              // 每页数量
        Resp::ok(Points::logs((int)$user['id'], $page, $size)); // 返回流水
    }
}
