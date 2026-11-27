<?php
// 挽屿AI —— 支付控制器
// 对外暴露三个接口：下单 / 查订单状态 / 川聊回调接收端
// 所有金额一律由服务端按商品定价生成，客户端传来的金额一律忽略
class PayController
{
    // ============ 商品定价（服务端硬编码，客户端金额一律忽略） ============
    // 积分：1元 = 200积分（$0.005 / 积分），档位参考主流APP
    private static function pointsProducts(): array
    {
        return [
            // SKU => [展示名, 点分数, 金额(元, 字符串, 两位小数)]
            'points_200'   => ['name' => '200 积分',   'points' => 200,   'amount' => '1.00'],
            'points_1000'  => ['name' => '1000 积分',  'points' => 1000,  'amount' => '5.00'],
            'points_5000'  => ['name' => '5000 积分',  'points' => 5000,  'amount' => '25.00'],
            'points_10000' => ['name' => '10000 积分', 'points' => 10000, 'amount' => '48.00'],
            'points_50000' => ['name' => '50000 积分', 'points' => 50000, 'amount' => '228.00'],
        ];
    }

    // 会员：参考用户提供的商城参考图
    private static function vipProducts(): array
    {
        return [
            // SKU => [展示名, 类型, 时长月数(永久传 9999), 金额]
            'vip_month'     => ['name' => '月度会员', 'type' => 'month',     'months' => 1,    'amount' => '10.00'],
            'vip_quarter'   => ['name' => '季度会员', 'type' => 'quarter',   'months' => 3,    'amount' => '28.00'],
            'vip_year'      => ['name' => '年费会员', 'type' => 'year',      'months' => 12,   'amount' => '106.00'],
            'vip_permanent' => ['name' => '永久会员', 'type' => 'permanent', 'months' => 9999, 'amount' => '186.00'],
        ];
    }

    /** 根据 product_type + sku 查定价（返回 null 表示 SKU 非法） */
    private static function lookupProduct(string $type, string $sku): ?array
    {
        if ($type === 'points') {
            $all = self::pointsProducts();
            return $all[$sku] ?? null;
        }
        if ($type === 'vip') {
            $all = self::vipProducts();
            return $all[$sku] ?? null;
        }
        return null;
    }

    /** 生成我方订单号：时间戳13位 + 6位随机hex，共19位，唯一且可排序 */
    private static function genOutOrderNo(): string
    {
        return (string)round(microtime(true) * 1000) . bin2hex(random_bytes(3));
    }

