<?php
/**
 * 版块模型
 *
 * 首页需要一次性展示所有版块及其统计信息，因此这里提供「一次查询、进程内缓存」的读取方式，
 * 避免每个版块都单独查一次库。
 */

declare(strict_types=1);

namespace Modules\Forum;

use Core\Cache;
use Core\Database;
use Core\Model;
use Core\Permission;

final class ForumModel extends Model
{
    protected static string $table = 'forums';

    /** @var array<int, array<string, mixed>>|null 进程内全量缓存 */
    private static ?array $all = null;

    /**
     * 全部版块（含已隐藏，按排序）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $rows = Cache::remember('forums:all', (int)config('app.cache_ttl', 300), static function (): array {
            return Database::select(
                'SELECT * FROM ' . Database::identifier('forums')
                . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
                . ' ORDER BY ' . Database::identifier('parent_id') . ' ASC, '
                . Database::identifier('sort_order') . ' ASC, ' . Database::identifier('id') . ' ASC'
            );
        });

        $result = [];
        foreach ((array)$rows as $row) {
            $result[(int)$row['id']] = $row;
        }

        self::$all = $result;

        return self::$all;
    }

    /** 按 ID 获取版块 */
    public static function find(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * 取版块，不存在则 404
     *
     * @return array<string, mixed>
     */
    public static function findOrFail(int $id): array
    {
        $forum = self::find($id);

        if ($forum === null) {
            \Core\App::abort(404, '版块不存在或已被删除。');
        }

        return $forum;
    }

    /**
     * 可见版块列表（按权限过滤，仅返回 status=1 的）
     *
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public static function visible(?array $user, bool $includeHidden = false): array
    {
        $result = [];

        foreach (self::all() as $forum) {
            if (!$includeHidden && (int)$forum['status'] !== 1) {
                continue;
            }

            if (!Permission::canViewForum($user, $forum)) {
                continue;
            }

            $result[] = $forum;
        }

        return $result;
    }

    /**
     * 构建版块树（一级版块 + 子版块）
     *
     * @param list<array<string, mixed>> $forums
     * @return list<array{forum:array<string,mixed>, children:list<array<string,mixed>>}>
     */
    public static function tree(array $forums): array
    {
        $byParent = [];
        foreach ($forums as $forum) {
            $byParent[(int)$forum['parent_id']][] = $forum;
        }

        $tree = [];
        foreach ($byParent[0] ?? [] as $root) {
            $tree[] = [
                'forum'    => $root,
                'children' => $byParent[(int)$root['id']] ?? [],
            ];
        }

        // 没有归属父级的版块（父级被删）也展示出来，避免内容「消失」
        foreach ($byParent as $parentId => $children) {
            if ($parentId === 0) {
                continue;
            }
            foreach ($children as $orphan) {
                $parentExists = false;
                foreach ($forums as $forum) {
                    if ((int)$forum['id'] === $parentId) {
                        $parentExists = true;
                        break;
                    }
                }
                if (!$parentExists) {
                    $tree[] = ['forum' => $orphan, 'children' => []];
                }
            }
        }

        return $tree;
    }

    /**
     * 为一批版块补齐「最后发表」
     *
     * forums 表上的 last_thread_id / last_thread_name / last_reply_at 是**冗余缓存**，
     * 历史上出现过三种脏数据，症状都是「点最后发表跳到了别的帖子」：
     *   1. 发表评论时只更新了 last_thread_id，没更新 last_thread_name
     *      → 文字是旧帖标题、链接指向新帖；
     *   2. 帖子被软删除后没重算 → 链接指向已删除的帖子；
     *   3. 直接写库/导入留下的空值 → 版块明明有帖子却显示不出「最后发表」。
     * 所以首页展示时**不信任这三列**，直接按帖子表实时算最新的一条；
     * 三列仍由写入方维护，作为其它消费方的快捷缓存。
     *
     * @param list<array<string, mixed>> $forums
     * @return list<array<string, mixed>>
     */
    public static function withLastThread(array $forums): array
    {
        if ($forums === []) {
            return [];
        }

        $forumIds = [];
        foreach ($forums as $forum) {
            $id = (int)($forum['id'] ?? 0);

            if ($id > 0) {
                $forumIds[$id] = $id;
            }
        }

        if ($forumIds === []) {
            return $forums;
        }

        $forumIds = array_values($forumIds);

        // 一次查出这些版块下所有可见帖子，按最后评论时间倒序；每条取到的第一条即最新
        $rows = Database::select(
            'SELECT ' . Database::identifier('forum_id') . ', ' . Database::identifier('id') . ', '
            . Database::identifier('title') . ', ' . Database::identifier('last_reply_at')
            . ' FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('status') . ' = 1'
            . ' AND ' . Database::identifier('forum_id') . ' IN (' . Database::placeholders(count($forumIds)) . ')'
            . ' ORDER BY ' . Database::identifier('last_reply_at') . ' DESC, ' . Database::identifier('id') . ' DESC',
            $forumIds
        );

        $latest = [];
        foreach ((array)$rows as $row) {
            $forumId = (int)$row['forum_id'];

            if (!isset($latest[$forumId])) {
                $latest[$forumId] = $row;
            }
        }

        foreach ($forums as $index => $forum) {
            $row = $latest[(int)($forum['id'] ?? 0)] ?? null;

            $forums[$index]['last_thread_id']   = $row === null ? 0 : (int)$row['id'];
            $forums[$index]['last_thread_name'] = $row === null ? '' : (string)$row['title'];
            $forums[$index]['last_reply_at']    = $row === null ? 0 : (int)$row['last_reply_at'];
        }

        return $forums;
    }

    /**
     * 重算某个版块的「最后发表」三列
     *
     * 帖子被软删除、版块内帖子全部删除等场景调用 —— 否则这三列会一直指着
     * 已经删掉的帖子，首页点「最后发表」就跳到一条不存在的帖子。
     * 没有可见帖子时三列清空（0 / '' / 0）。
     */
    public static function refreshLastThread(int $forumId): void
    {
        if ($forumId <= 0) {
            return;
        }

        $row = Database::first(
            'SELECT ' . Database::identifier('id') . ', ' . Database::identifier('title') . ', '
            . Database::identifier('last_reply_at')
            . ' FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('forum_id') . ' = ?'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('status') . ' = 1'
            . ' ORDER BY ' . Database::identifier('last_reply_at') . ' DESC, ' . Database::identifier('id') . ' DESC'
            . ' LIMIT 1',
            [$forumId]
        );

        Database::update(
            'forums',
            [
                'last_thread_id'   => $row === null ? 0 : (int)$row['id'],
                'last_thread_name' => $row === null ? '' : (string)$row['title'],
                'last_reply_at'    => $row === null ? 0 : (int)$row['last_reply_at'],
            ],
            Database::identifier('id') . ' = ?',
            [$forumId]
        );

        self::flush();
    }

    /**
     * 版块下拉选项
     *
     * @return array<int, string>
     */
    public static function options(bool $includeHidden = true): array
    {
        $options = [];

        foreach (self::all() as $id => $forum) {
            if (!$includeHidden && (int)$forum['status'] !== 1) {
                continue;
            }

            $prefix = (int)$forum['parent_id'] > 0 ? '　└ ' : '';
            $options[$id] = $prefix . (string)$forum['name'];
        }

        return $options;
    }

    /**
     * 新增版块
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool, message:string, id:int}
     */
    public static function createForum(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'message' => '请填写版块名称。', 'id' => 0];
        }

