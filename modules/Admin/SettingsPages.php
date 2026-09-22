<?php
/**
 * 后台「站点设置」的分组登记
 *
 * 设置页由「一页长表单」改成「侧栏下拉分组 + 每组独立页面」后，有三件事必须共用同一份定义，
 * 否则迟早对不上：侧栏下拉的链接、每个页面渲染哪些字段、**保存时只处理当前分组的键**。
 *
 * 最后一条是关键：分组之后提交上来的是「部分表单」，
 * 若照旧遍历全部布尔键（未勾选 = 未提交 = 0），保存「附件」页就会把「注册与登录」页的开关
 * 统统写成 0 —— 这类 bug 只在保存后才暴露，所以做了分组就一定要收窄键集合。
 *
 * 于是这里同时登记「键 → 处理方式」（文本 / 布尔 / 整数 / 扩展名白名单）与「键 → 所属分组」：
 * 新增设置项只要动本文件 + `Settings::defaults()` 两处。
 * 自检工具：`.tools/check-settings-pages.php`（查漏登记、查重复归属）。
 */

declare(strict_types=1);

namespace Modules\Admin;

final class SettingsPages
{
    /** 裸地址 /admin/settings 落到哪一页 */
    public const DEFAULT_SLUG = 'basic';

    /**
     * 文本项 => 最大长度（服务端按此裁剪，模板里的 maxlength 与之保持一致）
     *
     * @var array<string, int>
     */
    private const TEXT_KEYS = [
        'site_name'          => 60,
        'site_url'           => 191,
        'site_description'   => 200,
        'site_keywords'      => 200,
        'site_icp'           => 60,
        'site_closed_reason' => 200,
    ];

    /**
     * 「允许上传的扩展名」不走上面的定长裁剪：它有自己的清洗规则
     * （小写去重、剔除高危扩展名），见 AdminController::normalizeExtensions()
     *
     * @var list<string>
     */
    private const EXT_KEYS = ['upload_allow_ext'];

    /**
     * 布尔项：表单未勾选时浏览器不提交，因此必须显式写入 0
     *
     * @var list<string>
     */
    private const BOOL_KEYS = [
        'register_enabled', 'register_verify', 'login_captcha', 'register_captcha', 'guest_view',
        'thread_need_audit', 'post_need_audit',
        'fold_long_content',
        'upload_enabled',
        'site_closed', 'debug_mode',
    ];

    /**
     * 数值项 => [最小值, 最大值]（服务端钳制区间，模板的 min/max 与之保持一致）
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const INT_KEYS = [
        'register_group'     => [1, 999],
        'post_interval'      => [0, 86400],
        'post_min_length'    => [1, 1000],
        'post_max_length'    => [10, 200000],
        'fold_topic_height'  => [100, 2000],
        'fold_reply_height'  => [100, 2000],
        'fold_notice_height' => [100, 2000],
        'upload_max_size'    => [1, 512],
        'attachment_quota'   => [0, 1048576],
        'cache_ttl'          => [0, 86400],
    ];

    /**
     * 分组定义
     *
     * `form` 为 false 表示该页不由外壳套 <form>（页内自带独立表单/动作，避免 form 嵌套）。
     * 它**不等于**「不能保存」：能否提交看的是 keys 是否为空（见 AdminController::saveSettings）。
     *
     * 页面模板文件名 = `templates/admin/settings-{slug}.php`。
     *
     * @var array<string, array{label: string, icon: string, summary: string, form: bool, keys: list<string>}>
     */
    private const PAGES = [
        'basic' => [
            'label'   => '基本信息',
            'icon'    => 'settings',
            'summary' => '站点名称、地址、描述与备案号',
            'form'    => true,
            'keys'    => ['site_name', 'site_url', 'site_description', 'site_keywords', 'site_icp'],
        ],

        'login' => [
            'label'   => '注册与登录',
            'icon'    => 'user',
            'summary' => '注册开关、邮箱验证、验证码与游客浏览',
            'form'    => true,
            'keys'    => [
                'register_enabled', 'register_verify', 'login_captcha', 'register_captcha',
                'guest_view', 'register_group',
            ],
        ],

        /*
         * 「帖子」把原来的「发帖」与「长内容折叠」合成一页：
         * 两块设置都是「帖子怎么发、怎么显示」，放在同一个页面里看更顺 ——
         * 页面内仍分两张卡片（发帖 / 长内容折叠），保存条跟着最后一张卡片走。
         */
        'thread' => [
            'label'   => '帖子',
            'icon'    => 'message',
            'summary' => '内容审核、发帖间隔与字数限制、长内容折叠',
            'form'    => true,
            'keys'    => [
                'thread_need_audit', 'post_need_audit',
                'post_interval', 'post_min_length', 'post_max_length',
                'fold_long_content',
                'fold_topic_height', 'fold_reply_height', 'fold_notice_height',
            ],
        ],

        'upload' => [
            'label'   => '附件',
            'icon'    => 'paperclip',
            'summary' => '上传开关、单文件上限、空间配额与扩展名白名单',
            'form'    => true,
            'keys'    => [
                'upload_enabled', 'upload_max_size', 'attachment_quota',
                'upload_allow_ext', 'cache_ttl',
            ],
        ],

        /*
         * 「系统与维护」= 原来的「站点开关」+「系统维护」合成一页：
         * 关站点、开调试与「改完没效果就来清 OPcache」本来就是同一件事的两半。
         *
         * form = false：页内自带两个独立 <form>（保存站点开关 / 清理 OPcache），
         * 外壳不给它套表单（否则 form 嵌套）。但 keys 非空 ——
         * 保存与否看的是「这一组有没有登记键」，不是 form 标志（见 AdminController::saveSettings）。
         */
        'system' => [
            'label'   => '系统与维护',
            'icon'    => 'server',
            'summary' => '维护模式、调试模式，以及清理 OPcache 与查看系统日志',
            'form'    => false,
            'keys'    => ['site_closed', 'site_closed_reason', 'debug_mode'],
        ],
    ];

