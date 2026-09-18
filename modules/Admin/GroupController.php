<?php
/**
 * 后台：用户组管理
 *
 * 用户组是权限体系的载体，因此这里的表单与 CATALOG 完全联动：
 * 权限清单只在 Core\Permission::CATALOG 一处维护，新增权限时后台自动出现。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\App;
use Core\Permission;
use Core\Request;
use Core\Router;
use Modules\User\UsergroupModel;
use Modules\User\UserModel;

final class GroupController extends AdminBaseController
{
    /**
     * 用户组列表
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $groups = UsergroupModel::all();
        $counts = UserModel::countByGroups();

        $rows = [];
        foreach ($groups as $id => $group) {
            $permissions = json_decode((string)$group['permissions'], true);

            $rows[] = [
                'id'          => $id,
                'name'        => (string)$group['name'],
                'slug'        => (string)$group['slug'],
                'color'       => (string)$group['color'],
                'description' => (string)$group['description'],
                'is_system'   => (int)$group['is_system'] === 1,
                'sort_order'  => (int)$group['sort_order'],
                'members'     => (int)($counts[$id] ?? 0),
                'permissions' => is_array($permissions)
                    ? count(array_filter($permissions))
                    : 0,
            ];
        }

        return $this->adminView('admin/groups', [
            'pageTitle'   => '用户组管理 - ' . (string)setting('site_name'),
            'adminTitle'  => '用户组管理',
            'rows'        => $rows,
            'totalRights' => count(Permission::CATALOG),
        ]);
    }

    /**
     * 新增用户组表单
     *
     * @param array<string, string> $params
     */
    public function create(array $params): string
    {
        return $this->adminView('admin/group-form', [
            'pageTitle'  => '新增用户组 - ' . (string)setting('site_name'),
            'adminTitle' => '新增用户组',
            'group'      => null,
            'catalog'    => Permission::CATALOG,
            'checked'    => $this->defaultChecked(3),
            'action'     => Router::url('/admin/groups'),
        ]);
    }

    /**
     * 保存新用户组
     *
     * @param array<string, string> $params
     */
    public function store(array $params): never
    {
        $back = Router::url('/admin/groups/create');

        $input = [
            'name'        => Request::string('name', '', 60),
            'slug'        => Request::string('slug', '', 32),
            'description' => Request::string('description', '', 120),
            'color'       => Request::string('color', '#00A0E9', 7),
            'sort_order'  => Request::int('sort_order', 0),
            'permissions' => Request::array('permissions'),
        ];

        $validator = $this->validate(
            ['name' => $input['name'], 'slug' => $input['slug']],
            ['name' => 'required|between:1,60', 'slug' => 'alpha_dash'],
            ['name' => '用户组名称', 'slug' => '用户组标识']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), $back);
        }

        $result = UsergroupModel::createGroup($input);

        if (!$result['ok']) {
            $this->backWithErrors(['name' => $result['message']], $back);
        }

        $this->audit('group.create', 'group:' . $result['id'], '新增用户组：' . $input['name']);

        $this->redirectWith(Router::url('/admin/groups'), $result['message']);
    }

    /**
     * 编辑用户组表单
     *
     * @param array<string, string> $params
     */
    public function edit(array $params): string
    {
        $groupId = (int)($params['id'] ?? 0);
        $group   = UsergroupModel::find($groupId);

        if ($group === null || (int)$group['id'] !== $groupId) {
            App::abort(404, '用户组不存在。');
        }

        $stored  = json_decode((string)$group['permissions'], true);
        $stored  = is_array($stored) ? array_map(static fn ($v): bool => (bool)$v, $stored) : [];

        // 保存过就按保存值；未保存过的内置组按代码里的默认权限展示
        $checked = $stored === [] ? Permission::ofGroup($groupId) : array_merge(Permission::ofGroup($groupId), $stored);

        return $this->adminView('admin/group-form', [
            'pageTitle'  => '编辑用户组 - ' . (string)setting('site_name'),
            'adminTitle' => '编辑用户组',
            'group'      => $group,
            'catalog'    => Permission::CATALOG,
            'checked'    => $checked,
            'isSuper'    => $groupId === Permission::SUPER_GROUP,
            'action'     => Router::url('/admin/groups/' . $groupId),
        ]);
    }

    /**
     * 保存用户组编辑
     *
     * @param array<string, string> $params
     */
    public function update(array $params): never
    {
        $groupId = (int)($params['id'] ?? 0);
        $back    = Router::url('/admin/groups/' . $groupId . '/edit');

        $group = UsergroupModel::find($groupId);

        if ($group === null || (int)$group['id'] !== $groupId) {
            App::abort(404, '用户组不存在。');
        }

        $input = [
            'name'        => Request::string('name', '', 60),
            'description' => Request::string('description', '', 120),
            'color'       => Request::string('color', '#00A0E9', 7),
            'sort_order'  => Request::int('sort_order', 0),
            'permissions' => Request::array('permissions'),
        ];

        $validator = $this->validate(
            ['name' => $input['name']],
            ['name' => 'required|between:1,60'],
            ['name' => '用户组名称']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), $back);
        }

        $result = UsergroupModel::updateGroup($groupId, $input);

        if (!$result['ok']) {
            $this->backWithErrors(['name' => $result['message']], $back);
        }

        $this->audit('group.update', 'group:' . $groupId, '更新用户组：' . $input['name']);

        $this->redirectWith(Router::url('/admin/groups'), $result['message']);
    }

    /**
     * 删除用户组
     *
     * @param array<string, string> $params
     */
    public function destroy(array $params): never
    {
        $groupId = (int)($params['id'] ?? 0);
        $group   = UsergroupModel::find($groupId);
        $back    = Router::url('/admin/groups');

        if ($group === null || (int)$group['id'] !== $groupId) {
            App::abort(404, '用户组不存在。');
        }

        $result = UsergroupModel::deleteGroup($groupId);

        if (!$result['ok']) {
            $this->redirectWith($back, $result['message'], 'error');
        }

        $this->audit('group.delete', 'group:' . $groupId, '删除用户组：' . (string)$group['name']);

        $this->redirectWith($back, $result['message']);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 新增用户组时的默认勾选权限（与「注册用户」组一致，方便快速建组）
     *
     * @return array<string, bool>
     */
    private function defaultChecked(int $templateGroupId): array
    {
        return Permission::ofGroup($templateGroupId);
    }
}
