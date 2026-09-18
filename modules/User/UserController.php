<?php
/**
 * 用户中心控制器
 *
 * 覆盖：个人主页、Ta 的主题 / 回复 / 收藏、账号设置（资料 / 密码 / 头像）。
 *
 * 设计要点：
 *  - 所有列表都走「一次分页查询 + 批量补齐展示字段」，不产生 N+1
 *  - 隐私字段（邮箱、最后登录 IP）只在本人或具备 user.manage 权限时输出
 *  - 头像上传复用 Core\Upload 的 MIME 校验与随机重命名，不另开口子
 */

declare(strict_types=1);

namespace Modules\User;

use Core\App;
use Core\Auth;
use Core\Controller;
use Core\Hook;
use Core\Paginator;
use Core\Request;
use Core\Router;
use Core\Security;
use Core\Upload;
use Modules\Post\PostModel;
use Modules\Thread\FavoriteModel;
use Modules\Thread\ThreadModel;

final class UserController extends Controller
{
    /**
     * 个人主页
     *
     * @param array<string, string> $params
     */
    public function show(array $params): string
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '该用户不存在或已注销。');
        }

        $viewer = Auth::user();
        $isSelf = $viewer !== null && (int)$viewer['id'] === $userId;

        // 被禁用账号的主页仅本人与管理员可见
        if ((int)$user['status'] !== 1 && !$isSelf && !Auth::can('user.manage')) {
            App::abort(404, '该用户不存在或已注销。');
        }

        $user = UserModel::decorate($user);

        $recentThreads = ThreadModel::byUser($userId, 1, 10);
        $recentThreads['items'] = ThreadModel::decorate($recentThreads['items']);

        $recentPosts = PostModel::byUser($userId, 1, 10);
        $recentPosts['items'] = $this->attachThreadTitles($recentPosts['items']);

        return $this->view('user/show', [
            'pageTitle'     => (string)$user['username'] . ' 的个人主页 - ' . (string)setting('site_name'),
            'profile'       => $user,
            'isSelf'        => $isSelf,
            'canManage'     => Auth::can('user.manage'),
            'recentThreads' => $recentThreads['items'],
            'recentPosts'   => $recentPosts['items'],
            'stats'         => [
                'threads'   => (int)$user['thread_count'],
                'posts'     => (int)$user['post_count'],
                'favorites' => (int)$user['favorite_count'],
                'points'    => (int)$user['points'],
            ],
            'joinedAt'   => (int)$user['created_at'],
            'lastActive' => (int)$user['last_active_at'],
        ], 'layouts/main');
    }

    /**
     * Ta 发表的主题
     *
     * @param array<string, string> $params
     */
    public function threads(array $params): string
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '该用户不存在或已注销。');
        }

        $user   = UserModel::decorate($user);
        $page   = $this->currentPage();
        $result = ThreadModel::byUser($userId, $page, (int)config('app.per_page', 20));
        $result['items'] = ThreadModel::decorate($result['items']);

        return $this->view('user/threads', [
            'pageTitle'  => (string)$user['username'] . ' 发表的主题 - ' . (string)setting('site_name'),
            'profile'    => $user,
            'result'     => $result,
            'pagination' => Paginator::render($result, '/u/' . $userId . '/threads'),
        ], 'layouts/main');
    }

    /**
     * Ta 发表的回复
     *
     * @param array<string, string> $params
     */
    public function posts(array $params): string
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '该用户不存在或已注销。');
        }

        $user   = UserModel::decorate($user);
        $page   = $this->currentPage();
        $result = PostModel::byUser($userId, $page, (int)config('app.per_page', 20));
        $result['items'] = PostModel::decorate($this->attachThreadTitles($result['items']));

        return $this->view('user/posts', [
            'pageTitle'  => (string)$user['username'] . ' 发表的回复 - ' . (string)setting('site_name'),
            'profile'    => $user,
            'result'     => $result,
            'pagination' => Paginator::render($result, '/u/' . $userId . '/posts'),
        ], 'layouts/main');
    }

    /**
     * Ta 的收藏（仅本人与管理员可见）
     *
     * @param array<string, string> $params
     */
    public function favorites(array $params): string
    {
        $viewer = $this->requireLogin();
        $userId = (int)($params['id'] ?? 0);

        if ($userId !== (int)$viewer['id'] && !Auth::can('user.manage')) {
            App::abort(403, '收藏夹是私密内容，你没有权限查看。');
        }

        $user = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '该用户不存在或已注销。');
        }

        $page   = $this->currentPage();
        $result = FavoriteModel::paginateForUser($userId, $page, (int)config('app.per_page', 20));

        return $this->view('user/favorites', [
            'pageTitle'  => '我的收藏 - ' . (string)setting('site_name'),
            'profile'    => UserModel::decorate($user),
            'result'     => $result,
            'pagination' => Paginator::render($result, '/u/' . $userId . '/favorites'),
        ], 'layouts/main');
    }

    /**
     * 账号设置页
     *
     * @param array<string, string> $params
     */
    public function settings(array $params): string
    {
        $user = $this->requireLogin();

        return $this->view('user/settings', [
            'pageTitle' => '账号设置 - ' . (string)setting('site_name'),
            'profile'   => UserModel::decorate($user),
            'uploadEnabled' => (bool)config('app.upload.enabled', true),
            'avatarMaxMb'   => (int)round((int)config('app.upload.avatar_size', 2097152) / 1048576),
            'avatarStyles'  => \Core\Avatar::STYLES,
        ], 'layouts/main');
    }

    /**
     * 保存个人资料
     *
     * @param array<string, string> $params
     */
    public function updateProfile(array $params): never
    {
        $user = $this->requireLogin();

        $signature = Request::string('signature', '', 100);
        $bio       = Request::string('bio', '', 500);

        // 个性签名不允许夹带链接，避免被当作外链广告位
        if ($signature !== '' && preg_match('#(https?://|www\.)#i', $signature) === 1) {
            $this->backWithErrors(['signature' => '个性签名中不允许包含网址。'], Router::url('/settings'));
        }

        UserModel::updateProfile((int)$user['id'], [
            'signature' => $signature,
            'bio'       => $bio,
        ]);

        Hook::action('after_profile_update', ['user_id' => (int)$user['id']]);

        $message = '个人资料已保存。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => Router::url('/settings')]);
        }

        $this->redirectWith(Router::url('/settings'), $message);
    }

    /**
     * 修改密码
     *
     * @param array<string, string> $params
     */
    public function updatePassword(array $params): never
    {
        $user = $this->requireLogin();

        $current = (string)($_POST['current_password'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');

        $validator = $this->validate(
            [
                'current_password' => $current,
                'password'         => $password,
                'password_confirm' => $confirm,
            ],
            [
                'current_password' => 'required',
                'password'         => 'required',
                'password_confirm' => 'required|same:password',
            ],
            [
                'current_password' => '当前密码',
                'password'         => '新密码',
                'password_confirm' => '确认新密码',
            ]
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), Router::url('/settings'));
        }

        if (!Security::verifyPassword($current, (string)$user['password_hash'])) {
            $this->backWithErrors(['current_password' => '当前密码不正确。'], Router::url('/settings'));
        }

        // 新密码相关的多条规则一次性上报，避免用户「改一条、报一条」
        $passwordErrors = [];

        if ($current === $password) {
            $passwordErrors[] = '新密码不能与当前密码相同。';
        }

        if ($password !== '') {
            $passwordErrors = array_merge($passwordErrors, Security::passwordStrengthErrors($password));
        }

        if ($passwordErrors !== []) {
            $this->backWithErrors(['password' => $passwordErrors], Router::url('/settings'));
        }

        // 更新密码哈希；同时更换「记住我」密钥的派生来源，
        // 使其他设备上旧的记住我 Cookie 立即失效（Auth 会按新哈希校验）
        UserModel::updateById((int)$user['id'], [
            'password_hash' => Security::hashPassword($password),
        ]);

        Hook::action('after_password_change', ['user_id' => (int)$user['id']]);

        $message = '密码已更新，其他设备需要重新登录。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => Router::url('/settings')]);
        }

        $this->redirectWith(Router::url('/settings'), $message);
    }

    /**
     * 上传头像
     *
     * @param array<string, string> $params
     */
    public function uploadAvatar(array $params): never
    {
        $user = $this->requireLogin();

        if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
            $this->redirectWith(Router::url('/settings'), '请选择要上传的图片。', 'error');
        }

        $this->throttle('upload', 'upload:' . (int)$user['id']);

        try {
            $stored = Upload::store($_FILES['avatar'], true);
        } catch (\RuntimeException $e) {
            $this->redirectWith(Router::url('/settings'), $e->getMessage(), 'error');
        }

        $old = trim((string)($user['avatar'] ?? ''));

        UserModel::updateById((int)$user['id'], ['avatar' => $stored['path']]);

        // 换新头像后删除旧文件，避免存储无限增长
        if ($old !== '' && $old !== $stored['path']) {
            Upload::remove($old);
        }

        Hook::action('after_avatar_upload', [
            'user_id' => (int)$user['id'],
            'path'    => (string)$stored['path'],
        ]);

        $message = '头像已更新。';

        if (Request::wantsJson()) {
            $preview = avatar_url([
                'id'       => (int)$user['id'],
                'username' => (string)$user['username'],
                'avatar'   => (string)$stored['path'],
            ], 96);

            $this->json(['ok' => true, 'message' => $message, 'url' => $preview]);
        }

        $this->redirectWith(Router::url('/settings'), $message);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 为回帖列表补齐所属主题标题（一次批量查询）
     *
     * @param list<array<string, mixed>> $posts
     * @return list<array<string, mixed>>
     */
    private function attachThreadTitles(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $threadIds = array_map(static fn (array $p): int => (int)$p['thread_id'], $posts);
        $threads   = ThreadModel::mapByIds($threadIds);

        foreach ($posts as &$post) {
            $thread = $threads[(int)$post['thread_id']] ?? null;

            $post['thread_title'] = (string)($thread['title'] ?? '主题已删除');
            $post['forum_id']     = (int)($thread['forum_id'] ?? 0);
        }
        unset($post);

        return $posts;
    }

    /**
     * 应用一个预置头像（Avatar::presets() 清单里的 seed + 风格）
     *
     * 预置头像不落磁盘：users.avatar 里只存 `preset:{seed}|{style}` 标记，
     * 渲染时由 Avatar::url() 实时生成 SVG（见 Avatar::url 的 preset 分支）。
     */
    public function applyPresetAvatar(array $params): never
    {
        $user = $this->requireLogin();

        $seed  = strtolower(trim((string)Request::string('seed', '', 64)));
        $style = strtolower(trim((string)Request::string('style', '', 32)));

        // 只放行安全字符与合法风格，防止把任意路径/参数写进 avatar 字段
        if ($seed === '' || preg_match('/^[a-z0-9_-]{1,64}$/', $seed) !== 1
            || !in_array($style, \Core\Avatar::STYLES, true)) {
            $this->redirectWith(Router::url('/settings'), '预置头像参数无效。', 'error');
        }

        $old = trim((string)($user['avatar'] ?? ''));

        UserModel::updateById((int)$user['id'], ['avatar' => 'preset:' . $seed . '|' . $style]);

        // 之前上传的自定义头像文件不再被引用，删除以免存储无限增长
        if ($old !== '' && !str_starts_with($old, 'preset:')) {
            Upload::remove($old);
        }

        $this->redirectWith(Router::url('/settings'), '预置头像已应用。');
    }
}
