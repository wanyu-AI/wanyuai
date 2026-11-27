<?php
// 数据库访问类：PDO 单例 + 常用查询封装
class Db
{
    private static $pdo = null;                         // PDO 单例（PHP 7.2 不支持属性类型声明）

    public static function pdo(): PDO                   // 获取连接
    {
        if (self::$pdo instanceof PDO) {                // 已连接则复用
            return self::$pdo;                          // 返回连接
        }
        $cfg = Config::get('db');                       // 读取数据库配置
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', // 组装DSN
            $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']);
        self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [ // 创建连接
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,      // 异常模式
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,            // 默认关联数组
            PDO::ATTR_EMULATE_PREPARES   => false,                       // 使用真实预处理，防注入
            PDO::ATTR_STRINGIFY_FETCHES  => false,                       // 保持原始类型
        ]);
        return self::$pdo;                              // 返回连接
    }

    public static function q(string $sql, array $params = []): PDOStatement // 执行预处理语句
    {
        $stmt = self::pdo()->prepare($sql);             // 预处理
        $stmt->execute($params);                        // 绑定并执行
        return $stmt;                                   // 返回语句对象
    }

    public static function fetch(string $sql, array $params = []): ?array // 取一行
    {
        $row = self::q($sql, $params)->fetch();         // 取关联数组
        return $row === false ? null : $row;            // 无结果返回null
    }

    public static function fetchAll(string $sql, array $params = []): array // 取多行
    {
        return self::q($sql, $params)->fetchAll();      // 返回数组
    }

    public static function exec(string $sql, array $params = []): int // 执行写操作
    {
        return self::q($sql, $params)->rowCount();      // 返回受影响行数
    }

    public static function insert(string $sql, array $params = []): int // 插入并返回主键
    {
        self::q($sql, $params);                         // 执行插入
        return (int)self::pdo()->lastInsertId();        // 返回自增ID
    }

    public static function begin(): void                // 开启事务
    {
        if (!self::pdo()->inTransaction()) {            // 未在事务中才开启
            self::pdo()->beginTransaction();            // 开启事务
        }
    }

    public static function commit(): void               // 提交事务
    {
        if (self::pdo()->inTransaction()) {             // 存在事务才提交
            self::pdo()->commit();                      // 提交
        }
    }

    public static function rollback(): void             // 回滚事务
    {
        if (self::pdo()->inTransaction()) {             // 存在事务才回滚
            self::pdo()->rollBack();                    // 回滚
        }
    }

    public static function scalar(string $sql, array $params = []) // 取单个值
    {
        $stmt = self::q($sql, $params);                 // 执行查询
        return $stmt->fetchColumn();                    // 返回第一列
    }
}
