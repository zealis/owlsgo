<?php
/**
 * 后台：用户管理
 *
 * 包含三件事：
 *  1. 用户检索与分组筛选
 *  2. 资料调整（用户名 / 邮箱 / 用户组 / 状态 / 积分 / 简介）
 *  3. 封禁与解封（账号级 + 可选同时封禁 IP / 邮箱）
 *
 * 关键防呆：管理员不能修改自己的用户组、不能禁用自己的账号，
 * 避免「把自己锁在门外」这种不可逆事故。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\App;
use Core\Auth;
use Core\Paginator;
use Core\Permission;
use Core\Request;
use Core\Router;
use Modules\User\UsergroupModel;
use Modules\User\UserModel;

final class UserController extends AdminBaseController
{
    /**
     * 用户列表
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $keyword = Request::string('q', '', 60);
        $groupId = Request::int('group', 0);
        $page    = $this->currentPage();

        $result = UserModel::search($keyword, $groupId, $page, 30);
        $result['items'] = UserModel::decorateMany($result['items']);

        $query = array_filter([
            'q'     => $keyword,
            'group' => $groupId > 0 ? $groupId : '',
        ]);

        return $this->adminView('admin/users', [
            'pageTitle'  => '用户管理 - ' . (string)setting('site_name'),
            'adminTitle' => '用户管理',
            'result'     => $result,
            'keyword'    => $keyword,
            'groupId'    => $groupId,
            'groups'     => UsergroupModel::options(),
            'pagination' => Paginator::render($result, '/admin/users', $query),
        ]);
    }

    /**
     * 用户详情 / 编辑表单
     *
     * @param array<string, string> $params
     */
    public function edit(array $params): string
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '用户不存在或已注销。');
        }

        $ban = BanModel::active('user', (string)$userId, true);

        return $this->adminView('admin/user-edit', [
            'pageTitle'  => '编辑用户 - ' . (string)setting('site_name'),
            'adminTitle' => '编辑用户',
            'profile'    => UserModel::decorate($user),
            'groups'     => UsergroupModel::options(),
            'ban'        => $ban,
            'isSelf'     => $userId === Auth::id(),
            'isSuper'    => Permission::groupOf($user) === Permission::SUPER_GROUP,
            'mutedGroup' => Permission::MUTED_GROUP,
            'canBan'     => Auth::can('user.ban'),
            'stats'      => [
                'threads'   => (int)$user['thread_count'],
                'posts'     => (int)$user['post_count'],
                'favorites' => (int)$user['favorite_count'],
            ],
        ]);
    }

    /**
     * 保存用户资料
     *
     * @param array<string, string> $params
     */
    public function update(array $params): never
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '用户不存在或已注销。');
        }

        $back = Router::url('/admin/users/' . $userId);

        $username = Request::string('username', '', 20);
        $email    = Request::string('email', '', 191);
        $groupId  = Request::int('group_id', (int)$user['group_id']);
        $status   = Request::bool('status') ? 1 : 0;
        $points   = Request::int('points', (int)$user['points']);

        $validator = $this->validate(
            ['username' => $username, 'email' => $email],
            ['username' => 'required|username', 'email' => 'required|email|max:191'],
            ['username' => '用户名', 'email' => '邮箱']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator->errors(), $back);
        }

        if (UserModel::usernameTaken($username, $userId)) {
            $this->backWithErrors(['username' => '该用户名已被其他用户占用。'], $back);
        }

        if (UserModel::emailTaken($email, $userId)) {
            $this->backWithErrors(['email' => '该邮箱已被其他用户占用。'], $back);
        }

        if (!isset(UsergroupModel::options()[$groupId])) {
            $this->backWithErrors(['group_id' => '所选用户组不存在。'], $back);
        }

        // 防呆：不允许通过后台把自己降级或禁用
        if ($userId === Auth::id()) {
            if ($groupId !== Permission::SUPER_GROUP) {
                $this->backWithErrors(['group_id' => '不能修改自己的用户组。'], $back);
            }
            if ($status !== 1) {
                $this->backWithErrors(['status' => '不能禁用自己的账号。'], $back);
            }
        }

        UserModel::updateById($userId, [
            'username'  => $username,
            'email'     => strtolower($email),
            'group_id'  => $groupId,
            'status'    => $status,
            'points'    => max(0, $points),
            'location'  => Request::string('location', '', 60),
            'bio'       => Request::string('bio', '', 100),
        ]);

        $this->audit('user.update', 'user:' . $userId, '更新用户资料：' . $username);

        $message = '用户资料已更新。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 封禁用户
     *
     * @param array<string, string> $params
     */
    public function ban(array $params): never
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '用户不存在或已注销。');
        }

        $back = Router::url('/admin/users/' . $userId);

        if ($userId === Auth::id()) {
            $this->backWithErrors(['ban' => '不能封禁自己的账号。'], $back);
        }

        if (Permission::groupOf($user) === Permission::SUPER_GROUP && !Auth::isSuperAdmin()) {
            $this->backWithErrors(['ban' => '只有超级管理员可以封禁其他超级管理员。'], $back);
        }

        $reason = Request::string('reason', '', 200);
        $days   = Request::int('days', 0);
        $expires = $days > 0 ? time() + $days * 86400 : 0;

        $adminId = Auth::id();

        // 1) 账号级封禁
        $result = BanModel::add('user', (string)$userId, $reason, $expires, $adminId);

        if (!$result['ok']) {
            $this->backWithErrors(['ban' => $result['message']], $back);
        }

        // 2) 可选：同时封禁 IP / 邮箱，防止换号重来
        if (Request::bool('ban_ip')) {
            $ip = trim((string)($user['last_login_ip'] ?? '')) !== ''
                ? (string)$user['last_login_ip']
                : (string)($user['register_ip'] ?? '');

            if ($ip !== '') {
                BanModel::add('ip', $ip, $reason, $expires, $adminId);
            }
        }

        if (Request::bool('ban_email') && trim((string)$user['email']) !== '') {
            BanModel::add('email', (string)$user['email'], $reason, $expires, $adminId);
        }

        // 3) 可选：移入禁言组（只读）
        $muted = false;
        if (Request::bool('mute') && Permission::groupOf($user) !== Permission::SUPER_GROUP) {
            UserModel::updateById($userId, ['group_id' => Permission::MUTED_GROUP]);
            $muted = true;
        }

        $this->audit(
            'user.ban',
            'user:' . $userId,
            '封禁用户 ' . (string)$user['username']
            . ($expires > 0 ? '，解封：' . date('Y-m-d H:i', $expires) : '，永久')
            . ($muted ? '，并禁言' : '')
            . ($reason !== '' ? '，原因：' . $reason : '')
        );

        $message = '已封禁该用户' . ($muted ? '并移入禁言组' : '') . '。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }

    /**
     * 解除封禁
     *
     * @param array<string, string> $params
     */
    public function unban(array $params): never
    {
        $userId = (int)($params['id'] ?? 0);
        $user   = UserModel::find($userId);

        if ($user === null) {
            App::abort(404, '用户不存在或已注销。');
        }

        $back = Router::url('/admin/users/' . $userId);

        $revoked = BanModel::revokeUser($userId);

        // 同时解除该用户 IP / 邮箱上的封禁，避免「解了账号仍登不上」
        foreach (['ip', 'email'] as $type) {
            $value = $type === 'ip'
                ? (trim((string)$user['last_login_ip']) !== '' ? (string)$user['last_login_ip'] : (string)$user['register_ip'])
                : (string)$user['email'];

            if ($value === '') {
                continue;
            }

            $row = BanModel::active($type, $value, true);
            if ($row !== null) {
                BanModel::revoke((int)$row['id']);
            }
        }

        // 若当前处于禁言组，恢复到默认注册用户组
        if (Permission::groupOf($user) === Permission::MUTED_GROUP) {
            $target = \Core\Settings::int('register_group', 3);
            UserModel::updateById($userId, ['group_id' => $target > 0 ? $target : 3]);
        }

        if (!$revoked) {
            $this->redirectWith($back, '该用户当前没有生效中的封禁记录。', 'error');
        }

        $this->audit('user.unban', 'user:' . $userId, '解除封禁：' . (string)$user['username']);

        $message = '已解除该用户的封禁。';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => $back]);
        }

        $this->redirectWith($back, $message);
    }
}
