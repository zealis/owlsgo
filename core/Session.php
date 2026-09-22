<?php
/**
 * 会话管理
 *
 * 与常见写法不同之处（均为安全考量）：
 *  - 关闭 PHP 自带的会话 Cookie 下发（session.use_cookies=0），改由本类显式写入响应 Cookie，
 *    这样可以统一控制 SameSite / Secure / HttpOnly，也能在 CLI 场景（计划任务、自动化测试）下工作
 *  - 开启 use_strict_mode，拒绝客户端伪造未初始化的会话 ID，防会话固定
 *  - 会话文件固定在 storage/sessions/ 下，避免共享主机上的越权读取
 *  - 一次性数据（提示消息、旧输入、校验错误）采用「两级袋子」实现跨一次跳转的闪现语义
 */

declare(strict_types=1);

namespace Core;

final class Session
{
    private static bool $started = false;

    /** 本次请求内已闪存取出、等待清理的键 */
    private const BAG_FLASH = '_flash';
    private const BAG_OLD   = '_old';
    private const BAG_ERROR = '_errors';

    /**
     * 启动会话（幂等）
     */
    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        $savePath = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0755, true);
        }

        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        // 会话 ID 由我们自己通过响应 Cookie 下发
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string)((int)config('app.cookie_ttl', 2592000)));
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');

        /*
         * 关掉 PHP 的 session cache_limiter（默认 nocache）：它会给**每个**响应
         * 强加 `Cache-Control: no-store` + `Expires: 1981` + `Pragma: no-cache`，
         * 后果是浏览器对整站禁用缓存与 bfcache —— 每次导航都全量重拉，
         * 静态资源、头像也一并被殃及。缓存策略改由应用按响应类型自己发
         * （HTML → Response::html 的 private no-cache；媒体 → MediaController 的 public）。
         */
        ini_set('session.cache_limiter', '0');

        $name = (string)config('app.session_name', 'owlsgo_sid');
        session_name($name);

        // 浏览器 Cookie 名：HTTPS 下带 `__Host-` 前缀（见 Security::hostCookieName）
        $cookieName = self::cookieName();

        // 从请求 Cookie 中恢复会话 ID；格式不合法则换新，避免会话固定
        $incoming = Request::cookie($cookieName);
        if (is_string($incoming) && self::isValidId($incoming)) {
            session_id($incoming);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        self::$started = true;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // 极端情况下（如目录不可写）退化为仅在内存中保存会话数据
            $_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
        }

        /*
         * 会话绑定 UA 指纹（防「Cookie 文件被窃 → 异地重放」）：
         *  - 首次见到该会话：记下指纹（升级前的老会话也没有这个字段，直接补记、不踢人）；
         *  - 指纹变了（Cookie 被复制到其它浏览器/设备使用）：清空全部会话数据，
         *    登录态一并失效 —— 重放者拿到的是空会话。
         *    不用 session_regenerate_id：它与 session_unset 的组合在这套 Windows
         *    构建上会让进程直接崩掉（实测 500），清数据已足够消除重放价值。
         */
        $uaHash = Request::userAgentHash();
        if (!isset($_SESSION['ua'])) {
            $_SESSION['ua'] = $uaHash;
        }

        /*
         * 升级迁移：还在带「裸名」会话 Cookie 的浏览器，把它清掉
         * （新 Cookie 已用 `__Host-` 名下发，旧的那份留着只会白白随请求发送）。
         */
        if (Request::cookie($cookieName) === null && Request::cookie($name) !== null) {
            Response::forgetCookie($name);
        }

        self::ageBags();

        // 下发/刷新会话 Cookie
        self::persistCookie();
    }

    /** 会话 Cookie 的浏览器名（HTTPS 下带 `__Host-` 前缀） */
    private static function cookieName(): string
    {
        return Security::hostCookieName((string)config('app.session_name', 'owlsgo_sid'));
    }

    /** 会话 ID 是否合法（PHP 默认字符集） */
    private static function isValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9,\-]{22,128}$/', $id) === 1;
    }

    /** 把响应 Cookie 中的会话 ID 写回客户端 */
    private static function persistCookie(): void
    {
        $id = session_id();

        if (!is_string($id) || $id === '') {
            return;
        }

        Response::cookie(self::cookieName(), $id, time() + (int)config('app.cookie_ttl', 2592000), [
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * 闪现数据的「老化」：上一请求写入的 new 袋子在本请求变为可读的 old 袋子
     */
    private static function ageBags(): void
    {
        $bags = [self::BAG_FLASH, self::BAG_OLD, self::BAG_ERROR];

        foreach ($bags as $bag) {
            $pendingKey = $bag . '_new';
            $activeKey  = $bag . '_active';

            // 上上轮的残留直接丢弃
            unset($_SESSION[$activeKey]);

            if (isset($_SESSION[$pendingKey]) && is_array($_SESSION[$pendingKey])) {
                $_SESSION[$activeKey] = $_SESSION[$pendingKey];
                unset($_SESSION[$pendingKey]);
            }
        }
    }

    /** 读取会话值 */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    /** 写入会话值 */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /** 删除会话值 */
    public static function delete(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /** 是否存在某会话键 */
    public static function has(string $key): bool
    {
        self::start();

        return isset($_SESSION[$key]);
    }

    /**
     * 写入一次性提示消息（跳转后展示一次即消失）
     */
    public static function setFlash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[self::BAG_FLASH . '_new'][$key] = $value;
    }

    /** 读取一次性提示消息 */
    public static function flash(string $key = 'message', mixed $default = null): mixed
    {
        return $_SESSION[self::BAG_FLASH . '_active'][$key] ?? $default;
    }

    /** 判断一次性提示是否存在 */
    public static function hasFlash(string $key = 'message'): bool
    {
        return isset($_SESSION[self::BAG_FLASH . '_active'][$key]);
    }

    /**
     * 保存本次提交的原始输入，供跳转回表单时回填
     *
     * 注意：密码类字段绝不回填
     */
    public static function flashInput(array $input): void
    {
        self::start();

        $deny = ['password', 'password_confirm', 'old_password', 'new_password', '_token'];
        $safe = [];

        foreach ($input as $key => $value) {
            if (in_array((string)$key, $deny, true)) {
                continue;
            }
            // 只保留标量，避免会话膨胀
            if (is_scalar($value)) {
                $safe[(string)$key] = mb_substr((string)$value, 0, 2000);
            }
        }

        $_SESSION[self::BAG_OLD . '_new'] = $safe;
    }

    /** 读取旧输入 */
    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION[self::BAG_OLD . '_active'][$key] ?? $default;
    }

    /**
     * 保存表单校验错误
     *
     * 字段值既接受单条字符串，也接受字符串数组；内部统一归一化为「列表」，
     * 这样同一字段命中的多条规则可以一次性展示给用户。
     *
     * @param array<string, string|list<string>> $errors
     */
    public static function setErrors(array $errors): void
    {
        self::start();

        $_SESSION[self::BAG_ERROR . '_new'] = self::normalizeErrors($errors);
    }

    /**
     * 读取全部校验错误
     *
     * @return array<string, list<string>>
     */
    public static function errors(): array
    {
        $errors = $_SESSION[self::BAG_ERROR . '_active'] ?? [];

        return is_array($errors) ? self::normalizeErrors($errors) : [];
    }

    /**
     * 读取单个字段的校验错误
     *
     * 多条时用换行连接，配合 .field-error 的 white-space: pre-line 逐行展示。
     */
    public static function error(string $field): string
    {
        $errors = self::errors();
        $list   = $errors[$field] ?? [];

        return implode("\n", is_array($list) ? $list : [(string)$list]);
    }

    /**
     * 把错误集合归一化为 array<字段, list<消息>>
     *
     * 同时兼容历史会话中可能残留的「字段 => 单条字符串」旧格式。
     *
     * @param  array<string, mixed> $errors
     * @return array<string, list<string>>
     */
    private static function normalizeErrors(array $errors): array
    {
        $normalized = [];

        foreach ($errors as $field => $messages) {
            $list = is_array($messages) ? $messages : [$messages];
            $list = array_values(array_filter(
                array_map(static fn (mixed $m): string => is_scalar($m) ? (string)$m : '', $list),
                static fn (string $m): bool => $m !== ''
            ));

            if ($list !== []) {
                $normalized[(string)$field] = $list;
            }
        }

        return $normalized;
    }

    /** 清空所有闪现数据（例如校验通过后） */
    public static function clearFlashes(): void
    {
        self::start();

        foreach ([self::BAG_FLASH, self::BAG_OLD, self::BAG_ERROR] as $bag) {
            unset($_SESSION[$bag . '_new'], $_SESSION[$bag . '_active']);
        }
    }

    /**
     * 重建会话 ID（登录成功、提权等敏感操作后必须调用，防会话固定）
     */
    public static function regenerate(): void
    {
        self::start();

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        } else {
            // 内存退化模式下也换一个不可预测的 ID
            $_SESSION['_regen_at'] = time();
        }

        self::persistCookie();
    }

    /** 彻底销毁会话（登出） */
    public static function destroy(): void
    {
        self::start();

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }

        self::$started = false;

        // 新名（__Host- 前缀）+ 升级前的裸名一起清，浏览器里不留死 Cookie
        Response::forgetCookie(self::cookieName());
        Response::forgetCookie((string)config('app.session_name', 'owlsgo_sid'));
    }

    /**
     * 获取当前会话 ID（CLI 场景下用于串联请求）
     */
    public static function id(): string
    {
        self::start();

        $id = session_id();

        return is_string($id) ? $id : '';
    }
}