    // ============ 接口 1：创建订单 ============
    public static function create(): void
    {
        $user = Auth::requireUser(true);                         // 必须登录 + 设备可信 + 风控拦截
        $type = Req::string('product_type');                     // points / vip
        $sku  = Req::string('product_sku');                      // 具体 SKU
        $payType = Req::string('pay_type', 'APP');                // APP / H5，默认 APP
        $returnUrl = Req::string('return_url');                   // 成功跳转页（可空）
        $failUrl   = Req::string('fail_url');                     // 失败跳转页（可空）

        // 1. 校验风控：封禁/冻结用户禁止付费
        if ((int)$user['risk_status'] !== 0) {
            throw new ApiException(40336, '账号当前处于风控状态，无法购买', 403);
        }

        // 2. 校验商品 SKU
        $product = self::lookupProduct($type, $sku);
        if ($product === null) {
            throw new ApiException(40030, '商品不存在或已下架', 400);
        }

        // 2.1 重复购买检测：已是永久会员时，禁止再购买任何会员套餐（积分包不受影响）
        if ($type === 'vip') {
            $vipRow = Db::fetch('SELECT * FROM wanyu_vip WHERE user_id = ?', [(int)$user['id']]);
            if ($vipRow) {
                // 永久会员：vip_type=permanent 或 expires_at 为 NULL
                $isPermanent = $vipRow['vip_type'] === 'permanent' || $vipRow['expires_at'] === null;
                if ($isPermanent) {
                    throw new ApiException(40033, '您已是永久会员，无需重复购买', 400);
                }
            }
        }

        // 3. 查川聊 uid：首充未绑定时传 0（收银台会引导登录川聊账号，支付成功后回调绑定）
        //    禁止任何兜底：禁止 test_uid、禁止写死开发者 ID、禁止从请求参数读 uid
        $cfg = Config::get('pay');
        $bind = Db::fetch('SELECT chuanliao_uid FROM wanyu_chuanliao_bind WHERE user_id = ?', [(int)$user['id']]);
        $chuanliaoUid = $bind ? (int)$bind['chuanliao_uid'] : 0;

        // 4. 幂等防重：同一用户 60 秒内同一 SKU 已有「可支付」的未支付订单，直接复用，避免重复下单
        //    注意：必须放在插入之前查询，否则查到的永远是自己刚插入的那条，防重会失效
        $recentOrder = Db::fetch(
            'SELECT * FROM wanyu_orders WHERE user_id = ? AND product_sku = ? AND status = 0 AND pay_token <> "" AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1',
            [(int)$user['id'], $sku]
        );
        if ($recentOrder) {
            // 复用旧订单：只更新支付方式，返回旧订单的 pay_token/pay_url
            Db::exec('UPDATE wanyu_orders SET pay_type = ? WHERE id = ?', [$payType === 'H5' ? 'H5' : 'APP', (int)$recentOrder['id']]);
            Resp::ok([
                'out_order_no'       => $recentOrder['out_order_no'],
                'status'             => (int)$recentOrder['status'],
                'pay_token'          => $recentOrder['pay_token'],
                'pay_url'            => $recentOrder['pay_url'],
                'product_type'       => $recentOrder['product_type'],
                'product_sku'        => $recentOrder['product_sku'],
                'product_name'       => $recentOrder['product_name'],
                'amount'             => (string)$recentOrder['amount'],
                'expire_at'          => $recentOrder['expire_at'],
            ], '请打开收银台完成支付');
        }

        // 5. 生成我方订单号，先落库（status=0 待支付）
        $outOrderNo = self::genOutOrderNo();

        // 5.1 跳转地址：川聊收银台是 H5 网页，return/fail 必须是 https，禁止 wanyu:// 深链
        if ($returnUrl === '') {
            $returnUrl = 'https://wan.si888.cn/?pay=done&order=' . $outOrderNo;
        }
        if ($failUrl === '') {
            $failUrl = 'https://wan.si888.cn/?pay=fail&order=' . $outOrderNo;
        }
        // 通知回调地址：配置优先，否则用默认服务端回调
        $notifyUrl = $cfg['notify_url'] ?? '';
        if ($notifyUrl === '') {
            $notifyUrl = 'https://wan.si888.cn/backend/public/index.php/pay/callback';
        }
        $now = date('Y-m-d H:i:s');
        $orderId = Db::insert(
            'INSERT INTO wanyu_orders (user_id, chuanliao_uid, out_order_no, product_type, product_sku, product_name, quantity, amount, pay_type, notify_url, return_url, fail_url, status, pay_token, pay_url, expire_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "", "", ?, ?)',
            [
                (int)$user['id'],
                $chuanliaoUid,
                $outOrderNo,
                $type,
                $sku,
                $product['name'],
                $type === 'points' ? $product['points'] : $product['months'],
                $product['amount'],
                $payType === 'H5' ? 'H5' : 'APP',                 // 跟随客户端选择：APP 唤起 / H5 网页
                $notifyUrl,                                           // 川聊异步回调地址
                $returnUrl,
                $failUrl,
                date('Y-m-d H:i:s', time() + 300),               // token 5分钟过期
                $now,
            ]
        );

        // 6. 调用川聊 create_order.php（PHP 7.2 不支持命名参数，必须按位置传）
        try {
            $data = WanyuPay::createOrder(
                $outOrderNo,
                $product['amount'],
                $chuanliaoUid,
                $product['name'],
                $payType === 'H5' ? 'H5' : 'APP',              // APP / H5 均由客户端决定
                $notifyUrl,
                $returnUrl,
                $failUrl,
                'wanyu_uid:' . $user['id']                     // 透传挽屿用户ID，回调时据此绑定川聊UID
            );
        } catch (ApiException $e) {
            // 川聊失败时把订单标为关闭（status=2），客户端不会继续轮询
            Db::exec('UPDATE wanyu_orders SET status = 2, closed_at = NOW(), remark = ? WHERE id = ?',
                ['川聊下单失败：' . $e->getMessage(), $orderId]);
            throw $e;                                           // 继续抛给客户端
        }

        // 7. 写回川聊返回的 platform_order_no / pay_token / pay_url / expire_at
        Db::exec(
            'UPDATE wanyu_orders SET platform_order_no = ?, pay_token = ?, pay_url = ?, expire_at = ? WHERE id = ?',
            [
                $data['platform_order_no'] ?? '',
                $data['pay_token'] ?? '',
                $data['pay_url'] ?? '',
                $data['expire_at'] ?? date('Y-m-d H:i:s', time() + 300),
                $orderId,
            ]
        );

        // 8. 返回给客户端：pay_token（唤起川聊用）+ pay_url（APP不可用时H5降级）
        Resp::ok([
            'out_order_no'       => $outOrderNo,
            'status'             => 0,                             // 待支付
            'pay_token'          => $data['pay_token'] ?? '',
            'pay_url'            => $data['pay_url'] ?? '',
            'product_type'       => $type,
            'product_sku'        => $sku,
            'product_name'       => $product['name'],
            'amount'             => $product['amount'],
            'expire_at'          => $data['expire_at'] ?? date('Y-m-d H:i:s', time() + 300),
        ], '请打开收银台完成支付');
    }

