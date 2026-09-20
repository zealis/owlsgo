<?php
/**
 * 评论控制器
 */

declare(strict_types=1);

namespace Modules\Post;

use Core\Auth;
use Core\Controller;
use Core\Hook;
use Core\Permission;
use Core\Request;
use Core\Router;
use Core\Settings;
use Core\Upload;
use Modules\Forum\ForumModel;
use Modules\Thread\ThreadModel;
use Modules\User\AttachmentModel;
use Modules\User\LikeModel;
use Modules\User\NotificationModel;

final class PostController extends Controller
{
    /**
     * 发表评论
     *
     * @param array<string, string> $params
     */
    public function store(array $params): never
    {
        $user     = $this->requireLogin();
        $threadId = (int)($params['id'] ?? 0);

        $this->throttle('post', 'post:' . $user['id']);

        $thread = ThreadModel::findOrFail($threadId);
        $forum  = ForumModel::findOrFail((int)$thread['forum_id']);

        if ((int)$thread['is_locked'] === 1 && !Permission::allows($user, 'thread.essence', $forum)) {
            $this->failOrBack('该帖子已被锁定，无法评论。', Router::url('/t/' . $threadId));
        }

        if (!Permission::canReply($user, $forum)) {
            $this->failOrBack('你没有在该版块评论的权限。', Router::url('/t/' . $threadId));
        }

        if ((int)$thread['status'] !== 1 && !Permission::allows($user, 'thread.essence', $forum)) {
            $this->failOrBack('该帖子正在审核中，暂不可评论。', Router::url('/t/' . $threadId));
        }

        $content = Request::string('content', '', (int)config('app.post_max_length', 20000));

        $validator = $this->validate(
            ['content' => $content],
            ['content' => 'required|min:' . (int)config('app.post_min_length', 2) . '|max:' . (int)config('app.post_max_length', 20000)],
            ['content' => '评论内容']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), Router::url('/t/' . $threadId));
        }

        // 楼中楼：只允许评论同一帖子下的楼层
        $parentId = Request::int('parent_id', 0);
        if ($parentId > 0) {
            $parent = PostModel::find($parentId);
            if ($parent === null || (int)$parent['thread_id'] !== $threadId) {
                $parentId = 0;
            }
        }

        $content = (string)Hook::filter('content_render', $content, ['user' => $user, 'forum' => $forum, 'thread' => $thread]);

        Hook::action('before_post_create', [
            'user'    => $user,
            'thread'  => $thread,
            'forum'   => $forum,
            'content' => $content,
        ]);

        // 默认值与 Settings::defaults() 保持一致（默认开启审核）；有「审核内容」权限的免审
        $needAudit = \Core\Settings::bool('post_need_audit', false)
            && !Permission::allows($user, 'post.approve', $forum);

        $result = PostModel::reply(
            $thread,
            (int)$user['id'],
            $content,
            $parentId,
            Request::intArray('attachments'),
            $needAudit ? 0 : 1
        );

        // 通知：评论楼主 + @提及
        if (!$needAudit) {
            $excerpt = \Core\Text::excerpt($content, 100);
            NotificationModel::notifyThreadAuthor($thread, (int)$user['id'], $excerpt, $result['post_id']);
            NotificationModel::notifyMentions($content, (int)$user['id'], $threadId, $result['post_id']);

            Hook::action('after_post_create', [
                'user'      => $user,
                'thread_id' => $threadId,
                'post_id'   => $result['post_id'],
                'floor'     => $result['floor'],
            ]);
        }

        $message = $needAudit ? '评论已提交，等待审核通过后展示。' : '评论成功，当前为第 ' . $result['floor'] . ' 楼。';

        if (Request::wantsJson()) {
            $this->json([
                'ok'       => true,
                'message'  => $message,
                'post_id'  => $result['post_id'],
                'redirect' => Router::url('/t/' . $threadId, ['p' => $result['post_id']]),
            ]);
        }

        $this->redirectWith(Router::url('/t/' . $threadId, ['p' => $result['post_id']]), $message);
    }

    /**
     * 编辑评论表单
     *
     * @param array<string, string> $params
     */
    public function edit(array $params): string
    {
        $user = $this->requireLogin();
        $post = PostModel::findOrFail((int)($params['id'] ?? 0));

        if ((int)$post['is_first'] === 1) {
            // 首帖的编辑入口属于帖子编辑
            \Core\Response::redirect(Router::url('/t/' . $post['thread_id'] . '/edit'));
        }

        $context = PostModel::withContext((int)$post['id']);

        if ($context === null) {
            \Core\App::abort(404, '评论不存在或已被删除。');
        }

        if (!$this->canEdit($user, $context['post'], $context['forum'])) {
            \Core\App::abort(403, '你没有编辑该评论的权限。');
        }

        return $this->view('post/edit', [
            'pageTitle'         => '编辑评论 - ' . (string)setting('site_name'),
            'post'              => $context['post'],
            'thread'            => $context['thread'],
            'forum'             => $context['forum'],
            'editorUpload'      => Settings::bool('upload_enabled', true),
            'editorMaxMb'       => Upload::maxSizeMb(),
            'editorAttachments' => AttachmentModel::forEditor(
                AttachmentModel::ofPost((int)$context['post']['id'])
            ),
        ], 'layouts/main');
    }

    /**
     * 保存评论编辑
     *
     * @param array<string, string> $params
     */
    public function update(array $params): never
    {
        $user    = $this->requireLogin();
        $post    = PostModel::findOrFail((int)($params['id'] ?? 0));
        $context = PostModel::withContext((int)$post['id']);

        if ($context === null) {
            \Core\App::abort(404, '评论不存在或已被删除。');
        }

        if (!$this->canEdit($user, $context['post'], $context['forum'])) {
            \Core\App::abort(403, '你没有编辑该评论的权限。');
        }

        $content = Request::string('content', '', (int)config('app.post_max_length', 20000));

        $validator = $this->validate(
            ['content' => $content],
            ['content' => 'required|min:' . (int)config('app.post_min_length', 2) . '|max:' . (int)config('app.post_max_length', 20000)],
            ['content' => '评论内容']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), Router::url('/p/' . $post['id'] . '/edit'));
        }

        $content = (string)Hook::filter('content_render', $content, ['user' => $user, 'forum' => $context['forum']]);

        PostModel::updateContent((int)$post['id'], $content);

        /*
         * 附件绑定：编辑页提交的完整附件列表（回显的已有附件 + 新上传的）。
         * 没有这一步的话，编辑时新上传的附件不会被绑定到本评论。
         */
        AttachmentModel::bindToPost(
            Request::intArray('attachments'),
            (int)$user['id'],
            (int)$post['thread_id'],
            (int)$post['id']
        );

        $message = '评论已更新。';

        if (Request::wantsJson()) {
            $this->json([
                'ok'       => true,
                'message'  => $message,
                'redirect' => Router::url('/t/' . $post['thread_id'], ['p' => (int)$post['id']]),
            ]);
        }

        $this->redirectWith(
            Router::url('/t/' . $post['thread_id'], ['p' => (int)$post['id']]),
            $message
        );
    }

    /**
     * 删除评论
     *
     * @param array<string, string> $params
     */
    public function destroy(array $params): never
    {
        $user    = $this->requireLogin();
        $post    = PostModel::findOrFail((int)($params['id'] ?? 0));
        $context = PostModel::withContext((int)$post['id']);

        if ($context === null) {
            \Core\App::abort(404, '评论不存在或已被删除。');
        }

        if ((int)$post['is_first'] === 1) {
            $this->failOrBack('首帖不能单独删除，请删除整个帖子。', Router::url('/t/' . $post['thread_id']));
        }

        $isAuthor = (int)$post['user_id'] === (int)$user['id'];

        if (!$isAuthor && !Permission::allows($user, 'post.delete', $context['forum'])) {
            \Core\App::abort(403, '你没有删除该评论的权限。');
        }

        // 保护线：管理员发布的内容，版主也动不了
        if (!Permission::canManageContentOf($user, (int)$post['user_id'])) {
            \Core\App::abort(403, '这是管理员发布的评论，只有超级管理员可以删除。');
        }

        PostModel::destroy((int)$post['id']);

        Hook::action('after_post_delete', ['post_id' => (int)$post['id'], 'user' => $user]);

        $message = '评论已删除。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => Router::url('/t/' . $post['thread_id'])]);
        }

        $this->redirectWith(Router::url('/t/' . $post['thread_id']), $message);
    }

    /**
     * 评论点赞
     *
     * @param array<string, string> $params
     */
    public function toggleLike(array $params): never
    {
        $user = $this->requireLogin();
        $post = PostModel::findOrFail((int)($params['id'] ?? 0));

        $result = LikeModel::toggle((int)$user['id'], 'post', (int)$post['id']);

        $this->json([
            'message' => $result['liked'] ? '点赞成功。' : '已取消点赞。',
            'liked'   => $result['liked'],
            'count'   => $result['count'],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 是否可编辑该评论
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $forum
     */
    private function canEdit(array $user, array $post, array $forum): bool
    {
        /*
         * 保护线：超级管理员发布的评论，只有超级管理员能改。
         * 作者编辑自己的评论不受影响（canManageContentOf 对本人直接放行）。
         */
        if (!Permission::canManageContentOf($user, (int)$post['user_id'])) {
            return false;
        }

        if (Permission::allows($user, 'post.delete', $forum)) {
            return true;
        }

        if ((int)$post['user_id'] !== (int)$user['id']) {
            return false;
        }

        if (!Permission::allows($user, 'thread.edit', $forum)) {
            return false;
        }

        return (int)$post['status'] === 1;
    }

    /**
     * 统一的失败反馈：AJAX 返回 JSON，普通请求写提示并跳转
     */
    private function failOrBack(string $message, string $url): never
    {
        if (Request::wantsJson()) {
            $this->fail($message, 400);
        }

        $this->redirectWith($url, $message, 'error');
    }

    /** 未使用的引用占位，避免 IDE 误报 */
    private function unusedReferences(): void
    {
        if (Session::has('__never__')) {
            \Core\Text::excerpt('', 1);
        }
    }
}
