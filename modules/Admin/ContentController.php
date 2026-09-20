<?php
/**
 * 后台：内容管理（帖子与评论）
 *
 * 支持按关键词与审核状态筛选、批量视角的删除，以及待审核内容的放行。
 * 所有删除都是软删除，误操作可在数据库层面恢复。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\App;
use Core\Auth;
use Core\Paginator;
use Core\Permission;
use Core\Request;
use Core\Router;
use Modules\Forum\ForumModel;
use Modules\Post\PostModel;
use Modules\Thread\ThreadModel;
use Modules\User\NotificationModel;
use Modules\User\UserModel;

final class ContentController extends AdminBaseController
{
    /**
     * 帖子列表
     *
     * @param array<string, string> $params
     */
    public function threads(array $params): string
    {
        $keyword = Request::string('q', '', 60);
        $status  = $this->statusFilter();
        $scope   = $this->scopeFilter(array_keys(ThreadModel::ADMIN_SCOPES), 'all');
        $page    = $this->currentPage();

        $result = ThreadModel::adminPaginate($keyword, $status, $page, 30, $scope);
        $result['items'] = ThreadModel::decorate($result['items'], false);
        $result['items'] = $this->markDeletable($result['items']);

        $query = array_filter([
            'q'      => $keyword,
            'status' => $status !== -1 ? (string)$status : '',
            'scope'  => $scope !== 'all' ? $scope : '',
        ]);

        return $this->adminView('admin/content-threads', [
            'pageTitle'  => '帖子管理 - ' . (string)setting('site_name'),
            'adminTitle' => '帖子管理',
            'result'     => $result,
            'keyword'    => $keyword,
            'status'     => $status,
            'scope'      => $scope,
            'scopes'     => ThreadModel::ADMIN_SCOPES,
            'forums'     => ForumModel::options(true),
            'canApprove' => Auth::can('post.approve'),
            'pending'    => ThreadModel::pendingCount(),
            'pagination' => Paginator::render($result, '/admin/threads', $query),
        ]);
    }

    /**
     * 评论列表
     *
     * @param array<string, string> $params
     */
    public function posts(array $params): string
    {
        $keyword = Request::string('q', '', 60);
        $status  = $this->statusFilter();
        $scope   = $this->scopeFilter(array_keys(PostModel::ADMIN_SCOPES), 'content');
        $page    = $this->currentPage();

        $result = PostModel::adminPaginate($keyword, $status, $page, 30, $scope);
        $result['items'] = PostModel::decorate($result['items']);
        $result['items'] = $this->markDeletable($result['items']);

        // 补齐所属帖子标题，便于在列表里判断上下文
        $threadIds = array_map(static fn (array $p): int => (int)$p['thread_id'], $result['items']);
        $threads   = ThreadModel::mapByIds($threadIds);

        foreach ($result['items'] as &$item) {
            $thread = $threads[(int)$item['thread_id']] ?? null;
            $item['thread_title'] = (string)($thread['title'] ?? '帖子已删除');
        }
        unset($item);

        $query = array_filter([
            'q'      => $keyword,
            'status' => $status !== -1 ? (string)$status : '',
            'scope'  => $scope !== 'content' ? $scope : '',
        ]);

        return $this->adminView('admin/content-posts', [
            'pageTitle'  => '评论管理 - ' . (string)setting('site_name'),
            'adminTitle' => '评论管理',
            'result'     => $result,
            'keyword'    => $keyword,
            'status'     => $status,
            'scope'      => $scope,
            'scopes'     => PostModel::ADMIN_SCOPES,
            'canApprove' => Auth::can('post.approve'),
            'pending'    => PostModel::pendingCount(),
            'pagination' => Paginator::render($result, '/admin/posts', $query),
        ]);
    }

    /**
     * 回收站：已软删除的帖子与评论混排
     *
     * @param array<string, string> $params
     */
    public function recycle(array $params): string
    {
        $keyword = Request::string('q', '', 60);
        $scope   = $this->scopeFilter(array_keys(RecycleModel::SCOPES), 'all');
        $page    = $this->currentPage();

        $result = RecycleModel::paginate($keyword, $scope, $page, 30);

        $query = array_filter([
            'q'     => $keyword,
            'scope' => $scope !== 'all' ? $scope : '',
        ]);

        return $this->adminView('admin/content-recycle', [
            'pageTitle'  => '回收站 - ' . (string)setting('site_name'),
            'adminTitle' => '回收站',
            'result'     => $result,
            'keyword'    => $keyword,
            'scope'      => $scope,
            'scopes'     => RecycleModel::SCOPES,
            'counts'     => RecycleModel::counts(),
            'pagination' => Paginator::render($result, '/admin/recycle', $query),
        ]);
    }

    /**
     * 批量操作：帖子（删除 / 转移版块）
     *
     * @param array<string, string> $params
     */
    public function bulkThreads(array $params): never
    {
        $action = (string)Request::post('action', '');
        $ids    = Request::intArray('items');
        $back   = Router::url('/admin/threads');

        if ($ids === []) {
            $this->bulkFail('请先勾选要操作的帖子。', $back);
        }

        if ($action === 'move') {
            $targetForumId = Request::int('forum_id', 0);
            $forum         = ForumModel::find($targetForumId);

            if ($forum === null) {
                $this->bulkFail('请选择要转移到哪个版块。', $back);
            }

            // 转移是「改归属」，不涉及内容保护线（不删任何东西）
            $result = ThreadModel::moveToForum($ids, $targetForumId);
            $this->audit(
                'thread.move',
                'forum:' . $targetForumId,
                '批量转移 ' . $result['moved'] . ' 个帖子到「' . (string)$forum['name'] . '」'
            );

            $this->bulkOk('已转移 ' . $result['moved'] . ' 个帖子到「' . (string)$forum['name'] . '」。'
                . ($result['skipped'] > 0 ? '（' . $result['skipped'] . ' 个已在目标版块或被删除，已跳过）' : ''), $back);
        }

        if ($action === 'delete') {
            $done    = 0;
            $blocked = 0;

            foreach ($ids as $threadId) {
                $thread = ThreadModel::find($threadId);

                if ($thread === null) {
                    continue;
                }

                /*
                 * 内容保护线：管理员发布的内容只有超级管理员能删。
                 * 逐条判定而不是整体放行 —— 一次勾选里可能混着普通用户的帖子与管理员发的帖子。
                 */
                if (!Permission::canManageContentOf(Auth::user(), (int)$thread['user_id'])) {
                    $blocked++;
                    continue;
                }

                ThreadModel::destroy($threadId);
                $done++;
            }

            $this->audit('thread.delete', 'bulk', '批量删除 ' . $done . ' 个帖子');

            $this->bulkOk(
                '已删除 ' . $done . ' 个帖子（可在回收站恢复）。'
                . ($blocked > 0 ? ' ' . $blocked . ' 个因「管理员发布的内容只有超级管理员可删」被跳过。' : ''),
                $back
            );
        }

        $this->bulkFail('未知的批量操作。', $back);
    }

    /**
     * 批量操作：评论（删除）
     *
     * @param array<string, string> $params
     */
    public function bulkPosts(array $params): never
    {
        $ids  = Request::intArray('items');
        $back = Router::url('/admin/posts');

        if ($ids === []) {
            $this->bulkFail('请先勾选要操作的评论。', $back);
        }

        $done    = 0;
        $blocked = 0;
        $skipped = 0;

        foreach ($ids as $postId) {
            $post = PostModel::find($postId);

            if ($post === null) {
                continue;
            }

            // 首帖不能单独删（要删整个帖子），批量里直接跳过并计数
            if ((int)$post['is_first'] === 1) {
                $skipped++;
                continue;
            }

            if (!Permission::canManageContentOf(Auth::user(), (int)$post['user_id'])) {
                $blocked++;
                continue;
            }

            PostModel::destroy($postId);
            $done++;
        }

        $this->audit('post.delete', 'bulk', '批量删除 ' . $done . ' 条评论');

        $extra = '';
        if ($blocked > 0) {
            $extra .= ' ' . $blocked . ' 条因内容保护线被跳过。';
        }
        if ($skipped > 0) {
            $extra .= ' ' . $skipped . ' 条是帖子首帖，请到「帖子」页签删除整个帖子。';
        }

        $this->bulkOk('已删除 ' . $done . ' 条评论（可在回收站恢复）。' . $extra, $back);
    }

    /**
     * 批量操作：回收站（恢复 / 彻底删除）
     *
     * @param array<string, string> $params
     */
    public function bulkRecycle(array $params): never
    {
        $action = (string)Request::post('action', '');
        $items  = RecycleModel::parseItems(Request::array('items'));
        $back   = Router::url('/admin/recycle');

        if ($items === []) {
            $this->bulkFail('请先勾选要处理的内容。', $back);
        }

        if ($action === 'restore') {
            $done = 0;

            foreach ($items as $item) {
                if (RecycleModel::restore($item['type'], $item['id'])) {
                    $done++;
                }
            }

            $this->audit('recycle.restore', 'bulk', '从回收站恢复 ' . $done . ' 项内容');

            $this->bulkOk('已恢复 ' . $done . ' 项内容（相关计数已同步加回）。', $back);
        }

        if ($action === 'purge') {
            $done = 0;

            foreach ($items as $item) {
                if (RecycleModel::purge($item['type'], $item['id'])) {
                    $done++;
                }
            }

            $this->audit('recycle.purge', 'bulk', '从回收站彻底删除 ' . $done . ' 项内容');

            $this->bulkOk('已彻底删除 ' . $done . ' 项内容，无法再恢复。', $back);
        }

        $this->bulkFail('未知的批量操作。', $back);
    }

    /**
     * 删除帖子
     *
     * @param array<string, string> $params
     */
    public function deleteThread(array $params): never
    {
        $threadId = (int)($params['id'] ?? 0);
        $thread   = ThreadModel::find($threadId);

        if ($thread === null) {
            App::abort(404, '帖子不存在或已被删除。');
        }

        $back = Request::referer() !== '' ? Request::referer() : Router::url('/admin/threads');

        if (!Permission::canManageContentOf(Auth::user(), (int)$thread['user_id'])) {
            $this->redirectWith($back, '这是管理员发布的帖子，只有超级管理员可以删除。', 'error');
        }

        ThreadModel::destroy($threadId);

        $this->audit('thread.delete', 'thread:' . $threadId, '后台删除帖子：' . (string)$thread['title']);

        $message = '帖子已删除。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 删除评论
     *
     * @param array<string, string> $params
     */
    public function deletePost(array $params): never
    {
        $postId = (int)($params['id'] ?? 0);
        $post   = PostModel::find($postId);

        if ($post === null) {
            App::abort(404, '评论不存在或已被删除。');
        }

        $back = Request::referer() !== '' ? Request::referer() : Router::url('/admin/posts');

        // 首帖不能单独删除，必须连同帖子一起删
        if ((int)$post['is_first'] === 1) {
            $this->redirectWith($back, '这是帖子的首帖，请到「帖子管理」中删除整个帖子。', 'error');
        }

        if (!Permission::canManageContentOf(Auth::user(), (int)$post['user_id'])) {
            $this->redirectWith($back, '这是管理员发布的评论，只有超级管理员可以删除。', 'error');
        }

        PostModel::destroy($postId);

        $this->audit('post.delete', 'post:' . $postId, '后台删除评论（帖子 #' . (int)$post['thread_id'] . '）');

        $message = '评论已删除。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 审核通过评论（首帖通过时帖子一并放行）
     *
     * @param array<string, string> $params
     */
    public function approve(array $params): never
    {
        $postId = (int)($params['id'] ?? 0);
        $post   = PostModel::find($postId);

        if ($post === null) {
            App::abort(404, '内容不存在或已被删除。');
        }

        $back   = Request::referer() !== '' ? Request::referer() : Router::url('/admin/posts');
        $result = PostModel::approve($postId);

        if (!$result['ok']) {
            $this->redirectWith($back, $result['message'], 'error');
        }

        $this->notifyApproved($result['user_id'], $result['message'], $result['thread_id'], $postId);
        $this->audit('post.approve', 'post:' . $postId, $result['message']);

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $result['message'], 'redirect' => $back]);
        }

        $this->redirectWith($back, $result['message']);
    }

    /**
     * 审核通过帖子
     *
     * @param array<string, string> $params
     */
    public function approveThread(array $params): never
    {
        $threadId = (int)($params['id'] ?? 0);
        $thread   = ThreadModel::find($threadId);

        if ($thread === null) {
            App::abort(404, '帖子不存在或已被删除。');
        }

        $back   = Request::referer() !== '' ? Request::referer() : Router::url('/admin/threads');
        $result = ThreadModel::approve($threadId);

        if (!$result['ok']) {
            $this->redirectWith($back, $result['message'], 'error');
        }

        $this->notifyApproved($result['user_id'], '你发表的《' . (string)$thread['title'] . '》已通过审核。', $threadId, 0);
        $this->audit('thread.approve', 'thread:' . $threadId, $result['message']);

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $result['message'], 'redirect' => $back]);
        }

        $this->redirectWith($back, $result['message']);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 解析审核状态筛选参数
     *
     * -1 = 全部；0 = 待审核；1 = 已通过
     */
    /**
     * 为列表行补上「当前用户能否删除该内容」
     *
     * 管理员发布的内容，版主在后台也删不掉 —— 把判断结果附到行上供模板隐藏按钮。
     * 服务端在 deleteThread / deletePost 里还会再判一次：模板只是提示，改 DOM 绕不过去。
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function markDeletable(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        // decorate() 刚查过这批作者并写进了行缓存，这里再取一次不会产生额外查询
        $authorIds = array_map(static fn (array $row): int => (int)$row['user_id'], $items);
        $authors   = UserModel::mapByIds($authorIds);
        $user      = Auth::user();

        foreach ($items as &$item) {
            $authorId = (int)$item['user_id'];

            $item['can_delete'] = Permission::canManageContentOf(
                $user,
                $authorId,
                (int)($authors[$authorId]['group_id'] ?? 0)
            );
        }
        unset($item);

        return $items;
    }

    /**
     * 解析搜索范围参数（不在白名单内就回落到默认值）
     *
     * 白名单校验不能省：范围直接决定走哪条 SQL 分支，绝不能拿用户输入去拼。
     */
    private function scopeFilter(array $allowed, string $default): string
    {
        $raw = (string)Request::query('scope', '');

        return in_array($raw, $allowed, true) ? $raw : $default;
    }

    private function statusFilter(): int
    {
        $raw = Request::query('status', '');

        if ($raw === '' || $raw === null) {
            return -1;
        }

        $status = (int)$raw;

        return in_array($status, [0, 1], true) ? $status : -1;
    }

    /**
     * 通知作者审核结果
     */
    private function notifyApproved(int $userId, string $message, int $threadId, int $postId): void
    {
        if ($userId <= 0) {
            return;
        }

        NotificationModel::push($userId, Auth::id(), 'audit', $message, $threadId, $postId);
    }
}
