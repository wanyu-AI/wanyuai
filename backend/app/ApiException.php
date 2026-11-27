<?php
// 业务异常类：控制器抛出后由 bootstrap 统一捕获并输出 JSON
class ApiException extends Exception
{
    public $httpStatus;                                 // HTTP 状态码（PHP 7.2 兼容）
    public $data;                                       // 附加数据（风控状态等）

    public function __construct(int $code, string $message, int $httpStatus = 400, array $data = []) // 构造函数
    {
        parent::__construct($message, $code);          // 调用父类构造
        $this->httpStatus = $httpStatus;               // 保存HTTP状态码
        $this->data = $data;                           // 保存附加数据
    }
}
