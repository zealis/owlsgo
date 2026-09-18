<?php
/**
 * 回帖模型
 *
 * 楼层号 floor 在同一主题内唯一，通过「事务内取 MAX(floor)+1」保证不会重复。
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
     * 主题内回帖分页（不含首帖）
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
     * 取主题的首帖
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
     * 发表回复
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

            // 只有审核通过的回复才计入统计，避免待审核内容影响展示
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

                Database::execute(
                    'UPDATE ' . Database::identifier('forums')
                    . ' SET ' . Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1, '
                    . Database::identifier('last_reply_at') . ' = ?, '
                    . Database::identifier('last_thread_id') . ' = ?, '
                    . Database::identifier('updated_at') . ' = ?'
                    . ' WHERE ' . Database::identifier('id') . ' = ?',
                    [$now, $threadId, $now, (int)$thread['forum_id']]
                );
            }

            Database::execute(
                'UPDATE ' . Database::identifier('users')
                . ' SET ' . Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$now, $userId]
            );

            Model::flushRowCache();
            \Modules\Forum\ForumModel::flush();
            \Modules\Thread\ThreadModel::flush();

            return ['post_id' => $postId, 'floor' => $floor];
        });
    }

    /**
     * 为回帖列表补齐作者信息与附件
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
                'username' => '已注销用户',
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
     * 取帖子及其所属主题与版块（用于权限判定）
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
     * 软删除回帖，并同步计数
     */
    public static function destroy(int $postId): bool
    {
        $post = static::find($postId);

        if ($post === null) {
            return false;
        }

        // 首帖不允许单独删除，应删除整个主题
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

    /**
     * 更新回帖内容（同时刷新渲染缓存）
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
     *  - 非首帖通过审核时才把回复计入主题 reply_count 与版块 post_count
     *    （等待审核的回复在发表时并没有计入）
     *  - 首帖通过审核时把所属主题一并放行，且不重复计数
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
                // 首帖放行时主题随之通过
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
        });

        Model::flushRowCache();
        \Modules\Forum\ForumModel::flush();
        \Modules\Thread\ThreadModel::flush();

        return [
            'ok'        => true,
            'message'   => $isFirst ? '主题已通过审核。' : '回复已通过审核。',
            'thread_id' => $threadId,
            'user_id'   => (int)$post['user_id'],
            'is_thread' => $isFirst,
        ];
    }

    /** 某用户发表的回复 */
    public static function byUser(int $userId, int $page, int $perPage = 20): array
    {
        // 排除首帖，只展示「回复」
        return static::query()
            ->where('user_id', $userId)
            ->where('is_first', 0)
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);
    }

    /** 回帖总数 */
    public static function totalCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        );
    }

    /** 待审核回帖数量 */
    public static function pendingCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 0'
        );
    }

    /** 指定时间点之后发表的回复数（后台概览「今日新增」） */
    public static function countSince(int $timestamp): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('created_at') . ' >= ?',
            [$timestamp]
        );
    }

    /** 后台回帖列表 */
    public static function adminPaginate(string $keyword, int $status, int $page, int $perPage = 20): array
    {
        $query = static::query();

        if ($keyword !== '') {
            $query->whereContains('content', $keyword);
        }

        if ($status !== -1) {
            $query->where('status', $status);
        }

        return $query->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /**
     * 前台搜索：按内容关键词查已发布的回复（含主题首帖），带主题标题与作者名。
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
