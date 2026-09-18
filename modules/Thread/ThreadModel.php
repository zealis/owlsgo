<?php
/**
 * 主题模型
 *
 * 列表页的关键性能点：
 *  - 一次查出主题，再批量补齐作者 / 最后回复人 / 首帖摘要，全程无 N+1
 *  - 分页使用 (forum_id, is_pinned, last_reply_at) 复合索引，深分页受 app.max_page 限制
 */

declare(strict_types=1);

namespace Modules\Thread;

use Core\Database;
use Core\Model;
use Core\Text;

final class ThreadModel extends Model
{
    protected static string $table = 'threads';

    /**
     * 版块内主题分页
     *
     * @param int                  $forumId
     * @param int                  $page
     * @param int                  $perPage
     * @param array<string, mixed> $filters 支持 essence / pinned / keyword / author_id
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateInForum(int $forumId, int $page, int $perPage = 20, array $filters = []): array
    {
        $query = static::query()->where('forum_id', $forumId);

        // 游客/普通用户只看得到审核通过的内容，待审核主题仅作者与版主可见
        $status = (int)($filters['status'] ?? 1);
        $query->where('status', $status);

        if (!empty($filters['essence'])) {
            $query->where('is_essence', 1);
        }

        if (!empty($filters['pinned'])) {
            $query->where('is_pinned', 1);
        }

        if (!empty($filters['author_id'])) {
            $query->where('user_id', (int)$filters['author_id']);
        }

        if (!empty($filters['keyword'])) {
            $query->whereContains('title', (string)$filters['keyword']);
        }

        $query->orderBy('is_pinned', 'desc')
            ->orderBy('last_reply_at', 'desc')
            ->orderBy('id', 'desc');

        return $query->paginate($perPage, $page);
    }

    /**
     * 全局搜索主题
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(string $keyword, int $page, int $perPage = 20, int $forumId = 0): array
    {
        $query = static::query()->where('status', 1);

        if ($forumId > 0) {
            $query->where('forum_id', $forumId);
        }

        if ($keyword !== '') {
            $query->whereContains('title', $keyword);
        }

        $query->orderBy('last_reply_at', 'desc')->orderBy('id', 'desc');

        return $query->paginate($perPage, $page);
    }

    /**
     * 最新主题（首页/侧边栏）
     *
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit = 10, int $forumId = 0): array
    {
        $query = static::query()->where('status', 1);

        if ($forumId > 0) {
            $query->where('forum_id', $forumId);
        }

        return $query->orderBy('last_reply_at', 'desc')->orderBy('id', 'desc')->limit($limit)->get();
    }

    /**
     * 热门主题（按回复数）
     *
     * @return list<array<string, mixed>>
     */
    public static function hot(int $limit = 10): array
    {
        return static::query()
            ->where('status', 1)
            ->orderBy('reply_count', 'desc')
            ->orderBy('views', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 某用户发表的主题
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function byUser(int $userId, int $page, int $perPage = 20): array
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);
    }

    /**
     * 为主题列表补齐展示所需数据：作者、最后回复人、版块、首帖摘要
     *
     * @param list<array<string, mixed>> $threads
     * @return list<array<string, mixed>>
     */
    public static function decorate(array $threads, bool $withExcerpt = true): array
    {
        if ($threads === []) {
            return [];
        }

        $threadIds = array_map(static fn (array $t): int => (int)$t['id'], $threads);
        $userIds   = [];
        $forumIds  = [];

        foreach ($threads as $thread) {
            $userIds[]  = (int)$thread['user_id'];
            $userIds[]  = (int)$thread['last_reply_user_id'];
            $forumIds[] = (int)$thread['forum_id'];
        }

        // 三个批量查询替代 3N 次单条查询
        $users  = \Modules\User\UserModel::mapByIds($userIds);
        $forums = \Modules\Forum\ForumModel::all();

        $excerpts = [];
        if ($withExcerpt) {
            $rows = Database::select(
                'SELECT ' . Database::identifier('thread_id') . ', ' . Database::identifier('content')
                . ' FROM ' . Database::identifier('posts')
                . ' WHERE ' . Database::identifier('is_first') . ' = 1'
                . ' AND ' . Database::identifier('thread_id') . ' IN (' . Database::placeholders(count($threadIds)) . ')'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL',
                $threadIds
            );

            foreach ($rows as $row) {
                $excerpts[(int)$row['thread_id']] = Text::excerpt((string)$row['content'], 90);
            }
        }

        // 用户组颜色一次取出，避免逐行查库
        $groups = \Modules\User\UsergroupModel::all();

        $placeholders = [
            'id'       => 0,
            'username' => '已注销用户',
            'avatar'   => '',
            'group_id' => \Core\Permission::GUEST_GROUP,
        ];

        foreach ($threads as &$thread) {
            $author = $users[(int)$thread['user_id']] ?? $placeholders;
            $last   = $users[(int)$thread['last_reply_user_id']] ?? null;

            $authorGroupId = (int)($author['group_id'] ?? \Core\Permission::GUEST_GROUP);

            $thread['author']            = $author;
            $thread['author_group_id']   = $authorGroupId;
            $thread['author_group_name'] = (string)($groups[$authorGroupId]['name'] ?? '游客');
            $thread['author_group_color'] = (string)($groups[$authorGroupId]['color'] ?? '#999999');
            $thread['last_reply_user']   = $last;
            $thread['forum_name']        = (string)($forums[(int)$thread['forum_id']]['name'] ?? '未知版块');
            $thread['forum_id']          = (int)$thread['forum_id'];
            $thread['excerpt']           = $excerpts[(int)$thread['id']] ?? '';
        }
        unset($thread);

        return $threads;
    }

