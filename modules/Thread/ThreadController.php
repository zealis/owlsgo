<?php
/**
 * 主题控制器：列表/详情/发布/编辑/删除/版主操作/点赞/收藏/搜索
 */

declare(strict_types=1);

namespace Modules\Thread;

use Core\Auth;
use Core\Cache;
use Core\Controller;
use Core\Database;
use Core\HttpException;
use Core\Hook;
use Core\Paginator;
use Core\Permission;
use Core\Request;
use Core\Response;
use Core\Router;
use Core\Session;
use Core\Text;
use Modules\Forum\ForumModel;
use Modules\Notice\NoticeModel;
use Modules\Post\PostModel;
use Modules\User\AttachmentModel;
use Modules\User\LikeModel;
use Modules\User\NotificationModel;
use Modules\User\UserModel;

final class ThreadController extends Controller
{
    /**
     * 主题详情
     *
     * @param array<string, string> $params
     */
    public function show(array $params): string
    {
        $threadId = (int)($params['id'] ?? 0);
        $thread   = ThreadModel::find($threadId);

        if ($thread === null) {
            \Core\App::abort(404, '主题不存在或已被删除。');
        }

        $forum = ForumModel::findOrFail((int)$thread['forum_id']);
        $user  = Auth::user();

        if (!Permission::canViewForum($user, $forum)) {
            if ($user === null) {
                Auth::requireLogin();
            }
            \Core\App::abort(403, '你没有权限浏览该版块。');
        }

        $isAuthor    = $user !== null && (int)$user['id'] === (int)$thread['user_id'];
        $canModerate = Permission::allows($user, 'thread.essence', $forum);

        // 待审核内容仅作者与有审核权限者可见
        if ((int)$thread['status'] !== 1 && !$isAuthor && !$canModerate) {
            \Core\App::abort(404, '该主题正在审核中。');
        }

        ThreadModel::touchView($threadId);

        $perPage = (int)config('app.post_per_page', 15);

        // 支持通过 ?p=楼层ID 直接跳到指定楼层所在页
        $targetPostId = Request::int('p', 0);
        $page         = isset($params['page']) ? Paginator::page((int)$params['page']) : $this->currentPage();

        if ($targetPostId > 0) {
            $floor = (int)Database::value(
                'SELECT ' . Database::identifier('floor') . ' FROM ' . Database::identifier('posts')
                . ' WHERE ' . Database::identifier('id') . ' = ? AND ' . Database::identifier('thread_id') . ' = ?',
                [$targetPostId, $threadId]
            );

            if ($floor > 1) {
                $page = max(1, (int)ceil(($floor - 1) / $perPage));
            }
        }

        $replies = PostModel::paginateInThread($threadId, $page, $perPage);
        $replies['items'] = PostModel::decorate($replies['items']);
        $replies['items'] = (array)Hook::filter('post_list', $replies['items'], ['thread' => $thread]);

        // 首帖单独取出置顶展示
        $firstPost = PostModel::firstPost($threadId);

        if ($firstPost !== null) {
            $firstPost = PostModel::decorate([$firstPost])[0];
        }

        $thread = ThreadModel::decorate([$thread])[0];
        $thread['forum_name'] = (string)$forum['name'];

        $favorited = false;
        if ($user !== null) {
            $map       = FavoriteModel::favoritedMap((int)$user['id'], [$threadId]);
            $favorited = isset($map[$threadId]);
        }

        $likedMap = LikeModel::likedMap(Auth::id(), 'thread', [$threadId]);

        // 本页回帖 + 首帖的点赞状态，避免模板内逐个查询
        $postIds = array_map(static fn (array $p): int => (int)$p['id'], $replies['items']);
        if ($firstPost !== null) {
            $postIds[] = (int)$firstPost['id'];
        }
        $likedPosts = LikeModel::likedMap(Auth::id(), 'post', $postIds);

        // 回复目标（楼中楼）
        $replyTo = null;
        $replyToId = Request::int('reply_to', 0);
        if ($replyToId > 0) {
            $candidate = PostModel::find($replyToId);
            if ($candidate !== null && (int)$candidate['thread_id'] === $threadId) {
                $author    = \Modules\User\UserModel::find((int)$candidate['user_id']);
                $replyTo   = [
                    'id'       => (int)$candidate['id'],
                    'floor'    => (int)$candidate['floor'],
                    'username' => (string)($author['username'] ?? '已注销用户'),
                ];
            }
        }

        return $this->view('thread/show', [
            'pageTitle'   => (string)$thread['title'] . ' - ' . (string)setting('site_name'),
            'thread'      => $thread,
            'forum'       => $forum,
            'firstPost'   => $firstPost,
            'replies'     => $replies,
            'pagination'  => Paginator::render($replies, '/t/' . $threadId),
            'favorited'   => $favorited,
            'liked'       => isset($likedMap[$threadId]),
            'likedPosts'  => $likedPosts,
            'canReply'    => Permission::canReply($user, $forum) && (int)$thread['is_locked'] !== 1,
            'canModerate' => $canModerate,
            // 管理员发布的内容，版主也改不了（与删除同一条保护线）
            'canEdit'     => ($isAuthor || $canModerate)
                && Permission::canManageContentOf($user, (int)$thread['user_id']),
            'canCreate'   => Permission::canCreateThread($user, $forum),
            'replyTo'     => $replyTo,
            'uploadEnabled' => (bool)config('app.upload.enabled', true),
            'maxUploadMb'   => (int)round((int)config('app.upload.max_size', 4194304) / 1048576),
            'siteNotice'  => '',
        ], 'layouts/main');
    }

