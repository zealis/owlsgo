<?php
/**
 * 帖子模型
 *
 * 列表页的关键性能点：
 *  - 一次查出帖子，再批量补齐作者 / 最后评论人 / 首帖摘要，全程无 N+1
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
     * 版块内帖子分页
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

        // 游客/普通用户只看得到审核通过的内容，待审核帖子仅作者与版主可见
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
     * 全局搜索帖子（标题 + 首帖正文）
     *
     * 只搜标题会漏掉「内容里写了关键词但标题没提」的帖子，所以这里同时匹配
     * 首帖正文（posts.is_first=1）。评论内容走 PostModel::searchPublished，是另一个页签。
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
            $likeOp  = \Core\Database::likeOperator();
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $like    = '%' . $escaped . '%';
            $tail    = Database::likeEscapeClause();

            $query->whereRaw(
                \Core\Database::identifier('title') . ' ' . $likeOp . ' ?' . $tail
                . ' OR ' . \Core\Database::identifier('id') . ' IN ('
                .   'SELECT ' . \Core\Database::identifier('thread_id')
                .   ' FROM ' . \Core\Database::identifier('posts')
                .   ' WHERE ' . \Core\Database::identifier('is_first') . ' = 1'
                .   ' AND ' . \Core\Database::identifier('status') . ' = 1'
                .   ' AND ' . \Core\Database::identifier('deleted_at') . ' IS NULL'
                .   ' AND ' . \Core\Database::identifier('content') . ' ' . $likeOp . ' ?' . $tail
                . ')',
                [$like, $like]
            );
        }

        $query->orderBy('last_reply_at', 'desc')->orderBy('id', 'desc');

        return $query->paginate($perPage, $page);
    }

    /**
     * 最近被评论过的帖子（按「最后评论时间」倒序）
     *
     * 注意口径：排的是**帖子**，不是评论 —— 谁刚被评论，帖子就排到前面。
     * 首页第一个页签「新评论」与后台概览的「最新帖子」都用它。
     * 想按**发布时间**取最新帖子请用 newest()。
     *
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit = 10, int $forumId = 0): array
    {
        $query = static::query()->where('status', 1);

        if ($forumId > 0) {
            $query->where('forum_id', $forumId);
        }

        /* 与分页版同口径：置顶帖永远在最前（后台概览等调用方保持一致） */
        return $query->orderBy('is_pinned', 'desc')
            ->orderBy('last_reply_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 最新发布的帖子（按发布时间倒序）
     *
     * 首页第二个页签「新帖子」用它 —— 与 latest() 的区别就在这里：
     * 这个看帖子自己是什么时候发的，不看最后被评论的时间。
     *
     * @return list<array<string, mixed>>
     */
    public static function newest(int $limit = 10): array
    {
        return static::query()
            ->where('status', 1)
            ->orderBy('is_pinned', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * 分页版 latest()（首页「新评论」页签翻页用），口径与 latest() 完全一致
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateLatest(int $page, int $perPage = 20, int $forumId = 0): array
    {
        $query = static::query()->where('status', 1);

        if ($forumId > 0) {
            $query->where('forum_id', $forumId);
        }

        /* 与版块页同口径：置顶帖永远在最前 */
        return $query->orderBy('is_pinned', 'desc')
            ->orderBy('last_reply_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);
    }

    /**
     * 分页版 newest()（首页「新帖子」页签翻页用），口径与 newest() 完全一致
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateNewest(int $page, int $perPage = 20): array
    {
        return static::query()
            ->where('status', 1)
            ->orderBy('is_pinned', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);
    }

    /**
     * 分页取全站推荐帖子（首页「推荐」页签）：跨版块，按最后评论时间倒序
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateRecommended(int $page, int $perPage = 20): array
    {
        return static::query()
            ->where('status', 1)
            ->where('is_recommended', 1)
            ->orderBy('is_pinned', 'desc')
            ->orderBy('last_reply_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, $page);
    }

    /**
     * 热门帖子（按评论数）
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
     * 某用户发表的帖子
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
     * 为帖子列表补齐展示所需数据：作者、最后评论人、版块、首帖摘要
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
            'username' => '用户已删除',
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
     * 发布帖子（帖子 + 首帖在一个事务里完成，保证不会出现「有帖子无内容」）
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
     * 浏览量自增（同一会话内同一帖子只计一次，避免刷新刷量）
     */
    public static function touchView(int $threadId): void
    {
        $key   = 'viewed_threads';
        $seen  = \Core\Session::get($key, []);
        $seen  = is_array($seen) ? $seen : [];

        if (isset($seen[$threadId])) {
            return;
        }

        // 会话内最多记录 200 个帖子，防止会话膨胀
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
            return ['ok' => false, 'message' => '帖子不存在。'];
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
     * 审核通过帖子
     *
     * 说明：帖子与首帖的计数在发布时（ThreadModel::publish）就已写入，
     * 因此这里只翻转状态，不需要再调整统计，避免重复计数。
     *
     * @return array{ok:bool, message:string, user_id:int}
     */
    public static function approve(int $threadId): array
    {
        $thread = static::find($threadId);

        if ($thread === null) {
            return ['ok' => false, 'message' => '帖子不存在。', 'user_id' => 0];
        }

        if ((int)$thread['status'] === 1) {
            return ['ok' => false, 'message' => '该帖子已是审核通过状态。', 'user_id' => (int)$thread['user_id']];
        }

        Database::transaction(static function () use ($threadId): void {
            $now = time();

            static::updateById($threadId, ['status' => 1]);

            // 首帖随帖子一并放行
            Database::update(
                'posts',
                ['status' => 1, 'updated_at' => $now],
                Database::identifier('thread_id') . ' = ? AND ' . Database::identifier('is_first') . ' = 1',
                [$threadId]
            );
        });

        \Modules\Forum\ForumModel::flush();

        return ['ok' => true, 'message' => '帖子已通过审核。', 'user_id' => (int)$thread['user_id']];
    }

    /**
     * 软删除帖子，并同步计数
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

            /*
             * ⚠️ 必须**先**算「当初加了多少」，再批量软删评论 —— 顺序反了就啥也查不到。
             * 口径不能数该帖子下所有 posts：
             *   · 已软删除的评论在删除那一刻就已经减过一次了；
             *   · 待审核评论（status = 0）发表时根本没计入过；
             * 按「全部 posts」减就是**多减**，版块评论数会越删越少。
             * 正确口径与 publish()/reply() 对称：首帖 1 条 + 已通过且未删的评论。
             *
             * 这里按**作者分组**取，是因为下一步要逐个作者回滚（见下方注释）。
             */
            $commentRows = Database::select(
                'SELECT ' . Database::identifier('user_id') . ' AS uid, COUNT(*) AS c'
                . ' FROM ' . Database::identifier('posts')
                . ' WHERE ' . Database::identifier('thread_id') . ' = ?'
                . ' AND ' . Database::identifier('is_first') . ' = 0'
                . ' AND ' . Database::identifier('status') . ' = 1'
                . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
                . ' GROUP BY ' . Database::identifier('user_id'),
                [$threadId]
            );

            $postCount = 1;
            $byAuthor  = [];

            foreach ($commentRows as $row) {
                $byAuthor[(int)$row['uid']] = (int)$row['c'];
                $postCount += (int)$row['c'];
            }

            // 帖子下的评论一并软删除
            Database::update(
                'posts',
                ['deleted_at' => $now, 'updated_at' => $now],
                Database::identifier('thread_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
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

            /*
             * 计数回滚必须**按作者逐个减**，不能只减帖子作者一个人。
             *
             * 原来这里只对帖子作者减了 (首帖 + 帖子下全部已通过评论)：
             *   · 那些评论里凡是**别人写的**，它们当初计入的是**那个人的** post_count，
             *     减到帖子作者头上既减错了人，又让真正的评论作者一直虚高；
             *   · 上面那一步批量软删评论不会碰任何人的计数（它只是一条 UPDATE posts）。
             * 现实后果（2026-09-19 用 A/B 脚本复现过）：删掉一个带 18 层评论的帖子后，
             * 14 位评论者的「评论数」每人凭空多 1~3，修复脚本一跑就报出 15 行漂移。
             *
             * 正确拆法：每个评论作者 −(他在本帖下的已通过评论数)，帖子作者另 −1（首帖）。
             */
            $decPost = 'UPDATE ' . Database::identifier('users')
                . ' SET ' . Database::identifier('post_count') . ' = CASE WHEN ' . Database::identifier('post_count') . ' >= ? THEN ' . Database::identifier('post_count') . ' - ? ELSE 0 END, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?';

            foreach ($byAuthor as $authorId => $commentCount) {
                Database::execute($decPost, [$commentCount, $commentCount, $now, $authorId]);
            }

            Database::execute(
                'UPDATE ' . Database::identifier('users')
                . ' SET ' . Database::identifier('thread_count') . ' = CASE WHEN ' . Database::identifier('thread_count') . ' > 0 THEN ' . Database::identifier('thread_count') . ' - 1 ELSE 0 END, '
                . Database::identifier('post_count') . ' = CASE WHEN ' . Database::identifier('post_count') . ' > 0 THEN ' . Database::identifier('post_count') . ' - 1 ELSE 0 END, '
                . Database::identifier('updated_at') . ' = ?'
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$now, (int)$thread['user_id']]
            );

            /*
             * 版块的「最后发表」很可能正指着刚删掉的这条帖子 —— 不重算的话，
             * 首页版块列表点「最后发表」就会跳到一条已删除的帖子。
             */
            \Modules\Forum\ForumModel::refreshLastThread((int)$thread['forum_id']);

            // 收藏记录一并清理
            Database::delete('favorites', Database::identifier('thread_id') . ' = ?', [$threadId]);

            \Modules\Forum\ForumModel::flush();
            Model::flushRowCache();

            return true;
        });
    }

    /** 后台搜索范围（下拉菜单用） */
    public const ADMIN_SCOPES = [
        'all'     => '全文',
        'comment' => '评论',
        'user'    => '用户',
        'id'      => '帖子Id',
    ];

    /**
     * 后台帖子列表（支持搜索范围）
     *
     * @param string $scope all=标题+首帖正文｜comment=该帖下评论的内容｜user=作者名｜id=帖子 ID
     */
    public static function adminPaginate(
        string $keyword,
        int $status,
        int $page,
        int $perPage = 20,
        string $scope = 'all'
    ): array {
        $query = static::withTrashed()->whereNull('deleted_at');

        if ($keyword !== '') {
            self::applyAdminSearch($query, $keyword, $scope);
        }

        if ($status !== -1) {
            $query->where('status', $status);
        }

        return $query->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /**
     * 按范围给后台查询加搜索条件
     *
     * 用相关子查询而不是 JOIN：JOIN 会让一个帖子因为匹配到多条评论/用户而出现多行，
     * 外层分页的 COUNT 与条目数就都不对了（同一个坑在 counter_check 里也踩过）。
     */
    private static function applyAdminSearch(\Core\Query $query, string $keyword, string $scope): void
    {
        $t    = Database::identifier('threads');
        $q    = static fn (string $name): string => Database::identifier($name);
        $like = Database::likeOperator();
        $esc  = Database::likeEscapeClause();
        $bind = Database::likePattern($keyword);

        if ($scope === 'id') {
            /* 帖子 ID 走精确匹配，不套 LIKE —— 输入 12 不该把 120、512 一起捞出来 */
            $query->where('id', '=', (int)$keyword);

            return;
        }

        if ($scope === 'user') {
            $query->whereRaw(
                'EXISTS (SELECT 1 FROM ' . $q('users') . ' u WHERE u.' . $q('id') . ' = ' . $t . '.' . $q('user_id')
                . ' AND u.' . $q('username') . ' ' . $like . ' ?' . $esc . ')',
                [$bind]
            );

            return;
        }

        if ($scope === 'comment') {
            $query->whereRaw(
                'EXISTS (SELECT 1 FROM ' . $q('posts') . ' p WHERE p.' . $q('thread_id') . ' = ' . $t . '.' . $q('id')
                . ' AND p.' . $q('is_first') . ' = 0'
                . ' AND p.' . $q('deleted_at') . ' IS NULL'
                . ' AND p.' . $q('content') . ' ' . $like . ' ?' . $esc . ')',
                [$bind]
            );

            return;
        }

        // 全文 = 标题 或 首帖正文
        $query->whereRaw(
            '(' . $t . '.' . $q('title') . ' ' . $like . ' ?' . $esc
            . ' OR EXISTS (SELECT 1 FROM ' . $q('posts') . ' p WHERE p.' . $q('thread_id') . ' = ' . $t . '.' . $q('id')
            . ' AND p.' . $q('is_first') . ' = 1'
            . ' AND p.' . $q('deleted_at') . ' IS NULL'
            . ' AND p.' . $q('content') . ' ' . $like . ' ?' . $esc . '))',
            [$bind, $bind]
        );
    }

    /**
     * 从回收站恢复帖子 —— destroy() 的**严格逆操作**
     *
     * 恢复范围：帖子本身 + **与它同一时刻被级联删除的评论**。
     * destroy() 是用同一个时间戳批量软删的，所以用 `deleted_at = 帖子的 deleted_at` 当标记；
     * 更早被单独删掉的评论时间戳不同，保持删除状态，不会被误恢复。
     *
     * ⚠️ 计数要逐个加回去，与 destroy() 一一对应：
     *   版块 thread_count +1 / post_count +(1 + 已通过评论数)；
     *   每个评论作者 post_count +(他在本帖下的条数)；
     *   帖子作者 thread_count +1 / post_count +1（首帖）。
     * ⚠️ 统计必须**在恢复之前**做（恢复后 deleted_at 变 NULL，按标记就查不到了）。
     *
     * 无法恢复的：destroy() 把 `favorites` 记录**硬删**了，收藏关系找不回来。
     */
    public static function restore(int $threadId): bool
    {
        $thread = static::withTrashed()->where('id', '=', $threadId)->first();

        if ($thread === null || $thread['deleted_at'] === null) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($thread, $threadId): bool {
            $now  = time();
            $mark = (int)$thread['deleted_at'];
            $q    = static fn (string $name): string => Database::identifier($name);

            // ① 先统计（顺序不能反）
            $commentRows = Database::select(
                'SELECT ' . $q('user_id') . ' AS uid, COUNT(*) AS c FROM ' . $q('posts')
                . ' WHERE ' . $q('thread_id') . ' = ? AND ' . $q('deleted_at') . ' = ?'
                . ' AND ' . $q('is_first') . ' = 0 AND ' . $q('status') . ' = 1'
                . ' GROUP BY ' . $q('user_id'),
                [$threadId, $mark]
            );

            $postCount = 1;
            $byAuthor  = [];

            foreach ($commentRows as $row) {
                $byAuthor[(int)$row['uid']] = (int)$row['c'];
                $postCount += (int)$row['c'];
            }

            // ② 恢复帖子与那批评论
            Database::update(
                'threads',
                ['deleted_at' => null, 'updated_at' => $now],
                $q('id') . ' = ?',
                [$threadId]
            );

            Database::update(
                'posts',
                ['deleted_at' => null, 'updated_at' => $now],
                $q('thread_id') . ' = ? AND ' . $q('deleted_at') . ' = ?',
                [$threadId, $mark]
            );

            // ③ 计数加回去
            Database::execute(
                'UPDATE ' . $q('forums')
                . ' SET ' . $q('thread_count') . ' = ' . $q('thread_count') . ' + 1, '
                . $q('post_count') . ' = ' . $q('post_count') . ' + ?, '
                . $q('updated_at') . ' = ?'
                . ' WHERE ' . $q('id') . ' = ?',
                [$postCount, $now, (int)$thread['forum_id']]
            );

            $incPost = 'UPDATE ' . $q('users')
                . ' SET ' . $q('post_count') . ' = ' . $q('post_count') . ' + ?, '
                . $q('updated_at') . ' = ?'
                . ' WHERE ' . $q('id') . ' = ?';

            foreach ($byAuthor as $authorId => $commentCount) {
                Database::execute($incPost, [$commentCount, $now, $authorId]);
            }

            Database::execute(
                'UPDATE ' . $q('users')
                . ' SET ' . $q('thread_count') . ' = ' . $q('thread_count') . ' + 1, '
                . $q('post_count') . ' = ' . $q('post_count') . ' + 1, '
                . $q('updated_at') . ' = ?'
                . ' WHERE ' . $q('id') . ' = ?',
                [$now, (int)$thread['user_id']]
            );

            // ④ 版块「最后发表」与缓存
            \Modules\Forum\ForumModel::refreshLastThread((int)$thread['forum_id']);
            \Modules\Forum\ForumModel::flush();
            Model::flushRowCache();

            return true;
        });
    }

    /**
     * 批量转移版块
     *
     * 版块计数是**两张表各自维护**的，转移时必须「源版块减、目标版块加」成对操作；
     * `posts.forum_id` 也是冗余列（前台按版块筛评论要用），要跟着一起搬，
     * 否则搬完之后：源版块数字不变、目标版块数字不变、评论还留在旧版块里。
     *
     * @param list<int> $threadIds
     * @return array{moved:int, skipped:int}
     */
    public static function moveToForum(array $threadIds, int $targetForumId): array
    {
        $moved   = 0;
        $skipped = 0;
        $q       = static fn (string $name): string => Database::identifier($name);

        $touchedForums = [];

        foreach ($threadIds as $threadId) {
            $thread = static::find($threadId);

            if ($thread === null || (int)$thread['forum_id'] === $targetForumId) {
                $skipped++;
                continue;
            }

            $sourceForumId = (int)$thread['forum_id'];

            Database::transaction(static function () use ($threadId, $sourceForumId, $targetForumId, $q): void {
                $now = time();

                // 该帖子在版块计数里占的份额 = 首帖 1 + 已通过且未删的评论
                $postCount = 1 + (int)Database::value(
                    'SELECT COUNT(*) FROM ' . $q('posts')
                    . ' WHERE ' . $q('thread_id') . ' = ? AND ' . $q('is_first') . ' = 0'
                    . ' AND ' . $q('status') . ' = 1 AND ' . $q('deleted_at') . ' IS NULL',
                    [$threadId]
                );

                Database::execute(
                    'UPDATE ' . $q('forums')
                    . ' SET ' . $q('thread_count') . ' = CASE WHEN ' . $q('thread_count') . ' > 0 THEN ' . $q('thread_count') . ' - 1 ELSE 0 END, '
                    . $q('post_count') . ' = CASE WHEN ' . $q('post_count') . ' >= ? THEN ' . $q('post_count') . ' - ? ELSE 0 END, '
                    . $q('updated_at') . ' = ? WHERE ' . $q('id') . ' = ?',
                    [$postCount, $postCount, $now, $sourceForumId]
                );

                Database::execute(
                    'UPDATE ' . $q('forums')
                    . ' SET ' . $q('thread_count') . ' = ' . $q('thread_count') . ' + 1, '
                    . $q('post_count') . ' = ' . $q('post_count') . ' + ?, '
                    . $q('updated_at') . ' = ? WHERE ' . $q('id') . ' = ?',
                    [$postCount, $now, $targetForumId]
                );

                // 帖子与它下面**全部**楼层（含已删的）一起改归属，避免留下跨版块的孤儿评论
                Database::update('threads', ['forum_id' => $targetForumId, 'updated_at' => $now], $q('id') . ' = ?', [$threadId]);
                Database::update('posts', ['forum_id' => $targetForumId, 'updated_at' => $now], $q('thread_id') . ' = ?', [$threadId]);
            });

            $touchedForums[$sourceForumId] = true;
            $touchedForums[$targetForumId] = true;
            $moved++;
        }

        foreach (array_keys($touchedForums) as $forumId) {
            \Modules\Forum\ForumModel::refreshLastThread((int)$forumId);
        }

        if ($touchedForums !== []) {
            \Modules\Forum\ForumModel::flush();
            Model::flushRowCache();
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }

    /**
     * 彻底删除（从回收站清除，不可恢复）
     *
     * 硬删帖子行 + 它下面的全部楼层行 + 这些内容上的附件记录与磁盘文件。
     * 计数不需要再动：软删那一步已经减过了（否则会二次扣减）。
     */
    public static function purge(int $threadId): bool
    {
        $thread = static::withTrashed()->where('id', '=', $threadId)->first();

        if ($thread === null || $thread['deleted_at'] === null) {
            return false;
        }

        return (bool)Database::transaction(static function () use ($threadId): bool {
            $q = static fn (string $name): string => Database::identifier($name);

            // 附件：先删除磁盘文件与记录（记录挂在帖子或楼层上）
            \Modules\User\AttachmentModel::purgeForThread($threadId);

            Database::delete('posts', $q('thread_id') . ' = ?', [$threadId]);
            Database::delete('threads', $q('id') . ' = ?', [$threadId]);
            Database::delete('favorites', $q('thread_id') . ' = ?', [$threadId]);

            Model::flushRowCache();

            return true;
        });
    }

    /** 帖子总数 */
    public static function totalCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
        );
    }

    /** 待审核帖子数量 */
    public static function pendingCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 0'
        );
    }

    /** 指定时间点之后发表的帖子数（后台概览「今日新增」） */
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
     * 清除帖子相关缓存
     *
     * 帖子列表本身不做页面级缓存，此处只需清理模型行缓存；
     * 与 ForumModel::flush() 保持同名，便于「发帖 / 评论 / 删帖」后一并调用。
     */
    public static function flush(): void
    {
        Model::flushRowCache();
    }
}
