<?php
/**
 * 权限系统
 *
 * 权限分成两层：
 *  1. 用户组权限（usergroups.permissions）：控制「这个人能做什么」
 *  2. 版块权限（forums.group_view / group_thread / group_reply）：控制「在哪个版块能做」
 *
 * 判定顺序：超级管理员 > 版主 > 用户组权限 + 版块白名单。
 * 所有判定只在本类实现，避免权限逻辑散落各处导致越权。
 */

declare(strict_types=1);

namespace Core;

final class Permission
{
    /** 超级管理员组 ID（不可删除） */
    public const SUPER_GROUP = 1;

    /** 游客组 ID */
    public const GUEST_GROUP = 4;

    /** 禁言组 ID */
    public const MUTED_GROUP = 5;

    /**
     * 作者用户组缓存：键为用户 ID
     *
     * 主题页会把每个楼层都过一遍「能否编辑/删除」的判断，同一位作者常常出现多次。
     * 缓存后整个请求对每位作者只查一次库；静态属性随请求结束销毁，不会跨请求串数据。
     *
     * @var array<int, int>
     */
    private static array $authorGroupCache = [];

    /**
     * 权限清单：键为权限标识，值为 [名称, 分组, 说明]
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    public const CATALOG = [
        /* 内容 */
        'thread.view'        => ['浏览主题', '内容', '关闭后该类用户组无法查看任何主题'],
        'thread.create'      => ['发表主题', '内容', '允许在版块中发布新主题'],
        'thread.reply'       => ['回复主题', '内容', '允许在主题下发表回复'],
        'thread.edit'        => ['编辑自己的内容', '内容', '允许编辑自己发表的帖子'],
        'thread.essence'     => ['加精/置顶', '内容', '将主题设为精华、置顶或推荐'],
        'thread.delete'      => ['删除主题', '内容', '删除任意主题（含他人）'],
        'post.delete'        => ['删除回复', '内容', '删除任意回复（含他人）'],
        'post.approve'       => ['审核内容', '内容', '通过或驳回待审核的主题与回复'],
        'attachment.upload'  => ['上传附件', '内容', '允许上传图片与附件'],
        'attachment.download' => ['下载附件', '内容', '允许查看与下载帖子、公告里的附件（图片也算）；关闭后只列出文件名，点不开'],
        'attachment.manage'  => ['管理附件', '内容', '在后台查看与删除所有附件'],
        'notice.manage'      => ['发布公告', '内容', '在通知中心发布、编辑与管理全站通知'],

        /* 用户 */
        'user.ban'           => ['封禁用户/IP', '用户', '添加与解除封禁记录'],
        'user.manage'        => ['管理用户', '用户', '编辑用户资料、用户组与状态'],

