<?php
/**
 * owlsgo 示例插件 —— 业务服务
 *
 * 职责：
 *  - 读取插件配置（带默认值兜底）
 *  - 读写插件自建表 plugin_owlsgo_demo_activity
 *  - 实现 plugin.json 中声明过的各钩子回调
 *
 * 关键约束：钩子回调必须「快速、无副作用、异常不外抛」。
 * Core\Hook 已经会对回调异常做隔离并记日志，但回调自身仍不应阻塞主流程。
 */

declare(strict_types=1);

namespace OwlsgoDemo;

use Core\Auth;
use Core\Database;
use Core\Logger;
use Core\Plugin as PluginApi;
use Core\Query;
use Core\Request;
use Core\Response;
use Core\Router;
use Core\Session;

final class Service
{
    /** 配置项默认值，必须与 plugin.json 的 settings 一一对应 */
    private const DEFAULTS = [
        'greeting'             => '你好，owlsgo！',
        'welcome_style'        => 'card',
        'footnote'             => '',
        'block_keywords'       => '',
        'enable_head_assets'   => '1',
        'enable_footer_assets' => '1',
        'retention_days'       => '7',
    ];

    /** 允许的 welcome_style 取值，用于兜底非法配置 */
    private const WELCOME_STYLES = ['card', 'plain'];

    /* ------------------------------------------------------------------ */
    /*  配置                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 读取插件配置
     *
     * 注意：必须显式传入插件 ID。Plugin::$current 只在「加载阶段」有效，
     * 到了请求分发阶段它已经被重置为空串，省略 $pluginId 会读不到真实配置。
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return PluginApi::config(self::DEFAULTS, Plugin::ID);
    }

    public static function setting(string $key): string
    {
        $config = self::config();
        $value  = $config[$key] ?? self::DEFAULTS[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }

    /** 复选框型配置：后台保存时写的是 '1' / '0' */
    public static function settingOn(string $key): bool
    {
        return self::setting($key) === '1';
    }

    /** 前台页面样式，非法值回落到 card */
    public static function welcomeStyle(): string
    {
        $style = self::setting('welcome_style');

        return in_array($style, self::WELCOME_STYLES, true) ? $style : 'card';
    }

    /** 活动日志保留天数，限制在 1–365 之间 */
    public static function retentionDays(): int
    {
        $days = (int)self::setting('retention_days');

        return max(1, min(365, $days > 0 ? $days : 7));
    }

    /**
     * 禁止词列表（英文逗号分隔，去空去重，单条最长 30 字）
     *
     * @return list<string>
     */
    public static function blockKeywords(): array
    {
        $raw = self::setting('block_keywords');

        if ($raw === '') {
            return [];
        }

        $words = [];

        foreach (explode(',', $raw) as $word) {
            $word = trim($word);

            if ($word !== '') {
                $words[] = mb_substr($word, 0, 30);
            }
        }

        return array_values(array_unique($words));
    }

    /* ------------------------------------------------------------------ */
    /*  自建表读写                                                          */
    /* ------------------------------------------------------------------ */

    /** 插件自有表的查询构造器（表名由核心按 plugin_owlsgo_demo_ 前缀生成） */
    public static function table(): Query
    {
        return PluginApi::table('activity', Plugin::ID);
    }

    /** 插件自建表的完整表名，供 SQL 脚本与文档对齐 */
    public static function tableName(): string
    {
        return PluginApi::tableName('activity', Plugin::ID);
    }

