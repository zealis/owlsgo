<?php
/**
 * HTTP 响应
 *
 * 统一收口所有对外输出：状态码、响应头、Cookie、JSON、跳转、错误页。
 * 集中管理的好处是安全策略（CSP、X-Frame-Options、Cookie 属性）只需在一处维护。
 *
 * 注意：$status / $headers / $cookies 为静态属性，除真正发送响应外，
 * 也作为运行期可读的响应快照，便于自动化测试断言。
 */

declare(strict_types=1);

namespace Core;

final class Response
{
    /** 当前响应的 HTTP 状态码 */
    public static int $status = 200;

    /** @var array<string, string> 已登记的响应头 */
    public static array $headers = [];

    /** @var list<array{name:string,value:string,expires:int,options:array<string,mixed>,delete:bool}> 已登记的 Cookie */
    public static array $cookies = [];

    private static bool $sent = false;

    /** 设置状态码 */
    public static function setStatus(int $status): void
    {
        if ($status >= 100 && $status <= 599) {
            self::$status = $status;
        }
    }

    /** 设置响应头 */
    public static function header(string $name, string $value): void
    {
        self::$headers[self::normalizeHeaderName($name)] = $value;
    }

    /**
     * 写入 Cookie
     *
     * @param array<string, mixed> $options 支持 path / domain / secure / httponly / samesite
     */
    public static function cookie(
        string $name,
        string $value,
        int $expires = 0,
        array $options = []
    ): void {
        $options = array_merge([
            'path'     => '/',
            'domain'   => '',
            'secure'   => Security::cookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ], $options);

        // 记住「删除」语义：空值 + 过去时间
        $delete = $value === '' || $expires < 0;
        if ($delete) {
            $expires = time() - 86400;
        }

        self::$cookies[] = [
            'name'    => $name,
            'value'   => $value,
            'expires' => $expires,
            'options' => $options,
            'delete'  => $delete,
        ];
    }

    /** 删除 Cookie */
    public static function forgetCookie(string $name, array $options = []): void
    {
        self::cookie($name, '', time() - 86400, $options);
    }

    /**
     * 发送 JSON 响应并终止请求
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $code = 200): never
    {
        self::setStatus($code);
        self::header('Content-Type', 'application/json; charset=UTF-8');
        self::header('X-Content-Type-Options', 'nosniff');

        self::sendHeaders();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /** 发送 HTML 响应并终止请求 */
    public static function html(string $content, int $code = 200): never
    {
        self::setStatus($code);
        self::header('Content-Type', 'text/html; charset=UTF-8');

        self::sendHeaders();
        echo $content;

        exit;
    }

    /** 直接输出（不额外设置 Content-Type），用于下载等场景 */
    public static function raw(string $content, int $code = 200): never
    {
        self::setStatus($code);
        self::sendHeaders();
        echo $content;

        exit;
    }

    /** 302/301 跳转并终止请求 */
    public static function redirect(string $url, int $code = 302): never
    {
        // 只允许站内相对地址或本站绝对地址，避免开放重定向
        $url = Security::safeRedirect($url);

        self::setStatus($code);
        self::header('Location', $url);

        self::sendHeaders();
        echo '<!doctype html><meta charset="utf-8"><title>跳转中</title>'
            . '<p>正在跳转，若浏览器没有自动跳转，请<a href="' . e($url) . '">点击这里</a>。</p>';

        exit;
    }

    /** 发送已登记的响应头与 Cookie（幂等） */
    public static function sendHeaders(): void
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;

        // 全局安全响应头
        self::applySecurityHeaders();

        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        http_response_code(self::$status);

        foreach (self::$headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        foreach (self::$cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], [
                'expires'  => $cookie['expires'],
                'path'     => (string)$cookie['options']['path'],
                'domain'   => (string)$cookie['options']['domain'],
                'secure'   => (bool)$cookie['options']['secure'],
                'httponly' => (bool)$cookie['options']['httponly'],
                'samesite' => (string)$cookie['options']['samesite'],
            ]);
        }
    }

    /** 全站安全响应头 */
    private static function applySecurityHeaders(): void
    {
        $defaults = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'X-XSS-Protection'        => '0',
            'Permissions-Policy'      => 'geolocation=(), microphone=(), camera=()',
            // 零外部依赖：脚本与样式仅允许本站（OATUI 为本地文件）
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; "
                . "style-src 'self' 'unsafe-inline'; script-src 'self'; "
                . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
        ];

        foreach ($defaults as $name => $value) {
            if (!isset(self::$headers[$name])) {
                self::$headers[$name] = $value;
            }
        }
    }

    /** 规范化响应头名称（防响应头注入） */
    private static function normalizeHeaderName(string $name): string
    {
        $name = str_replace(["\r", "\n", ':'], '', $name);

        return trim($name);
    }

    /** 清空响应状态（测试用） */
    public static function reset(): void
    {
        self::$status  = 200;
        self::$headers = [];
        self::$cookies = [];
        self::$sent    = false;
    }
}
