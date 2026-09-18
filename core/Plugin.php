<?php
/**
 * 插件开发门面
 *
 * 插件作者只需要使用本类的静态方法，不需要（也不应该）直接操作核心内部状态：
 *
 *   Plugin::filter('content_render', fn (string $text) => str_replace('foo', 'bar', $text));
 *   Plugin::action('after_post_create', function (array $ctx, array $context): void { ... });
 *   Plugin::route('GET', '/hello/{name}', fn (array $params) => Response::html('Hi ' . e($params['name'])));
 *   Plugin::adminPage('hello', '示例页', 'HelloController@index');
 *   Plugin::menu('示例页', '/admin/plugin/hello');
 *   Plugin::cron('hello_tick', 3600, fn () => Logger::info('tick'));
 *
 * 注意：插件代码在加载时会执行，务必只做「注册」而不做「业务」。
 */

declare(strict_types=1);

namespace Core;

final class Plugin
{
    /** 正在加载的插件 ID */
    private static string $current = '';

    /** @var array<int, array{plugin:string, method:string, path:string, handler:callable|string, permission:string}> 插件路由 */
    private static array $routes = [];

    /** @var array<int, array{plugin:string, slug:string, title:string, handler:callable|string, permission:string, icon:string}> 插件后台页面 */
    private static array $adminPages = [];

    /** @var array<int, array{plugin:string, title:string, url:string, icon:string}> 插件前台菜单 */
    private static array $menus = [];

    /** @var array<int, array{plugin:string, name:string, interval:int, handler:callable|string}> 插件计划任务 */
    private static array $crons = [];

    /** @var array<string, array<string, mixed>> 插件配置缓存：pluginId => config */
    private static array $configCache = [];

    /** 当前插件 ID */
    public static function id(): string
    {
        return self::$current;
    }

    /** 由 PluginManager 在加载每个插件前调用 */
    public static function setCurrent(string $id): void
    {
        self::$current = $id;
    }

    /**
     * 注册内容过滤钩子
     */
    public static function filter(string $name, callable $callback, int $priority = 10): void
    {
        Hook::on($name, $callback, $priority);
    }

    /**
     * 注册动作钩子
     */
    public static function action(string $name, callable $callback, int $priority = 10): void
    {
        Hook::on($name, $callback, $priority);
    }

    /**
     * 注册一条插件路由
     *
     * @param string          $method     GET|POST
     * @param string          $path       形如 /hello/{name}
     * @param callable|string $handler    可调用对象，或 "Class@method"
     * @param string          $permission 需要的权限，空表示仅需登录
     */
    public static function route(string $method, string $path, callable|string $handler, string $permission = ''): void
    {
        self::$routes[] = [
            'plugin'     => self::$current,
            'method'     => strtoupper($method),
            'path'       => '/' . ltrim($path, '/'),
            'handler'    => $handler,
            'permission' => $permission,
        ];
    }

    /**
     * 注册一个后台页面
     *
     * 页面地址为 /admin/plugin/{slug}
     */
    public static function adminPage(
        string $slug,
        string $title,
        callable|string $handler,
        string $permission = 'admin.access',
        string $icon = ''
    ): void {
        $slug = preg_replace('/[^a-z0-9_\-]/', '', strtolower($slug)) ?: 'page';

        self::$adminPages[] = [
            'plugin'     => self::$current,
            'slug'       => $slug,
            'title'      => $title,
            'handler'    => $handler,
            'permission' => $permission,
            'icon'       => $icon,
        ];
    }

    /**
     * 注册前台导航菜单项
     */
    public static function menu(string $title, string $url, string $icon = ''): void
    {
        self::$menus[] = [
            'plugin' => self::$current,
            'title'  => $title,
            'url'    => $url,
            'icon'   => $icon,
        ];
    }

    /**
     * 注册一个计划任务
     *
     * @param int             $interval 运行间隔（秒）
     * @param callable|string $handler
     */
    public static function cron(string $name, int $interval, callable|string $handler): void
    {
        self::$crons[] = [
            'plugin'   => self::$current,
            'name'     => preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?: 'task',
            'interval' => max(60, $interval),
            'handler'  => $handler,
        ];
    }

