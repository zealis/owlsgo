<?php
/**
 * 路由
 *
 * 支持两种访问形态，自动探测、无需改代码：
 *  - 伪静态（推荐）：/t/12、/f/3  —— 需要 Web 服务器开启 URL 重写
 *  - 兼容形态：/index.php?r=/t/12 —— 任何环境都能跑，例如 php -S 内置服务器
 *
 * URL 生成统一走 Router::url()，避免在模板里手写路径导致兼容形态失效。
 */

declare(strict_types=1);

namespace Core;

final class Router
{
    /**
     * 路由表
     *
     * @var list<array{method:string, pattern:string, regex:string, params:list<string>, handler:mixed, permission:string, csrf:bool}>
     */
    private static array $routes = [];

    /** 是否使用 ?r= 兼容形态（null 表示尚未探测） */
    private static ?bool $queryStyle = null;

    /** 应用基础路径（子目录部署时非空） */
    private static ?string $base = null;

    /* ------------------------------------------------------------------ */
    /*  注册                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 注册路由
     *
     * 路径中的 {name} 或 {name:正则} 会被解析为参数，例如：
     *   /t/{id:\d+}         => id 必须是数字
     *   /media/{path:.+}    => path 可包含斜杠
     *
     * @param string          $method     GET|POST|PUT|DELETE
     * @param string          $pattern    路由路径
     * @param callable|string $handler    "Class@method" 或可调用对象
     * @param string          $permission 需要的权限（空 = 不校验）
     * @param bool            $csrf       是否强制校验 CSRF（默认对写方法自动开启）
     */
    public static function add(
        string $method,
        string $pattern,
        callable|string $handler,
        string $permission = '',
        bool $csrf = true
    ): void {
        $method  = strtoupper($method);
        $pattern = '/' . trim($pattern, '/');
        if ($pattern === '/') {
            $pattern = '/';
        }

        $params = [];
        $regex  = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                $inner    = $m[2] ?? '[^/]+';

                return '(' . $inner . ')';
            },
            $pattern
        ) ?? $pattern;

        self::$routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            'regex'      => '#^' . $regex . '$#u',
            'params'     => $params,
            'handler'    => $handler,
            'permission' => $permission,
            'csrf'       => $csrf,
        ];
    }

    public static function get(string $pattern, callable|string $handler, string $permission = '', bool $csrf = true): void
    {
        self::add('GET', $pattern, $handler, $permission, $csrf);
    }

    public static function post(string $pattern, callable|string $handler, string $permission = '', bool $csrf = true): void
    {
        self::add('POST', $pattern, $handler, $permission, $csrf);
    }

    /**
     * 从配置文件加载路由表
     */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        /** @var mixed $routes */
        $routes = require $file;

        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $route) {
            if (!is_array($route) || count($route) < 3) {
                continue;
            }

            [$method, $pattern, $handler] = array_pad($route, 3, '');

            self::add(
                (string)$method,
                (string)$pattern,
                $handler,
                (string)($route[3] ?? ''),
                (bool)($route[4] ?? true)
            );
        }
    }

    /** 注册插件路由 */
    public static function loadPluginRoutes(): void
    {
        foreach (Plugin::routes() as $route) {
            self::add(
                $route['method'],
                $route['path'],
                $route['handler'],
                $route['permission'],
                true
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  匹配                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 匹配路由
     *
     * @return array{handler:mixed, params:array<string,string>, permission:string, csrf:bool, pattern:string}|null
     */
    public static function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path   = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            array_shift($matches);

            $params = [];
            foreach ($route['params'] as $index => $name) {
                $params[$name] = $matches[$index] ?? '';
            }

            return [
                'handler'    => $route['handler'],
                'params'     => $params,
                'permission' => $route['permission'],
                'csrf'       => $route['csrf'],
                'pattern'    => $route['pattern'],
            ];
        }

        return null;
    }

    /** 当前请求是否为写方法（需要 CSRF） */
    public static function isWriteMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** 已注册路由数量 */
    public static function count(): int
    {
        return count(self::$routes);
    }

    /* ------------------------------------------------------------------ */
    /*  URL 生成                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 生成站内 URL
     *
     * @param string               $path   形如 /t/12
     * @param array<string, mixed> $params 查询参数
     */
    public static function url(string $path = '/', array $params = []): string
    {
        $path = '/' . ltrim($path, '/');

        // 已经是完整地址则原样返回
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $base = self::base();
        $url  = $base;

        if (self::queryStyle()) {
            $url .= '/index.php?r=' . rawurlencode(ltrim($path, '/'));
            // 查询串以 & 追加
            if ($params !== []) {
                $url .= '&' . http_build_query($params);
            }
        } else {
            $url .= $path === '/' ? '/' : $path;
            if ($params !== []) {
                $url .= '?' . http_build_query($params);
            }
        }

        return $url;
    }

    /**
     * 应用基础路径
     *
     * 部署在子目录（如 https://example.com/forum/）时返回 /forum，根目录部署返回空串。
     *
     * 解析顺序：
     *   1. config('app.base_path') 显式配置（非 null 时直接采用）
     *   2. SCRIPT_NAME 指向入口文件时为常态（伪静态、子目录部署），取其所在目录
     *   3. 回落：用「入口所在目录」相对 DOCUMENT_ROOT 推断
     *      （PHP 内置服务器的 router 脚本、部分 FastCGI 组合下 SCRIPT_NAME 是请求路径
     *        而非入口路径，此时若继续用 SCRIPT_NAME 会得到 /t 之类的错误前缀）
     */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        // 1) 显式配置优先
        $configured = config('app.base_path');

        if (is_string($configured)) {
            self::$base = rtrim(str_replace('\\', '/', $configured), '/');

            return self::$base;
        }

        // 2) 常规探测
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($script !== '' && basename($script) === 'index.php') {
            // 注意：Windows 下 dirname('/index.php') 返回的是 "\"，必须先归一化分隔符，
            // 否则基础路径会变成反斜杠，生成出 "\/install" 这类非法 URL。
            $dir = str_replace('\\', '/', dirname($script));

            self::$base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');

            return self::$base;
        }

        // 3) 回落探测
        $docRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
        $entry   = str_replace('\\', '/', APP_ROOT . '/public');

        // 必须严格处在站点根目录之内（末尾补 / 判断，避免 /var/www/html2 被 /var/www/html 误匹配）
        if ($docRoot !== '' && ($entry === $docRoot || str_starts_with($entry, $docRoot . '/'))) {
            self::$base = rtrim(substr($entry, strlen($docRoot)), '/');
        } else {
            self::$base = '';
        }

        return self::$base;
    }

    /**
     * 是否使用 ?r= 兼容形态
     */
    public static function queryStyle(): bool
    {
        if (self::$queryStyle !== null) {
            return self::$queryStyle;
        }

        $configured = config('app.pretty_url');

        if ($configured === true) {
            self::$queryStyle = false;
        } elseif ($configured === false) {
            self::$queryStyle = true;
        } else {
            // 自动探测：本次请求是通过 ?r= 进来的，说明没有 URL 重写
            self::$queryStyle = isset($_GET['r']);
        }

        return self::$queryStyle;
    }

    /** 强制指定 URL 形态（测试用） */
    public static function setQueryStyle(?bool $style): void
    {
        self::$queryStyle = $style;
    }

    /** 清空路由表（测试用） */
    public static function reset(): void
    {
        self::$routes = [];
        self::$base   = null;
    }
}
