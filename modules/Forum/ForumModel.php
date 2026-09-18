<?php
/**
 * 版块模型
 *
 * 首页需要一次性展示所有版块及其统计信息，因此这里提供「一次查询、进程内缓存」的读取方式，
 * 避免每个版块都单独查一次库。
 */

declare(strict_types=1);

namespace Modules\Forum;

use Core\Cache;
use Core\Database;
use Core\Model;
use Core\Permission;

final class ForumModel extends Model
{
    protected static string $table = 'forums';

    /** @var array<int, array<string, mixed>>|null 进程内全量缓存 */
    private static ?array $all = null;

    /**
     * 全部版块（含已隐藏，按排序）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $rows = Cache::remember('forums:all', (int)config('app.cache_ttl', 300), static function (): array {
            return Database::select(
                'SELECT * FROM ' . Database::identifier('forums')
                . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL'
                . ' ORDER BY ' . Database::identifier('parent_id') . ' ASC, '
                . Database::identifier('sort_order') . ' ASC, ' . Database::identifier('id') . ' ASC'
            );
        });

        $result = [];
        foreach ((array)$rows as $row) {
            $result[(int)$row['id']] = $row;
        }

        self::$all = $result;

        return self::$all;
    }

    /** 按 ID 获取版块 */
    public static function find(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * 取版块，不存在则 404
     *
     * @return array<string, mixed>
     */
    public static function findOrFail(int $id): array
    {
        $forum = self::find($id);

        if ($forum === null) {
            \Core\App::abort(404, '版块不存在或已被删除。');
        }

        return $forum;
    }

    /**
     * 可见版块列表（按权限过滤，仅返回 status=1 的）
     *
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public static function visible(?array $user, bool $includeHidden = false): array
    {
        $result = [];

        foreach (self::all() as $forum) {
            if (!$includeHidden && (int)$forum['status'] !== 1) {
                continue;
            }

            if (!Permission::canViewForum($user, $forum)) {
                continue;
            }

            $result[] = $forum;
        }

        return $result;
    }

    /**
     * 构建版块树（一级版块 + 子版块）
     *
     * @param list<array<string, mixed>> $forums
     * @return list<array{forum:array<string,mixed>, children:list<array<string,mixed>>}>
     */
    public static function tree(array $forums): array
    {
        $byParent = [];
        foreach ($forums as $forum) {
            $byParent[(int)$forum['parent_id']][] = $forum;
        }

        $tree = [];
        foreach ($byParent[0] ?? [] as $root) {
            $tree[] = [
                'forum'    => $root,
                'children' => $byParent[(int)$root['id']] ?? [],
            ];
        }

        // 没有归属父级的版块（父级被删）也展示出来，避免内容「消失」
        foreach ($byParent as $parentId => $children) {
            if ($parentId === 0) {
                continue;
            }
            foreach ($children as $orphan) {
                $parentExists = false;
                foreach ($forums as $forum) {
                    if ((int)$forum['id'] === $parentId) {
                        $parentExists = true;
                        break;
                    }
                }
                if (!$parentExists) {
                    $tree[] = ['forum' => $orphan, 'children' => []];
                }
            }
        }

        return $tree;
    }

    /**
     * 版块下拉选项
     *
     * @return array<int, string>
     */
    public static function options(bool $includeHidden = true): array
    {
        $options = [];

        foreach (self::all() as $id => $forum) {
            if (!$includeHidden && (int)$forum['status'] !== 1) {
                continue;
            }

            $prefix = (int)$forum['parent_id'] > 0 ? '　└ ' : '';
            $options[$id] = $prefix . (string)$forum['name'];
        }

        return $options;
    }

    /**
     * 新增版块
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool, message:string, id:int}
     */
    public static function createForum(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'message' => '请填写版块名称。', 'id' => 0];
        }

        $data = self::buildData($input);
        $data['name'] = mb_substr($name, 0, 60);

        $id = static::create($data);

        self::flush();

