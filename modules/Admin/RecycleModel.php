<?php
/**
 * 后台：回收站
 *
 * 把「已软删除的帖子 / 回帖 / 用户」放在**同一张列表**里，按删除时间倒序。
 * 合表是刻意的：站长清理时关心的是「我最近删了什么、要不要捞回来」，不是它属于哪张表。
 *
 * ⚠️ 恢复不是简单地把 deleted_at 置空：
 *   · 帖子 / 回帖恢复时必须把当初扣掉的 5 个冗余计数加回去（见 ThreadModel::restore / PostModel::restore）；
 *   · 用户恢复只是把账号捞回来（删账号时没有动过任何计数，所以也不需要补），
 *     **但不会顺带恢复他的内容** —— 那些内容是单独删除的，也在本列表里，要恢复请单独勾选。
 *
 * 搜索范围 = 类型过滤：「帖子 / 回帖 / 用户」各自只列自己那一类并匹配自己的文字字段；
 * 「全部」三类都列。用 `1 = 0` 把没选的分支整体排掉。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Database;
use Modules\Post\PostModel;
use Modules\Thread\ThreadModel;
use Modules\User\UserModel;

final class RecycleModel
{
    /** 搜索范围 = 类型过滤（下拉菜单用） */
    public const SCOPES = [
        'all'    => '全部',
        'user'   => '用户',
        'thread' => '帖子',
        'post'   => '回帖',
    ];

    /** 列表里可能出现的三种内容类型 */
    public const TYPES = [
        'thread' => '帖子',
        'post'   => '回帖',
        'user'   => '用户',
    ];

    /**
     * 已删除内容分页列表
     *
     * @param string $scope all｜user｜thread｜post
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginate(string $keyword, string $scope, int $page, int $perPage = 30): array
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        $q = static fn (string $name): string => Database::identifier($name);

        [$condT, $condP, $condU, $params] = self::searchConditions($keyword, $scope, $q);

        /*
         * 三个分支的列清单**必须完全一致**（UNION 的要求），缺的用常量占位：
         *   帖子没有所属帖子 → thread_id 用 0；回帖与用户没有版块 → forum_id 用 0 / 真实值。
         */
        $threadSql = 'SELECT ' . implode(', ', [
            "'thread' AS " . $q('type'),
            't.' . $q('id') . ' AS ' . $q('id'),
            't.' . $q('user_id') . ' AS ' . $q('user_id'),
            't.' . $q('forum_id') . ' AS ' . $q('forum_id'),
            '0 AS ' . $q('thread_id'),
            't.' . $q('title') . ' AS ' . $q('title'),
            't.' . $q('deleted_at') . ' AS ' . $q('deleted_at'),
            't.' . $q('created_at') . ' AS ' . $q('created_at'),
        ]) . ' FROM ' . $q('threads') . ' t WHERE ' . implode(' AND ', $condT);

        $postSql = 'SELECT ' . implode(', ', [
            "'post' AS " . $q('type'),
            'p.' . $q('id') . ' AS ' . $q('id'),
            'p.' . $q('user_id') . ' AS ' . $q('user_id'),
            'p.' . $q('forum_id') . ' AS ' . $q('forum_id'),
            'p.' . $q('thread_id') . ' AS ' . $q('thread_id'),
            'p.' . $q('content') . ' AS ' . $q('title'),
            'p.' . $q('deleted_at') . ' AS ' . $q('deleted_at'),
            'p.' . $q('created_at') . ' AS ' . $q('created_at'),
        ]) . ' FROM ' . $q('posts') . ' p WHERE ' . implode(' AND ', $condP);

        $userSql = 'SELECT ' . implode(', ', [
            "'user' AS " . $q('type'),
            'u.' . $q('id') . ' AS ' . $q('id'),
            'u.' . $q('id') . ' AS ' . $q('user_id'),
            '0 AS ' . $q('forum_id'),
            '0 AS ' . $q('thread_id'),
            'u.' . $q('username') . ' AS ' . $q('title'),
            'u.' . $q('deleted_at') . ' AS ' . $q('deleted_at'),
            'u.' . $q('created_at') . ' AS ' . $q('created_at'),
        ]) . ' FROM ' . $q('users') . ' u WHERE ' . implode(' AND ', $condU);

        $union = $threadSql . ' UNION ALL ' . $postSql . ' UNION ALL ' . $userSql;

        $total = (int)Database::value('SELECT COUNT(*) FROM (' . $union . ') AS x', $params);

        $offset = ($page - 1) * $perPage;

        $items = Database::select(
            $union . ' ORDER BY ' . $q('deleted_at') . ' DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items'    => self::decorate($items),
            'total'    => $total,
            'page'     => $page,
            'pages'    => (int)max(1, (int)ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    /** 已删除内容条数（帖子 / 回帖 / 用户分别统计） */
    public static function counts(): array
    {
        $q = static fn (string $name): string => Database::identifier($name);

        $threads = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $q('threads') . ' WHERE ' . $q('deleted_at') . ' IS NOT NULL'
        );
        $posts = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $q('posts')
            . ' WHERE ' . $q('deleted_at') . ' IS NOT NULL AND ' . $q('is_first') . ' = 0'
        );
        $users = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $q('users') . ' WHERE ' . $q('deleted_at') . ' IS NOT NULL'
        );

        return [
            'thread' => $threads,
            'post'   => $posts,
            'user'   => $users,
            'total'  => $threads + $posts + $users,
        ];
    }

    /**
     * 恢复一条已删除内容
     */
    public static function restore(string $type, int $id): bool
    {
        return match ($type) {
            'thread' => ThreadModel::restore($id),
            'post'   => PostModel::restore($id),
            'user'   => self::restoreUser($id),
            default  => false,
        };
    }

    /**
     * 彻底删除一条已删除内容（不可恢复）
     */
    public static function purge(string $type, int $id): bool
    {
        return match ($type) {
            'thread' => ThreadModel::purge($id),
            'post'   => PostModel::purge($id),
            'user'   => self::purgeUser($id),
            default  => false,
        };
    }

    /**
     * 解析批量提交过来的条目
     *
     * 前端每条复选框的 value 形如 `thread:12` / `post:34` / `user:5` ——
     * 三张表的主键各自从 1 开始，光看 ID 分不清是什么，必须带上类型。
     *
     * @param array<mixed> $raw
     * @return list<array{type:string,id:int}>
     */
    public static function parseItems(array $raw): array
    {
        $items = [];

        foreach ($raw as $value) {
            $value = (string)$value;

            if (!str_contains($value, ':')) {
                continue;
            }

            [$type, $id] = explode(':', $value, 2);

            if (!isset(self::TYPES[$type]) || (int)$id <= 0) {
                continue;
            }

            $items[] = ['type' => $type, 'id' => (int)$id];
        }

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 恢复用户账号
     *
     * 删账号（软删）时**没有**动过任何计数 —— 他的帖子 / 评论如果还在，
     * 版块与其它用户的计数本来就没被扣过；所以恢复只需要把行捞回来。
     *
     * ⚠️ 不会顺带恢复他的内容：那些是单独删除的条目，也在本列表里，请单独勾选恢复。
     */
    private static function restoreUser(int $userId): bool
    {
        $row = Database::first(
            'SELECT ' . Database::identifier('id') . ', ' . Database::identifier('deleted_at')
            . ' FROM ' . Database::identifier('users') . ' WHERE ' . Database::identifier('id') . ' = ?',
            [$userId]
        );

        if ($row === null || $row['deleted_at'] === null) {
            return false;
        }

        return Database::update(
            'users',
            ['deleted_at' => null, 'updated_at' => time()],
            Database::identifier('id') . ' = ?',
            [$userId]
        ) > 0;
    }

    /**
     * 彻底删除用户账号（硬删行）
     *
     * 只删账号这一行，**不动他的内容**：还留在站里的帖子 / 评论会继续存在，
     * 作者名显示「用户已删除」。这与其他程序「注销即抹除」的做法不同 ——
     * 内容里可能有别人的回复引用它，硬删内容是另一个需要单独确认的动作。
     */
    private static function purgeUser(int $userId): bool
    {
        $row = Database::first(
            'SELECT ' . Database::identifier('deleted_at')
            . ' FROM ' . Database::identifier('users') . ' WHERE ' . Database::identifier('id') . ' = ?',
            [$userId]
        );

        if ($row === null || $row['deleted_at'] === null) {
            return false;
        }

        Database::delete('users', Database::identifier('id') . ' = ?', [$userId]);
        UserModel::flushRowCache();

        return true;
    }

    /**
     * 生成三个 UNION 分支的 WHERE 条件
     *
     * 「用户 / 帖子 / 回帖」是**类型过滤**，不只是换匹配字段 ——
     * 选了「帖子」就只列已删的帖子。没选的分支用 `1 = 0` 整体排掉。
     *
     * @return array{0:list<string>,1:list<string>,2:list<string>,3:list<mixed>}
     */
    private static function searchConditions(string $keyword, string $scope, callable $q): array
    {
        $condT = ['t.' . $q('deleted_at') . ' IS NOT NULL'];
        $condP = ['p.' . $q('deleted_at') . ' IS NOT NULL', 'p.' . $q('is_first') . ' = 0'];
        $condU = ['u.' . $q('deleted_at') . ' IS NOT NULL'];
        $params = [];

        // 类型过滤（与有没有关键词无关）
        if ($scope === 'thread') {
            $condP[] = '1 = 0';
            $condU[] = '1 = 0';
        } elseif ($scope === 'post') {
            $condT[] = '1 = 0';
            $condU[] = '1 = 0';
        } elseif ($scope === 'user') {
            $condT[] = '1 = 0';
            $condP[] = '1 = 0';
        }

        if ($keyword === '') {
            return [$condT, $condP, $condU, $params];
        }

        $bind = Database::likePattern($keyword);
        $like = Database::likeOperator();
        $esc  = Database::likeEscapeClause();

        if ($scope === 'thread') {
            $condT[] = 't.' . $q('title') . ' ' . $like . ' ?' . $esc;
            $params[] = $bind;

            return [$condT, $condP, $condU, $params];
        }

        if ($scope === 'post') {
            $condP[] = 'p.' . $q('content') . ' ' . $like . ' ?' . $esc;
            $params[] = $bind;

            return [$condT, $condP, $condU, $params];
        }

        if ($scope === 'user') {
            $condU[] = 'u.' . $q('username') . ' ' . $like . ' ?' . $esc;
            $params[] = $bind;

            return [$condT, $condP, $condU, $params];
        }

        // 全部：三类各自的文字字段
        $condT[] = 't.' . $q('title') . ' ' . $like . ' ?' . $esc;
        $condP[] = 'p.' . $q('content') . ' ' . $like . ' ?' . $esc;
        $condU[] = 'u.' . $q('username') . ' ' . $like . ' ?' . $esc;
        array_push($params, $bind, $bind, $bind);

        return [$condT, $condP, $condU, $params];
    }

    /**
     * 补齐展示字段（作者 / 版块 / 所属帖子）
     *
     * 全部用**含已删**的查询：被删的内容，它的作者和所属帖子很可能也已被删，
     * 用普通查询会查不到而显示成空白。
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function decorate(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        $q = static fn (string $name): string => Database::identifier($name);

        $userIds = $threadIds = $forumIds = [];

        foreach ($items as $item) {
            $userIds[] = (int)$item['user_id'];
            $forumIds[] = (int)$item['forum_id'];

            if ((string)$item['type'] === 'post' && (int)($item['thread_id'] ?? 0) > 0) {
                $threadIds[] = (int)$item['thread_id'];
            }
        }

        $users   = self::mapByIds($q('users'), $userIds, ['id', 'username', 'email', 'group_id']);
        $forums  = self::mapByIds($q('forums'), $forumIds, ['id', 'name']);
        $threads = self::mapByIds($q('threads'), $threadIds, ['id', 'title']);

        foreach ($items as &$item) {
            $type    = (string)$item['type'];
            $userId  = (int)$item['user_id'];
            $isUser  = $type === 'user';

            $item['author_name']  = $isUser ? '' : (string)($users[$userId]['username'] ?? '用户已删除');
            $item['forum_name']   = $isUser ? '—' : (string)($forums[(int)$item['forum_id']]['name'] ?? '未知版块');
            $item['type_label']   = self::TYPES[$type] ?? '内容';
            $item['raw_title']    = (string)($item['title'] ?? '');
            $item['thread_title'] = $type === 'post'
                ? (string)($threads[(int)($item['thread_id'] ?? 0)]['title'] ?? '帖子已删除')
                : '';
            /* 用户行额外带出邮箱，方便确认要恢复的是哪个账号 */
            $item['email']   = $isUser ? (string)($users[$userId]['email'] ?? '') : '';
            $item['excerpt'] = \Core\Text::excerpt($item['raw_title'], 90);
        }
        unset($item);

        return $items;
    }

    /**
     * 按 ID 取行（**含已软删**，与 Model::query() 不同）
     *
     * @param list<int>    $ids
     * @param list<string> $columns
     * @return array<int, array<string,mixed>>
     */
    private static function mapByIds(string $table, array $ids, array $columns): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $cols  = implode(', ', array_map([Database::class, 'identifier'], $columns));
        $marks = implode(',', array_fill(0, count($ids), '?'));

        $map = [];

        foreach (Database::select(
            'SELECT ' . $cols . ' FROM ' . $table . ' WHERE ' . Database::identifier('id') . ' IN (' . $marks . ')',
            $ids
        ) as $row) {
            $map[(int)$row['id']] = $row;
        }

        return $map;
    }
}
