<?php
// 退出登录
require_once dirname(__DIR__) . '/app/bootstrap.php';  // 加载后台启动
AdminAuth::logout();                                    // 销毁会话
header('Location: login.php');                          // 跳转登录页
exit;                                                   // 结束
