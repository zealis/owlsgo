<?php
/**
 * 核心引擎
 *
 * 职责：
 *  - 注册错误与异常处理器（生产环境只落日志，绝不回显堆栈）
 *  - 启动会话、装载插件、装载路由
 *  - 统一分发请求：CSRF 校验 → 站点开关 → 权限校验 → 调用控制器
 *  - 统一渲染错误页（404 / 403 / 419 / 500 …）
 *
 * 这是全站唯一的路由入口，控制器不允许自行决定路由。
 */

declare(strict_types=1);

namespace Core;

final class App
{
    /** 是否已完成初始化 */
    private static bool $booted = false;

    /* ------------------------------------------------------------------ */
    /*  启动                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 运行应用
     */
    public static function run(): void
    {
        self::registerHandlers();

        try {
            self::boot();

            $path   = Request::path();
            $method = Request::method();

            // 未安装：把用户引到安装向导
            if (!self::isInstalled()) {
                if (!str_starts_with($path, '/install')) {
                    Response::redirect(Router::url('/install'));
                }

                self::dispatch($method, $path);

                return;
            }

            self::dispatch($method, $path);
        } catch (HttpException $e) {
            self::renderHttpException($e);
        } catch (\Throwable $e) {
            Logger::exception($e, 'app');
            self::renderServerError($e);
        }
    }

    /**
     * 完成一次请求所需的初始化
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        Session::start();

        // 站点设置、版块、用户组的懒加载缓存
        Settings::all();

        // 插件：同步元数据 → 加载已启用插件 → 收集插件路由
        try {
            PluginManager::syncDatabase();
            PluginManager::loadEnabled();
            PluginManager::mergeAssets();
        } catch (\Throwable $e) {
            Logger::exception($e, 'plugin-boot');
        }

        Router::loadPluginRoutes();

        Hook::action('app_boot', ['app' => self::class]);
    }

    /**
     * 分发请求
     */
    private static function dispatch(string $method, string $path): void
    {
        $route = Router::match($method, $path);

        if ($route === null) {
            throw new HttpException(404, '你访问的页面不存在：' . e($path));
        }

        // 1) 站点维护开关（管理员与安装向导不受影响）
        self::guardSiteClosed($path);

        // 2) 访客浏览开关（关闭后未登录用户只能看到登录页）
        self::guardGuestView($path);

        // 3) CSRF：所有写方法强制校验令牌
        if (Router::isWriteMethod($method) && $route['csrf']) {
            Security::guardCsrf();
        }

        // 4) 权限
        if ($route['permission'] !== '') {
            Auth::requirePermission($route['permission']);
        }

        Hook::action('app_before_dispatch', ['path' => $path, 'route' => $route['pattern']]);

        $result = self::invoke($route['handler'], $route['params']);

        Hook::action('app_after_dispatch', ['path' => $path, 'route' => $route['pattern']]);

        // 控制器直接返回字符串时视为 HTML 输出
        if (is_string($result) && $result !== '') {
            Response::html($result);
        }
    }

    /**
     * 调用控制器方法
     *
     * 支持三种处理器写法：
     *  - "Namespace\Class@method"  标准控制器
     *  - 闭包                       插件或内部简单路由
     *  - [object, 'method']         已实例化的对象
     *
     * @param array<string, string> $params
     */
    public static function invoke(mixed $handler, array $params = []): mixed
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            if (!class_exists($class)) {
                throw new HttpException(500, '控制器不存在：' . $class);
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new HttpException(500, '控制器方法不存在：' . $class . '::' . $method);
            }

