<?php
/**
 * owlsgo 示例插件 —— 处理器
 *
 * 同一个类里演示两类页面：
 *  1. 前台页面（/hello、/hello/{name}）：继承后台基类只是为了拿到 Controller 的
 *     渲染/跳转/JSON 等便捷方法，输出时使用前台布局 layouts/main（View 默认布局）。
 *  2. 后台页面（/admin/plugin/owlsgo-demo）：调用 adminView() 复用后台布局与侧栏导航。
 *
 * 安全约定（与核心保持一致）：
 *  - 所有输出到 HTML 的动态内容都经过 e() 转义
 *  - 写操作只暴露为 POST 路由，CSRF 由核心统一强制校验
 *  - 页面/接口的权限通过 Plugin::route() 与 Plugin::adminPage() 的权限参数声明
 */

declare(strict_types=1);

namespace OwlsgoDemo;

use Core\Auth;
use Core\Request;
use Core\Response;
use Core\Router;
use Modules\Admin\AdminBaseController;

final class AdminController extends AdminBaseController
{
    /** 后台页面一次展示的记录条数 */
    private const ADMIN_PAGE_SIZE = 30;

    /** 前台页面一次展示的记录条数 */
    private const FRONT_PAGE_SIZE = 8;

    /**
     * 前台首页：/hello
     *
     * @param array<string, string> $params
     */
    public function hello(array $params): string
    {
        return $this->view('plugin/owlsgo-demo/hello', [
            'pageTitle' => '示例插件 - ' . (string)setting('site_name'),
            'greeting'  => Service::setting('greeting'),
            'style'     => Service::welcomeStyle(),
            'user'      => Auth::user(),
            'stats'     => Service::stats(),
            'records'   => Service::recent(self::FRONT_PAGE_SIZE),
            'isAdmin'   => Auth::can('admin.access'),
            'adminUrl'  => Router::url('/admin/plugin/' . Plugin::ID),
            'configUrl' => Router::url('/admin/plugins/' . Plugin::ID . '/config'),
        ]);
    }

    /**
     * 前台问候页：/hello/{name}
     *
     * @param array<string, string> $params
     */
    public function greet(array $params): string
    {
        $name = trim((string)($params['name'] ?? ''));
        $name = mb_substr($name, 0, 40);

        // 名字为空时回到插件首页，避免渲染出空白页面
        if ($name === '') {
            Response::redirect(Router::url('/hello'));
        }

        $viewer = Auth::user();

        return $this->view('plugin/owlsgo-demo/greet', [
            'pageTitle' => '你好，' . $name . ' - ' . (string)setting('site_name'),
            'name'      => $name,
            'greeting'  => Service::setting('greeting'),
            'isSelf'    => $viewer !== null && (string)($viewer['username'] ?? '') === $name,
            'homeUrl'   => Router::url('/hello'),
        ]);
    }

    /**
     * 清空插件活动日志：POST /hello/clear
     *
     * @param array<string, string> $params
     */
    public function clear(array $params): never
    {
        $deleted = Service::clear();

        $this->audit('owlsgo-demo.clear', 'plugin:' . Plugin::ID, '清空插件活动日志 ' . $deleted . ' 条');

        $message = '已清空 ' . $deleted . ' 条活动记录。';
        $back    = Router::url('/admin/plugin/' . Plugin::ID);

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 后台页面：/admin/plugin/owlsgo-demo
     *
     * @param array<string, string> $params
     */
    public function admin(array $params): string
    {
        return $this->adminView('plugin/owlsgo-demo/admin', [
            'pageTitle'     => '示例插件 - ' . (string)setting('site_name'),
            'adminTitle'    => '示例插件',
            'adminSubtitle' => 'owlsgo-demo v1.0.0',
            'records'       => Service::recent(self::ADMIN_PAGE_SIZE),
            'stats'         => Service::stats(),
            'config'        => Service::config(),
            'retention'     => Service::retentionDays(),
            'tableName'     => Service::tableName(),
            'tableReady'    => Service::tableReady(),
            'frontUrl'      => Router::url('/hello'),
            'configUrl'     => Router::url('/admin/plugins/' . Plugin::ID . '/config'),
            'clearUrl'      => Router::url('/hello/clear'),
        ]);
    }
}