    // ============ 接口 2：查询订单状态（App 每 2 秒轮询 + onResume 补查） ============
    public static function query(): void
    {
        $user = Auth::requireUser(true);                         // 必须登录
        $outOrderNo = Req::string('out_order_no');               // 我方订单号（客户端唯一ID）
        if ($outOrderNo === '') {
            throw new ApiException(40031, '缺少订单号', 400);
        }

        // 1. 查本地订单（必须归属当前登录用户，防止越权）
        $order = Db::fetch('SELECT * FROM wanyu_orders WHERE out_order_no = ? AND user_id = ?',
            [$outOrderNo, (int)$user['id']]);
        if (!$order) {
            throw new ApiException(40032, '订单不存在', 404);
        }

        // 2. 本地已是终态（已支付/关闭/退款/失败）时，直接返回，不再打川聊
        $localStatus = (int)$order['status'];
        if ($localStatus !== 0) {                                // 0 = 待支付，其它都是终态
            Resp::ok(self::orderResponse($order, false));        // 不再远程查
        }

        // 3. 待支付：调用川聊 query_order.php 拿真实状态
        try {
            $remote = WanyuPay::queryOrder($outOrderNo, (string)$order['platform_order_no']);
        } catch (ApiException $e) {
            // 川聊偶发超时/网络错时，返回本地 0 让客户端继续轮询
            Resp::ok(self::orderResponse($order, true, '支付网关暂时不可用，请稍后重试'));
        }

        // 4. 川聊 status 口径：
        //    0=未支付 → 继续等
        //    1=已支付 → 发货！
        //    2=已关闭 → 不能付
        //    3=全额退款 / 4=部分退款 → 退款处理（不发货）
        //    5=支付失败（通常余额不足）→ 提示用户重新下单
        $cStatus = (int)($remote['status'] ?? 0);

        if ($cStatus === 1) {
            // === 已支付，事务内发货（幂等：只有本地还是 0 时才发货）===
            Db::begin();
            try {
                // 先把本地订单改成 status=1，防止重复发货
                $changed = Db::exec('UPDATE wanyu_orders SET status = 1, paid_at = NOW() WHERE out_order_no = ? AND status = 0', [$outOrderNo]);
                if ($changed > 0) {                              // 第一次支付成功 → 发货
                    self::deliver((int)$user['id'], $order);     // 发货（积分 or 会员）
                }
                Db::commit();
            } catch (Throwable $e) {
                Db::rollback();
                Log::error('wanyu_pay_deliver_fail', ['order_no' => $outOrderNo, 'err' => $e->getMessage()]);
                throw new ApiException(50015, '支付成功但发货失败，请联系客服', 500);
            }
            // 重新查本地订单（状态已变）
            $order = Db::fetch('SELECT * FROM wanyu_orders WHERE out_order_no = ?', [$outOrderNo]);
            Resp::ok(self::orderResponse($order, false), '支付成功');
        }

        // 其它状态：同步到本地
        $statusMap = [2 => 2, 3 => 3, 4 => 4, 5 => 5];           // 川聊 status 直接映射
        $localNew = $statusMap[$cStatus] ?? 0;
        if ($localNew !== 0) {
            Db::exec('UPDATE wanyu_orders SET status = ?, closed_at = NOW() WHERE out_order_no = ? AND status = 0', [$localNew, $outOrderNo]);
            $order = Db::fetch('SELECT * FROM wanyu_orders WHERE out_order_no = ?', [$outOrderNo]);
        }
        Resp::ok(self::orderResponse($order, false));
    }

