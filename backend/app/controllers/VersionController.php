<?php
// 版本控制器：App启动时检查更新
class VersionController
{
    public static function check(): void                // 检查是否有新版本
    {
        $current = Req::int('version_code', 0);         // 当前本地版本号
        if ($current <= 0) {                            // 参数校验
            throw new ApiException(40040, 'version_code无效'); // 拒绝
        }
        $row = Db::fetch('SELECT * FROM app_versions WHERE is_enabled = 1 AND version_code > ? ' . // 查询更高版本
            'ORDER BY version_code DESC LIMIT 1', [$current]);
        if (!$row) {                                    // 没有更新
            Resp::ok(['has_update' => false]);          // 返回无更新
        }
        Resp::ok([
            'has_update'   => true,                     // 有更新
            'version_code' => (int)$row['version_code'], // 最新版本号
            'version_name' => $row['version_name'],     // 版本名
            'changelog'    => $row['changelog'],        // 更新说明
            'download_url' => $row['download_url'],     // 下载地址
            'is_force'     => (int)$row['is_force'] === 1, // 是否强制
            'published_at' => date('c', strtotime((string)$row['created_at'])), // ISO时间，供客户端展示
        ]);                                             // 返回更新信息
    }

    /**
     * 原版 RikkaHub 更新方式：GET 一个 JSON，客户端自己比较版本号。
     * 返回结构对应客户端 UpdateInfo（camelCase），无需签名与登录。
     */
    public static function latest(): void
    {
        $row = Db::fetch('SELECT * FROM app_versions WHERE is_enabled = 1 ' . // 查询最新启用版本
            'ORDER BY version_code DESC LIMIT 1');
        $data = [
            'version'     => '0.0.0',                   // 无版本时给最低版本，客户端不会提示更新
            'publishedAt' => date('c'),                 // 当前时间
            'changelog'   => '',                        // 空更新说明
            'downloads'   => [],                        // 空下载列表
            'isForce'     => false,                     // 非强制
        ];
        if ($row) {                                     // 有启用版本
            $name = (string)$row['version_name'];       // 版本名
            $data = [
                'version'     => $name,                 // 客户端用于比较的版本号
                'publishedAt' => date('c', strtotime((string)$row['created_at'])), // 发布时间
                'changelog'   => (string)$row['changelog'], // 更新说明
                'downloads'   => [[                     // 下载项
                    'name' => 'wanyu-' . $name . '.apk', // 文件名
                    'url'  => (string)$row['download_url'], // APK 地址
                    'size' => '',                       // 大小可留空，客户端只展示
                ]],
                'isForce'     => (int)$row['is_force'] === 1, // 是否强制更新
            ];
        }
        header('Content-Type: application/json; charset=utf-8'); // 纯 JSON
        header('Cache-Control: no-store');              // 禁止缓存，保证及时拿到新版本
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // 直接输出，不套 code/message
        exit;                                           // 结束
    }
}
