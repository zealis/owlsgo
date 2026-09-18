<?php
/**
 * 后台控制器基类
 *
 * 统一处理：
 *  - 后台左侧导航（按当前用户的权限过滤后的结果）
 *  - 统一套用后台布局 layouts/admin
 *  - 导航中自动追加插件注册的后台页面
 *
 * 具体权限校验仍由路由表声明（见 config/routes.php 的第 4 列），
 * 控制器内部只在需要「更细粒度」判定时再调用 requirePermission()。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Auth;
use Core\Controller;
use Core\Hook;
use Core\Plugin;

abstract class AdminBaseController extends Controller
{
    /**
     * 后台导航定义
     *
     * @return list<array{label:string, url:string, icon:string, permission:string}>
     */
    protected function nav(): array
    {
        return [
            ['label' => '概览',    'url' => '/admin',             'icon' => 'dashboard', 'permission' => 'admin.access'],
            ['label' => '版块管理', 'url' => '/admin/forums',      'icon' => 'grid',      'permission' => 'admin.forum'],
            ['label' => '用户组',  'url' => '/admin/groups',      'icon' => 'shield',    'permission' => 'admin.group'],
            ['label' => '用户',    'url' => '/admin/users',       'icon' => 'users',     'permission' => 'admin.user'],
            ['label' => '内容',    'url' => '/admin/threads',     'icon' => 'file',      'permission' => 'admin.content'],
            ['label' => '附件',    'url' => '/admin/attachments', 'icon' => 'paperclip', 'permission' => 'attachment.manage'],
            ['label' => '插件',    'url' => '/admin/plugins',     'icon' => 'plug',      'permission' => 'admin.plugin'],
            ['label' => '计划任务', 'url' => '/admin/cron',        'icon' => 'clock',     'permission' => 'admin.cron'],
            ['label' => '日志',    'url' => '/admin/logs',        'icon' => 'list',      'permission' => 'admin.logs'],
            ['label' => '站点设置', 'url' => '/admin/settings',    'icon' => 'settings',  'permission' => 'admin.settings'],
        ];
    }

    /**
     * 后台导航（已按权限过滤，并附加插件页面）
     *
     * @return list<array{label:string, url:string, icon:string, permission:string}>
     */
    protected function filteredNav(): array
    {
        $nav = [];

        foreach ($this->nav() as $item) {
            if (Auth::can($item['permission'])) {
                $nav[] = $item;
            }
        }

        // 插件注册的后台页面（Plugin::adminPage）
        foreach (Plugin::adminPages() as $page) {
            $permission = (string)($page['permission'] ?? 'admin.access');

            if (!Auth::can($permission)) {
                continue;
            }

            $nav[] = [
                'label'      => (string)$page['title'],
                'url'        => '/admin/plugin/' . (string)$page['slug'],
                'icon'       => (string)($page['icon'] ?? '') !== '' ? (string)$page['icon'] : 'puzzle',
                'permission' => $permission,
            ];
        }

        // 插件可对后台导航做最终增删改（例如把插件页面并入自定义分组）
        return (array)Hook::filter('admin_nav_links', $nav, ['user' => Auth::user()]);
    }

    /**
     * 渲染后台页面
     *
     * @param array<string, mixed> $data
     */
    protected function adminView(string $template, array $data = []): string
    {
        $data = array_merge([
            'adminNav'    => $this->filteredNav(),
            'adminTitle'  => (string)($data['adminTitle'] ?? ''),
            'adminSubtitle' => (string)($data['adminSubtitle'] ?? ''),
        ], $data);

        return $this->view($template, $data, 'layouts/admin');
    }

    /**
     * 记录一条后台操作日志
     */
    protected function audit(string $action, string $target = '', string $detail = ''): void
    {
        LogModel::record(Auth::id(), $action, $target, $detail);
    }
}
