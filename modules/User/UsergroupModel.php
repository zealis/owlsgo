<?php
/**
 * 用户组模型
 *
 * 用户组数据被权限判定与用户展示频繁读取，因此整表缓存在进程内（按需加载）。
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Cache;
use Core\Database;
use Core\Model;
use Core\Permission;

final class UsergroupModel extends Model
{
    protected static string $table = 'usergroups';

    /** 用户组没有软删除 */
    protected static bool $softDelete = false;

    /** @var array<int, array<string, mixed>>|null 进程内缓存 */
    private static ?array $cache = null;

    /**
     * 全部用户组（按排序）
     *
     * @return array<int, array<string, mixed>> 以 ID 为键
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = Cache::remember('usergroups:all', (int)config('app.cache_ttl', 300), static function (): array {
            return Database::select(
                'SELECT * FROM ' . Database::identifier('usergroups')
                . ' ORDER BY ' . Database::identifier('sort_order') . ' ASC, ' . Database::identifier('id') . ' ASC'
            );
        });

        $result = [];
        foreach ((array)$rows as $row) {
            $result[(int)$row['id']] = $row;
        }

        self::$cache = $result;

        return self::$cache;
    }

    /**
     * 按 ID 获取用户组（带默认回退）
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $all = self::all();

        if (isset($all[$id])) {
            return $all[$id];
        }

        return $all[Permission::GUEST_GROUP] ?? null;
    }

    /**
     * 用户组下拉选项
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $result = [];

        foreach (self::all() as $id => $group) {
            $result[$id] = (string)$group['name'];
        }

        return $result;
    }

    /**
     * 新增用户组
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool, message:string, id:int}
     */
    public static function createGroup(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $slug = trim((string)($input['slug'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'message' => '请填写用户组名称。', 'id' => 0];
        }

        if ($slug === '') {
            $slug = 'group-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        if (!preg_match('/^[a-z0-9_\-]{2,32}$/', $slug)) {
            return ['ok' => false, 'message' => '用户组标识只能包含小写字母、数字、下划线与短横线。', 'id' => 0];
        }

        $duplicated = Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('usergroups')
            . ' WHERE ' . Database::identifier('slug') . ' = ?',
            [$slug]
        );

        if ((int)$duplicated > 0) {
            return ['ok' => false, 'message' => '该用户组标识已存在。', 'id' => 0];
        }

        $permissions = Permission::normalize((array)($input['permissions'] ?? []));

        $id = static::create([
            'name'        => mb_substr($name, 0, 60),
            'slug'        => $slug,
            'description' => mb_substr(trim((string)($input['description'] ?? '')), 0, 120),
            'color'       => self::normalizeColor((string)($input['color'] ?? '#00A0E9')),
            'icon'        => '',
            'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            'is_system'   => 0,
            'sort_order'  => (int)($input['sort_order'] ?? 0),
        ]);

        self::flush();

        return ['ok' => true, 'message' => '用户组已创建。', 'id' => $id];
    }

    /**
     * 更新用户组
     *
     * @param array<string, mixed> $input
     */
    public static function updateGroup(int $id, array $input): array
    {
        $group = self::find($id);

        if ($group === null || (int)$group['id'] !== $id) {
            return ['ok' => false, 'message' => '用户组不存在。'];
        }

        $data = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                return ['ok' => false, 'message' => '用户组名称不能为空。'];
            }
            $data['name'] = mb_substr($name, 0, 60);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = mb_substr(trim((string)$input['description']), 0, 120);
        }

        if (array_key_exists('color', $input)) {
            $data['color'] = self::normalizeColor((string)$input['color']);
        }

        if (array_key_exists('sort_order', $input)) {
            $data['sort_order'] = (int)$input['sort_order'];
        }

        if (array_key_exists('permissions', $input)) {
            $permissions = Permission::normalize((array)$input['permissions']);

            // 超级管理员组权限不可被削减，避免把自己锁在门外
            if ($id === Permission::SUPER_GROUP) {
                $permissions = array_fill_keys(array_keys(Permission::CATALOG), true);
            }

            $data['permissions'] = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        }

        if ($data !== []) {
            static::updateById($id, $data);
        }

        self::flush();

        return ['ok' => true, 'message' => '用户组已更新。'];
    }

    /**
     * 删除用户组（系统内置组与仍有成员的用户组不可删除）
     */
    public static function deleteGroup(int $id): array
    {
        $group = self::find($id);

        if ($group === null || (int)$group['id'] !== $id) {
            return ['ok' => false, 'message' => '用户组不存在。'];
        }

        if ((int)$group['is_system'] === 1) {
            return ['ok' => false, 'message' => '系统内置用户组不可删除。'];
        }

        $members = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('group_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$id]
        );

        if ($members > 0) {
            return ['ok' => false, 'message' => '该用户组下仍有 ' . $members . ' 位用户，请先调整他们的用户组。'];
        }

        Database::delete('usergroups', Database::identifier('id') . ' = ?', [$id]);
        self::flush();

        return ['ok' => true, 'message' => '用户组已删除。'];
    }

    /** 清除用户组缓存 */
    public static function flush(): void
    {
        self::$cache = null;
        Cache::forget('usergroups:all');
        Model::flushRowCache();
    }

    /** 规范化颜色值，只接受 3/6 位十六进制色值 */
    private static function normalizeColor(string $color): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) === 1 ? strtolower($color) : '#00a0e9';
    }
}