        return ['ok' => true, 'message' => '版块已创建。', 'id' => $id];
    }

    /**
     * 更新版块
     *
     * @param array<string, mixed> $input
     */
    public static function updateForum(int $id, array $input): array
    {
        $forum = self::find($id);

        if ($forum === null) {
            return ['ok' => false, 'message' => '版块不存在。'];
        }

        $data = self::buildData($input, $forum);

        if ($data === []) {
            return ['ok' => false, 'message' => '没有需要更新的内容。'];
        }

        static::updateById($id, $data);
        self::flush();

        return ['ok' => true, 'message' => '版块已更新。'];
    }

    /**
     * 删除版块（软删除；若仍有主题则拒绝，避免内容变成孤儿）
     */
    public static function deleteForum(int $id): array
    {
        $forum = self::find($id);

        if ($forum === null) {
            return ['ok' => false, 'message' => '版块不存在。'];
        }

        $threads = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('forum_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$id]
        );

        if ($threads > 0) {
            return ['ok' => false, 'message' => '该版块下还有 ' . $threads . ' 个主题，请先移动或删除后再试。'];
        }

        $children = (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('forums')
            . ' WHERE ' . Database::identifier('parent_id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$id]
        );

        if ($children > 0) {
            return ['ok' => false, 'message' => '请先删除该版块下的子版块。'];
        }

        static::deleteById($id);
        self::flush();

        return ['ok' => true, 'message' => '版块已删除。'];
    }

    /**
     * 从表单构建版块字段
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private static function buildData(array $input, ?array $current = null): array
    {
        $data = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);
            if ($name === '') {
                return [];
            }
            $data['name'] = mb_substr($name, 0, 60);
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = mb_substr(trim((string)$input['description']), 0, 200);
        }

        if (array_key_exists('announcement', $input)) {
            $data['announcement'] = mb_substr(trim((string)$input['announcement']), 0, 1000);
        }

        if (array_key_exists('icon', $input)) {
            $data['icon'] = mb_substr(trim((string)$input['icon']), 0, 40);
        }

        if (array_key_exists('slug', $input)) {
            $slug = strtolower(trim((string)$input['slug']));
            $data['slug'] = preg_match('/^[a-z0-9_\-]{0,60}$/', $slug) === 1 ? $slug : '';
        }

        if (array_key_exists('parent_id', $input)) {
            $parentId = (int)$input['parent_id'];
            // 不允许把自己设为自己的父级
            if ($current !== null && $parentId === (int)$current['id']) {
                $parentId = 0;
            }
            $data['parent_id'] = $parentId > 0 ? $parentId : 0;
        }

        if (array_key_exists('sort_order', $input)) {
            $data['sort_order'] = (int)$input['sort_order'];
        }

        foreach (['status', 'allow_thread', 'allow_reply', 'allow_attachment'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $data[$flag] = (int)(bool)$input[$flag];
            }
        }

        // 用户组白名单字段：空数组表示不限制
        foreach (['group_view', 'group_thread', 'group_reply', 'moderators'] as $field) {
            if (array_key_exists($field, $input)) {
                $ids = is_array($input[$field]) ? $input[$field] : explode(',', (string)$input[$field]);
                $clean = [];
                foreach ($ids as $id) {
                    if (is_scalar($id) && preg_match('/^\d+$/', (string)$id)) {
                        $clean[] = (int)$id;
                    }
                }
                $data[$field] = implode(',', array_values(array_unique($clean)));
            }
        }

        return $data;
    }

    /**
     * 版块统计（首页汇总卡片）
     */
    public static function stats(): array
    {
        $forumRow = Database::first(
            'SELECT COUNT(*) AS ' . Database::identifier('total')
            . ' FROM ' . Database::identifier('forums')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 1'
        );

        $threadRow = Database::first(
            'SELECT COUNT(*) AS ' . Database::identifier('total')
            . ' FROM ' . Database::identifier('threads')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 1'
        );

        $postRow = Database::first(
            'SELECT COUNT(*) AS ' . Database::identifier('total')
            . ' FROM ' . Database::identifier('posts')
            . ' WHERE ' . Database::identifier('deleted_at') . ' IS NULL AND ' . Database::identifier('status') . ' = 1'
        );

        return [
            'forums'  => (int)($forumRow['total'] ?? 0),
            'threads' => (int)($threadRow['total'] ?? 0),
            'posts'   => (int)($postRow['total'] ?? 0),
        ];
    }

    /** 清除版块缓存 */
    public static function flush(): void
    {
        self::$all = null;
        Cache::forget('forums:all');
        Model::flushRowCache();
    }
}
