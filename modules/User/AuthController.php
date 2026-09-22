<?php
/**
 * 认证控制器：登录 / 注册 / 登出
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Auth;
use Core\Ban;
use Core\Captcha;
use Core\Controller;
use Core\Hook;
use Core\Request;
use Core\Router;
use Core\Security;
use Core\Session;
use Core\Settings;
use Modules\Admin\LogModel;

final class AuthController extends Controller
{
    /**
     * 登录页
     *
     * @param array<string, string> $params
     */
    public function showLogin(array $params): string
    {
        if (Auth::check()) {
            \Core\Response::redirect(Router::url('/'));
        }

        return $this->view('auth/login', [
            'pageTitle' => '登录 - ' . (string)Settings::get('site_name'),
            'captchaEnabled' => Captcha::enabled(),
            'formErrors' => Session::errors(),
        ], 'layouts/auth');
    }

    /**
     * 输出图形验证码
     *
     * 每次请求都会重新生成一张并把答案写进会话，所以前端「看不清就换一张」
     * 只要重新请求本地址即可，不需要额外的随机参数（也就不会破坏缓存判断）。
     */
    public function captcha(array $params): never
    {
        // scope 决定存在哪张会话表里：登录与注册各存各的，互不覆盖
        $scope = Request::string('scope', 'login', 16) === 'register' ? 'register' : 'login';

        // 验证码是即时状态：任何缓存都可能让用户拿到一张已经作废的图
        \Core\Response::header('Content-Type', 'image/svg+xml; charset=utf-8');
        \Core\Response::header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        \Core\Response::header('Pragma', 'no-cache');

        \Core\Response::raw(Captcha::render($scope));
    }

    /**
     * 处理登录
     *
     * @param array<string, string> $params
     */
    public function login(array $params): never
    {
        $login    = Request::string('login', '', 191);
        $password = (string)($_POST['password'] ?? '');
        $remember = Request::bool('remember');

        // 防暴力破解：同 IP 5 分钟最多 8 次
        $this->throttle('login', 'login:' . Request::ip());

        /*
         * 验证码（受后台「登录需要验证码」开关控制）。
         *
         * 放在账号密码校验之前有两点考虑：
         *   1. 不给自动化脚本试探「账号是否存在」「密码对不对」的机会；
         *   2. 验证码错误与密码错误是两类信息，分开提示用户才好定位问题。
         */
        if (Captcha::enabled() && !Captcha::verify(Request::string('captcha', '', 12))) {
            Session::flashInput(['login' => $login]);
            Session::setFlash('message', '验证码不正确或已过期，请重新输入。');
            Session::setFlash('type', 'error');

            \Core\Response::redirect(Router::url('/login'));
        }

        $result = Auth::attempt($login, $password, $remember);

        if (!$result['ok']) {
            LogModel::record(0, 'user.login.fail', 'login:' . mb_substr($login, 0, 40), '登录失败：' . $result['message']);

            /*
             * 登录失败属于「操作结果」，而不是某个字段的格式错误，因此按统一约定
             * 走弹出式通知：只写 flash 消息，不写字段错误，避免同一次失败
             * 既弹 toast 又出内联块（partials/flash.php 会据此二选一）。
             */
            Session::flashInput(['login' => $login]);
            Session::setFlash('message', $result['message']);
            Session::setFlash('type', 'error');

            \Core\Response::redirect(Router::url('/login'));
        }

        $userId = (int)($result['user']['id'] ?? 0);

        LogModel::record($userId, 'user.login', 'user:' . $userId, '登录成功');

        $target = Auth::pullReturnUrl('/');

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => '登录成功。', 'redirect' => Router::url($target)]);
        }

        $this->redirectWith(Router::url($target), '欢迎回来，' . (string)($result['user']['username'] ?? ''));
    }

    /**
     * 注册页
     *
     * @param array<string, string> $params
     */
    public function showRegister(array $params): string
    {
        if (Auth::check()) {
            \Core\Response::redirect(Router::url('/'));
        }

        if (!Settings::bool('register_enabled', true)) {
            \Core\App::abort(403, '站点当前已关闭注册。');
        }

        return $this->view('auth/register', [
            'pageTitle'  => '注册 - ' . (string)Settings::get('site_name'),
            'formErrors' => Session::errors(),
            'requireVerify' => Settings::bool('register_verify', false),
            'captchaEnabled' => Captcha::enabled('register'),
        ], 'layouts/auth');
    }

    /**
     * 处理注册
     *
     * @param array<string, string> $params
     */
    public function register(array $params): never
    {
        if (!Settings::bool('register_enabled', true)) {
            $this->fail('站点当前已关闭注册。', 403);
        }

        $this->throttle('register', 'register:' . Request::ip());

        /*
         * 注册验证码（受后台「注册需要验证码」开关控制）。
         * 放在字段校验之前：既挡住批量注册脚本，也省得为机器人白跑一遍完整校验。
         */
        if (Captcha::enabled('register')
            && !Captcha::verify(Request::string('captcha', '', 12), 'register')) {
            $this->backWithErrors(
                ['captcha' => '验证码不正确或已过期，请重新输入。'],
                Router::url('/register')
            );
        }

        if (Ban::isIpBanned()) {
            $this->fail('当前网络环境已被限制注册。', 403);
        }

        $username = Request::string('username', '', 20);
        $email    = Request::string('email', '', 191);
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');

        $validator = $this->validate(
            [
                'username'         => $username,
                'email'            => $email,
                'password'         => $password,
                'password_confirm' => $confirm,
            ],
            [
                'username'         => 'required|username',
                'email'            => 'required|email|max:191',
                'password'         => 'required',
                'password_confirm' => 'required|same:password',
            ],
            [
                'username'         => '用户名',
                'email'            => '邮箱',
                'password'         => '密码',
                'password_confirm' => '确认密码',
            ]
        );

        $errors = $validator->errors();

        /*
         * 密码强度要求与格式校验合并成一次上报。
         * 原来分两步（先报格式、用户改完再报强度）会导致「改一条、报一条」。
         * 仅在密码非空（已通过 required）时才追加强度要求，避免空密码时
         * 顺带给出一条无意义的「长度至少 8 位」。
         */
        if (!isset($errors['password']) && $password !== '') {
            $strengthErrors = Security::passwordStrengthErrors($password);
            if ($strengthErrors !== []) {
                $errors['password'] = $strengthErrors;
            }
        }

        if ($errors !== []) {
            $this->backWithErrors($errors, Router::url('/register'));
        }

        /*
         * 保留用户名（后台「注册与登录 → 保留用户名」可配置，逗号分隔）：
         * 只拦前台自注册——安装向导的管理员命名与后台改名不受此限。
         */
        $reserved = array_filter(array_map('trim', explode(',', (string)Settings::get('reserved_names', ''))));
        foreach ($reserved as $name) {
            if (strcasecmp($username, $name) === 0) {
                $this->backWithErrors(['username' => '该用户名被系统保留，请更换一个。'], Router::url('/register'));
            }
        }

        // 插件可在此拦截注册（例如邀请码校验）
        Hook::action('before_user_register', ['username' => $username, 'email' => $email]);

        $groupId = Settings::int('register_group', 3);

        $created = UserModel::register($username, $email, $password, $groupId > 0 ? $groupId : 3);

        if (!$created['ok']) {
            $this->backWithErrors(['username' => $created['message']], Router::url('/register'));
        }

        $userId = (int)$created['user_id'];

        Hook::action('after_user_register', ['user_id' => $userId, 'username' => $username]);

        LogModel::record($userId, 'user.register', 'user:' . $userId, '注册新账号：' . $username);

        // 注册后自动登录，减少一次输入成本
        Auth::login($userId);

        $message = Settings::bool('register_verify', false)
            ? '注册成功。邮箱验证功能尚未开启，你可以直接使用站点。'
            : '注册成功，欢迎加入！';

        if (Request::wantsJson()) {
            $this->json(['ok' => true, 'message' => $message, 'redirect' => Router::url('/')]);
        }

        $this->redirectWith(Router::url('/'), $message);
    }

    /**
     * 登出
     *
     * @param array<string, string> $params
     */
    public function logout(array $params): never
    {
        $userId = Auth::id();

        Auth::logout();

        if ($userId > 0) {
            LogModel::record($userId, 'user.logout', 'user:' . $userId, '退出登录');
        }

        $this->redirectWith(Router::url('/'), '你已安全退出。');
    }
}
