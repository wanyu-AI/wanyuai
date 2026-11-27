<?php
// 后台辅助函数：转义、跳转、闪存提示、分页
function h($value): string                              // HTML转义
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); // 返回安全字符串
}

function admin_redirect(string $page, array $query = []): void // 页面跳转
{
    $url = 'index.php?page=' . urlencode($page);        // 基础地址
    if ($query) {                                       // 追加参数
        $url .= '&' . http_build_query($query);         // 拼接
    }
    header('Location: ' . $url);                        // 跳转
    exit;                                               // 结束
}

function flash(string $type, string $message): void      // 写入一次性提示
{
    AdminAuth::startSession();                          // 启动会话
    $_SESSION['flash'] = ['type' => $type, 'message' => $message]; // 保存
}

function get_flash(): ?array                            // 读取并清除提示
{
    AdminAuth::startSession();                          // 启动会话
    $flash = $_SESSION['flash'] ?? null;                // 读取
    unset($_SESSION['flash']);                          // 清除
    return $flash;                                      // 返回
}

function admin_post_action(): string                    // 当前POST动作
{
    return isset($_POST['action']) ? (string)$_POST['action'] : ''; // 返回动作名
}

function verify_post_csrf(): void                       // 校验表单CSRF
{
    AdminAuth::verifyCsrf($_POST['csrf'] ?? null);      // 调用鉴权类
}

function render_pagination(int $page, int $total, int $size, array $baseQuery): string // 渲染分页
{
    $pages = max(1, (int)ceil($total / $size));          // 总页数
    if ($pages <= 1) {                                  // 只有一页不显示
        return '';                                      // 返回空
    }
    $html = '<div class="pagination">';                 // 容器
    for ($i = 1; $i <= $pages; $i++) {                  // 逐页
        if ($i > 1 && $i < $page - 2) {                 // 省略前部
            $html .= '<span class="page-gap">...</span>'; // 省略号
            $i = $page - 2;                             // 跳到附近
            continue;                                   // 继续
        }
        if ($i < $pages && $i > $page + 2) {            // 省略后部
            $html .= '<span class="page-gap">...</span>'; // 省略号
            break;                                      // 结束
        }
        $query = array_merge($baseQuery, ['p' => $i]);  // 页码参数
        $url = 'index.php?' . http_build_query($query); // 地址
        $class = $i === $page ? 'page active' : 'page'; // 当前页高亮
        $html .= '<a class="' . $class . '" href="' . h($url) . '">' . $i . '</a>'; // 页码
    }
    $html .= '</div>';                                  // 结束
    return $html;                                       // 返回
}

function format_time(?string $value): string            // 格式化时间
{
    return $value ? date('Y-m-d H:i', strtotime($value)) : '-'; // 空值显示-
}
