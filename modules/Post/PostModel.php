<?php
/**
 * 评论模型
 *
 * 楼层号 floor 在同一帖子内唯一，通过「事务内取 MAX(floor)+1」保证不会重复。
 */

declare(strict_types=1);

namespace Modules\Post;

use Core\Database;
use Core\Model;
use Core\Text;

final class PostModel extends Model
{
    protected static string $table = 'posts';

    /**
     * 帖子内评论分页（不含首帖）
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateInThread(int $threadId, int $page, int $perPage = 15, int $status = 1): array
    {
        return static::query()
            ->where('thread_id', $threadId)
            ->where('is_first', 0)
            ->where('status', $status)
            ->orderBy('floor', 'asc')
            ->paginate($perPage, $page);
    }

    /**
     * 取帖子的首帖
     *
     * @return array<string, mixed>|null
     */
    public static function firstPost(int $threadId): ?array
    {
        return static::query()
            ->where('thread_id', $threadId)
            ->where('is_first', 1)
            ->first();
    }

    /**
     * 发表评论
     *
     * @param array<int, int> $attachmentIds
     * @return array{post_id:int, floor:int}
     */
    public static function reply(
        array $thread,
        int $userId,
        string $content,
        int $parentId = 0,
        array $attachmentIds = [],
        int $status = 1
    ): array {
        return Database::transaction(static function () use ($thread, $userId, $content, $parentId, $attachmentIds, $status): array {
            $threadId = (int)$thread['id'];
            $now      = time();

            // 事务内取楼层号，配合 (thread_id, floor) 索引保证顺序稳定
            $maxFloor = (int)Database::value(
                'SELECT COALESCE(MAX(' . Database::identifier('floor') . '), 1) FROM ' . Database::identifier('posts')
                . ' WHERE ' . Database::identifier('thread_id') . ' = ?',
                [$threadId]
            );

            $floor = $maxFloor + 1;

            $postId = Database::insert('posts', [
                'thread_id'    => $threadId,
                'forum_id'     => (int)$thread['forum_id'],
                'user_id'      => $userId,
                'parent_id'    => $parentId,
                'floor'        => $floor,
                'is_first'     => 0,
                'content'      => $content,
                'content_html' => Text::toHtml($content),
                'like_count'   => 0,
                'status'       => $status,
                'ip'           => \Core\Request::ip(),
                'device'       => \Core\Request::device(),
                'user_agent'   => \Core\Request::userAgent(),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            if ($attachmentIds !== []) {
                \Modules\User\AttachmentModel::bindToPost($attachmentIds, $userId, $threadId, $postId);
            }

            // 只有审核通过的评论才计入统计，避免待审核内容影响展示
            if ($status === 1) {
                Database::execute(
                    'UPDATE ' . Database::identifier('threads')
                    . ' SET ' . implode(', ', [
                        Database::identifier('reply_count') . ' = ' . Database::identifier('reply_count') . ' + 1',
                        Database::identifier('last_reply_at') . ' = ?',
                        Database::identifier('last_reply_user_id') . ' = ?',
                        Database::identifier('updated_at') . ' = ?',
                    ])
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, $userId, $now, $threadId]
                );

                /*
                 * 版块「最后发表」同步。
                 * ⚠️ last_thread_name 必须跟着 last_thread_id 一起写：只写 id 会让
                 * 冗余列自相矛盾 —— 首页显示的标题还是上一条帖子的，链接却指向新帖，
                 * 点进去就是「另一篇文章」（这个 bug 已经出现过一次）。
                 */
                Database::execute(
                    'UPDATE ' . Database::identifier('forums')
                    . ' SET ' . Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1, '
                    . Database::identifier('last_reply_at') . ' = ?, '
                    . Database::identifier('last_thread_id') . ' = ?, '
                    . Database::identifier('last_thread_name') . ' = ?, '
                    . Database::identifier('updated_at') . ' = ?'
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, $threadId, (string)($thread['title'] ?? ''), $now, (int)$thread['forum_id']]
                );

                /*
                 * 作者的评论数 +1 —— **必须待审核通过之后才算**，所以放在这个 if 里面。
                 *
                 * ⚠️ 原来这行写在 if 外面（待审核也先 +1），而 PostModel::approve 通过时又 +1，
                 *    结果一条「待审核 → 通过」的评论会让作者评论数 +2（帖子/版块只 +1）；
                 *    若那条待审核评论被直接删除，PostModel::destroy 又只在 status===1 时回滚，
                 *    那 +1 就永久留在作者头上。
                 *    两条路都会漂，2026-09-19 用 .tools/ab-test-post-audit.php 复现并修掉。
                 */
                Database::execute(
                    'UPDATE ' . Database::identifier('users')
                    . ' SET ' . Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1, '
                    . Database::identifier('updated_at') . ' = ?'
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, $userId]
                );
            }

            Model::flushRowCache();
            \Modules\Forum\ForumModel::flush();
            \Modules\Thread\ThreadModel::flush();

            return ['post_id' => $postId, 'floor' => $floor];
        });
    }

    /**
     * 为评论列表补齐作者信息与附件
     *
     * @param list<array<string, mixed>> $posts
     * @return list<array<string, mixed>>
     */
    public static function decorate(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $userIds = array_map(static fn (array $p): int => (int)$p['user_id'], $posts);
        $postIds = array_map(static fn (array $p): int => (int)$p['id'], $posts);

        $users      = \Modules\User\UserModel::mapByIds($userIds);
        $attachments = \Modules\User\AttachmentModel::mapByPosts($postIds);
        $groups     = \Modules\User\UsergroupModel::all();

        foreach ($posts as &$post) {
            $author = $users[(int)$post['user_id']] ?? [
                'id'       => 0,
                'username' => '用户已删除',
                'avatar'   => '',
                'group_id' => \Core\Permission::GUEST_GROUP,
            ];

            $groupId = (int)($author['group_id'] ?? \Core\Permission::GUEST_GROUP);

            $post['author']            = $author;
            $post['author_group_name']  = (string)($groups[$groupId]['name'] ?? '游客');
            $post['author_group_color'] = (string)($groups[$groupId]['color'] ?? '#999999');
            $post['attachments']        = $attachments[(int)$post['id']] ?? [];
        }
        unset($post);

        return $posts;
    }

    /**
     * 取帖子及其所属帖子与版块（用于权限判定）
     *
     * @return array{post:array<string,mixed>, thread:array<string,mixed>, forum:array<string,mixed>}|null
     */
    public static function withContext(int $postId): ?array
    {
        $post = static::find($postId);

        if ($post === null) {
            return null;
        }

        $thread = \Modules\Thread\ThreadModel::find((int)$post['thread_id']);

        if ($thread === null) {
            return null;
        }

        $forum = \Modules\Forum\ForumModel::find((int)$thread['forum_id']);

        if ($forum === null) {
            return null;
        }

        return ['post' => $post, 'thread' => $thread, 'forum' => $forum];
    }

    /**
     * 软删除评论，并同步计数
     */
    public static function destroy(int $postId): bool
    {
        $post = static::find($postId);

        if ($post === null) {
            return false;
        }

        // 首帖不允许单独删除，应删除整个帖子
        if ((int)$post['is_first'] === 1) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($post, $postId): bool {
            $now = time();

            static::deleteById($postId);

            if ((int)$post['status'] === 1) {
                Database::execute(
                    'UPDATE ' . Database::identifier('threads')
                    . ' SET ' . Database::identifier('reply_count') . ' = CASE WHEN ' . Database::identifier('reply_count') . ' > 0 THEN ' . Database::identifier('reply_count') . ' - 1 ELSE 0 END, '
                    . Database::identifier('updated_at') . ' = ?'
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, (int)$post['thread_id']]
                );

                Database::execute(
                    'UPDATE ' . Database::identifier('forums')
                    . ' SET ' . Database::identifier('post_count') . ' = CASE WHEN ' . Database::identifier('post_count') . ' > 0 THEN ' . Database::identifier('post_count') . ' - 1 ELSE 0 END, '
                    . Database::identifier('updated_at') . ' = ?'
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, (int)$post['forum_id']]
                );

                Database::execute(
                    'UPDATE ' . Database::identifier('users')
                    . ' SET ' . Database::identifier('post_count') . ' = CASE WHEN ' . Database::identifier('post_count') . ' > 0 THEN ' . Database::identifier('post_count') . ' - 1 ELSE 0 END, '
                    . Database::identifier('updated_at') . ' = ?'
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, (int)$post['user_id']]
                );
            }

            Model::flushRowCache();
            \Modules\Forum\ForumModel::flush();

            return true;
        });
    }

    /** 后台搜索范围（下拉菜单用） */
    public const ADMIN_SCOPES = [
        'content' => '回帖内容',
        'user'    => '作者',
        'id'      => '回帖Id',
        'thread'  => '帖子Id',
    ];

    /**
     * 后台评论列表（支持搜索范围）
     *
     * @param string $scope content=评论内容｜user=作者名｜id=评论 ID｜thread=所属帖子 ID
     */
    public static function adminPaginate(
        string $keyword,
        int $status,
        int $page,
        int $perPage = 20,
        string $scope = 'content'
    ): array {
        $query = static::query();

        if ($keyword !== '') {
            self::applyAdminSearch($query, $keyword, $scope);
        }

        if ($status !== -1) {
            $query->where('status', $status);
        }

        return $query->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /** 按范围给后台查询加搜索条件（用 EXISTS 而非 JOIN，理由见 ThreadModel::applyAdminSearch） */
    private static function applyAdminSearch(\Core\Query $query, string $keyword, string $scope): void
    {
        $q    = static fn (string $name): string => Database::identifier($name);
        $like = Database::likeOperator();
        $esc  = Database::likeEscapeClause();

        if ($scope === 'id') {
            /* 回帖 ID 精确匹配 */
            $query->where('id', '=', (int)$keyword);

            return;
        }

        if ($scope === 'thread') {
            /* 帖子 ID 精确匹配：列出该帖下的楼层 */
            $query->where('thread_id', '=', (int)$keyword);

            return;
        }

        if ($scope === 'user') {
            $query->whereRaw(
                'EXISTS (SELECT 1 FROM ' . $q('users') . ' u WHERE u.' . $q('id') . ' = '
                . $q('posts') . '.' . $q('user_id')
                . ' AND u.' . $q('username') . ' ' . $like . ' ?' . $esc . ')',
                [Database::likePattern($keyword)]
            );

            return;
        }

        $query->whereContains('content', $keyword);
    }

    /**
     * 从回收站恢复评论 —— destroy() 的**严格逆操作**
     *
     * 首帖不能单独恢复（它跟着帖子走，恢复帖子时一起回来）；
     * 计数与 destroy() 对称：只有「已通过」的评论才需要把三处计数加回去。
     */
    public static function restore(int $postId): bool
    {
        $post = static::withTrashed()->where('id', '=', $postId)->first();

        if ($post === null || (int)$post['is_first'] === 1 || $post['deleted_at'] === null) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($post, $postId): bool {
            $now = time();
            $q   = static fn (string $name): string => Database::identifier($name);

            Database::update(
                'posts',
                ['deleted_at' => null, 'updated_at' => $now],
                $q('id') . ' = ?',
                [$postId]
            );

            if ((int)$post['status'] === 1) {
                Database::execute(
                    'UPDATE ' . $q('threads') . ' SET ' . $q('reply_count') . ' = ' . $q('reply_count') . ' + 1, '
                    . $q('updated_at') . ' = ? WHERE ' . $q('id') . ' = ?',
                    [$now, (int)$post['thread_id']]
                );

                Database::execute(
                    'UPDATE ' . $q('forums') . ' SET ' . $q('post_count') . ' = ' . $q('post_count') . ' + 1, '
                    . $q('updated_at') . ' = ? WHERE ' . $q('id') . ' = ?',
                    [$now, (int)$post['forum_id']]
                );

                Database::execute(
                    'UPDATE ' . $q('users') . ' SET ' . $q('post_count') . ' = ' . $q('post_count') . ' + 1, '
                    . $q('updated_at') . ' = ? WHERE ' . $q('id') . ' = ?',
                    [$now, (int)$post['user_id']]
                );
            }

            Model::flushRowCache();
            \Modules\Forum\ForumModel::flush();

            return true;
        });
    }

    /**
     * 彻底删除评论（从回收站清除，不可恢复）
     *
     * 硬删行 + 它上面的附件；计数不再变动（软删那一步已经减过）。
     */
    public static function purge(int $postId): bool
    {
        $post = static::withTrashed()->where('id', '=', $postId)->first();

        if ($post === null || $post['deleted_at'] === null) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($postId): bool {
            \Modules\User\AttachmentModel::purgeForPost($postId);
            Database::delete('posts', Database::identifier('id') . ' = ?', [$postId]);
            Model::flushRowCache();

            return true;
        });
    }

    /**
     * 更新评论内容（同时刷新渲染缓存）
     */
    public static function updateContent(int $postId, string $content): void
    {
        static::updateById($postId, [
            'content'      => $content,
            'content_html' => Text::toHtml($content),
        ]);
    }

    /**
     * 审核通过 / 驳回
     */
    public static function setStatus(int $postId, int $status): void
    {
        static::updateById($postId, ['status' => $status]);
    }

    /**
     * 审核通过一条待审核内容
     *
     * 计数规则要与 PostModel::reply 保持一致：
     *  - 非首帖通过审核时才把评论计入帖子 reply_count 与版块 post_count
     *    （等待审核的评论在发表时并没有计入）
     *  - 首帖通过审核时把所属帖子一并放行，且不重复计数
     *
     * @return array{ok:bool, message:string, thread_id:int, user_id:int, is_thread:bool}
     */
    public static function approve(int $postId): array
    {
        $post = static::find($postId);

        if ($post === null) {
            return ['ok' => false, 'message' => '内容不存在。', 'thread_id' => 0, 'user_id' => 0, 'is_thread' => false];
        }

        if ((int)$post['status'] === 1) {
            return [
                'ok'        => false,
                'message'   => '该内容已是审核通过状态。',
                'thread_id' => (int)$post['thread_id'],
                'user_id'   => (int)$post['user_id'],
                'is_thread' => (int)$post['is_first'] === 1,
            ];
        }

        $threadId  = (int)$post['thread_id'];
        $isFirst   = (int)$post['is_first'] === 1;

        Database::transaction(static function () use ($post, $postId, $threadId, $isFirst): void {
            $now = time();

            static::updateById($postId, ['status' => 1]);

            if ($isFirst) {
                // 首帖放行时帖子随之通过
                Database::execute(
                    'UPDATE ' . Database::identifier('threads')
                    . ' SET ' . Database::identifier('status') . ' = 1, ' . Database::identifier('updated_at') . ' = ?'
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, $threadId]
                );

                return;
            }

            Database::execute(
                'UPDATE ' . Database::identifier('threads')
                . ' SET ' . Database::identifier('reply_count') . ' = ' . Database::identifier('reply_count') . ' + 1, '
                . Database::identifier('last_reply_at') . ' = ?, '
                . Database::identifier('last_reply_user_id') . ' = ?, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$now, (int)$post['user_id'], $now, $threadId]
            );

            Database::execute(
                'UPDATE ' . Database::identifier('forums')
                . ' SET ' . Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$now, (int)$post['forum_id']]
            );

            /*
             * 作者的评论数也要 +1 —— reply() 里是加的，审核通过这条路径原来漏了，
             * 于是「审核通过」与「直接发表」两条路的计数口径不一致。
             */
            Database::execute(
                'UPDATE ' . Database::identifier('users')
                . ' SET ' . Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$now, (int)$post['user_id']]
            );
        });

        Model::flushRowCache();
        \Modules\Forum\ForumModel::flush();
        \Modules\Thread\ThreadModel::flush();

        return [
            'ok'        => true,
            'message'   => $isFirst ? '帖子已通过审核。' : '评论已通过审核。',
            'thread_id' => $threadId,
            'user_id'   => (int)$post['user_id'],
            'is_thread' => $isFirst,
        ];
    }

    /** 某用户发表的评论 */
    public static function byUser(int $userId, int $page, int $perPage = 20): array
    {
        // 排除首帖，只展示「评论」
        return static::query()
            ->where('user_id', $userId)
            ->where('is_first', 0)
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);
    }

    /** 评论总数 */
    public static function totalCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        );
    }

    /** 待审核评论数量 */
    public static function pendingCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 0'
        );
    }

    /** 指定时间点之后发表的评论数（后台概览「今日新增」） */
    public static function countSince(int $timestamp): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('created_at') . ' >= ?',
            [$timestamp]
        );
    }

    /**
     * 前台搜索：按内容关键词查已发布的评论（含帖子首帖），带帖子标题与作者名。
     *
     * 返回结构与 Query::paginate() 一致，供分页组件直接使用。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function searchPublished(string $keyword, int $page, int $perPage = 20, int $forumId = 0): array
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);

        $likeOp = Database::likeOperator();
        $where  = ['p.status = 1', 'p.deleted_at IS NULL', 't.status = 1', 't.deleted_at IS NULL'];
        $params = [];

        if ($keyword !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $where[] = 'p.content ' . $likeOp . ' ?' . Database::likeEscapeClause();
            $params[] = '%' . $escaped . '%';
        }

        if ($forumId > 0) {
            $where[] = 'p.forum_id = ?';
            $params[] = $forumId;
        }

        $cond = ' WHERE ' . implode(' AND ', $where);

        $total = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('posts') . ' p'
            . ' JOIN ' . Database::identifier('threads') . ' t ON t.id = p.thread_id'
            . $cond,
            $params
        );

        $items = Database::select(
            'SELECT p.id, p.thread_id, p.floor, p.content, p.created_at, p.user_id,'
            . ' t.title AS thread_title, u.username AS author'
            . ' FROM ' . Database::identifier('posts') . ' p'
            . ' JOIN ' . Database::identifier('threads') . ' t ON t.id = p.thread_id'
            . ' JOIN ' . Database::identifier('users') . ' u ON u.id = p.user_id'
            . $cond
            . ' ORDER BY p.created_at DESC, p.id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int)ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }
}
