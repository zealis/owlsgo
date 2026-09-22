<?php
/**
 * 身份认证
 *
 * 登录方式：
 *  - 会话登录：登录成功后把 uid 写入 $_SESSION，并 session_regenerate_id(true) 防会话固定
 *  - 记住我：下发 HMAC 签名的 Cookie（密钥 = 应用密钥 + 用户密码哈希），
 *    因此用户一旦修改密码，所有旧的「记住我」Cookie 立即失效
 *
 * 所有「当前是谁」的判断都必须经由 Auth::user()，避免各处以不同口径读取身份。
 */

declare(strict_types=1);

namespace Core;

use Modules\User\UserModel;
use RuntimeException;

final class Auth
{
    /** 当前请求内缓存的用户记录 */
    private static ?array $user = null;

    /** 是否已经解析过身份 */
    private static bool $resolved = false;

    /** 会话中的用户键 */
    private const SESSION_KEY = 'uid';

    /* ------------------------------------------------------------------ */
    /*  读取身份                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 当前登录用户（未登录返回 null）
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;

        /*
         * 会话层不做 UA 校验：会话 Cookie 本身短时效 + HttpOnly + 服务端存储，
         * 被复制的风险远低于长期「记住我」凭据；而且这里一旦误杀（浏览器升级、
         * UA 轮换、维护模式下的重新登录），用户感知就是「明明登录了却被踢」。
         * 防重放由「记住我」Cookie 的 UA 指纹绑定承担（见 Security::makeAuthCookie）。
         */
        if (!isset($_SESSION['ua'])) {
            $_SESSION['ua'] = Request::userAgentHash();
        }

        $userId = (int)Session::get(self::SESSION_KEY, 0);

        if ($userId > 0) {
            $user = UserModel::find($userId);

            if ($user === null || (int)($user['status'] ?? 1) !== 1) {
                // 用户已被删除或禁用：清理会话
                Session::delete(self::SESSION_KEY);
                $user = null;
            }
        } else {
            $user = self::resolveFromCookie();
        }

        if ($user !== null && Ban::isBanned($user)) {
            // 被封禁的账号立即失效
            Session::delete(self::SESSION_KEY);
            self::forgetAuthCookies();
            $user = null;
        }

        self::$user = $user;

        if ($user !== null) {
            self::touch($user);
        }