    /**
     * 记录一条插件活动
     */
    public static function record(string $kind, string $detail, int $userId = 0, string $username = ''): void
    {
        try {
            self::table()->insert([
                'user_id'    => $userId,
                'username'   => mb_substr($username, 0, 20),
                'kind'       => mb_substr($kind, 0, 20),
                'detail'     => mb_substr($detail, 0, 255),
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            // 插件记录失败绝不能影响发帖主流程
            Logger::exception($e, 'plugin:owlsgo-demo');
        }
    }

    /**
     * 最近的活动记录
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));

        try {
            return self::table()->orderBy('id', 'desc')->limit($limit)->get();
        } catch (\Throwable $e) {
            Logger::exception($e, 'plugin:owlsgo-demo');

            return [];
        }
    }

    /**
     * 活动概览统计
     *
     * @return array{total:int, threads:int, posts:int, latest:int}
     */
    public static function stats(): array
    {
        try {
            $latest = (int)(self::table()->orderBy('id', 'desc')->value('created_at') ?? 0);

            return [
                'total'   => self::table()->count(),
                'threads' => self::table()->where('kind', 'thread')->count(),
                'posts'   => self::table()->where('kind', 'post')->count(),
                'latest'  => $latest,
            ];
        } catch (\Throwable $e) {
            Logger::exception($e, 'plugin:owlsgo-demo');

            return ['total' => 0, 'threads' => 0, 'posts' => 0, 'latest' => 0];
        }
    }

    /** 清空插件活动日志，返回删除条数 */
    public static function clear(): int
    {
        try {
            return self::table()->delete();
        } catch (\Throwable $e) {
            Logger::exception($e, 'plugin:owlsgo-demo');

            return 0;
        }
    }

    /** 计划任务处理器：清理过期活动日志（签名必须无必填参数） */
    public static function cleanup(): void
    {
        $cutoff  = time() - self::retentionDays() * 86400;
        $deleted = 0;

        try {
            $deleted = self::table()->where('created_at', '<', $cutoff)->delete();
        } catch (\Throwable $e) {
            Logger::exception($e, 'plugin-cron:owlsgo-demo');
        }

        PluginApi::log('清理过期活动日志完成', [
            'retention_days' => self::retentionDays(),
            'deleted'        => $deleted,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  钩子回调                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * before_thread_create / before_post_create：禁止词前置拦截
     *
     * 演示「提交前拦截」的推荐写法：
     *  - AJAX 请求返回 JSON，前端直接弹出提示
     *  - 普通表单提交则回填已填内容并跳回来源页，用户不必重打
     *
     * 实现要点：Core\Hook::filter() 只允许 HttpException 冒泡，
     * 因此这里用 Response::json() / Response::redirect()（两者都是 never）中断流程，
     * 而不是抛普通异常 —— 抛普通异常会被隔离并记日志，拦截不会生效。
     *
     * @param array<string, mixed> $context
     */
    public static function guardContent(array $context = []): void
    {
        $keywords = self::blockKeywords();

        if ($keywords === []) {
            return;
        }

        // 主题有标题，回复没有，统一拼成待检查文本
        $haystack = (string)($context['title'] ?? '') . "\n" . (string)($context['content'] ?? '');

        foreach ($keywords as $word) {
            if (mb_stripos($haystack, $word) === false) {
                continue;
            }

            $message = '内容包含被禁止的词语「' . $word . '」，请修改后再提交。';

            if (Request::wantsJson()) {
                Response::json(['ok' => false, 'message' => $message], 403);
            }

            Session::setErrors(['content' => $message]);
            Session::flashInput(Request::allPost());
            Session::setFlash('message', $message);
            Session::setFlash('type', 'error');

            $referer = Request::referer();

            Response::redirect($referer !== '' ? $referer : Router::url('/'));
        }
    }

    /**
     * content_rendered：在渲染好的正文 HTML 后追加脚注
     *
     * @param array<string, mixed> $context
     */
    public static function decorateContent(string $html, array $context = []): string
    {
        $footnote = trim(self::setting('footnote'));

        if ($footnote === '' || $html === '') {
            return $html;
        }

        return $html
            . '<p class="owlsgo-demo-footnote">'
            . e($footnote)
            . '</p>';
    }

    /**
     * head_assets：向 <head> 注入插件元信息与内联变量
     *
     * @param array<string, mixed> $context
     */
    public static function headAssets(string $html, array $context = []): string
    {
        if (!self::settingOn('enable_head_assets')) {
            return $html;
        }

        return $html
            . '<meta name="owlsgo-plugin" content="owlsgo-demo">' . "\n"
            . '<style>:root{--owlsgo-demo-accent:#00a0e9;}</style>';
    }

    /**
     * footer_assets：把插件配置以 JSON 形式传给插件脚本
     *
     * @param array<string, mixed> $context
     */
    public static function footerAssets(string $html, array $context = []): string
    {
        if (!self::settingOn('enable_footer_assets')) {
            return $html;
        }

        $payload = json_encode([
            'greeting' => self::setting('greeting'),
            'style'    => self::welcomeStyle(),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return $html
            . '<script>window.OwlsgoDemoPlugin = ' . ($payload === false ? '{}' : $payload) . ';</script>';
    }

    /**
     * nav_links：为登录用户追加一条「打招呼」快捷入口
     *
     * @param array<int, mixed>    $links
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public static function navLinks(array $links, array $context = []): array
    {
        $user = $context['user'] ?? null;

        if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
            return $links;
        }

        $links[] = [
            'label' => '打招呼',
            'url'   => Router::url('/hello/' . rawurlencode((string)($user['username'] ?? ''))),
            'icon'  => 'bulb',
        ];

        return $links;
    }

    /**
     * user_profile_tabs：为用户中心追加插件标签页
     *
     * 注意：url 字段会被模板交给 url() 处理，因此必须是「不含查询串的站内路径」，
     * 需要带参数时请改用 view('...', $data) 由页面自行渲染。
     *
     * @param array<int, mixed>    $tabs
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public static function profileTabs(array $tabs, array $context = []): array
    {
        $profile = $context['profile'] ?? null;
        $userId  = is_array($profile) ? (int)($profile['id'] ?? 0) : 0;

        if ($userId <= 0) {
            return $tabs;
        }

        $tabs[] = [
            'key'   => 'owlsgo-demo',
            'label' => '插件标签页',
            'url'   => '/hello/' . rawurlencode((string)($profile['username'] ?? '')),
        ];

        return $tabs;
    }

    /**
     * after_thread_create：记录一条主题活动
     *
     * @param array<string, mixed> $context
     */
    public static function onThreadCreate(array $context = []): void
    {
        $user = is_array($context['user'] ?? null) ? $context['user'] : [];
        $forum = is_array($context['forum'] ?? null) ? $context['forum'] : [];

        self::record(
            'thread',
            '在「' . (string)($forum['name'] ?? '未知版块') . '」发表了主题 #' . (int)($context['thread_id'] ?? 0),
            (int)($user['id'] ?? 0),
            (string)($user['username'] ?? '')
        );
    }

    /**
     * after_post_create：记录一条回复活动
     *
     * @param array<string, mixed> $context
     */
    public static function onPostCreate(array $context = []): void
    {
        $user = is_array($context['user'] ?? null) ? $context['user'] : [];

        self::record(
            'post',
            '在主题 #' . (int)($context['thread_id'] ?? 0) . ' 发表了第 ' . (int)($context['floor'] ?? 0) . ' 楼',
            (int)($user['id'] ?? 0),
            (string)($user['username'] ?? '')
        );
    }

    /* ------------------------------------------------------------------ */
    /*  辅助                                                                */
    /* ------------------------------------------------------------------ */

    /** 当前登录用户，供处理器使用 */
    public static function currentUser(): ?array
    {
        return Auth::user();
    }

    /** 插件自建表是否存在（用于给出友好提示而不是抛异常） */
    public static function tableReady(): bool
    {
        try {
            return Database::tableExists(self::tableName());
        } catch (\Throwable) {
            return false;
        }
    }

    /** 活动类型的中文名 */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'thread' => '发表主题',
            'post'   => '发表回复',
            default  => $kind === '' ? '其它' : $kind,
        };
    }
}