    /**
     * 读取插件配置（合并默认值）
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function config(array $defaults = [], ?string $pluginId = null): array
    {
        $pluginId = $pluginId ?? self::$current;

        if (!isset(self::$configCache[$pluginId])) {
            $row    = Database::first(
                'SELECT ' . Database::identifier('config') . ' FROM ' . Database::identifier('plugins')
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$pluginId]
            );
            $config = json_decode((string)($row['config'] ?? '{}'), true);
            self::$configCache[$pluginId] = is_array($config) ? $config : [];
        }

        return array_merge($defaults, self::$configCache[$pluginId]);
    }

    /**
     * 保存插件配置（合并写入）
     *
     * @param array<string, mixed> $config
     */
    public static function saveConfig(array $config, ?string $pluginId = null): void
    {
        $pluginId = $pluginId ?? self::$current;
        $merged   = array_merge(self::config([], $pluginId), $config);

        Database::update(
            'plugins',
            [
                'config'     => json_encode($merged, JSON_UNESCAPED_UNICODE),
                'updated_at' => time(),
            ],
            Database::identifier('id') . ' = ?',
            [$pluginId]
        );

        self::$configCache[$pluginId] = $merged;
    }

    /**
     * 插件自有表名，自动加 plugin_{id}_ 前缀，避免与核心表冲突
     *
     * ID 规范化为小写下划线形式，例如 owlsgo-demo + activity => plugin_owlsgo_demo_activity。
     * 插件建表脚本必须使用完全相同的名字（见 plugins/{id}/sql/*.sql）。
     */
    public static function tableName(string $suffix, ?string $pluginId = null): string
    {
        $pluginId = $pluginId ?? self::$current;
        $suffix   = preg_replace('/[^a-z0-9_]/', '', strtolower($suffix)) ?: 'data';

        return 'plugin_' . self::slug($pluginId) . '_' . $suffix;
    }

    /**
     * 把插件 ID 规范化为可安全用于表名的片段（短横线转下划线）
     *
     * 表名只允许 [A-Za-z0-9_]，因此不能直接使用带短横线的插件 ID。
     */
    private static function slug(string $pluginId): string
    {
        $slug = preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', strtolower($pluginId)));

        return $slug === null || $slug === '' ? 'plugin' : $slug;
    }

    /** 获取插件自有表的查询构造器 */
    public static function table(string $suffix, ?string $pluginId = null): Query
    {
        return Database::table(self::tableName($suffix, $pluginId));
    }

    /** 插件目录绝对路径 */
    public static function path(string $relative = '', ?string $pluginId = null): string
    {
        $pluginId = $pluginId ?? self::$current;

        return APP_ROOT . '/plugins/' . $pluginId . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    /** 插件静态资源 URL */
    public static function asset(string $relative, ?string $pluginId = null): string
    {
        $pluginId = $pluginId ?? self::$current;

        return Router::url('/plugin-file/' . $pluginId . '/' . ltrim($relative, '/'));
    }

    /** 写插件日志 */
    public static function log(string $message, array $context = []): void
    {
        Logger::info('[plugin:' . self::$current . '] ' . $message, $context);
    }

    /* ------------------------------------------------------------------ */
    /*  以下方法供核心内部与后台管理使用                                     */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array{plugin:string, method:string, path:string, handler:callable|string, permission:string}> */
    public static function routes(): array
    {
        return self::$routes;
    }

    /** @return array<int, array{plugin:string, slug:string, title:string, handler:callable|string, permission:string, icon:string}> */
    public static function adminPages(): array
    {
        return self::$adminPages;
    }

    /** @return array<int, array{plugin:string, title:string, url:string, icon:string}> */
    public static function menus(): array
    {
        return self::$menus;
    }

    /** @return array<int, array{plugin:string, name:string, interval:int, handler:callable|string}> */
    public static function crons(): array
    {
        return self::$crons;
    }

    /**
     * 按 slug 查找已注册的后台页面
     *
     * 注意：注册用的 API 是 adminPage()（多参数），本方法用于「查」，
     * 二者不能同名 —— 同名方法会导致 PHP 编译期致命错误（Cannot redeclare）。
     *
     * @return array{plugin:string, slug:string, title:string, handler:callable|string, permission:string, icon:string}|null
     */
    public static function findAdminPage(string $slug): ?array
    {
        foreach (self::$adminPages as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }

    /** 清空注册表（测试或重载时使用） */
    public static function reset(): void
    {
        self::$routes     = [];
        self::$adminPages = [];
        self::$menus      = [];
        self::$crons      = [];
        self::$configCache = [];
        self::$current    = '';
    }
}
