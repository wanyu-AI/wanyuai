<?php
// 设置同步控制器：模型配置、供应商等客户端设置跨设备备份与恢复
class SettingsController
{
    /** 单个用户设置备份的最大字节数（2MB），避免大字段拖垮数据库 */
    private const MAX_BYTES = 2097152;

    public static function save(): void                  // 上传本地设置到云端
    {
        $user = Auth::requireUser(true);                 // 必须登录且设备可信
        $raw = Req::input('settings', null);             // 读取原始设置
        if (!is_string($raw) || $raw === '') {           // 必须是字符串（客户端传序列化后的JSON文本）
            throw new ApiException(40030, '设置内容不能为空'); // 拒绝空内容
        }
        if (strlen($raw) > self::MAX_BYTES) {            // 限制体积
            throw new ApiException(40031, '设置内容超过2MB上限'); // 拒绝过大内容
        }
        json_decode($raw, true);                         // 校验JSON格式
        if (json_last_error() !== JSON_ERROR_NONE) {     // 解析失败
            throw new ApiException(40032, '设置内容不是合法JSON'); // 拒绝
        }
        Db::exec('UPDATE users SET settings_json = ? WHERE id = ?', // 写入备份
            [$raw, (int)$user['id']]);
        Resp::ok(['saved_at' => date('c')], '设置已同步'); // 返回保存时间
    }

    public static function load(): void                  // 拉取云端设置
    {
        $user = Auth::requireUser(true, false);          // 读取备份不受风控拦截
        $row = Db::fetch('SELECT settings_json FROM users WHERE id = ?', [(int)$user['id']]); // 查询
        $settings = $row['settings_json'] ?? '';         // 取出备份
        Resp::ok([
            'settings' => is_string($settings) ? $settings : '', // 原样返回JSON文本
            'has_data' => is_string($settings) && $settings !== '', // 是否已有备份
        ]);
    }
}
