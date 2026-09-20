<?php
/**
 * 用户组模型
 *
 * 用户组数据被权限判定与用户展示频繁读取，因此整表缓存在进程内（按需加载）。
 */

declare(strict_types=1);

namespace Modules\User;

use Core\Cache;
use Core\Database;
use Core\Model;
use Core\Permission;

final class UsergroupModel extends Model
{
    protected static string $table = 'usergroups';

    /** 用户组没有软删除 */
    protected static bool $softDelete = false;

    /**
     * 附件空间配额（单位 MB，0 = 不限制）
     *
     * 这是**后加字段**，项目没有迁移机制，老站点靠 ensureQuotaColumn() 惰性补列。
     */
    public const QUOTA_COLUMN = 'attach_quota_mb';

    /** 配额上限（MB）：纯粹是防手滑填天文数字，1 TB */
    public const QUOTA_MAX = 1048576;

    /** @var array<int, array<string, mixed>>|null 进程内缓存 */
    private static ?array $cache = null;

    /**
     * 保证配额字段存在（老站点惰性补列）
     *
     * ⚠️ 补列后**不能**指望 `Model::columns()` 立刻认得它：那是进程内静态缓存，
     * 同一次请求里拿到的仍是旧列表，`filterColumns()` 会把新字段静默丢掉。
     * 所以本类的配额读写一律走 `Database` 的裸 SQL，不经过模型白名单。
     */
    public static function ensureQuotaColumn(): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }
        $checked = true;

        if (Database::hasColumn('usergroups', self::QUOTA_COLUMN)) {
            return;
        }

        try {
            Database::execute(
                'ALTER TABLE ' . Database::identifier('usergroups')
                . ' ADD COLUMN ' . Database::identifier(self::QUOTA_COLUMN) . ' INTEGER NOT NULL DEFAULT 0'
            );
        } catch (\Throwable) {
            // 并发请求可能同时补列，失败的一方忽略即可（列此时已由另一方建好）
        }
    }

    /**
     * 取某个用户组的附件配额（MB，0 = 不限制）
     */
    public static function quotaOf(int $groupId): int
    {
        if ($groupId <= 0) {
            return 0;
        }

        self::ensureQuotaColumn();

        return max(0, (int)Database::value(
            'SELECT ' . Database::identifier(self::QUOTA_COLUMN) . ' FROM ' . Database::identifier('usergroups')
            . ' WHERE ' . Database::identifier('id') . ' = ?',
            [$groupId]
        ));
    }

    /**
     * 写入某个用户组的附件配额（MB）
     *
     * 走 Database::update 而不是 updateById()：见 ensureQuotaColumn() 的说明，
     * 新建的列可能还不在模型的字段白名单里。
     */
    public static function setQuota(int $groupId, int $quotaMb): void
    {
        self::ensureQuotaColumn();

        Database::update(
            'usergroups',
            [self::QUOTA_COLUMN => max(0, min(self::QUOTA_MAX, $quotaMb))],
            Database::identifier('id') . ' = ?',
            [$groupId]
        );

        self::flush();
    }

    /**
     * 全部用户组的配额（ID => MB），供用户组列表一次性取回，避免逐行查询
     *
     * @return array<int, int>
     */
    public static function quotaMap(): array
    {
        self::ensureQuotaColumn();

        $map = [];

        foreach (Database::select(
            'SELECT ' . Database::identifier('id') . ', ' . Database::identifier(self::QUOTA_COLUMN)
            . ' FROM ' . Database::identifier('usergroups')
        ) as $row) {
            $map[(int)$row['id']] = max(0, (int)$row[self::QUOTA_COLUMN]);
        }

        return $map;
    }

    /** 把表单里传来的配额值规范成合法的 MB 整数（0 = 不限制） */
    public static function normalizeQuota(mixed $value): int
    {
        $mb = (int)trim((string)$value);

        return max(0, min(self::QUOTA_MAX, $mb));
    }

    /** 配额的可读文案：0 显示为「不限」 */
    public static function quotaText(int $quotaMb): string
    {
        return $quotaMb <= 0 ? '不限' : format_size($quotaMb * 1048576);
    }

    /**
     * 用户组列表里的配额文案
     *
     * 配额是「能传多少」，上传权限是「能不能传」——前者只对后者为真的组有意义。
     * 游客、禁言用户这类没有 `attachment.upload` 的组，配额留 0 是「用不上」而不是
     * 「不限制」，若照常显示「不限」，会被读成「这个组可以随便上传」，所以直接标出来。
     */
    public static function quotaLabel(bool $canUpload, int $quotaMb): string
    {
        return $canUpload ? self::quotaText($quotaMb) : '无上传权限';
    }

    /**
     * 全部用户组（按排序）
     *
     * @return array<int, array<string, mixed>> 以 ID 为键
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = Cache::remember('usergroups:all', (int)config('app.cache_ttl', 300), static function (): array {
            return Database::select(
                'SELECT * FROM ' . Database::identifier('usergroups')
                . ' ORDER BY ' . Database::identifier('sort_order') . ' ASC, ' . Database::identifier('id') . ' ASC'
            );
        });

        $result = [];
        foreach ((array)$rows as $row) {
            $result[(int)$row['id']] = $row;
        }

        self::$cache = $result;

        return self::$cache;
    }

    /**
     * 按 ID 获取用户组（带默认回退）
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        $all = self::all();

        if (isset($all[$id])) {
            return $all[$id];
        }

        return $all[Permission::GUEST_GROUP] ?? null;
    }

    /**
     * 用户组下拉选项
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $result = [];

        foreach (self::all() as $id => $group) {
            $result[$id] = (string)$group['name'];
        }

        return $result;
    }

    /**
     * 新增用户组
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool, message:string, id:int}
     */
    public static function createGroup(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $slug = trim((string)($input['slug'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'message' => '请填写用户组名称。', 'id' => 0];
        }

        if ($slug === '') {
            $slug = 'group-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        if (!preg_match('/^[a-z0-9_\-]{2,32}$/', $slug)) {
            return ['ok' => false, 'message' => '用户组标识只能包含小写字母、数字、下划线与短横线。', 'id' => 0];
        }

        $duplicated = Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('usergroups')
            . ' WHERE ' . Database::identifier('slug') . ' = ?',
            [$slug]
        );

        if ((int)$duplicated > 0) {
            return ['ok' => false, 'message' => '该用户组标识已存在。', 'id' => 0];
        }

        $permissions = Permission::normalize((array)($input['permissions'] ?? []));

        $id = static::create([
            'name'        => mb_substr($name, 0, 60),
            'slug'        => $slug,
            'description' => mb_substr(trim((string)($input['description'] ?? '')), 0, 120),
            'color'       => self::normalizeColor((string)($input['color'] ?? '#00A0E9')),
            'icon'        => '',
            'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            'is_system'   => 0,
            'sort_order'  => (int)($input['sort_order'] ?? 0),
        ]);

        /*
         * 附件配额单独写：它可能在模型字段白名单之外（见 ensureQuotaColumn()），
         * 混在 create() 的数组里会被 filterColumns() 静默丢掉。
         */
        self::setQuota($id, self::normalizeQuota($input['attach_quota_mb'] ?? 0));

        self::flush();

        return ['ok' => true, 'message' => '用户组已创建。', 'id' => $id];
    }

    /**
     * 更新用户组
     *
     * @param array<string, mixed> $input
     */
    public static function updateGroup(int $id, array $input): array
    {
        $group = self::find($id);

        if ($group === null || (int)$group['id'] !== $id) {
            return ['ok' => false, 'message' => '用户组不存在。'];
        }

        $data = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                return ['ok' => false, 'message' => '用户组名称不能为空。'];
            }
            $data['name'] = mb_substr($name, 0, 60);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = mb_substr(trim((string)$input['description']), 0, 120);
        }

        if (array_key_exists('color', $input)) {
            $data['color'] = self::normalizeColor((string)$input['color']);
        }

        if (array_key_exists('sort_order', $input)) {
            $data['sort_order'] = (int)$input['sort_order'];
        }

        if (array_key_exists('permissions', $input)) {
            $permissions = Permission::normalize((array)$input['permissions']);

            // 超级管理员组权限不可被削减，避免把自己锁在门外
            if ($id === Permission::SUPER_GROUP) {
                $permissions = array_fill_keys(array_keys(Permission::CATALOG), true);
            }

            $data['permissions'] = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        }

        if ($data !== []) {
            static::updateById($id, $data);
        }

        /* 附件配额单独写（可能不在模型白名单里，理由同 createGroup） */
        if (array_key_exists('attach_quota_mb', $input)) {
            self::setQuota($id, self::normalizeQuota($input['attach_quota_mb']));
        }

        self::flush();

        return ['ok' => true, 'message' => '用户组已更新。'];
    }

    /**
     * 删除用户组（系统内置组与仍有成员的用户组不可删除）
     */
    public static function deleteGroup(int $id): array
    {
        $group = self::find($id);

        if ($group === null || (int)$group['id'] !== $id) {
            return ['ok' => false, 'message' => '用户组不存在。'];
        }

        if ((int)$group['is_system'] === 1) {
            return ['ok' => false, 'message' => '系统内置用户组不可删除。'];
        }

        $members = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('users')
            . ' WHERE ' . Database::identifier('group_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$id]
        );

        if ($members > 0) {
            return ['ok' => false, 'message' => '该用户组下仍有 ' . $members . ' 位用户，请先调整他们的用户组。'];
        }

        Database::delete('usergroups', Database::identifier('id') . ' = ?', [$id]);
        self::flush();

        return ['ok' => true, 'message' => '用户组已删除。'];
    }

    /** 清除用户组缓存 */
    public static function flush(): void
    {
        self::$cache = null;
        Cache::forget('usergroups:all');
        Model::flushRowCache();
    }

    /** 规范化颜色值，只接受 3/6 位十六进制色值 */
    private static function normalizeColor(string $color): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) === 1 ? strtolower($color) : '#00a0e9';
    }
}
