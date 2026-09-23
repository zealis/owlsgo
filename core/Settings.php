<?php
/**
 * 站点设置
 *
 * 采用「按需懒加载」：只有真正访问设置项时才查库，且一次请求内只查一次。
 * 高负载场景下这一层缓存能显著减少查询量。
 */

declare(strict_types=1);

namespace Core;

final class Settings
{
    /** @var array<string, mixed>|null 本次请求内的设置快照 */
    private static ?array $cache = null;

    /**
     * 全部内置设置项及默认值
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            /* 基本信息 */
            'site_name'          => 'owlsgo',
            'site_url'           => '',
            'site_description'   => '一个极简、轻量、零依赖的原生 PHP 论坛',
            'site_keywords'      => '论坛,PHP,轻量社区',
            'site_notice'        => '',
            'site_icp'           => '',

            /* 通知中心（公告）—— 在 /notices/settings 里维护 */
            'notice_center_intro' => '发布站点公告与社区消息',
            'notice_push_enabled' => '1',

            /*
             * 「外观」三个颜色键（theme_primary / theme_primary_dark / theme_highlight）
             * 已随后台设置页的「外观」区块一并退役：站点配色由 theme.css 的固定令牌控制，
             * 布局里的 theme-color 用固定品牌蓝 #00A0E9，不再从设置读取。
             * 老站点 settings 表里的遗留行不影响任何行为，可留可清。
             */

            /* 注册与登录 */
            'register_enabled'   => '1',
            'register_verify'    => '0',
            'register_group'     => '3',
            'login_captcha'      => '0',
            'register_captcha'   => '0',

            /* 发帖 */
            /*
             * 内容审核默认关闭：新帖子 / 新评论立即公开并计入统计。
             *
             * 站长可在后台「发帖」分区随时打开 —— 开启后新帖子需审核通过才对其他人可见、
             * 新评论需审核通过才计入统计；拥有「审核内容」（post.approve）权限的用户组
             * （管理员、版主）不受影响，发帖即时可见。
             *
             * ⚠️ 默认值只对「数据库里还没存过这个键」的站点生效。已保存过设置的站点
             *    以库里的值为准，改默认值不会覆盖它。
             */
            'thread_need_audit'  => '0',
            'post_need_audit'    => '0',
            'post_interval'      => '15',
            'post_min_length'    => '2',
            'post_max_length'    => '20000',
            'guest_view'         => '1',

            /*
             * 长内容折叠：正文渲染高度超过各自阈值时折起来，末尾给「展开全文」。
             *
             * 只影响展示 —— 不截断原文、不改 content 与 content_html，搜索/引用/附件
             * 降级等一律照旧。高度单位 px，与后台表单的 min/max（200–2000）一致。
             */
            'fold_long_content'  => '1',
            'fold_topic_height'  => '250',   // 首楼
            'fold_reply_height'  => '200',   // 回复楼层（比首楼矮一档；200 是允许的最低值）
            'fold_notice_height' => '270',   // 通知中心的公告正文

            /* 附件 */
            'upload_enabled'     => '1',
            'upload_max_size'    => '20',
            'attachment_quota'   => '0',
            'upload_allow_ext'   => 'jpg,jpeg,png,gif,webp,zip,rar,7z,txt,pdf,md',

            /* 站点开关 */
            'site_closed'        => '0',
            'reserved_names'     => 'admin,root,administrator,system,support,official,staff,moderator,api,www,login,register,account,help,about,null,undefined,guest,test',
            'site_closed_reason' => '站点正在维护，请稍后再访问。',

            /* 调试模式：开启后记录 debug 级日志、出错页显示详细报错 */
            'debug_mode'         => '0',

            /* 缓存 */
            'cache_ttl'          => '300',

            /* 计划任务触发令牌（留空表示只允许登录的后台管理员手动触发） */
            'cron_token'         => '',

            /* 版本 */
            'installed_at'       => '',
            'app_version'        => '',
        ];
    }

    /**
     * 读取设置（带缓存）
     */
    public static function get(string $key, mixed $default = ''): mixed
    {
        $all = self::all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $default;
    }

    /** 读取布尔型设置 */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');

        return in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
    }

    /** 读取整型设置 */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, (string)$default);

        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * 全部设置（数据库值覆盖默认值）
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defaults = self::defaults();

        /*
         * 未安装时直接返回内置默认值，不去连数据库。
         *
         * 下面那段 try/catch 看似能兜住「表还不存在」，但兜不住副作用：
         * SQLite 驱动在连接一个不存在的文件时会先把文件创建出来，
         * 所以「查一次 → 失败 → catch」的代价是 storage/database/ 下
         * 凭空多出一个空库文件。真正干净的做法是根本不去连。
         */
        if (!App::isInstalled()) {
            self::$cache = $defaults;

            return self::$cache;
        }

        try {
            $rows = Cache::remember('settings:all', (int)config('app.cache_ttl', 300), static function (): array {
                return Database::select(
                    'SELECT ' . Database::identifier('key') . ', ' . Database::identifier('value')
                    . ' FROM ' . Database::identifier('settings')
                );
            });
        } catch (\Throwable $e) {
            // 安装阶段数据表可能尚未建立
            self::$cache = $defaults;

            return self::$cache;
        }

        $values = [];
        foreach ((array)$rows as $row) {
            $values[(string)$row['key']] = $row['value'];
        }

        self::$cache = array_merge($defaults, $values);

        return self::$cache;
    }

    /**
     * 批量保存设置
     *
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        $allowed = array_keys(self::defaults());
        $now     = time();

        foreach ($values as $key => $value) {
            if (!in_array((string)$key, $allowed, true)) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Database::upsert('settings', [
                'key'        => (string)$key,
                'value'      => is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ], ['key']);
        }

        self::flush();
    }

    /** 清除设置缓存 */
    public static function flush(): void
    {
        self::$cache = null;
        Cache::forget('settings:all');
    }

    /** 初始化默认设置（仅在安装时调用，不覆盖已有值） */
    public static function seedDefaults(): void
    {
        $now = time();

        foreach (self::defaults() as $key => $value) {
            Database::insertIgnore('settings', [
                'key'        => $key,
                'value'      => (string)$value,
                'updated_at' => $now,
            ], ['key']);
        }

        self::flush();
    }
}
