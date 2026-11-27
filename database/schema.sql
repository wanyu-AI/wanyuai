-- ============================ 支付订单表 ============================
CREATE TABLE IF NOT EXISTS `wanyu_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,                -- 本地主键
  `user_id` BIGINT UNSIGNED NOT NULL,                          -- 本项目用户ID
  `chuanliao_uid` BIGINT NOT NULL DEFAULT 0,                   -- 付款用户的川聊UID；0=首次充值未绑定，支付成功后由回调写入
  `out_order_no` VARCHAR(64) NOT NULL,                         -- 我方订单号，应用内唯一，客户下单时按时间戳+随机生成
  `platform_order_no` VARCHAR(64) NOT NULL DEFAULT '',         -- 川聊平台订单号（支付成功后由create_order.php返回）
  `product_type` ENUM('points','vip') NOT NULL,                -- 商品类型：points=积分，vip=会员
  `product_sku` VARCHAR(32) NOT NULL DEFAULT '',               -- SKU：如 points_100 / vip_month / vip_year / vip_permanent
  `product_name` VARCHAR(128) NOT NULL DEFAULT '',              -- 商品展示名，用于 subject 字段
  `quantity` INT NOT NULL DEFAULT 1,                           -- 数量（积分包为点分数，会员为时长月数或永久=9999）
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,               -- 订单金额（元），由服务端按商品定价生成，客户端金额一律忽略
  `pay_type` ENUM('APP','H5') NOT NULL DEFAULT 'APP',          -- 唤起方式：APP 唤起川聊收银台 / H5 浏览器跳转
  `status` TINYINT NOT NULL DEFAULT 0,                         -- 支付状态：0待支付 1已支付 2已关闭 3全额退款 4部分退款 5支付失败
  `pay_token` VARCHAR(48) NOT NULL DEFAULT '',                 -- 川聊一次性支付token，5分钟有效
  `pay_url` VARCHAR(512) NOT NULL DEFAULT '',                  -- H5支付链接，APP不可用时降级用
  `notify_url` VARCHAR(512) NOT NULL DEFAULT '',                -- 川聊回调地址（当前为空，待部署回调接收端后填入）
  `return_url` VARCHAR(512) NOT NULL DEFAULT '',               -- 用户支付完成后浏览器跳转页
  `fail_url` VARCHAR(512) NOT NULL DEFAULT '',                 -- 用户支付失败后浏览器跳转页
  `attach` VARCHAR(255) NOT NULL DEFAULT '',                    -- 透传字段，回调时原样返回
  `paid_at` DATETIME DEFAULT NULL,                              -- 川聊确认支付时间
  `expire_at` DATETIME DEFAULT NULL,                            -- 支付token过期时间（5分钟后）
  `closed_at` DATETIME DEFAULT NULL,                            -- 关闭/退款/失败时间
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,     -- 订单创建时间
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- 更新时间
  `remark` VARCHAR(500) NOT NULL DEFAULT '',                   -- 备注（幂等标记/人工操作记录等）
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_out_order_no` (`out_order_no`),                -- 我方订单号唯一，防重复提交
  KEY `idx_user_created` (`user_id`,`created_at`),              -- 用户订单查询
  KEY `idx_platform_order_no` (`platform_order_no`),           -- 按平台订单号查（回调时幂等用）
  KEY `idx_status` (`status`),                                  -- 按状态筛选
  KEY `idx_expire` (`expire_at`)                                -- 清理过期待支付订单
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='挽屿AI支付订单表';

-- ============================ 用户会员表 ============================
CREATE TABLE IF NOT EXISTS `wanyu_vip` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,                          -- 本项目用户ID
  `vip_type` ENUM('month','year','permanent') NOT NULL,        -- 会员类型
  `expires_at` DATETIME DEFAULT NULL,                           -- 到期时间，永久会员为 NULL
  `benefit_json` TEXT DEFAULT NULL,                             -- 会员权益JSON（预留，未来可扩展）
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`)                           -- 每用户只有一条会员记录
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='挽屿AI会员表';

-- ============================ 用户川聊绑定表 ============================
CREATE TABLE IF NOT EXISTS `wanyu_chuanliao_bind` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `chuanliao_uid` BIGINT NOT NULL,
  `bound_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),                         -- 每用户绑定一个川聊UID
  UNIQUE KEY `uk_chuanliao_uid` (`chuanliao_uid`)               -- 一个川聊UID只能绑一个挽屿账号
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户川聊绑定表';
