<?php
/**
 * 后台：内容管理（主题与回复）
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
     * 主题列表
     *
     * @param array<string, string> $params
     */
    public function threads(array $params): string
    {
        $keyword = Request::string('q', '', 60);
        $status  = $this->statusFilter();
        $page    = $this->currentPage();

        $result = ThreadModel::adminPaginate($keyword, $status, $page, 30);
        $result['items'] = ThreadModel::decorate($result['items'], false);
        $result['items'] = $this->markDeletable($result['items']);

        $query = array_filter([
            'q'      => $keyword,
            'status' => $status !== -1 ? (string)$status : '',
        ]);

        return $this->adminView('admin/content-threads', [
            'pageTitle'  => '主题管理 - ' . (string)setting('site_name'),
            'adminTitle' => '主题管理',
            'result'     => $result,
            'keyword'    => $keyword,
            'status'     => $status,
            'forums'     => ForumModel::options(true),
            'canApprove' => Auth::can('post.approve'),
            'pending'    => ThreadModel::pendingCount(),
            'pagination' => Paginator::render($result, '/admin/threads', $query),
        ]);
    }

    /**
     * 回复列表
     *
     * @param array<string, string> $params
     */
    public function posts(array $params): string
    {
        $keyword = Request::string('q', '', 60);
        $status  = $this->statusFilter();
        $page    = $this->currentPage();

        $result = PostModel::adminPaginate($keyword, $status, $page, 30);
        $result['items'] = PostModel::decorate($result['items']);
        $result['items'] = $this->markDeletable($result['items']);

        // 补齐所属主题标题，便于在列表里判断上下文
        $threadIds = array_map(static fn (array $p): int => (int)$p['thread_id'], $result['items']);
        $threads   = ThreadModel::mapByIds($threadIds);

        foreach ($result['items'] as &$item) {
            $thread = $threads[(int)$item['thread_id']] ?? null;
            $item['thread_title'] = (string)($thread['title'] ?? '主题已删除');
        }
        unset($item);

        $query = array_filter([
            'q'      => $keyword,
            'status' => $status !== -1 ? (string)$status : '',
        ]);

        return $this->adminView('admin/content-posts', [
            'pageTitle'  => '回复管理 - ' . (string)setting('site_name'),
            'adminTitle' => '回复管理',
            'result'     => $result,
            'keyword'    => $keyword,
            'status'     => $status,
            'canApprove' => Auth::can('post.approve'),
            'pending'    => PostModel::pendingCount(),
            'pagination' => Paginator::render($result, '/admin/posts', $query),
        ]);
    }

    /**
     * 删除主题
     *
     * @param array<string, string> $params
     */
    public function deleteThread(array $params): never
    {
        $threadId = (int)($params['id'] ?? 0);
        $thread   = ThreadModel::find($threadId);

        if ($thread === null) {
            App::abort(404, '主题不存在或已被删除。');
        }

        $back = Request::referer() !== '' ? Request::referer() : Router::url('/admin/threads');

        if (!Permission::canManageContentOf(Auth::user(), (int)$thread['user_id'])) {
            $this->redirectWith($back, '这是管理员发布的主题，只有超级管理员可以删除。', 'error');
        }

        ThreadModel::destroy($threadId);

        $this->audit('thread.delete', 'thread:' . $threadId, '后台删除主题：' . (string)$thread['title']);

        $message = '主题已删除。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 删除回复
     *
     * @param array<string, string> $params
     */
    public function deletePost(array $params): never
    {
        $postId = (int)($params['id'] ?? 0);
        $post   = PostModel::find($postId);

        if ($post === null) {
            App::abort(404, '回复不存在或已被删除。');
        }

        $back = Request::referer() !== '' ? Request::referer() : Router::url('/admin/posts');

        // 首帖不能单独删除，必须连同主题一起删
        if ((int)$post['is_first'] === 1) {
            $this->redirectWith($back, '这是主题的首帖，请到「主题管理」中删除整个主题。', 'error');
        }

        if (!Permission::canManageContentOf(Auth::user(), (int)$post['user_id'])) {
            $this->redirectWith($back, '这是管理员发布的回复，只有超级管理员可以删除。', 'error');
        }

        PostModel::destroy($postId);

        $this->audit('post.delete', 'post:' . $postId, '后台删除回复（主题 #' . (int)$post['thread_id'] . '）');

        $message = '回复已删除。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 审核通过回复（首帖通过时主题一并放行）
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
     * 审核通过主题
     *
     * @param array<string, string> $params
     */
    public function approveThread(array $params): never
    {
        $threadId = (int)($params['id'] ?? 0);
        $thread   = ThreadModel::find($threadId);

        if ($thread === null) {
            App::abort(404, '主题不存在或已被删除。');
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