    /**
     * 发帖表单
     *
     * @param array<string, string> $params
     */
    public function create(array $params): string
    {
        $user = $this->requireLogin();

        $forums = ForumModel::visible($user);
        $forums = array_values(array_filter(
            $forums,
            static fn (array $f): bool => Permission::canCreateThread($user, $f)
        ));

        if ($forums === []) {
            \Core\App::abort(403, '当前没有任何版块允许你发表主题。');
        }

        $forumId = Request::int('fid', (int)$forums[0]['id']);
        $forum   = ForumModel::find($forumId);

        if ($forum === null || !Permission::canCreateThread($user, $forum)) {
            $forum   = $forums[0];
            $forumId = (int)$forum['id'];
        }

        return $this->view('thread/create', [
            'pageTitle'     => '发表新主题 - ' . (string)setting('site_name'),
            'forums'        => $forums,
            'forum'         => $forum,
            'uploadEnabled' => (bool)config('app.upload.enabled', true) && (int)$forum['allow_attachment'] === 1,
            'maxUploadMb'   => (int)round((int)config('app.upload.max_size', 4194304) / 1048576),
            'formErrors'    => Session::errors(),
        ], 'layouts/main');
    }

    /**
     * 提交新主题
     *
     * @param array<string, string> $params
     */
    public function store(array $params): string
    {
        $user = $this->requireLogin();

        $this->throttle('post', 'post:' . $user['id']);

        $forumId = Request::int('forum_id', 0);
        $forum   = ForumModel::findOrFail($forumId);

        if (!Permission::canCreateThread($user, $forum)) {
            \Core\App::abort(403, '你没有在该版块发表主题的权限。');
        }

        $title   = Request::string('title', '', 80);
        $content = Request::string('content', '', (int)config('app.post_max_length', 20000));

        $validator = $this->validate(
            ['title' => $title, 'content' => $content],
            [
                'title'   => 'required|between:' . (int)config('app.thread_title_min', 4) . ',' . (int)config('app.thread_title_max', 80),
                'content' => 'required|min:' . (int)config('app.post_min_length', 2) . '|max:' . (int)config('app.post_max_length', 20000),
            ],
            ['title' => '主题标题', 'content' => '正文内容']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), Router::url('/new', ['fid' => $forumId]));
        }

        // 正文交给插件过滤（例如敏感词、自动格式化）
        $content = (string)Hook::filter('content_render', $content, ['user' => $user, 'forum' => $forum]);

        // 插件可在此阻止发帖
        Hook::action('before_thread_create', [
            'user'    => $user,
            'forum'   => $forum,
            'title'   => $title,
            'content' => $content,
        ]);

        // 默认值与 Settings::defaults() 保持一致（默认开启审核）；
        // 有「审核内容」权限的用户组免审，避免管理员/版主自己发帖也要过审
        $needAudit = \Core\Settings::bool('thread_need_audit', false) && !Permission::allows($user, 'post.approve', $forum);

        $result = ThreadModel::publish([
            'forum_id' => $forumId,
            'user_id'  => (int)$user['id'],
            'title'    => $title,
            'is_pinned' => 0,
            'is_essence' => 0,
            'is_locked' => 0,
            'is_recommended' => 0,
            'status'   => $needAudit ? 0 : 1,
        ], $content, Request::intArray('attachments'));

        Hook::action('after_thread_create', [
            'user'      => $user,
            'forum'     => $forum,
            'thread_id' => $result['thread_id'],
            'post_id'   => $result['post_id'],
        ]);

        // 通知版主有待审核内容
        if ($needAudit) {
            $this->notifyModerators($forum, '有新的主题等待审核：' . $title, $result['thread_id'], $result['post_id']);
        }

        $message = $needAudit ? '主题已提交，等待审核通过后展示。' : '主题发表成功。';

        if (Request::wantsJson()) {
            $this->json([
                'ok'       => true,
                'message'  => $message,
                'redirect' => Router::url('/t/' . $result['thread_id']),
            ]);
        }

        $this->redirectWith(Router::url('/t/' . $result['thread_id']), $message);
    }

    /**
     * 编辑主题表单
     *
     * @param array<string, string> $params
     */
    public function edit(array $params): string
    {
        $user   = $this->requireLogin();
        $thread = ThreadModel::findOrFail((int)($params['id'] ?? 0));
        $forum  = ForumModel::findOrFail((int)$thread['forum_id']);

        if (!$this->canEdit($user, $thread, $forum)) {
            \Core\App::abort(403, '你没有编辑该主题的权限。');
        }

        $firstPost = PostModel::firstPost((int)$thread['id']);

        // 编辑页支持附件：回显已有附件 + 可继续上传（保存时统一绑定）
        $attachments = [];
        if ($firstPost !== null) {
            $attachments = AttachmentModel::forEditor(
                AttachmentModel::ofPost((int)$firstPost['id'])
            );
        }

        return $this->view('thread/edit', [
            'pageTitle'         => '编辑主题 - ' . (string)setting('site_name'),
            'thread'            => $thread,
            'forum'             => $forum,
            'firstPost'         => $firstPost,
            'formErrors'        => Session::errors(),
            'editorUpload'      => (bool)config('app.upload.enabled', true),
            'editorMaxMb'       => (int)round((int)config('app.upload.max_size', 20971520) / 1048576),
            'editorAttachments' => $attachments,
        ], 'layouts/main');
    }

    /**
     * 保存主题编辑
     *
     * @param array<string, string> $params
     */
    public function update(array $params): never
    {
        $user   = $this->requireLogin();
        $thread = ThreadModel::findOrFail((int)($params['id'] ?? 0));
        $forum  = ForumModel::findOrFail((int)$thread['forum_id']);

        if (!$this->canEdit($user, $thread, $forum)) {
            \Core\App::abort(403, '你没有编辑该主题的权限。');
        }

        $title   = Request::string('title', '', 80);
        $content = Request::string('content', '', (int)config('app.post_max_length', 20000));

        $validator = $this->validate(
            ['title' => $title, 'content' => $content],
            [
                'title'   => 'required|between:' . (int)config('app.thread_title_min', 4) . ',' . (int)config('app.thread_title_max', 80),
                'content' => 'required|min:' . (int)config('app.post_min_length', 2) . '|max:' . (int)config('app.post_max_length', 20000),
            ],
            ['title' => '主题标题', 'content' => '正文内容']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), Router::url('/t/' . $thread['id'] . '/edit'));
        }

        $content = (string)Hook::filter('content_render', $content, ['user' => $user, 'forum' => $forum]);

        ThreadModel::updateById((int)$thread['id'], [
            'title'          => $title,
            'is_essence'     => $thread['is_essence'],
            'updated_at'     => time(),
        ]);

        $firstPost = PostModel::firstPost((int)$thread['id']);
        if ($firstPost !== null) {
            PostModel::updateContent((int)$firstPost['id'], $content);

            /*
             * 附件绑定：编辑页提交的完整附件列表（回显的已有附件 + 新上传的）。
             * bindToPost 只允许绑定「自己上传」的附件，防越权；列表里不包含的
             * 已有附件保持原绑定不变（不会误删数据）。
             */
            AttachmentModel::bindToPost(
                Request::intArray('attachments'),
                (int)$user['id'],
                (int)$thread['id'],
                (int)$firstPost['id']
            );
        }

        // 同步版块「最后主题名」
        ForumModel::updateForum((int)$forum['id'], []);
        Database::update(
            'forums',
            ['last_thread_name' => $title, 'updated_at' => time()],
            Database::identifier('id') . ' = ? AND ' . Database::identifier('last_thread_id') . ' = ?',
            [(int)$forum['id'], (int)$thread['id']]
        );

        Hook::action('after_thread_update', ['thread_id' => (int)$thread['id'], 'user' => $user]);

        $message = '主题已更新。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => Router::url('/t/' . $thread['id'])]);
        }

        $this->redirectWith(Router::url('/t/' . $thread['id']), $message);
    }

    /**
     * 删除主题
     *
     * @param array<string, string> $params
     */
    public function destroy(array $params): never
    {
        $user   = $this->requireLogin();
        $thread = ThreadModel::findOrFail((int)($params['id'] ?? 0));
        $forum  = ForumModel::findOrFail((int)$thread['forum_id']);

        $isAuthor = (int)$thread['user_id'] === (int)$user['id'];

        // 作者可删自己的主题，版主/管理员可删任意主题
        if (!$isAuthor && !Permission::allows($user, 'thread.delete', $forum)) {
            \Core\App::abort(403, '你没有删除该主题的权限。');
        }

        // 保护线：管理员发布的内容，版主也动不了
        if (!Permission::canManageContentOf($user, (int)$thread['user_id'])) {
            \Core\App::abort(403, '这是管理员发布的主题，只有超级管理员可以删除。');
        }

        ThreadModel::destroy((int)$thread['id']);

        Hook::action('after_thread_delete', ['thread_id' => (int)$thread['id'], 'user' => $user]);

        $message = '主题已删除。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => Router::url('/f/' . $forum['id'])]);
        }

        $this->redirectWith(Router::url('/f/' . $forum['id']), $message);
    }

    /**
     * 版主操作：置顶 / 加精 / 锁定 / 推荐
     *
     * @param array<string, string> $params
     */
    public function moderate(array $params): never
    {
        $user   = $this->requireLogin();
        $thread = ThreadModel::findOrFail((int)($params['id'] ?? 0));
        $forum  = ForumModel::findOrFail((int)$thread['forum_id']);

        if (!Permission::allows($user, 'thread.essence', $forum)) {
            \Core\App::abort(403, '你没有管理该主题的权限。');
        }

        $action = Request::string('action', '', 20);

        $result = ThreadModel::moderate((int)$thread['id'], $action);

        if (Request::wantsJson()) {
            $this->json([
                'ok'      => $result['ok'],
                'message' => $result['message'],
            ], $result['ok'] ? 200 : 400);
        }

        $this->redirectWith(Router::url('/t/' . $thread['id']), $result['message'], $result['ok'] ? 'success' : 'error');
    }

    /**
     * 主题点赞
     *
     * @param array<string, string> $params
     */
    public function toggleLike(array $params): never
    {
        $user     = $this->requireLogin();
        $threadId = (int)($params['id'] ?? 0);

        ThreadModel::findOrFail($threadId);

        $result = LikeModel::toggle((int)$user['id'], 'thread', $threadId);

        $this->json([
            'message' => $result['liked'] ? '点赞成功。' : '已取消点赞。',
            'liked'   => $result['liked'],
            'count'   => $result['count'],
        ]);
    }

    /**
     * 切换收藏
     *
     * @param array<string, string> $params
     */
    public function toggleFavorite(array $params): never
    {
        $user     = $this->requireLogin();
        $threadId = (int)($params['id'] ?? 0);

        ThreadModel::findOrFail($threadId);

        $result = FavoriteModel::toggle((int)$user['id'], $threadId);

        $this->json([
            'message'   => $result['favorited'] ? '已加入收藏。' : '已取消收藏。',
            'favorited' => $result['favorited'],
            'count'     => $result['count'],
        ]);
    }

    /**
     * 搜索
     *
     * @param array<string, string> $params
     */
    public function search(array $params): string
    {
        /*
         * 搜索页的三道限制：
         *   1. 必须登录 —— 未登录由 requireLogin() 引导去登录页（记住来路，登录后跳回）；
         *   2. 频率限制 —— 递进等待：60 秒内第 2 次搜索等 3 秒、第 3 次等 8 秒，以此类推
         *      （只拦新搜索，结果翻页不算）；
         *   3. 内容干净 —— 先掐掉控制字符/零宽字符再参与查询，长度由 Request::string 截到 60。
         *
         * 搜索范围（type）：主题（默认，含公告命中）/ 回复 / 用户。
         * 搜索结果页是典型的动态参数页（?q=…&type=…&page=… 组合近乎无限），
         * 统一输出 X-Robots-Tag: noindex，避免搜索引擎把海量搜索结果都收进索引。
         */
        Auth::requireLogin();

        Response::header('X-Robots-Tag', 'noindex, nofollow');

        $keyword = Text::cleanSearchKeyword(Request::string('q', '', 60));

        $page = $this->currentPage();
        $type = Request::string('type', 'thread');
        if (!in_array($type, ['thread', 'post', 'user'], true)) {
            $type = 'thread';
        }

        // 限流只针对「新搜索」：结果翻页（page>=2）不算一次新搜索，不触发风控
        if ($keyword !== '' && $page <= 1) {
            $this->searchThrottle();
        }

        $notices = [];
        if ($type === 'thread') {
            $result = ThreadModel::search($keyword, $page, (int)config('app.per_page', 20));
            $result['items'] = $keyword === '' ? [] : ThreadModel::decorate($result['items']);
            // 主题范围内顺带命中公告（标题或正文），单独成块展示在主题结果上方
            $notices = $keyword === '' ? [] : NoticeModel::search($keyword, 5);
        } elseif ($type === 'post') {
            $result = PostModel::searchPublished($keyword, $page, (int)config('app.per_page', 20));
        } else {
            $result = UserModel::searchByName($keyword, $page, 20);
        }

        // 无重写环境下分页链接需要带上关键词与范围
        $query = array_filter([
            'q'    => $keyword,
            'type' => $type !== 'thread' ? $type : '',
        ]);

        return $this->view('thread/search', [
            'pageTitle'  => '搜索' . ($keyword !== '' ? '：' . $keyword : '') . ' - ' . (string)setting('site_name'),
            'keyword'    => $keyword,
            'type'       => $type,
            'notices'    => $notices,
            'result'     => $result,
            'pagination' => $keyword === '' ? '' : Paginator::render($result, '/search', $query),
        ], 'layouts/main');
    }

    /**
     * 搜索专用递进限流（不占用通用 throttle 的固定窗口规则）。
     *
     * 60 秒滑动窗口内第 n 次搜索前需等待 n²-1 秒：
     * 第 2 次等 3 秒、第 3 次等 8 秒、第 4 次等 15 秒、第 5 次等 24 秒……
     * 命中即 429（文案带剩余等待时间，响应带 Retry-After 头）。
     */
    private function searchThrottle(): void
    {
        $key   = 'search-throttle:' . substr(hash('sha256', Request::ip()), 0, 24);
        $state = Cache::get($key);
        $now   = time();

        $count = 0;
        $last  = 0;
        if (is_array($state) && isset($state[0], $state[1])) {
            $count = (int)$state[0];
            $last  = (int)$state[1];
        }

        if ($count > 0) {
            $gap  = ($count + 1) * ($count + 1) - 1;      // 3, 8, 15, 24 …
            $wait = $last + $gap - $now;
            if ($wait > 0) {
                $waitText = $wait >= 60
                    ? (int)ceil($wait / 60) . ' 分钟'
                    : $wait . ' 秒';

                $message = '搜索太频繁了，请 ' . $waitText . '后再试。';

                // 页面请求不渲染 429 错误页 —— 那样「返回上一页」回放带关键词的
                // 搜索 URL 又会立刻再撞限流。直接带提示回到空白搜索页：
                // 用户不点「开始搜索」就不会再次触发限流。
                if (!Request::wantsJson()) {
                    $this->redirectWith(url('/search'), $message, 'warning');
                }

                throw new HttpException(429, $message, [
                    'Retry-After' => (string)$wait,
                ]);
            }
        }

        Cache::set($key, [$count + 1, $now], 60);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 是否可编辑该主题
     *
     * @param array<string, mixed> $thread
     * @param array<string, mixed> $forum
     */
    private function canEdit(array $user, array $thread, array $forum): bool
    {
        /*
         * 保护线：超级管理员发布的主题，只有超级管理员能改。
         * 作者编辑自己的主题不受影响（canManageContentOf 对本人直接放行）。
         */
        if (!Permission::canManageContentOf($user, (int)$thread['user_id'])) {
            return false;
        }

        if (Permission::allows($user, 'thread.essence', $forum)) {
            return true;
        }

        if ((int)$thread['user_id'] !== (int)$user['id']) {
            return false;
        }

        if (!Permission::allows($user, 'thread.edit', $forum)) {
            return false;
        }

        // 锁定的主题普通作者不可编辑
        return (int)$thread['is_locked'] !== 1;
    }

    /**
     * 通知版块版主与管理员有新内容待审核
     *
     * @param array<string, mixed> $forum
     */
    private function notifyModerators(array $forum, string $message, int $threadId, int $postId): void
    {
        $moderatorIds = group_ids_from_field((string)$forum['moderators']);

        $rows = Database::select(
            'SELECT ' . Database::identifier('id') . ' FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('group_id') . ' IN (1, 2)'
            . ' AND ' . Database::identifier('status') . ' = 1'
            . ' AND ' . Database::identifier('deleted_at') . ' IS NULL'
            . ' LIMIT 50'
        );

        $recipients = array_unique(array_merge(
            $moderatorIds,
            array_map(static fn (array $r): int => (int)$r['id'], $rows)
        ));

        foreach ($recipients as $recipientId) {
            NotificationModel::push($recipientId, 0, 'audit', $message, $threadId, $postId);
        }
    }
}