        /* 后台 */
        'admin.access'       => ['进入后台', '后台', '访问后台管理首页'],
        'admin.settings'     => ['站点设置', '后台', '修改站点名称、注册开关等'],
        'admin.forum'        => ['版块管理', '后台', '新增、编辑、排序、删除版块'],
        'admin.group'        => ['用户组管理', '后台', '编辑用户组权限与外观'],
        'admin.user'         => ['用户管理', '后台', '在后台查看与调整用户资料'],
        'admin.content'      => ['内容管理', '后台', '批量管理主题与回复'],
        'admin.plugin'       => ['插件管理', '后台', '安装、启用、停用插件'],
        'admin.cron'         => ['计划任务', '后台', '查看与手动执行计划任务'],
        'admin.logs'         => ['查看日志', '后台', '查看操作日志与运行日志'],
    ];

    /**
     * 「作用点在后台」的非 admin.* 权限
     *
     * 这些权限的业务入口（路由、界面）全部落在 /admin 下，勾选它们等于要使用后台，
     * 因此在 normalize() 里与 admin.* 同等对待，会自动补上「进入后台」。
     *
     * 典型例子：管理附件 → /admin/attachments；封禁用户 → /admin/users/{id}/ban。
     *
     * ⚠️ 以后新增这类「名字不带 admin.、但只服务于后台」的权限，记得同步此表；
     *    纯前台功能（如 notice.manage 发布公告，入口在通知中心）不要加进来。
     *
     * @var list<string>
     */
    public const BACKEND_ONLY = [
        'attachment.manage',
        'user.manage',
        'user.ban',
    ];

    /**
     * 判断用户是否拥有某权限
     *
     * @param array<string, mixed>|null $user   用户记录
     * @param string                    $perm   权限标识
     * @param array<string, mixed>|null $forum  版块记录（传入时附带版块级判定）
     */
    public static function allows(?array $user, string $perm, ?array $forum = null): bool
    {
        $groupId = self::groupOf($user);

        // 超级管理员拥有一切权限
        if ($groupId === self::SUPER_GROUP) {
            return true;
        }

        // 版块级判定优先：版主在该版块内拥有内容管理权限
        if ($forum !== null && self::isModerator($user, $forum)) {
            if (in_array($perm, ['thread.essence', 'thread.delete', 'post.delete', 'post.approve', 'thread.view', 'thread.create', 'thread.reply'], true)) {
                return true;
            }
        }

        $permissions = self::ofGroup($groupId);

        if (!(bool)($permissions[$perm] ?? false)) {
            return false;
        }

        // 进一步做版块白名单校验
        if ($forum !== null) {
            return self::forumAllows($groupId, $perm, $forum);
        }

        return true;
    }

    /**
     * 读取用户组权限（合并默认值）
     *
     * @return array<string, bool>
     */
    public static function ofGroup(int $groupId): array
    {
        static $cache = [];

        if (isset($cache[$groupId])) {
            return $cache[$groupId];
        }

        $defaults = self::groupDefaults($groupId);

        try {
            $row = Database::first(
                'SELECT ' . Database::identifier('permissions') . ' FROM ' . Database::identifier('usergroups')
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$groupId]
            );

            $stored = [];
            if ($row !== null) {
                $decoded = json_decode((string)$row['permissions'], true);
                if (is_array($decoded)) {
                    $stored = array_map(static fn ($v): bool => (bool)$v, $decoded);
                }
            }
        } catch (\Throwable) {
            $stored = [];
        }

        $result = array_merge($defaults, $stored);
        $result = self::withBackendAccess($result);
        $cache[$groupId] = $result;

        return $result;
    }

    /**
     * 各内置用户组的默认权限
     *
     * @return array<string, bool>
     */
    private static function groupDefaults(int $groupId): array
    {
        $all = array_fill_keys(array_keys(self::CATALOG), false);

        return match ($groupId) {
            // 超级管理员：全开
            self::SUPER_GROUP => array_fill_keys(array_keys(self::CATALOG), true),

            // 版主：内容管理 + 审核，无站点设置
            2 => array_merge($all, [
                'thread.view'       => true,
                'thread.create'     => true,
                'thread.reply'      => true,
                'thread.edit'       => true,
                'thread.essence'    => true,
                'thread.delete'     => true,
                'post.delete'       => true,
                'post.approve'      => true,
                'attachment.upload' => true,
                'attachment.download' => true,
                'notice.manage'     => true,
                'user.ban'          => true,
                'admin.access'      => true,
                'admin.content'     => true,
            ]),

            // 注册用户：正常发帖
            3 => array_merge($all, [
                'thread.view'       => true,
                'thread.create'     => true,
                'thread.reply'      => true,
                'thread.edit'       => true,
                'attachment.upload' => true,
                'attachment.download' => true,
            ]),

            // 禁言用户：只能看
            self::MUTED_GROUP => array_merge($all, [
                'thread.view' => true,
            ]),

            // 游客（未登录）
            default => array_merge($all, [
                'thread.view' => true,
            ]),
        };
    }

    /**
     * 版块级白名单判定
     *
     * 规则：字段为空表示「所有用户组可用」；非空则必须是逗号分隔的用户组 ID 之一。
     *
     * @param array<string, mixed> $forum
     */
    public static function forumAllows(int $groupId, string $permission, array $forum): bool
    {
        $field = match ($permission) {
            'thread.view'   => 'group_view',
            'thread.create' => 'group_thread',
            'thread.reply'  => 'group_reply',
            default         => '',
        };

        // 不属于版块级权限，直接放行
        if ($field === '') {
            return true;
        }

        $allowed = group_ids_from_field((string)($forum[$field] ?? ''));

        if ($allowed === []) {
            return true;
        }

        return in_array($groupId, $allowed, true);
    }

    /**
     * 当前用户是否可在该版块浏览
     */
    public static function canViewForum(?array $user, array $forum): bool
    {
        return self::allows($user, 'thread.view', $forum);
    }

    /**
     * 当前用户是否可在该版块发主题
     */
    public static function canCreateThread(?array $user, array $forum): bool
    {
        if ((int)($forum['allow_thread'] ?? 1) !== 1) {
            return false;
        }

        return self::allows($user, 'thread.create', $forum);
    }

    /**
     * 当前用户是否可在该版块回复
     */
    public static function canReply(?array $user, array $forum): bool
    {
        if ((int)($forum['allow_reply'] ?? 1) !== 1) {
            return false;
        }

        return self::allows($user, 'thread.reply', $forum);
    }

    /** 用户所属用户组 ID（未登录为游客组） */
    public static function groupOf(?array $user): int
    {
        if ($user === null) {
            return self::GUEST_GROUP;
        }

        $groupId = (int)($user['group_id'] ?? self::GUEST_GROUP);

        return $groupId > 0 ? $groupId : self::GUEST_GROUP;
    }

    /**
     * 是否为该版块的版主（版主名单 + 用户组为版主/管理员）
     *
     * @param array<string, mixed> $forum
     */
    public static function isModerator(?array $user, array $forum): bool
    {
        if ($user === null) {
            return false;
        }

        $groupId = self::groupOf($user);

        if ($groupId === self::SUPER_GROUP) {
            return true;
        }

        $moderators = group_ids_from_field((string)($forum['moderators'] ?? ''));

        return in_array((int)($user['id'] ?? 0), $moderators, true);
    }

    /**
     * 能否改动某位作者发布的内容（编辑 / 删除）
     *
     * 唯一的硬规则：**超级管理员发布的内容，只有超级管理员能改**。
     * 版主可以管理普通用户的内容，但动不了管理员的 —— 与「只有超管能封禁超管」
     * （Modules\Admin\UserController）保持同一口径。
     *
     * 至于「这个人有没有编辑/删除他人内容的权限」（thread.edit / thread.delete /
     * post.delete，或后台的内容管理权限），由调用方先行判定，这里只管那条保护线。
     *
     * 两种情形不受限制：改自己的内容；内容的作者已不存在（ID ≤ 0）。
     *
     * @param array<string, mixed>|null $user        操作者
     * @param int                       $authorId    内容作者的用户 ID
     * @param int|null                  $authorGroup 作者用户组；列表页批量判断时可传入，省去逐行查库
     */
    public static function canManageContentOf(?array $user, int $authorId, ?int $authorGroup = null): bool
    {
        if ($authorId <= 0 || (int)($user['id'] ?? 0) === $authorId) {
            return true;
        }

        // 操作者自己就是超级管理员：不设限（与「只有超管能封禁超管」的口径一致）
        if (self::groupOf($user) === self::SUPER_GROUP) {
            return true;
        }

        if ($authorGroup === null) {
            $authorGroup = self::authorGroupId($authorId);
        }

        return $authorGroup !== self::SUPER_GROUP;
    }

    /**
     * 查某位用户所属的用户组（带请求内缓存，见 $authorGroupCache）
     */
    private static function authorGroupId(int $authorId): int
    {
        if (!array_key_exists($authorId, self::$authorGroupCache)) {
            self::$authorGroupCache[$authorId] = (int)Database::value(
                'SELECT ' . Database::identifier('group_id')
                . ' FROM ' . Database::identifier('users')
                . ' WHERE ' . Database::identifier('id') . ' = ?',
                [$authorId]
            );
        }

        return self::$authorGroupCache[$authorId];
    }

    /**
     * 规范化权限数组（只保留白名单内的键，供后台保存使用）
     *
     * @param array<string, mixed> $input
     * @return array<string, bool>
     */
    public static function normalize(array $input): array
    {
        $result = [];

        foreach (array_keys(self::CATALOG) as $key) {
            $result[$key] = in_array((string)$key, array_map('strval', $input), true)
                || (bool)($input[$key] ?? false);
        }

        /*
         * 勾了任意一项「后台权限」，就自动补上「进入后台」。
         *
         * 「进入后台」是所有后台页面的前置条件 —— 顶栏的后台入口、维护模式下的
         * 豁免、后台导航里的「概览」都看它。若只勾具体页面权限而不勾它，会出现
         * 「能靠直接输入网址打开某个后台页，却看不到任何入口、也打不开概览」的
         * 割裂状态，所以在这里补全，让「后台权限」始终自洽。
         */
        return self::withBackendAccess($result);
    }

    /**
     * 补全「进入后台」
     *
     * 拥有任意一项后台权限，就应当同时具备后台准入 —— 理由见 normalize() 里的说明。
     * 规则集中在这里，保存（normalize）与读取（ofGroup）两条路径共用：
     *  - 保存时补全 → 落库，用户组编辑页的勾选状态与实际生效一致；
     *  - 读取时补全 → 兜底，让「本次改动之前就已保存过的用户组」无需重存即刻修正。
     *
     * @param  array<string, bool> $permissions
     * @return array<string, bool>
     */
    private static function withBackendAccess(array $permissions): array
    {
        if (!empty($permissions['admin.access'])) {
            return $permissions;
        }

        foreach ($permissions as $key => $enabled) {
            if (!$enabled) {
                continue;
            }

            $key = (string)$key;

            if ($key !== 'admin.access'
                && (str_starts_with($key, 'admin.') || in_array($key, self::BACKEND_ONLY, true))) {
                $permissions['admin.access'] = true;
                break;
            }
        }

        return $permissions;
    }
}
