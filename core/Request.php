<?php
/**
 * HTTP 请求封装
 *
 * 统一读取与净化输入：所有外部输入都必须经过这里，不允许控制器直接触碰
 * $_GET / $_POST / $_COOKIE，从而保证「输入必过滤」这条规则可被强制审查。
 */

declare(strict_types=1);

namespace Core;

final class Request
{
    /**
     * 读取查询参数
     */
    public static function query(string $key, mixed $default = null): mixed
    {
        return self::clean($_GET[$key] ?? $default, $default);
    }

    /**
     * 读取表单字段
     */
    public static function post(string $key, mixed $default = null): mixed
    {
        return self::clean($_POST[$key] ?? $default, $default);
    }

    /**
     * 读取参数（POST 优先，其次 GET）
     */
    public static function input(string $key, mixed $default = null): mixed
    {
        return self::clean($_POST[$key] ?? $_GET[$key] ?? $default, $default);
    }

    /** 全部 POST 字段（已净化） */
    public static function allPost(): array
    {
        $result = [];
        foreach ($_POST as $key => $value) {
            $result[(string)$key] = self::clean($value, '');
        }

        return $result;
    }

    /** 全部 GET 字段（已净化） */
    public static function allQuery(): array
    {
        $result = [];
        foreach ($_GET as $key => $value) {
            $result[(string)$key] = self::clean($value, '');
        }

        return $result;
    }

    /** 读取 Cookie */
    public static function cookie(string $key, mixed $default = null): ?string
    {
        $value = $_COOKIE[$key] ?? null;

        return is_string($value) ? $value : ($default === null ? null : (string)$default);
    }

    /**
     * 读取整型参数
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;

        if (is_array($value) || $value === null || $value === '') {
            return $default;
        }

        // 只接受纯数字（含负号），拒绝 "1abc" 之类的松散解析
        if (!preg_match('/^-?\d+$/', (string)$value)) {
            return $default;
        }

        return (int)$value;
    }

    /** 读取布尔参数（"1"/"on"/"true" 视为真） */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return $default;
        }

        return in_array(strtolower((string)$value), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * 读取字符串参数，并限制长度
     */
    public static function string(string $key, string $default = '', int $maxLength = 0): string
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;

        if (is_array($value) || $value === null) {
            return $default;
        }

        $value = trim((string)$value);

        // 去除不可见控制字符（保留换行、制表）
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    /**
     * 读取数组型参数（如多选框），并对每个元素做净化
     *
     * @return list<mixed>
     */
    public static function array(string $key, array $default = []): array
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;

        if (!is_array($value)) {
            return $default;
        }

        $result = [];
        foreach ($value as $item) {
            $result[] = self::clean($item, '');
        }

        return $result;
    }

    /**
     * 读取整型数组
     *
     * @return list<int>
     */
    public static function intArray(string $key): array
    {
        $result = [];
        foreach (self::array($key) as $item) {
            if (is_scalar($item) && preg_match('/^\d+$/', (string)$item)) {
                $result[] = (int)$item;
            }
        }

        return array_values(array_unique($result));
    }

    /** 请求方法（大写） */
    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isGet(): bool
    {
        return self::method() === 'GET';
    }

    /** 是否为 AJAX 请求 */
    public static function isAjax(): bool
    {
        $requestedWith = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $accept        = (string)($_SERVER['HTTP_ACCEPT'] ?? '');

        return strtolower($requestedWith) === 'xmlhttprequest'
            || str_contains($accept, 'application/json');
    }

    /** 期望的响应类型：json 或 html */
    public static function wantsJson(): bool
    {
        return self::isAjax() || strtolower((string)($_SERVER['HTTP_X_OWLSGO_RESPONSE'] ?? '')) === 'json';
    }

    /** 客户端 IP（仅信任 REMOTE_ADDR，避免 X-Forwarded-For 伪造） */
    public static function ip(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /** User-Agent（截断后入库） */
    public static function userAgent(int $max = 255): string
    {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $ua) ?? $ua, 0, $max);
    }

    /**
     * User-Agent 指纹（16 位十六进制）
     *
     * 用于把「会话 / 记住我」凭据绑定到签发时的浏览器环境：
     * Cookie 即使被从本机（浏览器存储文件、导出工具）窃走，
     * 换一个 UA 重放也过不了校验 —— 签名里根本没有这个指纹。
     * UA 本身可伪造，所以它是纵深防御的一层，不是唯一防线。
     */
    public static function userAgentHash(): string
    {
        return substr(hash('sha256', self::userAgent()), 0, 16);
    }

    /** 简化的设备标识：mobile / tablet / desktop */
    public static function device(): string
    {
        $ua = strtolower(self::userAgent(512));

        if (preg_match('/ipad|tablet|playbook|silk/', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone/', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    /** 当前请求的路径（不含查询字符串） */
    public static function path(): string
    {
        // 1) 伪静态重写：PATH_INFO / REQUEST_URI
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }

        $base = Router::base();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        $uri = '/' . ltrim(rawurldecode($uri), '/');

        // 去掉入口文件名，例如 /index.php
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, strlen('/index.php'));
            $uri = $uri === '' ? '/' : $uri;
        }

        // 2) 无重写环境下使用 index.php?r=/path
        $r = $_GET['r'] ?? null;
        if (is_string($r) && $r !== '') {
            $uri = '/' . ltrim(rawurldecode($r), '/');
        }

        // 消除重复斜杠与结尾斜杠（根路径除外）
        $uri = preg_replace('#/+#', '/', $uri) ?? '/';
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        return $uri === '' ? '/' : $uri;
    }

    /** 当前完整 URL（用于登录后跳回） */
    public static function currentUrl(): string
    {
        return self::path() . (($_SERVER['QUERY_STRING'] ?? '') !== '' && !isset($_GET['r'])
            ? '?' . (string)$_SERVER['QUERY_STRING']
            : '');
    }

    /** 来源页 URL（仅接受站内地址） */
    public static function referer(): string
    {
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer === '') {
            return '';
        }

        $parts = parse_url($referer);
        if ($parts === false) {
            return '';
        }

        $host = $parts['host'] ?? '';
        $self = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '' && $host !== $self) {
            return '';
        }

        return (string)($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** 是否为 HTTPS */
    public static function isSecure(): bool
    {
        $https = (string)($_SERVER['HTTPS'] ?? '');

        return $https !== '' && strtolower($https) !== 'off';
    }

    /**
     * 递归净化输入值
     */
    private static function clean(mixed $value, mixed $default): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::clean($item, '');
            }

            return $result;
        }

        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            // 去除 NULL 字节与控制字符，避免截断攻击与日志污染
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

            return trim($value);
        }

        return $value;
    }
}
