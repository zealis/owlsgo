<?php
/**
 * 通知中心：全站通知（公告）的发布与管理
 *
 * 权限模型（与参考实现一致）：
 *  - 查看公告：所有登录用户，入口就是前台「通知」页（/notifications）；
 *  - 发布/编辑/删除、通知中心设置：要求 notice.manage（用户组里的「发布公告」），
 *    由 requireManage() 统一拦截；
 *  - 附件管理：要求 attachment.manage（用户组里的「管理附件」），由
 *    requireAttachmentManage() 拦截 —— 与「发布公告」是两条独立授权，可以只授其一。
 *
 * 附件：编辑页复用站点现有的上传组件（POST /upload，回填隐藏域
 * attachments[]），保存时把附件 ID 序列化进 notices.attachment_ids，
 * 附件管理页据此统计「已引用 / 未被公告引用」。
 */

declare(strict_types=1);

namespace Modules\Notice;

use Core\App;
use Core\Controller;
use Core\Permission;
use Core\Request;
use Core\Router;
use Core\Settings;
use Modules\Admin\LogModel;
use Modules\User\NotificationModel;

final class NoticeController extends Controller
{
    /**
     * 要求「发布公告」权限
     *
     * @return array<string, mixed> 当前用户
     */
    private function requireManage(): array
    {
        $user = $this->requireLogin();

        if (!Permission::allows($user, 'notice.manage')) {
            App::abort(403, '你没有发布公告的权限。');
        }

        return $user;
    }

    /**
     * 附件管理的准入：只看「管理附件」（attachment.manage）。
     *
     * 与「发布公告」是两条独立授权 —— 可以只会管附件、不会发公告，反之亦然，
     * 所以不复用 requireManage()。
     */
    private function requireAttachmentManage(): array
    {
        $user = $this->requireLogin();

        if (!Permission::allows($user, 'attachment.manage')) {
            App::abort(403, '你没有管理附件的权限。');
        }

        return $user;
    }

    /* ------------------------------------------------------------------ */
    /*  公告的增删改                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * 发布公告
     *
     * @param array<string, string> $params
     */
    public function create(array $params): string
    {
        $this->requireManage();

        return $this->view('notice/form', [
            'pageTitle' => '发布公告 - ' . (string)setting('site_name'),
            'notice'    => null,
            'action'    => Router::url('/notices'),
            'heading'   => '发布公告',
            'maxMb'     => (int)setting('upload_max_mb', 2),
        ], 'layouts/main');
    }

    /**
     * 保存新公告
     *
     * @param array<string, string> $params
     */
    public function store(array $params): never
    {
        $user  = $this->requireManage();
        $input = $this->inputFromRequest();
        $back  = Router::url('/notices/create');

        $errors = $this->validateNotice($input);

        if ($errors !== []) {
            $this->backWithErrors($errors, $back);
        }

        $id = NoticeModel::createNotice($input);

        LogModel::record((int)$user['id'], 'notice.create', 'notice:' . $id, '发布公告：' . $input['title']);

        // 公开的公告才推送，草稿不打扰用户
        $this->pushIfNeeded(null, $input, (int)$id, (int)$user['id']);

        $this->redirectWith(Router::url('/notifications'), '公告已发布。');
    }

    /**
     * 编辑公告
     *
     * @param array<string, string> $params
     */
    public function edit(array $params): string
    {
        $this->requireManage();

        $notice = NoticeModel::findOrFail((int)($params['id'] ?? 0));

        return $this->view('notice/form', [
            'pageTitle' => '编辑公告 - ' . (string)setting('site_name'),
            'notice'    => $notice,
            'action'    => Router::url('/notices/' . (int)$notice['id']),
            'heading'   => '编辑公告',
            'maxMb'     => (int)setting('upload_max_mb', 2),
        ], 'layouts/main');
    }

    /**
     * 更新公告
     *
     * @param array<string, string> $params
     */
    public function update(array $params): never
    {
        $user = $this->requireManage();

        $id     = (int)($params['id'] ?? 0);
        $notice = NoticeModel::findOrFail($id);
        $input  = $this->inputFromRequest();

        $errors = $this->validateNotice($input);

        if ($errors !== []) {
            $this->backWithErrors($errors, Router::url('/notices/' . $id . '/edit'));
        }

        NoticeModel::updateNotice($id, $input);

        LogModel::record((int)$user['id'], 'notice.update', 'notice:' . $id, '更新公告：' . $input['title']);

        $this->pushIfNeeded($notice, $input, $id, (int)$user['id']);

        $this->redirectWith(Router::url('/notifications'), '公告已保存。');
    }

