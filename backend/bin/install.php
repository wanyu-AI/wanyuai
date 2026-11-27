<?php
// 安装脚本：导入数据库结构、创建管理员、生成签名密钥
// 使用：php backend/bin/install.php [管理员用户名] [管理员密码]
declare(strict_types=1);                               // 严格类型
if (PHP_SAPI !== 'cli') {                              // 仅允许命令行
    exit('请在命令行执行：php backend/bin/install.php'); // 拒绝浏览器访问
}
require_once dirname(__DIR__) . '/app/bootstrap.php';  // 加载启动文件

echo "== 挽屿AI 安装程序 ==\n";                         // 提示
$schemaFile = dirname(__DIR__, 2) . '/database/schema.sql'; // SQL文件路径
if (!is_file($schemaFile)) {                           // 文件不存在
    exit("找不到 database/schema.sql\n");              // 退出
}
$pdo = Db::pdo();                                      // 建立连接
$sql = file_get_contents($schemaFile);                 // 读取SQL
if ($sql === false) {                                  // 读取失败
    exit("读取 schema.sql 失败\n");                     // 退出
}
try {
    $pdo->exec($sql);                                  // 执行建表脚本（PDO允许一次执行多条）
    echo "[OK] 数据库表结构已导入\n";                    // 提示
} catch (Throwable $e) {                               // 失败
    exit('[FAIL] 导入失败：' . $e->getMessage() . "\n"); // 输出原因
}

$username = $argv[1] ?? 'admin';                       // 管理员用户名
$password = $argv[2] ?? '';                            // 管理员密码
if ($password === '') {                                // 未提供密码
    echo '请输入管理员密码：';                          // 提示
    $password = trim((string)fgets(STDIN));            // 从标准输入读取
}
if (strlen($password) < 8) {                           // 密码强度
    exit("密码至少8位\n");                              // 退出
}
$hash = password_hash($password, PASSWORD_DEFAULT);    // 生成密码哈希
Db::exec('INSERT INTO admin_users (username, password_hash, role, status) VALUES (?, ?, "admin", 1) ' . // 创建管理员
    'ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = "admin", status = 1', [$username, $hash]);
echo "[OK] 管理员账号：{$username}\n";                   // 提示

foreach (['api_sign_key', 'admin_sign_key'] as $key) { // 检查两个签名密钥
    $value = Config::db($key);                          // 读取当前值
    if ($value === '' || strpos($value, 'CHANGE_ME') !== false) { // 未配置
        $newValue = bin2hex(random_bytes(32));          // 生成64位随机密钥
        Config::setDb($key, $newValue);                 // 写入数据库
        echo "[OK] {$key} = {$newValue}\n";              // 输出，供安卓配置使用
    }
}
echo "[OK] 安装完成，请删除或保护本脚本\n";               // 结束提示
