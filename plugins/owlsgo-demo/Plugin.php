<?php
/**
 * owlsgo 示例插件 —— 入口文件
 *
 * 入口文件在「每次请求的应用引导阶段」都会被载入，因此本文件只允许做两件事：
 *   1. 注册插件自身的自动加载（一次性、极轻量）
 *   2. 在 Plugin::register() 中把能力「登记」到核心，而不执行任何业务逻辑
 *
 * 业务代码一律放在同目录的其它类里（Service / AdminController），
 * 由核心在真正需要时调用。
 *
 * 目录约定：
 *   plugin.json      清单（元数据、钩子、资源、配置项声明）
 *   Plugin.php       本文件，入口 + register()
 *   Service.php      业务逻辑与插件自建表的读写
 *   AdminController.php  前台路由与后台页面的处理器
 *   sql/{driver}.sql 启用插件时自动执行的建表脚本（sqlite / mysql / pgsql）
 *   assets/          插件静态资源，经 /plugin-file/{id}/{path} 分发
 *   templates/       插件自带模板，经 view('plugin/owlsgo-demo/xxx') 渲染
 */

declare(strict_types=1);

namespace OwlsgoDemo;

use Core\Plugin as PluginApi;
use Core\Router;

/*
 * 为插件自身注册 PSR-4 自动加载。
 *
 * 核心的自动加载器只认 Core\ 与 Modules\ 前缀，插件的命名空间由插件自己负责。
 * 这里只是「注册」，没有触发任何文件读取，开销可以忽略。
 */
spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

final class Plugin
{
    /** 插件 ID，必须与所在目录名完全一致 */
    public const ID = 'owlsgo-demo';

    /**
     * 由 PluginManager 在加载本插件时调用
     *
     * 注意：此方法内只能「注册」，不能查库、不能输出、不能假设用户已登录。
     */
    public static function register(): void
    {
        /* ---------- 1. 内容过滤（filter 必须把值返回，否则正文会变成 null） ---------- */
        PluginApi::filter('content_rendered', [Service::class, 'decorateContent']);

        /* ---------- 2. 业务动作（只关心「发生了什么」，无返回值） ---------- */
        PluginApi::action('after_thread_create', [Service::class, 'onThreadCreate']);
        PluginApi::action('after_post_create', [Service::class, 'onPostCreate']);

        /* ---------- 2.1 前置拦截：命中禁止词时不写入数据库 ---------- */
        PluginApi::action('before_thread_create', [Service::class, 'guardContent']);
        PluginApi::action('before_post_create', [Service::class, 'guardContent']);

        /* ---------- 3. 页面资源与导航 ---------- */
        PluginApi::filter('head_assets', [Service::class, 'headAssets']);
        PluginApi::filter('footer_assets', [Service::class, 'footerAssets']);
        PluginApi::filter('nav_links', [Service::class, 'navLinks']);
        PluginApi::filter('user_profile_tabs', [Service::class, 'profileTabs']);

        // 前台顶部导航菜单项（渲染位置见 templates/partials/header.php）
        PluginApi::menu('插件示例', Router::url('/hello'), 'bulb');

        /* ---------- 4. 前台路由 ---------- */
        // 控制器处理器必须写成 "Class@method" 字符串：核心会自行实例化控制器，
        // 传 [类名, 方法名] 形式的数组会被当成「静态调用」，非静态方法在 PHP 8 下会直接报错。
        PluginApi::route('GET', '/hello', AdminController::class . '@hello');
        PluginApi::route('GET', '/hello/{name}', AdminController::class . '@greet');

        // 写操作用 POST + 权限声明；CSRF 由核心强制校验，插件无需自己处理
        PluginApi::route('POST', '/hello/clear', AdminController::class . '@clear', 'admin.access');

        /* ---------- 5. 后台页面：访问地址为 /admin/plugin/owlsgo-demo ---------- */
        PluginApi::adminPage(
            'owlsgo-demo',
            '示例插件',
            AdminController::class . '@admin',
            'admin.access',
            'bulb'
        );

        /* ---------- 6. 计划任务：每天清理一次插件自己的活动日志 ---------- */
        // 计划任务允许两种写法：静态方法数组，或 "Class@method" 字符串
        PluginApi::cron('owlsgo_demo_cleanup', 86400, Service::class . '@cleanup');
    }
}
