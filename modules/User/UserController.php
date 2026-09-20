<?php
/**
 * 用户中心控制器
 *
 * 覆盖：个人主页、Ta 的帖子 / 评论 / 收藏、账号设置（资料 / 密码 / 头像）。
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
use Core\Settings;
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

        /*
         * 头部数据卡要用的「收到的赞」：只在这里算一次（一条聚合查询），
         * 不放进 decorate() —— 那是列表场景用的，加进去会变成每行一条查询。
         */
        $user['like_received'] = UserModel::receivedLikeCount($userId);

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
        ], 'layouts/main');
    }

    /**
     * Ta 发表的帖子
     *
     * @param array<string, string> $params
     */
    /**
     * 标签页可见性：作者把「发表的帖子 / 发表的评论」设为「仅自己可见」时，
     * 只有本人与有 user.manage 权限的人能访问。
     *
     * @param array<string, mixed> $user 目标用户（已 decorate）
     * @param string $tab threads|posts
     */
    private function assertTabVisible(array $user, string $tab): void
    {
        $viewer   = Auth::user();
        $viewerId = (int)($viewer['id'] ?? 0);

        if ($viewerId === (int)($user['id'] ?? 0) || can('user.manage')) {
            return;
        }

        $privacy = UserModel::privacyOf($user);
        $visible = $tab === 'threads' ? $privacy['threads'] : $privacy['posts'];

        if (!$visible) {
            App::abort(403, (string)($user['username'] ?? '该用户') . ' 没有公开这一页。');
        }
    }

    /**
     * 保存「我的隐私」（个人主页两个标签页的公开性）
     *
     * 开关以「勾选 = 所有人可见」提交：复选框未勾选时浏览器根本不发这个字段，
     * Request::bool 取到的就是 false（= 仅自己可见）。
     *
     * @param array<string, string> $params
     */
    public function updatePrivacy(array $params): never
    {
        $user = $this->requireLogin();

        UserModel::updatePrivacy(
            (int)$user['id'],
            Request::bool('public_threads'),
            Request::bool('public_posts')
        );

        Hook::action('after_privacy_update', ['user_id' => (int)$user['id']]);

        $this->redirectWith(Router::url('/settings'), '隐私设置已保存。');
    }

    public function threads(array $params): string
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '该用户不存在或已注销。');
        }

        $user   = UserModel::decorate($user);
        $this->assertTabVisible($user, 'threads');

        $page   = $this->currentPage();
        $result = ThreadModel::byUser($userId, $page, (int)config('app.per_page', 20));
        $result['items'] = ThreadModel::decorate($result['items']);

        return $this->view('user/threads', [
            'pageTitle'  => (string)$user['username'] . ' 发表的帖子 - ' . (string)setting('site_name'),
            'profile'    => $user,
            'result'     => $result,
            'pagination' => Paginator::render($result, '/u/' . $userId . '/threads'),
        ], 'layouts/main');
    }

    /**
     * Ta 发表的评论
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
        $this->assertTabVisible($user, 'posts');

        $page   = $this->currentPage();
        $result = PostModel::byUser($userId, $page, (int)config('app.per_page', 20));
        $result['items'] = PostModel::decorate($this->attachThreadTitles($result['items']));

        return $this->view('user/posts', [
            'pageTitle'  => (string)$user['username'] . ' 发表的评论 - ' . (string)setting('site_name'),
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
            /*
             * 上传开关取**站点设置**（后台「附件 → 允许上传附件」）。
             *
             * 这里原本读的是 config('app.upload.enabled') —— 那是 config/app.php 里的
             * 静态常量（恒为 true），跟后台开关根本不是同一个值，于是后台关掉上传后
             * 设置页仍然渲染出上传表单，用户点进去才被 Upload::store 拦。
             */
            'uploadEnabled' => Settings::bool('upload_enabled', true),
            'avatarMaxMb'   => Upload::maxSizeMb(true),
        ], 'layouts/main');
    }

    /**
     * 个性装扮页：夜间模式跟随系统等**个人外观偏好**。
     *
     * 这些偏好存在浏览器 localStorage（键 owlsgo_theme，见 public/assets/js/theme-boot.js），
     * 不落库 —— 深浅色属于「这台设备/这个浏览器」的偏好而不是账号属性，
     * 游客在顶栏齿轮里也能切换，服务端不参与读写。
     * 因此页面没有 POST 表单，开关状态由 app.js 的 initThemeToggle() 按当前偏好回填。
     *
     * @param array<string, string> $params
     */
    public function appearance(array $params): string
    {
        $user = $this->requireLogin();

        return $this->view('user/appearance', [
            'pageTitle' => '个性装扮 - ' . (string)setting('site_name'),
            'profile'   => UserModel::decorate($user),
        ], 'layouts/main');
    }

    /**
     * 保存账号信息（用户名 / 邮箱）
     *
     * 这两个字段是账号标识：修改前先做格式校验 + 唯一性检查，再走
     * verifyAccountChange() 这个身份校验扩展点（验证码 / 人机验证的接入位）。
     *
     * @param array<string, string> $params
     */
    public function updateAccount(array $params): never
    {
        $user   = $this->requireLogin();
        $userId = (int)$user['id'];

        $username = trim(Request::string('username', '', 20));
        $email    = trim(Request::string('email', '', 191));

        $validator = $this->validate(
            ['username' => $username, 'email' => $email],
            ['username' => 'required|username', 'email' => 'required|email|max:191'],
            ['username' => '用户名', 'email' => '邮箱']
        );

        $errors = $validator->errors();

        // 唯一性：排除自己（改成原值不算占用）
        if ($username !== '' && strcasecmp($username, (string)$user['username']) !== 0
            && UserModel::usernameTaken($username, $userId)) {
            $errors['username'] = '该用户名已被占用，换一个试试。';
        }

        if ($email !== '' && strcasecmp($email, (string)$user['email']) !== 0
            && UserModel::emailTaken($email, $userId)) {
            $errors['email'] = '该邮箱已被其他账号使用。';
        }

        if ($errors !== []) {
            $this->backWithErrors($errors, Router::url('/settings'));
        }

        /*
         * 身份校验扩展点 —— 接入图形验证码 / 人机验证（Turnstile、极验等）时
         * 只需要在这里补校验，表单与调用链都不用动：
         *   · 服务端：本方法或插件挂 before_account_change 钩子；
         *   · 表单侧：settings 模板已用 hook('account_change_fields') 预留字段位。
         */
        $this->verifyAccountChange($user, ['username' => $username, 'email' => $email]);

        UserModel::updateAccount($userId, $username, $email);

        \Modules\Admin\LogModel::record(
            $userId,
            'account.update',
            'user:' . $userId,
            '更新账号信息：' . (string)$user['username'] . ' → ' . $username
        );

        Hook::action('after_account_update', [
            'user_id'  => $userId,
            'username' => $username,
            'email'    => $email,
        ]);

        $message = '账号信息已更新。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => Router::url('/settings')]);
        }

        $this->redirectWith(Router::url('/settings'), $message);
    }

    /**
     * 账号信息变更前的身份校验（验证码 / 人机验证的接入位）
     *
     * 当前直接放行，只广播一个 action 钩子 —— 将来要加人机验证时，
     * 在这里（或插件监听 before_account_change）校验失败抛异常/回跳即可，
     * 不必改动控制器主流程与模板结构。
     *
     * @param array<string, mixed> $user
     * @param array<string, string> $input
     */
    private function verifyAccountChange(array $user, array $input): void
    {
        Hook::action('before_account_change', ['user' => $user, 'input' => $input]);
    }

    /**
     * 保存个人资料
     *
     * @param array<string, string> $params
     */
    public function updateProfile(array $params): never
    {
        $user = $this->requireLogin();

        // 个人简介会展示在个人主页与每个楼层下方，因此与签名合并为一个字段
        $bio = Request::string('bio', '', 100);

        // 它出现在帖子流里，不允许夹带链接，避免被当作外链广告位
        if ($bio !== '' && preg_match('#(https?://|www\.)#i', $bio) === 1) {
            $this->backWithErrors(['bio' => '个人简介中不允许包含网址。'], Router::url('/settings'));
        }

        UserModel::updateProfile((int)$user['id'], ['bio' => $bio]);

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

        /*
         * 站点关闭上传后，头像这条路也必须一起关。
         *
         * 头像走的是本方法（不是 UploadController），所以 UploadController 里那句
         * upload_enabled 判断管不到这里 —— 关掉开关后仍能上传头像，就是这么来的。
         * 两处都判一次，任一入口被单独调用时都不会漏。
         */
        if (!Settings::bool('upload_enabled', true)) {
            $this->redirectWith(Router::url('/settings'), '站点当前已关闭文件上传，无法上传头像。', 'error');
        }

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
     * 为评论列表补齐所属帖子标题（一次批量查询）
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

            $post['thread_title'] = (string)($thread['title'] ?? '帖子已删除');
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