    /**
     * 旧分组 slug => 新 slug（合并/改名后，老书签与旧链接重定向过去，别直接 404）
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'switch'      => 'system',
        'maintenance' => 'system',
    ];

    /**
     * 全部分组（slug => 定义）
     *
     * @return array<string, array{label: string, icon: string, summary: string, form: bool, keys: list<string>}>
     */
    public static function all(): array
    {
        return self::PAGES;
    }

    public static function has(string $slug): bool
    {
        return isset(self::PAGES[$slug]);
    }

    /**
     * 旧 slug → 新 slug（不存在则返回原值）
     *
     * 分组合并/改名后，旧地址靠它 302 到新页面，别让用户的书签直接 404。
     */
    public static function canonical(string $slug): string
    {
        return self::ALIASES[$slug] ?? $slug;
    }

    /** 该分组登记的键（保存门槛与自检都看它：没有键 = 没有可提交的设置） */
    public static function keys(string $slug): array
    {
        return self::meta($slug)['keys'];
    }

    /**
     * 取某个分组的定义（未知 slug 调用前先 has()）
     *
     * @return array{label: string, icon: string, summary: string, form: bool, keys: list<string>}
     */
    public static function meta(string $slug): array
    {
        return self::PAGES[$slug] ?? self::PAGES[self::DEFAULT_SLUG];
    }

    /** 当前分组的页面标题（侧栏子项与面包屑共用同一份文案） */
    public static function label(string $slug): string
    {
        return self::meta($slug)['label'];
    }

    /** 分组页面的路径（未套 url()，调用方自行决定是否加前缀） */
    public static function path(string $slug): string
    {
        return '/admin/settings/' . $slug;
    }

    /**
     * 当前分组内、按「文本裁剪」处理的键 => 最大长度
     *
     * @return array<string, int>
     */
    public static function textKeys(string $slug): array
    {
        return array_intersect_key(self::TEXT_KEYS, array_flip(self::meta($slug)['keys']));
    }

    /**
     * 当前分组内、按「扩展名白名单」处理的键
     *
     * @return list<string>
     */
    public static function extKeys(string $slug): array
    {
        return array_values(array_intersect(self::EXT_KEYS, self::meta($slug)['keys']));
    }

    /**
     * 当前分组内的布尔键
     *
     * @return list<string>
     */
    public static function boolKeys(string $slug): array
    {
        return array_values(array_intersect(self::BOOL_KEYS, self::meta($slug)['keys']));
    }

    /**
     * 当前分组内的数值键 => [最小值, 最大值]
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function intKeys(string $slug): array
    {
        return array_intersect_key(self::INT_KEYS, array_flip(self::meta($slug)['keys']));
    }

    /**
     * 侧栏下拉用的导航项
     *
     * @return list<array{label: string, url: string, icon: string}>
     */
    public static function nav(): array
    {
        $items = [];

        foreach (self::PAGES as $slug => $page) {
            $items[] = [
                'label' => $page['label'],
                'url'   => self::path($slug),
                'icon'  => $page['icon'],
            ];
        }

        return $items;
    }

    /* ------------------------------------------------------------------ */
    /*  自检用（.tools/check-settings-pages.php）：只读，不参与渲染         */
    /* ------------------------------------------------------------------ */

    /**
     * 已登记「处理方式」的全部键（文本 + 扩展名 + 布尔 + 数值）
     *
     * @return list<string>
     */
    public static function registeredKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::TEXT_KEYS),
            self::EXT_KEYS,
            self::BOOL_KEYS,
            array_keys(self::INT_KEYS)
        )));
    }

    /**
     * 各分组声明的键 => 出现了几次（重复归属说明写重了）
     *
     * @return array<string, int>
     */
    public static function keyOwners(): array
    {
        $owners = [];

        foreach (self::PAGES as $page) {
            foreach ($page['keys'] as $key) {
                $owners[$key] = ($owners[$key] ?? 0) + 1;
            }
        }

        return $owners;
    }
}
