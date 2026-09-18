<?php
/**
 * HTTP 异常
 *
 * 用于在任意深度中断请求并返回指定状态码，例如：
 *   throw new HttpException(403, '你没有权限删除该主题。');
 * 由 App 统一捕获并渲染错误页，绝不会把堆栈暴露给浏览器。
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    private int $statusCode;

    /** @var array<string, string> 附加响应头 */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $statusCode, string $message = '', array $headers = [])
    {
        parent::__construct($message);

        $this->statusCode = $statusCode;
        $this->headers    = $headers;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** 状态码对应的默认文案 */
    public static function defaultMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => '请求参数不正确。',
            401 => '请先登录后再继续操作。',
            403 => '你没有权限访问该页面。',
            404 => '你访问的页面不存在或已被删除。',
            405 => '请求方式不被允许。',
            419 => '页面已过期，请刷新后重试。',
            429 => '操作过于频繁，请稍后再试。',
            500 => '服务器内部错误，请稍后重试。',
            503 => '站点暂时不可用，请稍后重试。',
            default => '请求处理失败。',
        };
    }
}
