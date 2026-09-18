<?php
/**
 * 后台：版块管理
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Auth;
use Core\Request;
use Core\Router;
use Modules\Forum\ForumModel;
use Modules\User\NotificationModel;
use Modules\User\UsergroupModel;
use Modules\User\UserModel;

final class ForumController extends AdminBaseController
{
    /**
     * 版块列表
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        // 后台需要看到隐藏版块，因此 includeHidden = true
        $tree = ForumModel::tree(ForumModel::visible(Auth::user(), true));

        $rows = [];
        foreach ($tree as $node) {
            $rows[] = ['forum' => $node['forum'], 'depth' => 0];

            foreach ($node['children'] as $child) {
                $rows[] = ['forum' => $child, 'depth' => 1];
            }
        }

        return $this->adminView('admin/forums', [
            'pageTitle'  => '版块管理 - ' . (string)setting('site_name'),
            'adminTitle' => '版块管理',
            'rows'       => $rows,
            'total'      => count($rows),
        ]);
    }

    /**
     * 新增版块表单
     *
     * @param array<string, string> $params
     */
    public function create(array $params): string
    {
        return $this->adminView('admin/forum-form', [
            'pageTitle'  => '新增版块 - ' . (string)setting('site_name'),
            'adminTitle' => '新增版块',
            'forum'      => null,
            'parents'    => ForumModel::options(false),
            'groups'     => UsergroupModel::options(),
            // 版主名单从用户表中挑选，避免模板自行查库
            'moderatorCandidates' => UserModel::options(),
            'action'     => Router::url('/admin/forums'),
        ]);
    }

    /**
     * 保存新板块
     *
     * @param array<string, string> $params
     */
    public function store(array $params): never
    {
        $input = $this->inputFromRequest();
        $back  = Router::url('/admin/forums/create');

        $validator = $this->validate(
            ['name' => $input['name'], 'slug' => $input['slug']],
            ['name' => 'required|between:1,60', 'slug' => 'alpha_dash'],
            ['name' => '版块名称', 'slug' => '版块标识']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), $back);
        }

        $result = ForumModel::createForum($input);

        if (!$result['ok']) {
            $this->backWithErrors(['name' => $result['message']], $back);
        }

        $this->audit('forum.create', 'forum:' . $result['id'], '新增版块：' . $input['name']);

        // 新建版块时若已填写公告，同样广播成系统通知（与编辑时的行为一致）
        $announce = trim((string)($input['announcement'] ?? ''));

        if ($announce !== '') {
            NotificationModel::broadcastSystem(
                '【' . (string)$input['name'] . '】' . $announce,
                'system',
                Auth::id()
            );
        }

        $this->redirectWith(Router::url('/admin/forums'), $result['message']);
    }

    /**
     * 编辑版块表单
     *
     * @param array<string, string> $params
     */
    public function edit(array $params): string
    {
        $forum = ForumModel::findOrFail((int)($params['id'] ?? 0));

        return $this->adminView('admin/forum-form', [
            'pageTitle'  => '编辑版块 - ' . (string)setting('site_name'),
            'adminTitle' => '编辑版块',
            'forum'      => $forum,
            'parents'    => $this->parentOptions((int)$forum['id']),
            'groups'     => UsergroupModel::options(),
            // 版主名单从用户表中挑选，避免模板自行查库
            'moderatorCandidates' => UserModel::options(),
            'action'     => Router::url('/admin/forums/' . (int)$forum['id']),
        ]);
    }

    /**
     * 保存版块编辑
     *
     * @param array<string, string> $params
     */
    public function update(array $params): never
    {
        $forumId = (int)($params['id'] ?? 0);
        $forum   = ForumModel::findOrFail($forumId);

        // 留一份旧公告用于比对：只有公告真的变了才广播通知
        $announceBefore = trim((string)($forum['announcement'] ?? ''));

        $input = $this->inputFromRequest();
        $back  = Router::url('/admin/forums/' . $forumId . '/edit');

        $validator = $this->validate(
            ['name' => $input['name'], 'slug' => $input['slug']],
            ['name' => 'required|between:1,60', 'slug' => 'alpha_dash'],
            ['name' => '版块名称', 'slug' => '版块标识']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), $back);
        }

        // 不允许把自己作为自己的父版块
        if ((int)$input['parent_id'] === $forumId) {
            $input['parent_id'] = 0;
        }

        $result = ForumModel::updateForum($forumId, $input);

        if (!$result['ok']) {
            $this->backWithErrors(['name' => $result['message']], $back);
        }

        /*
         * 版块公告变化时广播成系统通知。
         * 前台版块页已不再渲染公告块，通知是公告唯一的展示出口；
         * 内容带上版块名，用户在通知列表里能一眼看出来自哪个版块。
         */
        $announceAfter = trim((string)($input['announcement'] ?? ''));

        if ($announceAfter !== '' && $announceAfter !== $announceBefore) {
            NotificationModel::broadcastSystem(
                '【' . (string)$forum['name'] . '】' . $announceAfter,
                'system',
                Auth::id()
            );
        }

        $this->audit('forum.update', 'forum:' . $forumId, '更新版块：' . $input['name']);

        $this->redirectWith(Router::url('/admin/forums'), $result['message']);
    }

    /**
     * 删除版块
     *
     * @param array<string, string> $params
     */
    public function destroy(array $params): never
    {
        $forumId = (int)($params['id'] ?? 0);
        $forum   = ForumModel::findOrFail($forumId);
        $back    = Router::url('/admin/forums');

        $result = ForumModel::deleteForum($forumId);

        if (!$result['ok']) {
            $this->redirectWith($back, $result['message'], 'error');
        }

        $this->audit('forum.delete', 'forum:' . $forumId, '删除版块：' . (string)$forum['name']);

        $this->redirectWith($back, $result['message']);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 从表单构建版块输入
     *
     * 说明：多选框未勾选时浏览器不会提交该字段，因此布尔项一律显式取值，
     * 避免「取消勾选却保存为开启」这类静默错误。
     *
     * @return array<string, mixed>
     */
    private function inputFromRequest(): array
    {
        return [
            'name'             => Request::string('name', '', 60),
            'slug'             => Request::string('slug', '', 60),
            'description'      => Request::string('description', '', 200),
            'announcement'     => Request::string('announcement', '', 1000),
            'icon'             => Request::string('icon', '', 40),
            'parent_id'        => Request::int('parent_id', 0),
            'sort_order'       => Request::int('sort_order', 0),
            'status'           => Request::bool('status') ? 1 : 0,
            'allow_thread'     => Request::bool('allow_thread') ? 1 : 0,
            'allow_reply'      => Request::bool('allow_reply') ? 1 : 0,
            'allow_attachment' => Request::bool('allow_attachment') ? 1 : 0,
            'group_view'       => Request::intArray('group_view'),
            'group_thread'     => Request::intArray('group_thread'),
            'group_reply'      => Request::intArray('group_reply'),
            'moderators'       => Request::intArray('moderators'),
        ];
    }

    /**
     * 父版块选项（排除自己，避免形成自引用）
     *
     * @return array<int, string>
     */
    private function parentOptions(int $selfId): array
    {
        $options = [];

        foreach (ForumModel::options(true) as $id => $name) {
            if ((int)$id === $selfId) {
                continue;
            }
            $options[$id] = $name;
        }

        return $options;
    }
}