    /**
     * 发布主题（主题 + 首帖在一个事务里完成，保证不会出现「有主题无内容」）
     *
     * @param array<string, mixed> $threadData
     * @return array{thread_id:int, post_id:int}
     */
    public static function publish(array $threadData, string $content, array $attachmentIds = []): array
    {
        return Database::transaction(static function () use ($threadData, $content, $attachmentIds): array {
            $now = time();

            $threadId = static::create(array_merge($threadData, [
                'views'              => 0,
                'reply_count'        => 0,
                'like_count'         => 0,
                'favorite_count'     => 0,
                'last_reply_at'      => $now,
                'last_reply_user_id' => (int)$threadData['user_id'],
            ]));

            $postId = Database::insert('posts', [
                'thread_id'    => $threadId,
                'forum_id'     => (int)$threadData['forum_id'],
                'user_id'      => (int)$threadData['user_id'],
                'parent_id'    => 0,
                'floor'        => 1,
                'is_first'     => 1,
                'content'      => $content,
                'content_html' => Text::toHtml($content),
                'like_count'   => 0,
                'status'       => (int)($threadData['status'] ?? 1),
                'ip'           => \Core\Request::ip(),
                'device'       => \Core\Request::device(),
                'user_agent'   => \Core\Request::userAgent(),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            if ($attachmentIds !== []) {
                \Modules\User\AttachmentModel::bindToPost($attachmentIds, (int)$threadData['user_id'], $threadId, $postId);
            }

            // 更新版块统计与「最后发表」
            Database::execute(
                'UPDATE ' . Database::identifier('forums')
                . ' SET ' . implode(', ', [
                    Database::identifier('thread_count') . ' = ' . Database::identifier('thread_count') . ' + 1',
                    Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1',
                    Database::identifier('last_thread_id') . ' = ?',
                    Database::identifier('last_thread_name') . ' = ?',
                    Database::identifier('last_reply_at') . ' = ?',
                    Database::identifier('updated_at') . ' = ?',
                ])
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$threadId, (string)$threadData['title'], $now, $now, (int)$threadData['forum_id']]
            );

            // 作者发帖数 +1
            Database::execute(
                'UPDATE ' . Database::identifier('users')
                . ' SET ' . Database::identifier('thread_count') . ' = ' . Database::identifier('thread_count') . ' + 1, '
                . Database::identifier('post_count') . ' = ' . Database::identifier('post_count') . ' + 1, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$now, (int)$threadData['user_id']]
            );

            Model::flushRowCache();
            \Modules\Forum\ForumModel::flush();

            return ['thread_id' => $threadId, 'post_id' => $postId];
        });
    }

    /**
     * 浏览量自增（同一会话内同一主题只计一次，避免刷新刷量）
     */
    public static function touchView(int $threadId): void
    {
        $key   = 'viewed_threads';
        $seen  = \Core\Session::get($key, []);
        $seen  = is_array($seen) ? $seen : [];

        if (isset($seen[$threadId])) {
            return;
        }

        // 会话内最多记录 200 个主题，防止会话膨胀
        if (count($seen) >= 200) {
            $seen = array_slice($seen, -100, null, true);
        }

        $seen[$threadId] = time();
        \Core\Session::set($key, $seen);

        static::increment($threadId, 'views', 1);
    }

    /**
     * 版主操作：置顶 / 加精 / 锁定 / 推荐
     *
     * @return array{ok:bool, message:string}
     */
    public static function moderate(int $threadId, string $action): array
    {
        $thread = static::find($threadId);

        if ($thread === null) {
            return ['ok' => false, 'message' => '主题不存在。'];
        }

        $field = match ($action) {
            'pin', 'unpin'         => 'is_pinned',
            'essence', 'unessence' => 'is_essence',
            'lock', 'unlock'       => 'is_locked',
            'recommend', 'unrecommend' => 'is_recommended',
            default                => '',
        };

        if ($field === '') {
            return ['ok' => false, 'message' => '不支持的操作类型。'];
        }

        $value = str_starts_with($action, 'un') ? 0 : 1;

        static::updateById($threadId, [$field => $value]);
        \Modules\Forum\ForumModel::flush();

        $labels = [
            'is_pinned'      => '置顶',
            'is_essence'     => '精华',
            'is_locked'      => '锁定',
            'is_recommended' => '推荐',
        ];

        return [
            'ok'      => true,
            'message' => '已' . ($value === 1 ? '设置' : '取消') . $labels[$field] . '。',
        ];
    }

    /**
     * 审核通过主题
     *
     * 说明：主题与首帖的计数在发布时（ThreadModel::publish）就已写入，
     * 因此这里只翻转状态，不需要再调整统计，避免重复计数。
     *
     * @return array{ok:bool, message:string, user_id:int}
     */
    public static function approve(int $threadId): array
    {
        $thread = static::find($threadId);

        if ($thread === null) {
            return ['ok' => false, 'message' => '主题不存在。', 'user_id' => 0];
        }

        if ((int)$thread['status'] === 1) {
            return ['ok' => false, 'message' => '该主题已是审核通过状态。', 'user_id' => (int)$thread['user_id']];
        }

        Database::transaction(static function () use ($threadId): void {
            $now = time();

            static::updateById($threadId, ['status' => 1]);

            // 首帖随主题一并放行
            Database::update(
                'posts',
                ['status' => 1, 'updated_at' => $now],
                Database::identifier('thread_id') . ' = ? AND ' . Database::identifier('is_first') . ' = 1',
                [$threadId]
            );
        });

        \Modules\Forum\ForumModel::flush();

        return ['ok' => true, 'message' => '主题已通过审核。', 'user_id' => (int)$thread['user_id']];
    }

    /**
     * 软删除主题，并同步计数
     */
    public static function destroy(int $threadId): bool
    {
        $thread = static::find($threadId);

        if ($thread === null) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($thread, $threadId): bool {
            $now = time();

            static::deleteById($threadId);

            // 主题下的回帖一并软删除
            Database::update(
                'posts',
                ['deleted_at' => $now, 'updated_at' => $now],
                Database::identifier('thread_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
                [$threadId]
            );

            $postCount = (int)Database::value(
                'SELECT COUNT(*) FROM ' . Database::identifier('posts') . ' WHERE ' . Database::identifier('thread_id') . ' = ?',
                [$threadId]
            );

            Database::execute(
                'UPDATE ' . Database::identifier('forums')
                . ' SET ' . Database::identifier('thread_count') . ' = CASE WHEN ' . Database::identifier('thread_count') . ' > 0 THEN ' . Database::identifier('thread_count') . ' - 1 ELSE 0 END, '
                . Database::identifier('post_count') . ' = CASE WHEN ' . Database::identifier('post_count') . ' >= ? THEN ' . Database::identifier('post_count') . ' - ? ELSE 0 END, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$postCount, $postCount, $now, (int)$thread['forum_id']]
            );

            // 收藏记录一并清理
            Database::delete('favorites', Database::identifier('thread_id') . ' = ?', [$threadId]);

            \Modules\Forum\ForumModel::flush();
            Model::flushRowCache();

            return true;
        });
    }

    /** 后台主题列表 */
    public static function adminPaginate(string $keyword, int $status, int $page, int $perPage = 20): array
    {
        $query = static::withTrashed()->whereNull('deleted_at');

        if ($keyword !== '') {
            $query->whereContains('title', $keyword);
        }

        if ($status !== -1) {
            $query->where('status', $status);
        }

        return $query->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /** 主题总数 */
    public static function totalCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        );
    }

    /** 待审核主题数量 */
    public static function pendingCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 0'
        );
    }

    /** 指定时间点之后发表的主题数（后台概览「今日新增」） */
    public static function countSince(int $timestamp): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' AND ' . Database::identifier('created_at') . ' >= ?',
            [$timestamp]
        );
    }

    /**
     * 清除主题相关缓存
     *
     * 主题列表本身不做页面级缓存，此处只需清理模型行缓存；
     * 与 ForumModel::flush() 保持同名，便于「发帖 / 回帖 / 删帖」后一并调用。
     */
    public static function flush(): void
    {
        Model::flushRowCache();
    }
}