            return $controller->{$method}($params);
        }

        if (is_callable($handler)) {
            return $handler($params);
        }

        throw new HttpException(500, '无法识别的路由处理器。');
    }

    /**
     * 站点维护模式拦截
     */
    private static function guardSiteClosed(string $path): void
    {
        if (!Settings::bool('site_closed', false)) {
            return;
        }

        // 后台、登录、安装与媒体资源在维护期间仍可访问
        $allowed = ['/login', '/logout', '/install'];
        if (str_starts_with($path, '/admin') || in_array($path, $allowed, true)
            || str_starts_with($path, '/media') || str_starts_with($path, '/avatar')
            || str_starts_with($path, '/assets')) {
            return;
        }

        if (Auth::can('admin.access')) {
            return;
        }

        throw new HttpException(503, (string)Settings::get('site_closed_reason', '站点正在维护，请稍后再访问。'));
    }

    /**
     * 访客浏览开关（后台「注册与登录 → 允许访客浏览」）
     *
     * 关闭后未登录用户只能看到登录/注册页，其它页面一律引导去登录 ——
     * 站点内容不再对匿名访客与搜索引擎开放。
     */
    private static function guardGuestView(string $path): void
    {
        if (Settings::bool('guest_view', true) || Auth::check()) {
            return;
        }

        /*
         * 必须放行的路径，漏掉任何一个都可能把站点锁死：
         *   - 登录 / 注册 / 验证码 / 登出：不放行的话没人能登录进来；
         *   - 安装向导：站点尚未装好时不能拦；
         *   - 后台：它自身有登录校验，且管理员登录前也要能打开登录页；
         *   - 静态资源与媒体：否则登录页连样式和验证码图都加载不出来。
         */
        $alwaysAllow = ['/login', '/register', '/captcha', '/logout', '/install'];

        if (in_array($path, $alwaysAllow, true)
            || str_starts_with($path, '/admin')
            || str_starts_with($path, '/assets')
            || str_starts_with($path, '/plugin-file')
            || str_starts_with($path, '/plugin-assets')
            || str_starts_with($path, '/media')
            || str_starts_with($path, '/avatar')) {
            return;
        }

        // requireLogin() 会记住当前地址，登录成功后自动跳回来
        Auth::requireLogin();
    }

    /* ------------------------------------------------------------------ */
    /*  安装状态                                                            */
    /* ------------------------------------------------------------------ */

    /** 安装锁文件路径 */
    public static function lockFile(): string
    {
        return APP_ROOT . '/storage/install.lock';
    }

    /** 是否已安装 */
    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    /**
     * 是否处于调试模式
     *
     * 两个来源取「或」，各自有明确用途：
     *   - `config/app.php` 的 debug：写死在文件里的开关，适合本地开发时钉住；
     *   - 后台「站点设置 → 调试模式」（settings.debug_mode）：运维可随时切换，
     *     不用改代码、不用碰服务器文件。
     *
     * 只要任意一个开着就算调试模式。开启后会记录 debug 级日志、
     * 并在出错页面显示详细报错，因此排查完毕应及时关掉。
     *
     * ⚠️ 注意：这里会读 settings 表。`Settings::all()` 自带未安装短路与异常兜底，
     * 所以即使数据库故障、或站点还没装好，也不会在这里二次抛错（避免错误处理里递归出错）。
     */
    public static function debug(): bool
    {
        /*
         * ⚠️ 这里必须直接读 config，绝不能写成 self::debug() —— 那就是无限递归。
         * （本方法曾被一次批量替换脚本误伤成自调用，导致调用即爆栈、
         *   debug 日志永远写不出来，排查了一轮才发现。）
         */
        if ((bool)config('app.debug', false)) {
            return true;
        }

        return Settings::bool('debug_mode', false);
    }

    /* ------------------------------------------------------------------ */
    /*  错误处理                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 注册全局错误与异常处理器
     */
    public static function registerHandlers(): void
    {
        $debug = self::debug();

        // 生产环境关闭错误显示，避免泄露表结构与文件路径
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            // 把 PHP 通知/警告也纳入日志，便于排查
            Logger::warning(sprintf('%s in %s:%d', $message, basename($file), $line));

            if (self::debug() && in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            return true;
        });

        set_exception_handler(static function (\Throwable $e): void {
            Logger::exception($e, 'uncaught');

            if ($e instanceof HttpException) {
                self::renderHttpException($e);

                return;
            }

            self::renderServerError($e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            Logger::error(sprintf('致命错误：%s in %s:%d', $error['message'], $error['file'], $error['line']));
        });
    }

    /**
     * 中断请求并返回错误页
     */
    public static function abort(int $statusCode, string $message = ''): never
    {
        throw new HttpException($statusCode, $message);
    }

    /** 渲染 HttpException */
    private static function renderHttpException(HttpException $e): void
    {
        foreach ($e->headers() as $name => $value) {
            Response::header($name, $value);
        }

        $message = $e->getMessage() !== '' ? $e->getMessage() : HttpException::defaultMessage($e->statusCode());

        if (Request::wantsJson()) {
            Response::json([
                'ok'      => false,
                'status'  => $e->statusCode(),
                'message' => $message,
            ], $e->statusCode());
        }

        Response::html(self::errorPage($e->statusCode(), $message), $e->statusCode());
    }

    /** 渲染 500 内部错误 */
    private static function renderServerError(\Throwable $e): void
    {
        $debug   = self::debug();
        $message = $debug
            ? $e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()
            : '服务器内部错误，我们已经记录该问题，请稍后重试。';

        if (Request::wantsJson()) {
            Response::json(['ok' => false, 'status' => 500, 'message' => $message], 500);
        }

        Response::html(self::errorPage(500, $message, $debug), 500);
    }

    /**
     * 生成错误页 HTML
     */
    private static function errorPage(int $statusCode, string $message, bool $debug = false): string
    {
        try {
            return View::render('errors/error', [
                'code'     => $statusCode,
                'title'    => self::statusTitle($statusCode),
                'message'  => $message,
                'debug'    => $debug,
                'pageTitle' => $statusCode . ' ' . self::statusTitle($statusCode),
                // 错误页使用自包含布局，不引入站点头部/页脚，减少二次异常的可能
                'layout'   => 'layouts/error',
            ]);
        } catch (\Throwable $e) {
            // 模板不可用时退化为极简页面，保证任何情况下都有输出
            Logger::exception($e, 'error-page');

            return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>' . $statusCode . ' - 出错了</title></head><body>'
                . '<h1>' . $statusCode . ' ' . e(self::statusTitle($statusCode)) . '</h1>'
                . '<p>' . e($message) . '</p>'
                . '<p><a href="' . e(Router::url('/')) . '">返回首页</a></p>'
                . '</body></html>';
        }
    }

    /** 状态码标题 */
    private static function statusTitle(int $statusCode): string
    {
        return match ($statusCode) {
            400 => '请求参数有误',
            401 => '需要登录',
            403 => '没有权限',
            404 => '页面不存在',
            405 => '请求方式不允许',
            419 => '页面已过期',
            429 => '请求过于频繁',
            500 => '服务器内部错误',
            503 => '站点维护中',
            default => '出错了',
        };
    }
}
