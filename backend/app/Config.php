<?php
// 配置读取类：区分「文件配置」与「数据库系统配置」
class Config
{
    private static $fileConfig = [];                    // 文件配置缓存（PHP 7.2 兼容）
    private static $dbCache = [];                       // 数据库配置缓存（单次请求内）

    public static function init(array $config): void    // 初始化文件配置
    {
        self::$fileConfig = $config;                    // 保存配置数组
    }

    public static function get(string $key, $default = null) // 读取文件配置
    {
        return self::$fileConfig[$key] ?? $default;     // 不存在时返回默认值
    }

    public static function set(string $key, $value): void // 运行期覆盖文件配置
    {
        self::$fileConfig[$key] = $value;               // 写入数组
    }

    public static function db(string $key, string $default = ''): string // 读取 system_configs
    {
        if (array_key_exists($key, self::$dbCache)) {   // 命中请求内缓存
            return self::$dbCache[$key];                // 直接返回
        }
        $row = Db::fetch('SELECT config_value FROM system_configs WHERE config_key = ?', [$key]); // 查询配置
        $value = $row ? (string)$row['config_value'] : $default; // 不存在时用默认值
        self::$dbCache[$key] = $value;                  // 写入缓存
        return $value;                                  // 返回配置值
    }

    public static function setDb(string $key, string $value): void // 写入 system_configs
    {
        Db::exec('INSERT INTO system_configs (config_key, config_value) VALUES (?, ?) ' . // 插入或更新
                 'ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)', [$key, $value]);
        self::$dbCache[$key] = $value;                  // 同步刷新缓存
    }

    public static function int(string $key, int $default = 0): int // 读取整数型数据库配置
    {
        $v = self::db($key);                            // 读取字符串
        return $v === '' ? $default : (int)$v;          // 空串时用默认值
    }

    public static function forget(): void               // 清空数据库配置缓存
    {
        self::$dbCache = [];                            // 重置数组
    }
}