    /**
     * 删除公告
     *
     * @param array<string, string> $params
     */
    public function destroy(array $params): never
    {
        $user = $this->requireManage();

        $id     = (int)($params['id'] ?? 0);
        $notice = NoticeModel::findOrFail($id);

        NoticeModel::deleteById($id);

        LogModel::record((int)$user['id'], 'notice.delete', 'notice:' . $id, '删除公告：' . (string)($notice['title'] ?? ''));

        $this->redirectWith(Router::url('/notifications'), '公告已删除。');
    }

    /* ------------------------------------------------------------------ */
    /*  附件管理与通知中心设置                                              */
    /* ------------------------------------------------------------------ */

    /**
     * 附件管理
     *
     * 呈现公告引用的附件资源与统计（已引用 / 未被公告引用）。
     * 附件文件本身的增删由后台「附件」页负责，这里只做引用关系的查看。
     *
     * @param array<string, string> $params
     */
    public function resources(array $params): string
    {
        $this->requireAttachmentManage();

        return $this->view('notice/resources', [
            'pageTitle' => '附件管理 - ' . (string)setting('site_name'),
            'rows'      => NoticeModel::withAttachments(),
            'stats'     => NoticeModel::attachmentStats(),
        ], 'layouts/main');
    }

    /**
     * 通知中心设置
     *
     * @param array<string, string> $params
     */
    public function settings(array $params): string
    {
        $this->requireManage();

        return $this->view('notice/settings', [
            'pageTitle' => '通知中心设置 - ' . (string)setting('site_name'),
            'intro'     => (string)setting('notice_center_intro', ''),
            'push'      => (bool)setting('notice_push_enabled', '1'),
        ], 'layouts/main');
    }

    /**
     * 保存通知中心设置
     *
     * @param array<string, string> $params
     */
    public function saveSettings(array $params): never
    {
        $user = $this->requireManage();

        Settings::save([
            'notice_center_intro' => mb_substr(trim((string)Request::post('notice_center_intro', '')), 0, 120),
            'notice_push_enabled' => Request::bool('notice_push_enabled') ? '1' : '0',
        ]);

        LogModel::record((int)$user['id'], 'notice.settings', 'notice', '更新通知中心设置');

        $this->redirectWith(Router::url('/notices/settings'), '通知中心设置已保存。');
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 从请求里取出公告字段
     *
     * @return array<string, mixed>
     */
    private function inputFromRequest(): array
    {
        return [
            'name'           => Request::string('name', '', 100),
            'title'          => Request::string('title', '', 200),
            'body'           => mb_substr((string)Request::post('body', ''), 0, 20000),
            'attachment_ids' => NoticeModel::normalizeIds(Request::post('attachments', [])),
            'enabled'        => Request::bool('enabled'),
            'sort'           => Request::int('sort', NoticeModel::DEFAULT_SORT),
        ];
    }

    /**
     * 公告字段校验
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function validateNotice(array $input): array
    {
        $errors = [];

        if (trim((string)$input['title']) === '') {
            $errors['title'] = '公告标题不能为空。';
        }

        if (trim((string)$input['body']) === '') {
            $errors['body'] = '公告内容不能为空。';
        }

        return $errors;
    }

    /**
     * 按设置决定是否把公告推送给全体用户
     *
     * 触发条件（三者同时满足）：
     *  1. 通知中心设置里开启了「同步推送」；
     *  2. 公告处于公开状态（草稿不打扰用户）；
     *  3. 新建，或标题/正文相对旧值发生了变化（避免改个排序也全站推送）。
     *
     * @param array<string, mixed>|null $before 旧公告（新建时传 null）
     * @param array<string, mixed>      $input  本次提交
     */
    private function pushIfNeeded(?array $before, array $input, int $id, int $authorId = 0): void
    {
        if (!(bool)setting('notice_push_enabled', '1')) {
            return;
        }

        if (empty($input['enabled'])) {
            return;
        }

        $title = trim((string)$input['title']);
        $body  = trim((string)$input['body']);

        if ($before !== null
            && $title === trim((string)($before['title'] ?? ''))
            && $body === trim((string)($before['body'] ?? ''))) {
            return;
        }

        // 广播内容：「标题」+ 摘要，用户在通知列表里就能读个大概
        $excerpt = mb_substr(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '', 0, 120);

        NotificationModel::broadcastSystem(
            '【公告】' . $title . ($excerpt !== '' ? '：' . $excerpt : ''),
            'system',
            $authorId
        );
    }
}