        return self::$user;
    }

    /** 是否已登录 */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** 当前用户 ID（未登录返回 0） */
    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    /**
     * 判断当前用户是否拥有权限
     *
     * @param array<string, mixed>|null $forum
     */
    public static function can(string $permission, ?array $forum = null): bool
    {
        return Permission::allows(self::user(), $permission, $forum);
    }

    /** 是否为超级管理员 */
    public static function isSuperAdmin(): bool
    {
        return Permission::groupOf(self::user()) === Permission::SUPER_GROUP;
    }

    /* ------------------------------------------------------------------ */
    /*  登录 / 登出                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * 尝试登录
     *
     * @param string $login    用户名或邮箱
     * @param string $password 明文密码
     * @param bool   $remember 是否记住登录状态
     * @return array{ok:bool, message:string, user?:array<string,mixed>}
     */
    public static function attempt(string $login, string $password, bool $remember = false): array
    {
        $login = trim($login);

        if ($login === '' || $password === '') {
            return ['ok' => false, 'message' => '请输入用户名和密码。'];
        }

        $user = UserModel::findByLogin($login);

        // 统一错误提示，避免暴露「用户名是否存在」
        if ($user === null) {
            self::fakeVerify($password);

            return ['ok' => false, 'message' => '用户名或密码不正确。'];
        }

        if (!Security::verifyPassword($password, (string)$user['password_hash'])) {
            Logger::warning('登录失败：密码错误', ['user_id' => (int)$user['id'], 'ip' => Request::ip()]);

            return ['ok' => false, 'message' => '用户名或密码不正确。'];
        }

        if ((int)$user['status'] !== 1) {
            return ['ok' => false, 'message' => '该账号已被禁用，请联系管理员。'];
        }

        if (Ban::isBanned($user)) {
            return ['ok' => false, 'message' => Ban::message($user)];
        }

        // 密码哈希算法升级
        if (Security::needsRehash((string)$user['password_hash'])) {
            UserModel::updateById((int)$user['id'], ['password_hash' => Security::hashPassword($password)]);
        }

        self::login((int)$user['id'], $remember, (string)$user['password_hash']);

        return ['ok' => true, 'message' => '登录成功。', 'user' => $user];
    }

    /**
     * 建立登录态
     */
    public static function login(int $userId, bool $remember = false, string $passwordHash = ''): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('无效的用户 ID。');
        }

        // 防会话固定：登录成功后立即换一个会话 ID
        Session::regenerate();

        Session::set(self::SESSION_KEY, $userId);
        Session::set('login_at', time());

        self::$resolved = false;
        self::$user     = null;

        if ($remember) {
            if ($passwordHash === '') {
                $row          = UserModel::find($userId);
                $passwordHash = (string)($row['password_hash'] ?? '');
            }

            if ($passwordHash !== '') {
                $expires = time() + (int)config('app.cookie_ttl', 2592000);

                Response::cookie(
                    self::cookieName(),
                    Security::makeAuthCookie($userId, $expires, $passwordHash, Request::userAgentHash()),
                    $expires,
                    ['httponly' => true, 'samesite' => 'Lax']
                );
            }
        }

        UserModel::updateById($userId, [
            'last_login_at'  => time(),
            'last_login_ip'  => Request::ip(),
            'last_active_at' => time(),
        ]);

        Hook::action('after_user_login', ['user_id' => $userId]);
    }

    /** 登出 */
    public static function logout(): void
    {
        $userId = self::id();

        self::forgetAuthCookies();
        Session::destroy();

        self::$resolved = false;
        self::$user     = null;

        if ($userId > 0) {
            Hook::action('after_user_logout', ['user_id' => $userId]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  关卡                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 要求登录，否则跳转登录页（AJAX 请求返回 401 JSON）
     */
    public static function requireLogin(): array
    {
        $user = self::user();

        if ($user === null) {
            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'message' => '请先登录。', 'code' => 'login_required'], 401);
            }

            $return = Request::currentUrl();
            if ($return !== '' && $return !== '/') {
                Session::set('return_url', $return);
            }

            Response::redirect(Router::url('/login'));
        }

        return $user;
    }

    /**
     * 要求具备某权限，否则返回 403
     *
     * @param array<string, mixed>|null $forum
     */
    public static function requirePermission(string $permission, ?array $forum = null, string $message = ''): array
    {
        $user = self::requireLogin();

        if (!Permission::allows($user, $permission, $forum)) {
            $message = $message !== '' ? $message : '你没有执行该操作的权限。';

            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'message' => $message], 403);
            }

            App::abort(403, $message);
        }

        return $user;
    }

    /** 取走「登录后跳回」的地址（只取一次） */
    public static function pullReturnUrl(string $fallback = '/'): string
    {
        $url = (string)Session::get('return_url', '');
        Session::delete('return_url');

        return $url !== '' ? $url : $fallback;
    }

    /* ------------------------------------------------------------------ */
    /*  内部实现                                                            */
    /* ------------------------------------------------------------------ */

    /** 「记住我」Cookie 名称（HTTPS 下带 `__Host-` 前缀，见 Security::hostCookieName） */
    public static function cookieName(): string
    {
        return \Core\Security::hostCookieName((string)config('app.cookie_prefix', 'owlsgo_') . 'auth');
    }

    /**
     * 清掉「记住我」Cookie：新名 + 升级前的裸名一起清，
     * 否则升级后旧 Cookie 会一直躺在浏览器里直到自然过期。
     */
    private static function forgetAuthCookies(): void
    {
        self::forgetAuthCookies();
        Response::forgetCookie((string)config('app.cookie_prefix', 'owlsgo_') . 'auth');
    }

    /**
     * 从「记住我」Cookie 恢复登录
     *
     * @return array<string, mixed>|null
     */
    private static function resolveFromCookie(): ?array
    {
        $raw = Request::cookie(self::cookieName());

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // Cookie 中不含 uid，需要先试解析出用户 ID 再校验签名；
        // 因此这里先把 uid 取出来，再拉用户记录完成完整校验。
        // 格式（4 段）：uid|expires|uaHash|hmac —— 三段式的旧 Cookie 走不进这里，自然作废。
        $parts = explode('|', $raw);
        if (count($parts) !== 4 || !ctype_digit($parts[0])) {
            self::forgetAuthCookies();

            return null;
        }

        $user = UserModel::find((int)$parts[0]);

        if ($user === null || (int)($user['status'] ?? 1) !== 1) {
            self::forgetAuthCookies();

            return null;
        }

        $parsed = Security::parseAuthCookie($raw, (string)$user['password_hash'], Request::userAgentHash());

        if ($parsed === null) {
            Logger::warning('记住我 Cookie 校验失败', ['user_id' => (int)$user['id'], 'ip' => Request::ip()]);
            /*
             * 这里**不**主动发删除 Set-Cookie：在本项目实际部署的 Windows/PHP 构建
             * 上，恢复失败路径里发 setcookie 会直接崩掉进程（实测 500，无任何
             * fatal 输出）。凭据本身已失效 —— 三段式旧格式过不了解析、新格式
             * 过不了 UA 绑定 —— 用户重新登录时它会被同名覆盖。
             */

            return null;
        }

        /*
         * 有效期上限跟随当前配置：Cookie 里签的是「签发当时算起的天数」，
         * 站点把时长改短之后，早先签发的那批不能继续用到原定日期 ——
         * 超出当前上限的一律作废，用户重新登录一次即可。
         */
        if ((int)$parsed['expires'] > time() + (int)config('app.cookie_ttl', 2592000)) {
            self::forgetAuthCookies();

            return null;
        }

        // 恢复为会话登录
        Session::regenerate();
        Session::set(self::SESSION_KEY, (int)$user['id']);

        return $user;
    }

    /**
     * 用户不存在时也执行一次哈希校验，避免通过响应时间判断用户名是否存在
     */
    private static function fakeVerify(string $password): void
    {
        // 固定的 bcrypt 哈希（对应明文为随机串，不可能匹配）
        static $dummy = '$2y$11$usesomesillystringfore7hnbRJHxXVLeakoG8K30MkdpBQHqo8Za';

        Security::verifyPassword($password, $dummy);
    }

    /**
     * 轻量更新「最后活跃时间」（每分钟最多写一次，避免高频写库）
     *
     * @param array<string, mixed> $user
     */
    private static function touch(array $user): void
    {
        $last = (int)Session::get('last_touch', 0);
        $now  = time();

        if ($now - $last < 60) {
            return;
        }

        Session::set('last_touch', $now);

        try {
            UserModel::updateById((int)$user['id'], ['last_active_at' => $now]);
        } catch (\Throwable $e) {
            Logger::debug('更新活跃时间失败：' . $e->getMessage());
        }
    }

    /** 重置缓存（测试或身份切换后使用） */
    public static function reset(): void
    {
        self::$resolved = false;
        self::$user     = null;
    }
}
