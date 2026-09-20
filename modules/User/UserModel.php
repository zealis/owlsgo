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

        /* 「我的隐私」：规范成布尔，模板与导航可直接当开关状态用 */
        $user['public_threads'] = (int)($user['public_threads'] ?? 1) === 1;
        $user['public_posts']   = (int)($user['public_posts'] ?? 1) === 1;

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
     * 关键词为纯数字时同时精确匹配用户 ID —— 后台经常按 ID 找人。
     */
    public static function search(string $keyword, int $groupId, int $page, int $perPage = 20): array
    {
        $query = static::query();

        if ($keyword !== '') {
            $query->orGroup(static function ($q) use ($keyword): void {
                if (ctype_digit($keyword)) {
                    $q->whereRaw(\Core\Database::identifier('id') . ' = ?', [(int)$keyword]);
                }
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
     * 更新个人资料（个人简介）
     *
     * 简介与签名已合并为同一个字段 `bio`：它既显示在个人主页，也显示在每个
     * 评论楼层下方。users.signature 列保留在表结构中但不再读写。
     *
     * @param array<string, mixed> $input
     */
    public static function updateProfile(int $userId, array $input): void
    {
        $data = [];

        foreach (['bio' => 100] as $field => $limit) {
            if (array_key_exists($field, $input)) {
                $data[$field] = mb_substr(trim((string)$input[$field]), 0, $limit);
            }
        }

        if ($data !== []) {
            static::updateById($userId, $data);
        }
    }

    /**
     * 更新账号标识（用户名 / 邮箱）
     *
     * 与 updateProfile 分开：这两个字段是**账号标识**，调用前必须由控制器
     * 完成格式校验、唯一性检查，以及（未来）验证码 / 人机验证，
     * 所以不放进「随便传什么就存什么」的资料接口里。
     */
    public static function updateAccount(int $userId, string $username, string $email): void
    {
        static::updateById($userId, [
            'username' => trim($username),
            'email'    => strtolower(trim($email)),
        ]);
    }

    /*
     * ------------------------------------------------------------------
     *  「我的隐私」：个人主页标签页的公开性
     * ------------------------------------------------------------------
     *
     * public_threads / public_posts = 1 → 所有人可见，0 → 仅本人（与管理员）。
     * 这两个字段是后加的，老站点的库里没有 → 写库前用 ensurePrivacyColumns() 惰性补列。
     */

    /** 后加的隐私字段（需要惰性补列） */
    public const PRIVACY_FIELDS = ['public_threads', 'public_posts'];

    /**
     * 惰性补列：库里缺 public_threads / public_posts 时补上
     *
     * 项目没有通用迁移机制（参照 NoticeModel::migrate 的做法）。
     * 注意这里**不复用 Model::columns()** —— 它带进程内静态缓存，ALTER 之后缓存里
     * 仍是旧列表，会让 filterColumns() 把新字段滤掉；所以探测用下面的 hasColumn()，
     * 写入也直接用 Database::update 绕开白名单。
     */
    private static function ensurePrivacyColumns(): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }
        $checked = true;

        foreach (self::PRIVACY_FIELDS as $column) {
            if (self::hasColumn('users', $column)) {
                continue;
            }

            try {
                Database::execute(
                    'ALTER TABLE ' . Database::identifier('users')
                    . ' ADD COLUMN ' . Database::identifier($column) . ' INTEGER NOT NULL DEFAULT 1'
                );
            } catch (\Throwable $e) {
                // 并发请求可能同时补列，失败的一方忽略即可（列此时已由另一方建好）
            }
        }
    }

    /** 直接查库判断列是否存在（不经过 Model::columns 的缓存） */
    /**
     * 字段是否存在（实时探测）
     *
     * 实现已收敛到 Database::hasColumn()，这里保留同名私有方法只是为了让
     * ensurePrivacyColumns() 的调用点保持可读；不要再在这里重写一份探测逻辑。
     */
    private static function hasColumn(string $table, string $column): bool
    {
        return Database::hasColumn($table, $column);
    }

    /**
     * 某用户的标签页公开性（缺列时一律按「所有人可见」处理）
     *
     * @param array<string, mixed>|null $user
     * @return array{threads: bool, posts: bool}
     */
    public static function privacyOf(?array $user): array
    {
        return [
            'threads' => (int)($user['public_threads'] ?? 1) === 1,
            'posts'   => (int)($user['public_posts'] ?? 1) === 1,
        ];
    }

    /** 更新「我的隐私」（个人主页标签页的公开性） */
    public static function updatePrivacy(int $userId, bool $publicThreads, bool $publicPosts): void
    {
        self::ensurePrivacyColumns();

        Database::update('users', [
            'public_threads' => $publicThreads ? 1 : 0,
            'public_posts'   => $publicPosts ? 1 : 0,
            'updated_at'     => time(),
        ], Database::identifier('id') . ' = ?', [$userId]);
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

    /**
     * 按 ID 集合取用户名映射（版主指派回显用）
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function usernamesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::select(
            'SELECT ' . Database::identifier('id') . ', ' . Database::identifier('username')
            . ' FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('id') . ' IN (' . $placeholders . ')',
            $ids
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

    /**
     * 某用户**收到**的赞总数（个人主页头部数据卡用）
     *
     * 口径：他名下所有未删除帖子的 like_count ＋ 他名下所有未删除楼层的 like_count。
     *
     * 为什么两块都要加：点赞的目标有两种（见 LikeModel::TARGETS）——
     *   - 帖子详情页标题下的「赞」→ target=thread，计在 threads.like_count
     *   - 每个楼层下方的「赞」（含 1 楼）→ target=post，计在 posts.like_count
     * 两个 target 互不重叠（同一个内容只会被其中一种按钮点到），所以直接相加不会重复计。
     *
     * 注意：likes 表里的 user_id 是**点赞的人**，不是被赞的人，
     * 所以「收到的赞」不能查 likes 表，必须按内容作者聚合 like_count。
     * 不缓存到 users 表：点赞分散在两类内容上，维护同步的收益不抵复杂度
     * （每次进个人主页一条聚合查询，有 user_id 索引）。
     */
    public static function receivedLikeCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $liked = 'SELECT COALESCE(SUM(' . Database::identifier('like_count') . '), 0)'
            . ' FROM %s WHERE ' . Database::identifier('user_id') . ' = ?'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL';

        $sql = 'SELECT (' . sprintf($liked, Database::identifier('threads')) . ')'
            . ' + (' . sprintf($liked, Database::identifier('posts')) . ')';

        return (int)Database::value($sql, [$userId, $userId]);
    }

    /**
     * 某用户**未删除**的帖子 ID 列表（批量删账号用）
     *
     * @return list<int>
     */
    public static function threadIdsOf(int $userId): array
    {
        $rows = Database::select(
            'SELECT ' . Database::identifier('id') . ' FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('user_id') . ' = ?'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$userId]
        );

        return array_map(static fn (array $r): int => (int)$r['id'], $rows);
    }

    /**
     * 某用户**未删除**的评论 ID 列表（批量删账号用）
     *
     * 排除 `is_first = 1`：首帖是帖子的正文，删它必须走「删整个帖子」，
     * 否则会留下一个没有正文的空帖子。
     *
     * @return list<int>
     */
    public static function replyIdsOf(int $userId): array
    {
        $rows = Database::select(
            'SELECT ' . Database::identifier('id') . ' FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('user_id') . ' = ?'
            . ' AND ' . Database::identifier('is_first') . ' = 0'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$userId]
        );

        return array_map(static fn (array $r): int => (int)$r['id'], $rows);
    }

    /**
     * 后台按 ID 列表取用户（保留传入顺序）
     */
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
