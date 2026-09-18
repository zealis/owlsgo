<?php
/**
 * 用户模型
 *
 * 所有针对 users 表的查询都收敛在这里，控制器不写 SQL。
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Auth;
use Core\Database;
use Core\Model;
use Core\Permission;
use Core\Security;
use Core\Text;

final class UserModel extends Model
{
    protected static string $table = 'users';

    /** 允许写入的字段白名单之外的内容一律忽略（由 Model::filterColumns 保证） */

    /**
     * 按用户名或邮箱查找（登录用）
     *
     * @return array<string, mixed>|null
     */
    public static function findByLogin(string $login): ?array
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        return Database::first(
            'SELECT * FROM ' . Database::identifier('users')
            . ' WHERE (' . Database::identifier('username') . ' = ? OR ' . Database::identifier('email') . ' = ?)'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' LIMIT 1',
            [$login, $login]
        );
    }

    /**
     * 按用户名查找
     *
     * @return array<string, mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        return static::query()->where('username', trim($username))->first();
    }

    /** 用户名是否已被占用（排除某个用户 ID） */
    public static function usernameTaken(string $username, int $exceptId = 0): bool
    {
        $query = static::query()->where('username', trim($username));

        if ($exceptId > 0) {
            $query->whereRaw(Database::identifier('id') . ' <> ?', [$exceptId]);
        }

        return $query->exists();
    }

    /** 邮箱是否已被占用 */
    public static function emailTaken(string $email, int $exceptId = 0): bool
    {
        $query = static::query()->where('email', strtolower(trim($email)));

        if ($exceptId > 0) {
            $query->whereRaw(Database::identifier('id') . ' <> ?', [$exceptId]);
        }

        return $query->exists();
    }

    /**
     * 创建用户（注册流程）
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, message:string, user_id:int}
     */
    public static function register(string $username, string $email, string $password, int $groupId = 3): array
    {
        if (static::usernameTaken($username)) {
            return ['ok' => false, 'message' => '该用户名已被注册。', 'user_id' => 0];
        }

        if (static::emailTaken($email)) {
            return ['ok' => false, 'message' => '该邮箱已被注册。', 'user_id' => 0];
        }

        $now = time();

        $userId = static::create([
            'username'       => $username,
            'email'          => strtolower(trim($email)),
            'password_hash'  => Security::hashPassword($password),
            'group_id'       => $groupId,
            'avatar'         => '',
            'signature'      => '',
            'bio'            => '',
            'location'       => '',
            'points'         => 10,
            'thread_count'   => 0,
            'post_count'     => 0,
            'favorite_count' => 0,
            'status'         => 1,
            'register_ip'    => \Core\Request::ip(),
            'last_login_ip'  => '',
            'last_login_at'  => 0,
            'last_active_at' => $now,
        ]);

        return ['ok' => true, 'message' => '注册成功。', 'user_id' => $userId];
    }

    /**
     * 为用户记录附加用户组信息（名称、颜色）
     *
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>|null
     */
    public static function decorate(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $groupId = Permission::groupOf($user);
        $group   = UsergroupModel::find($groupId);

        $user['group_id']     = $groupId;
        $user['group_name']   = (string)($group['name'] ?? '游客');
        $user['group_color']  = (string)($group['color'] ?? '#999999');
        $user['is_admin']     = $groupId === Permission::SUPER_GROUP;
        $user['is_moderator'] = $groupId === 2;
        $user['avatar_url']   = \Core\Avatar::url($user, 96);

        return $user;
    }

    /**
     * 批量附加用户组信息，避免 N+1
     *
     * @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    public static function decorateMany(array $users): array
    {
        // 一次取出全部用户组，避免每行都查一次库
        $groups = UsergroupModel::all();

        foreach ($users as &$user) {
            $groupId = Permission::groupOf($user);
            $group   = $groups[$groupId] ?? null;

            $user['group_id']    = $groupId;
            $user['group_name']  = (string)($group['name'] ?? '游客');
            $user['group_color'] = (string)($group['color'] ?? '#999999');
            $user['avatar_url']  = \Core\Avatar::url($user, 96);
        }
        unset($user);

        return $users;
    }

    /**
     * 搜索用户（后台）
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(string $keyword, int $groupId, int $page, int $perPage = 20): array
    {
        $query = static::query();

        if ($keyword !== '') {
            $query->orGroup(static function ($q) use ($keyword): void {
                $q->whereContains('username', $keyword);
                $q->whereRaw(\Core\Database::identifier('email') . ' ' . \Core\Database::likeOperator() . ' ?', ['%' . $keyword . '%']);
            });
        }

        if ($groupId > 0) {
            $query->where('group_id', $groupId);
        }

        $query->orderBy('id', 'desc');

        return $query->paginate($perPage, $page);
    }

    /**
     * 更新用户资料中的可编辑字段
     *
     * @param array<string, mixed> $input
     */
    public static function updateProfile(int $userId, array $input): void
    {
        $data = [];

        foreach (['signature' => 120, 'location' => 60, 'bio' => 500] as $field => $limit) {
            if (array_key_exists($field, $input)) {
                $data[$field] = mb_substr(trim((string)$input[$field]), 0, $limit);
            }
        }

        if ($data !== []) {
            static::updateById($userId, $data);
        }
    }

    /** 统计各用户组人数 */
    public static function countByGroups(): array
    {
        $rows = Database::select(
            'SELECT ' . Database::identifier('group_id') . ' AS ' . Database::identifier('group_id')
            . ', COUNT(*) AS ' . Database::identifier('total')
            . ' FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' GROUP BY ' . Database::identifier('group_id')
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['group_id']] = (int)$row['total'];
        }

        return $result;
    }

    /** 用户总数 */
    public static function totalCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        );
    }

    /** 最近注册的用户 */
    public static function recent(int $limit = 10): array
    {
        return static::query()->orderBy('id', 'desc')->limit($limit)->get();
    }

    /**
     * 可供选择的人员下拉（后台版主名单等场景）
     *
     * 只取后台挑选版主所需的最小字段，避免把密码哈希等敏感列读进内存。
     * 上限保护：超大规模站点也不会因为一次渲染把整表读出来。
     *
     * @return array<int, string> 用户 ID => 用户名
     */
    public static function options(int $limit = 500): array
    {
        $rows = Database::select(
            'SELECT ' . Database::identifier('id') . ', ' . Database::identifier('username')
            . ' FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' ORDER BY ' . Database::identifier('id') . ' ASC'
            . ' LIMIT ' . max(1, min(2000, $limit))
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['id']] = (string)$row['username'];
        }

        return $result;
    }

    /** 指定时间点之后注册的用户数（后台概览「今日新增」） */
    public static function countSince(int $timestamp): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('created_at') . ' >= ?',
            [$timestamp]
        );
    }

    /** 后台按 ID 列表取用户（保留传入顺序） */
    public static function byIds(array $ids): array
    {
        $map = static::mapByIds($ids);

        $result = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (isset($map[$id])) {
                $result[] = $map[$id];
            }
        }

        return $result;
    }

    /**
     * 前台搜索：按用户名匹配正常状态的用户（分页）
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function searchByName(string $keyword, int $page, int $perPage = 20): array
    {
        $query = static::query()->where('status', 1);

        if ($keyword !== '') {
            $query->whereContains('username', $keyword);
        }

        return $query
            ->orderBy('post_count', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(max(1, $perPage), max(1, $page));
    }
}
