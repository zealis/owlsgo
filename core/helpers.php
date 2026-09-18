<?php
/**
 * 全局辅助函数
 *
 * 只放「模板与控制器都会高频使用」的轻量函数。
 * 所有输出到 HTML 的动态内容必须经过 e() 转义。
 */

declare(strict_types=1);

use Core\Auth;
use Core\Config;
use Core\Hook;
use Core\Response;
use Core\Security;
use Core\Session;
use Core\Settings;
use Core\Text;
use Core\View;

if (!function_exists('e')) {
    /**
     * HTML 转义（输出到页面的所有动态内容都必须走这里）
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('config')) {
    /** 读取配置（点号路径） */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('setting')) {
    /** 读取站点设置（数据库 settings 表，带懒加载缓存） */
    function setting(string $key, mixed $default = ''): mixed
    {
        return Settings::get($key, $default);
    }
}

if (!function_exists('setting_bool')) {
    /** 读取布尔型站点设置 */
    function setting_bool(string $key, bool $default = false): bool
    {
        return Settings::bool($key, $default);
    }
}

if (!function_exists('now')) {
    /** 当前 Unix 时间戳 */
    function now(): int
    {
        return time();
    }
}

if (!function_exists('app_url')) {
    /** 生成站内 URL */
    function app_url(string $path = '', array $params = []): string
    {
        return \Core\Router::url($path, $params);
    }
}

if (!function_exists('url')) {
    /** app_url 的简写，模板中使用更顺手 */
    function url(string $path = '', array $params = []): string
    {
        return \Core\Router::url($path, $params);
    }
}

if (!function_exists('asset')) {
    /** 生成静态资源 URL，并附带版本号以利缓存刷新 */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = APP_ROOT . '/public/' . $path;
        $ver  = is_file($file) ? (string)filemtime($file) : (string)config('app.version', '1');

        return \Core\Router::base() . '/' . $path . '?v=' . $ver;
    }
}

if (!function_exists('csrf_token')) {
    /** 当前会话的 CSRF Token */
    function csrf_token(): string
    {
        return Security::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    /** 输出隐藏的 CSRF 表单字段 */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Security::csrfToken()) . '">';
    }
}

if (!function_exists('old')) {
    /** 读取上一次提交的输入（表单回填） */
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::old($key, $default);
    }
}

if (!function_exists('flash')) {
    /** 读取一次性提示消息 */
    function flash(string $key = 'message', mixed $default = null): mixed
    {
        return Session::flash($key, $default);
    }
}

if (!function_exists('has_flash')) {
    /** 是否存在一次性提示 */
    function has_flash(string $key = 'message'): bool
    {
        return Session::hasFlash($key);
    }
}

if (!function_exists('errors')) {
    /** 读取表单校验错误集合 */
    function errors(): array
    {
        return Session::errors();
    }
}

if (!function_exists('old_error')) {
    /** 读取某个字段的校验错误 */
    function old_error(string $field): string
    {
        return Session::error($field);
    }
}

if (!function_exists('redirect')) {
    /** 302 跳转并终止请求 */
    function redirect(string $url, int $code = 302): never
    {
        Response::redirect($url, $code);
    }
}

if (!function_exists('can')) {
    /** 判断当前登录用户是否拥有某权限 */
    function can(string $permission, ?array $forum = null): bool
    {
        return Auth::can($permission, $forum);
    }
}

if (!function_exists('auth_user')) {
    /** 当前登录用户（未登录返回 null） */
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('is_logged_in')) {
    /** 是否已登录 */
    function is_logged_in(): bool
    {
        return Auth::check();
    }
}

if (!function_exists('avatar_url')) {
    /** 生成头像地址；上传过头像用上传文件，否则回退到内置 SVG 头像 */
    function avatar_url(?array $user, int $size = 48): string
    {
        return \Core\Avatar::url($user, $size);
    }
}

if (!function_exists('avatar_img')) {
    /** 输出头像 <img> 标签 */
    function avatar_img(?array $user, int $size = 48, string $class = ''): string
    {
        $url = \Core\Avatar::url($user, $size);
        $alt = e((string)($user['username'] ?? '游客'));

        return '<img src="' . e($url) . '" width="' . $size . '" height="' . $size
            . '" alt="' . $alt . '" loading="lazy" class="avatar ' . e($class) . '">';
    }
}

if (!function_exists('render_content')) {
    /** 把用户提交的正文（Markdown / UBB）安全地渲染为 HTML */
    function render_content(string $raw): string
    {
        $html = Text::toHtml($raw);

        // 插件可在输出阶段对最终 HTML 做二次加工（外链加标识、代码高亮、注入卡片等）
        return (string)Hook::filter('content_rendered', $html, ['raw' => $raw]);
    }
}

if (!function_exists('plain_text')) {
    /** 把正文转换为纯文本（用于摘要、SEO 描述） */
    function plain_text(string $raw, int $max = 120): string
    {
        return Text::excerpt($raw, $max);
    }
}

if (!function_exists('human_time')) {
    /** 相对时间：刚刚 / 5 分钟前 / 3 天前 / 2024-05-01 */
    function human_time(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . ' 分钟前';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' 小时前';
        }
        if ($diff < 2592000) {
            return intdiv($diff, 86400) . ' 天前';
        }

        return date('Y-m-d', $timestamp);
    }
}

if (!function_exists('format_number')) {
    /** 大数字缩写：1234 -> 1.2k */
    function format_number(int $number): string
    {
        if ($number < 1000) {
            return (string)$number;
        }
        if ($number < 1000000) {
            return rtrim(rtrim(number_format($number / 1000, 1, '.', ''), '0'), '.') . 'k';
        }

        return rtrim(rtrim(number_format($number / 1000000, 1, '.', ''), '0'), '.') . 'M';
    }
}

if (!function_exists('format_size')) {
    /** 字节数转可读体积 */
    function format_size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float)$bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index === 0 ? (string)(int)$value : number_format($value, $value < 10 ? 1 : 0)) . ' ' . $units[$index];
    }
}

if (!function_exists('hook')) {
    /** 触发一个内容型 Hook，返回值可被插件修改 */
    function hook(string $name, mixed $value = null, array $context = []): mixed
    {
        return Hook::filter($name, $value, $context);
    }
}

if (!function_exists('do_action')) {
    /** 触发一个动作型 Hook，忽略返回值 */
    function do_action(string $name, array $context = []): void
    {
        Hook::action($name, $context);
    }
}

if (!function_exists('view')) {
    /** 渲染模板并返回 HTML */
    function view(string $template, array $data = []): string
    {
        return View::render($template, $data);
    }
}

if (!function_exists('group_ids_from_field')) {
    /**
     * 把版块权限字段（逗号分隔的用户组 ID 字符串）解析为 int 数组
     *
     * @return list<int>
     */
    function group_ids_from_field(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $value) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && ctype_digit($piece)) {
                $ids[] = (int)$piece;
            }
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('selected')) {
    /** 模板中输出 select 的 selected 属性 */
    function selected(mixed $a, mixed $b): string
    {
        return (string)$a === (string)$b ? ' selected' : '';
    }
}

if (!function_exists('checked')) {
    /** 模板中输出 checkbox 的 checked 属性 */
    function checked(mixed $condition): string
    {
        return $condition ? ' checked' : '';
    }
}