    // ============ 接口 3：川聊回调接收端（幂等，HTTP 200 + 纯文本 success） ============
    // 注意：这个接口不走 App 端的登录校验！它是川聊服务器直接回调过来的。
    // 所以路由注册时需要写在 requirePostJson / Security::verify 之前（见 index.php 末尾注释）
    public static function callback(): void
    {
        // 1. 读原始请求体（php://input 只读一次，必须先读）
        $rawBody = file_get_contents('php://input') ?: '';
        // 2. 验签（恒定时间比较）
        if (!WanyuPay::verifyCallbackSign($rawBody)) {
            http_response_code(401);
            echo 'fail';
            exit;
        }
        // 3. 解析 payload
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo 'fail';
            exit;
        }
        // 4. 幂等键：platform_order_no
        $platformOrderNo = (string)($data['platform_order_no'] ?? '');
        if ($platformOrderNo === '') {
            http_response_code(400);
            echo 'fail';
            exit;
        }
        // 5. 查本地订单（必须已存在 out_order_no 或 platform_order_no）
        $order = Db::fetch('SELECT * FROM wanyu_orders WHERE platform_order_no = ? OR out_order_no = ?',
            [$platformOrderNo, (string)($data['out_order_no'] ?? '')]);
        if (!$order) {
            // 找不到订单也必须返回 success（川聊回调会重试；我们查不到说明是脏数据，不能让川聊重试造成压力）
            echo 'success';
            exit;
        }
        // 6. 校验付款人 uid：已绑定订单必须本人支付，防止 A 下单 B 支付
        $payUid = (int)($data['uid'] ?? 0);
        if ((int)$order['chuanliao_uid'] > 0) {
            if ($payUid !== (int)$order['chuanliao_uid']) {
                Log::error('wanyu_pay_callback_uid_mismatch', [
                    'order' => $order['out_order_no'],
                    'order_uid' => (int)$order['chuanliao_uid'],
                    'pay_uid' => $payUid,
                ]);
                http_response_code(400);
                echo 'fail';
                exit;
            }
        }
        // 7. 校验金额：必须与下单金额完全一致（字符串比较，避免浮点误差）
        if ((string)($data['amount'] ?? '') !== (string)$order['amount']) {
            Log::error('wanyu_pay_callback_amount_mismatch', [
                'order' => $order['out_order_no'],
                'order_amount' => (string)$order['amount'],
                'pay_amount' => (string)($data['amount'] ?? ''),
            ]);
            http_response_code(400);
            echo 'fail';
            exit;
        }
        // 8. 已支付过直接 success（幂等）
        if ((int)$order['status'] === 1) {
            echo 'success';
            exit;
        }
        // 9. 只有川聊确认 status=1 时才发货
        $cStatus = (int)($data['status'] ?? 0);
        if ($cStatus === 1 && (int)$order['status'] === 0) {
            Db::begin();
            try {
                // 首充绑定：attach 里带 wanyu_uid:xxx，把川聊 uid 写入绑定表
                $attach = (string)($data['attach'] ?? '');
                if (strpos($attach, 'wanyu_uid:') === 0) {
                    $wanyuUid = (int)substr($attach, strlen('wanyu_uid:'));
                    // 只在订单未记录付款人 uid 时绑定（首充场景）
                    if ($wanyuUid > 0 && $payUid > 0 && (int)$order['chuanliao_uid'] === 0) {
                        Db::exec(
                            'INSERT INTO wanyu_chuanliao_bind (user_id, chuanliao_uid) VALUES (?, ?) ' .
                            'ON DUPLICATE KEY UPDATE chuanliao_uid = VALUES(chuanliao_uid)',
                            [$wanyuUid, $payUid]
                        );
                        // 同步更新订单的 chuanliao_uid，便于后续对账
                        Db::exec('UPDATE wanyu_orders SET chuanliao_uid = ? WHERE id = ?', [$payUid, (int)$order['id']]);
                    }
                }
                $changed = Db::exec('UPDATE wanyu_orders SET status = 1, paid_at = NOW() WHERE id = ? AND status = 0', [(int)$order['id']]);
                if ($changed > 0) {
                    self::deliver((int)$order['user_id'], $order);
                }
                Db::commit();
            } catch (Throwable $e) {
                Db::rollback();
                Log::error('wanyu_pay_callback_deliver', ['order' => $order['out_order_no'], 'err' => $e->getMessage()]);
            }
        }
        // 10. 响应：必须纯文本 success，返回 JSON 或空都算失败
        // 注意：不能用 Resp::ok，那会输出 JSON！
        http_response_code(200);
        echo 'success';
        exit;
    }

    // ============ 发货：事务内原子发放积分 or 延长会员 ============
    private static function deliver(int $userId, array $order): void
    {
        $type = (string)$order['product_type'];
        $sku  = (string)$order['product_sku'];

        if ($type === 'points') {
            // 积分包：直接加 points 字段 + 写流水
            $product = self::lookupProduct('points', $sku);
            if (!$product) {
                Log::error('wanyu_pay_deliver_points_bad_sku', ['order' => $order['out_order_no'], 'sku' => $sku]);
                return;                                         // SKU 找不到时静默跳过（订单已标记 1，不再重试）
            }
            // 用 unique request_id 保证幂等（防回调 + 轮询同时命中）
            $requestId = 'pay:' . $order['out_order_no'];
            // 先查流水是否已存在
            $exists = Db::fetch('SELECT id FROM point_transactions WHERE request_id = ?', [$requestId]);
            if ($exists) return;                                // 已发放过，跳过
            Points::add($userId, $product['points'], 'pay_points', '支付购买 ' . $product['name'], ['request_id' => $requestId]);
            return;
        }

        if ($type === 'vip') {
            // 会员：续期规则 —— 未过期叠加，过期从当前起算；永久会员直接给 forever
            $product = self::lookupProduct('vip', $sku);
            if (!$product) {
                Log::error('wanyu_pay_deliver_vip_bad_sku', ['order' => $order['out_order_no'], 'sku' => $sku]);
                return;
            }
            $now = time();
            $row = Db::fetch('SELECT * FROM wanyu_vip WHERE user_id = ? FOR UPDATE', [$userId]);
            if (!$row) {
                // 首次开通
                if ($product['months'] >= 9999) {
                    // 永久会员：expires_at 存 NULL
                    Db::exec('INSERT INTO wanyu_vip (user_id, vip_type, expires_at) VALUES (?, ?, NULL)', [$userId, $product['type']]);
                } else {
                    Db::exec('INSERT INTO wanyu_vip (user_id, vip_type, expires_at) VALUES (?, ?, ?)',
                        [$userId, $product['type'], date('Y-m-d H:i:s', $now + $product['months'] * 86400 * 30)]);
                }
                return;
            }
            // 续费：expires_at = max(now, 当前到期) + 新增时长
            if ($product['months'] >= 9999) {
                // 升到永久
                Db::exec('UPDATE wanyu_vip SET vip_type = ?, expires_at = NULL WHERE user_id = ?', ['permanent', $userId]);
                return;
            }
            $curExpire = $row['expires_at'] ? strtotime($row['expires_at']) : $now;
            $base = max($now, $curExpire);
            $newExpire = date('Y-m-d H:i:s', $base + $product['months'] * 86400 * 30);
            // 如果当前已是永久会员，续费非永久套餐不降级（保持永久）
            if ($row['vip_type'] === 'permanent') return;
            Db::exec('UPDATE wanyu_vip SET vip_type = ?, expires_at = ? WHERE user_id = ?',
                [$product['type'], $newExpire, $userId]);
        }
    }

    /** 组装订单查询接口返回给客户端的数据 */
    private static function orderResponse(array $order, bool $polling = true, string $pollMsg = ''): array
    {
        return [
            'out_order_no'  => $order['out_order_no'],
            'status'        => (int)$order['status'],
            'product_type'  => $order['product_type'],
            'product_sku'   => $order['product_sku'],
            'product_name'  => $order['product_name'],
            'amount'        => (string)$order['amount'],
            'paid'          => (int)$order['status'] === 1,
            'paid_at'       => $order['paid_at'],
            'expire_at'     => $order['expire_at'],
            'polling'       => $polling,                             // true=仍在待支付，可继续轮询
            'poll_msg'      => $pollMsg,
        ];
    }

    // ============ 会员状态查询（给客户端「我的会员」展示用） ============
    public static function vipStatus(): void
    {
        $user = Auth::requireUser(true);
        $row = Db::fetch('SELECT * FROM wanyu_vip WHERE user_id = ?', [(int)$user['id']]);
        if (!$row) {
            Resp::ok(['is_vip' => false, 'type' => '', 'expires_at' => null, 'days_left' => 0]);
        }
        $isPermanent = $row['vip_type'] === 'permanent' || $row['expires_at'] === null;
        $now = time();
        $expire = $row['expires_at'] ? strtotime($row['expires_at']) : $now;
        $daysLeft = $isPermanent ? -1 : max(0, (int)ceil(($expire - $now) / 86400));
        $isVip = $isPermanent || $expire >= $now;
        // PHP 7.2 兼容：用 switch 替代 match 表达式（match 是 PHP 8.0 才引入的）
        $vipName = '会员';
        switch ($row['vip_type']) {
            case 'permanent': $vipName = '永久会员'; break;
            case 'year':      $vipName = '年度会员'; break;
            case 'quarter':   $vipName = '季度会员'; break;
            case 'month':     $vipName = '月度会员'; break;
        }
        Resp::ok([
            'is_vip'      => $isVip,
            'type'        => $row['vip_type'],
            'permanent'   => $isPermanent,
            'expires_at'  => $row['expires_at'],
            'days_left'   => $daysLeft,
            'vip_name'    => $vipName,
        ]);
    }
}
