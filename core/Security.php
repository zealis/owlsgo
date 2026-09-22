<?php
/**
 * 安全中心
 *
 * 集中实现：CSRF 令牌、密码哈希、Cookie 安全策略、开放重定向防护、
 * 登录态 Cookie 签名、接口限流。任何安全相关的判断都只在这里实现，
 * 便于一次性审查与审计。
 */

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class Security
{
    /** CSRF 令牌在会话中的键名 */
    private const CSRF_KEY = '_csrf_token';

    /**
     * 生成（或复用）当前会话的 CSRF 令牌
     */
    public static function csrfToken(): string
    {
        Session::start();

        $token = Session::get(self::CSRF_KEY);

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::set(self::CSRF_KEY, $token);
        }

        return $token;
    }

    /**
     * 校验 CSRF 令牌（恒定时间比较，防时序侧信道）
     */
    public static function verifyCsrf(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = Session::get(self::CSRF_KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * 拦截非安全方法的请求（POST/PUT/PATCH/DELETE 必须携带有效令牌）
     *
     * 例外：安装向导在写 install.lock 之前允许无令牌（此时尚无会话数据），
     * 由调用方通过白名单显式声明。
     */
    public static function guardCsrf(): void
    {
        if (!in_array(Request::method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!self::verifyCsrf(is_string($token) ? $token : null)) {
            Logger::warning('CSRF 校验失败：' . Request::path());

            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'message' => '会话已过期，请刷新页面后重试。'], 419);
            }

            Response::html(
                View::render('errors/error', [
                    'title'     => '会话已过期',
                    'code'      => 419,
                    'message'   => '表单安全令牌无效或已过期，请返回上一页刷新后重试。',
                    'pageTitle' => '419 会话已过期',
                    'layout'    => 'layouts/error',
                ]),
                419
            );
        }
    }

    /**
     * 生成密码哈希（bcrypt，成本因子随硬件自动适配）
     */
    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('密码哈希失败。');
        }

        return $hash;
    }

    /**
     * 校验密码，并在必要时自动升级哈希算法
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    /** 判断哈希是否需要用当前算法重算 */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    /**
     * 密码强度校验：至少 8 位、最多 72 位，且必须同时包含字母与数字
     *
     * 返回**全部**未满足的要求（而不是只返回第一条），这样才能一次性告诉用户
     * 需要改哪几处，避免「改一条、报一条」来回试错。
     *
     * @return list<string> 空数组表示通过
     */
    public static function passwordStrengthErrors(string $password): array
    {
        $errors = [];
        $length = mb_strlen($password);

        if ($length < 8) {
            $errors[] = '密码长度至少 8 位。';
        }

        if ($length > 72) {
            $errors[] = '密码长度不能超过 72 位。';
        }

        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = '密码必须同时包含字母和数字。';
        }

        return $errors;
    }

    /**
     * 密码强度校验（兼容旧调用）：只返回第一条错误
     *
     * 新代码请优先使用 passwordStrengthErrors()，以便一次展示全部未满足项。
     *
     * @return string 空字符串表示通过
     */
    public static function checkPasswordStrength(string $password): string
    {
        $errors = self::passwordStrengthErrors($password);

        return $errors === [] ? '' : $errors[0];
    }

    /**
     * 是否应为 Cookie 加上 Secure 标记
     */
    public static function cookieSecure(): bool
    {
        $setting = config('app.cookie_secure', 'auto');

        if ($setting === true) {
            return true;
        }
        if ($setting === false) {
            return false;
        }

        return Request::isSecure();
    }

    /**
     * 登录身份类 Cookie 的名称（HTTPS 下加 `__Host-` 前缀）
     *
     * `__Host-` 是浏览器强制的安全前缀：带它的话浏览器会拒绝「缺 Secure、
     * Path 不是 /、声明了 Domain」的 Cookie —— 从机制上杜绝子域名
     * （或被攻破的相邻应用）覆写主站登录 Cookie 这一类攻击。
     * 仅在 HTTPS 下启用（`__Host-` 必须配 Secure，HTTP 站点设不上），
     * HTTP 开发环境自动退回裸名，行为不变。
     */
    public static function hostCookieName(string $base): string
    {
        return self::cookieSecure() ? '__Host-' . $base : $base;
    }

    /**
     * 生成「记住我」登录 Cookie 的内容
     *
     * 格式：uid|expires|uaHash|hmac(前三段)
     * 密钥含用户密码哈希：改密码后所有旧 Cookie 立即失效。
     * HMAC 覆盖 UA 指纹：Cookie 被从本机窃走后换环境重放无法通过校验。
     * （$uaHash 传空 = 不绑定 UA，仅供测试；正式签发一律传 Request::userAgentHash()）
     */
    public static function makeAuthCookie(int $userId, int $expires, string $passwordHash, string $uaHash = ''): string
    {
        $payload = $userId . '|' . $expires . '|' . $uaHash;
        $mac     = hash_hmac('sha256', $payload, self::authKey() . $passwordHash);

        return $payload . '|' . $mac;
    }

    /**
     * 校验登录 Cookie
     *
     * @return array{user_id:int, expires:int}|null
     */
    public static function parseAuthCookie(string $cookie, string $passwordHash, string $uaHash = ''): ?array
    {
        $parts = explode('|', $cookie);
        if (count($parts) !== 4) {
            return null;   // 旧的三段式 Cookie（未绑 UA）在升级后自然失效，重新登录一次即可
        }

        [$rawId, $rawExpires, $rawUa, $mac] = $parts;

        if (!ctype_digit($rawId) || !ctype_digit($rawExpires) || !preg_match('/^[0-9a-f]{0,16}$/', $rawUa)) {
            return null;
        }

        $userId  = (int)$rawId;
        $expires = (int)$rawExpires;
        $ua      = (string)$rawUa;

        if ($expires < time()) {
            return null;
        }

        $expected = hash_hmac('sha256', $userId . '|' . $expires . '|' . $ua, self::authKey() . $passwordHash);

        if (!hash_equals($expected, $mac)) {
            return null;
        }

        // Cookie 是给当前浏览器环境签的：UA 指纹对不上 = 换了环境重放
        if ($ua !== $uaHash) {
            return null;
        }

        return ['user_id' => $userId, 'expires' => $expires];
    }

    /**
     * 应用级签名密钥
     *
     * 由 storage/config/secret.key 提供（安装时生成）；缺失时即时生成并落盘，
     * 保证多台机器、多次请求之间的签名一致。
     */
    public static function authKey(): string
    {
        static $key = null;

        if (is_string($key)) {
            return $key;
        }

        $file = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'config' . DIRECTORY_SEPARATOR . 'secret.key';

        if (is_file($file)) {
            $content = (string)file_get_contents($file);
            if (strlen(trim($content)) >= 32) {
                $key = trim($content);

                return $key;
            }
        }

        $key = bin2hex(random_bytes(32));

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, $key, LOCK_EX);
        @chmod($file, 0600);

        return $key;
    }

    /**
     * 过滤跳转目标，阻断开放重定向
     *
     * 只允许：站内相对路径、以本站 Host 开头的绝对地址。
     */
    public static function safeRedirect(string $url): string
    {
        $url = trim(str_replace(["\r", "\n", "\t"], '', $url));

        if ($url === '') {
            return Router::url('/');
        }

        // 协议相对地址（//evil.com）与带协议的绝对地址一律只取本站
        if (str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $parts = parse_url($url);

            if ($parts === false) {
                return Router::url('/');
            }

            $host = $parts['host'] ?? '';
            $self = (string)($_SERVER['HTTP_HOST'] ?? '');

            // 站内绝对地址：仅保留路径与查询串
            if ($host !== '' && $host === $self) {
                $url = (string)($parts['path'] ?? '/')
                    . (isset($parts['query']) ? '?' . $parts['query'] : '');

                return Router::url($url);
            }

            return Router::url('/');
        }

        return Router::url($url);
    }

    /**
     * 简单的固定窗口限流
     *
     * 采用数据库计数而非文件锁，跨进程一致；窗口过期后自动重置。
     *
     * @param string $action 动作标识，如 login / post / upload
     * @param string $key    维度键，通常是 IP 或 IP+账号
     * @return bool true 表示允许通过
     */
    public static function rateLimit(string $action, string $key = ''): bool
    {
        return self::rateLimitRetryAfter($action, $key) === 0;
    }

    /**
     * 限流检查（带等待时长）：0 = 放行；>0 = 需要等待的秒数（窗口重置前）。
     *
     * 与 rateLimit() 同一套桶逻辑，区别只在返回值——调用方可以用它
     * 在报错文案里告诉用户「还要等多久」。
     */
    public static function rateLimitRetryAfter(string $action, string $key = ''): int
    {
        $rule = (array)config('app.rate_limit.' . $action, config('app.rate_limit.default', ['max' => 60, 'window' => 60]));

        $max    = max(1, (int)($rule['max'] ?? 60));
        $window = max(1, (int)($rule['window'] ?? 60));

        $bucket = $action . ':' . substr(hash('sha256', $key === '' ? Request::ip() : $key), 0, 40);
        $now    = time();

        try {
            $row = Database::first(
                'SELECT ' . Database::identifier('hits') . ',' . Database::identifier('expires_at')
                . ' FROM ' . Database::identifier('rate_limits')
                . ' WHERE ' . Database::identifier('bucket') . ' = ?',
                [$bucket]
            );

            if ($row === null || (int)$row['expires_at'] < $now) {
                Database::upsert('rate_limits', [
                    'bucket'     => $bucket,
                    'hits'       => 1,
                    'expires_at' => $now + $window,
                    'created_at' => $now,
                ], ['bucket']);

                return 0;
            }

            $hits = (int)$row['hits'] + 1;

            Database::update('rate_limits', ['hits' => $hits], Database::identifier('bucket') . ' = ?', [$bucket]);

            return $hits <= $max ? 0 : max(1, (int)$row['expires_at'] - $now);
        } catch (\Throwable $e) {
            // 限流表不可用时宁可放行，也不要因为基础设施问题阻断正常访问。
            // 安装阶段表还没建，属预期情况，只记 debug；已安装却读不到表才是真问题。
            if (App::isInstalled()) {
                Logger::warning('限流失效：' . $e->getMessage());
            } else {
                Logger::debug('限流表尚未创建（安装阶段）：' . $e->getMessage());
            }

            return 0;
        }
    }

    /** 清理过期的限流记录（由计划任务调用） */
    public static function pruneRateLimits(): int
    {
        return Database::delete('rate_limits', Database::identifier('expires_at') . ' < ?', [time()]);
    }

    /**
     * 生成 URL 安全的随机串
     */
    public static function randomString(int $bytes = 16): string
    {
        return bin2hex(random_bytes(max(8, $bytes)));
    }
}