        $data = self::buildData($input);
        $data['name'] = mb_substr($name, 0, 60);

        $id = static::create($data);

        self::flush();

        return ['ok' => true, 'message' => '版块已创建。', 'id' => $id];
    }

    /**
     * 更新版块
     *
     * @param array<string, mixed> $input
     */
    public static function updateForum(int $id, array $input): array
    {
        $forum = self::find($id);

        if ($forum === null) {
            return ['ok' => false, 'message' => '版块不存在。'];
        }

        $data = self::buildData($input, $forum);

        if ($data === []) {
            return ['ok' => false, 'message' => '没有需要更新的内容。'];
        }

        static::updateById($id, $data);
        self::flush();

        return ['ok' => true, 'message' => '版块已更新。'];
    }

    /**
     * 删除版块（软删除；若仍有帖子则拒绝，避免内容变成孤儿）
     */
    public static function deleteForum(int $id): array
    {
        $forum = self::find($id);

        if ($forum === null) {
            return ['ok' => false, 'message' => '版块不存在。'];
        }

        $threads = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('forum_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$id]
        );

        if ($threads > 0) {
            return ['ok' => false, 'message' => '该版块下还有 ' . $threads . ' 个帖子，请先移动或删除后再试。'];
        }

        $children = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('forums')
            . ' WHERE ' . Database::identifier('parent_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$id]
        );

        if ($children > 0) {
            return ['ok' => false, 'message' => '请先删除该版块下的子版块。'];
        }

        static::deleteById($id);
        self::flush();

        return ['ok' => true, 'message' => '版块已删除。'];
    }

    /**
     * 从表单构建版块字段
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private static function buildData(array $input, ?array $current = null): array
    {
        $data = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                return [];
            }
            $data['name'] = mb_substr($name, 0, 60);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = mb_substr(trim((string)$input['description']), 0, 200);
        }

        if (array_key_exists('announcement', $input)) {
            $data['announcement'] = mb_substr(trim((string)$input['announcement']), 0, 1000);
        }

        if (array_key_exists('icon', $input)) {
            $data['icon'] = mb_substr(trim((string)$input['icon']), 0, 40);
        }

        if (array_key_exists('slug', $input)) {
            $slug = strtolower(trim((string)$input['slug']));
            $data['slug'] = preg_match('/^[a-z0-9_\-]{0,60}$/', $slug) === 1 ? $slug : '';
        }

        if (array_key_exists('parent_id', $input)) {
            $parentId = (int)$input['parent_id'];
            // 不允许把自己设为自己的父级
            if ($current !== null && $parentId === (int)$current['id']) {
                $parentId = 0;
            }
            $data['parent_id'] = $parentId > 0 ? $parentId : 0;
        }

        if (array_key_exists('sort_order', $input)) {
            $data['sort_order'] = (int)$input['sort_order'];
        }

        foreach (['status', 'allow_thread', 'allow_reply', 'allow_attachment'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $data[$flag] = (int)(bool)$input[$flag];
            }
        }

        // 用户组白名单字段：空数组表示不限制
        foreach (['group_view', 'group_thread', 'group_reply', 'moderators'] as $field) {
            if (array_key_exists($field, $input)) {
                $ids = is_array($input[$field]) ? $input[$field] : explode(',', (string)$input[$field]);
                $clean = [];
                foreach ($ids as $id) {
                    if (is_scalar($id) && preg_match('/^\d+$/', (string)$id)) {
                        $clean[] = (int)$id;
                    }
                }
                $data[$field] = implode(',', array_values(array_unique($clean)));
            }
        }

        return $data;
    }

    /**
     * 版块统计（首页汇总卡片）
     */
    public static function stats(): array
    {
        $forumRow = Database::first(
            'SELECT COUNT(*) AS ' . Database::identifier('total')
            . ' FROM ' . Database::identifier('forums')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 1'
        );

        $threadRow = Database::first(
            'SELECT COUNT(*) AS ' . Database::identifier('total')
            . ' FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 1'
        );

        $postRow = Database::first(
            'SELECT COUNT(*) AS ' . Database::identifier('total')
            . ' FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 1'
        );

        return [
            'forums'  => (int)($forumRow['total'] ?? 0),
            'threads' => (int)($threadRow['total'] ?? 0),
            'posts'   => (int)($postRow['total'] ?? 0),
        ];
    }

    /** 清除版块缓存 */
    public static function flush(): void
    {
        self::$all = null;
        Cache::forget('forums:all');
        Model::flushRowCache();
    }

    /**
     * 以「全量目标集合」设置某用户的版主身份（后台用户编辑页入口）
     *
     * $forumIds 里的版块补上该用户，其余版块一律移除；逐个版块比对旧值，
     * 只写发生变化的行 —— 与 Permission::isModerator 读取的 forums.moderators 直接对应。
     *
     * @return int 发生变化的版块数
     */
    public static function setModeratorForUser(int $userId, array $forumIds): int
    {
        $userId   = max(0, $userId);
        $wanted   = array_values(array_unique(array_filter(array_map('intval', $forumIds))));
        $now      = time();
        $changed  = 0;

        /*
         * 版主设置会写两张表：forums.moderators（可能多行）+ users.group_id（版主组 ↔ 注册组）。
         * 两处必须一致 —— 版块记住他是版主、用户组却没同步（或反过来）会直接影响权限判定，
         * 所以整段放进事务。
         */
        return Database::transaction(static function () use ($userId, $wanted, $now): int {
            $rows = Database::select(
                'SELECT ' . Database::identifier('id') . ', ' . Database::identifier('moderators')
                . ' FROM ' . Database::identifier('forums')
            );

            foreach ($rows as $row) {
                $forumId = (int)$row['id'];
                $current = group_ids_from_field((string)$row['moderators']);
                $has     = in_array($userId, $current, true);
                $want    = in_array($forumId, $wanted, true);

                if ($has === $want) {
                    continue;
                }

                $next = $want
                    ? array_values(array_unique(array_merge($current, [$userId])))
                    : array_values(array_diff($current, [$userId]));

                Database::update(
                    'forums',
                    ['moderators' => implode(',', $next), 'updated_at' => $now],
                    Database::identifier('id') . ' = ?',
                    [$forumId]
                );
                $changed++;
            }

            if ($changed > 0) {
                self::flush();
            }

            /*
             * 用户组同步：担任任一版块版主 → 进入「版主」组（前台组名/身份展示一致）；
             * 不再担任任何版块版主 → 回到「注册用户」组。
             * 只在「版主组 ↔ 注册用户组」之间自动切换，管理员/超管/自定义组不动。
             */
            $user = Database::first(
                'SELECT ' . Database::identifier('group_id')
                . ' FROM ' . Database::identifier('users')
                . ' WHERE ' . Database::identifier('id') . ' = ?'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
                [$userId]
            );

            if ($user !== null) {
                $currentGroup = (int)$user['group_id'];
                $autoSwitchable = [\Core\Permission::MEMBER_GROUP, \Core\Permission::MODERATOR_GROUP];
                if (in_array($currentGroup, $autoSwitchable, true)) {
                    $targetGroup = $wanted === [] ? \Core\Permission::MEMBER_GROUP : \Core\Permission::MODERATOR_GROUP;
                    if ($targetGroup !== $currentGroup) {
                        Database::update(
                            'users',
                            ['group_id' => $targetGroup, 'updated_at' => $now],
                            Database::identifier('id') . ' = ?',
                            [$userId]
                        );
                        Model::flushRowCache();
                    }
                }
            }

        return $changed;
        });
    }
}
