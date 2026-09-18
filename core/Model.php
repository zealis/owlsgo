<?php
/**
 * 基础模型
 *
 * 约定：
 *  - 每个业务表对应一个继承本类的 Model，控制器中不得出现 SQL
 *  - 默认开启软删除（deleted_at / updated_at 由框架维护）
 *  - 提供进程内行缓存（row cache），用于消灭列表页常见的 N+1 查询
 *  - 所有写操作都会自动清理行缓存，保证读到的数据不会陈旧
 */

declare(strict_types=1);

namespace Core;

abstract class Model
{
    /** 数据表名，由子类覆盖 */
    protected static string $table = '';

    /** 是否启用软删除 */
    protected static bool $softDelete = true;

    /** 是否自动维护 created_at / updated_at */
    protected static bool $timestamps = true;

    /** @var array<string, array<string, mixed>> 行缓存：table:id => row */
    private static array $rowCache = [];

    /** 行缓存条目上限，防止大请求下内存膨胀 */
    private const ROW_CACHE_LIMIT = 2000;

    /** 当前表名 */
    public static function tableName(): string
    {
        return static::$table;
    }

    /** 构造查询构造器（默认已排除软删除记录） */
    public static function query(): Query
    {
        $query = Database::table(static::$table);

        if (static::$softDelete) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    /** 包含已软删除记录的查询 */
    public static function withTrashed(): Query
    {
        return Database::table(static::$table);
    }

    /**
     * 按主键查询（带进程内缓存）
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $cacheKey = static::$table . ':' . $id;
        if (isset(self::$rowCache[$cacheKey])) {
            return self::$rowCache[$cacheKey];
        }

        $row = static::query()->where('id', $id)->first();

        if ($row !== null) {
            self::remember($cacheKey, $row);
        }

        return $row;
    }

    /**
     * 按主键查询，不存在则抛出 404（由 App 统一渲染错误页）
     *
     * @return array<string, mixed>
     */
    public static function findOrFail(int $id): array
    {
        $row = static::find($id);

        if ($row === null) {
            App::abort(404, '内容不存在或已被删除。');
        }

        return $row;
    }

    /**
     * 批量按主键查询，返回 id => row 映射（消灭 N+1 的核心手段）
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public static function mapByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));

        if ($ids === []) {
            return [];
        }

        $result  = [];
        $missing = [];

        foreach ($ids as $id) {
            $cacheKey = static::$table . ':' . $id;
            if (isset(self::$rowCache[$cacheKey])) {
                $result[$id] = self::$rowCache[$cacheKey];
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $rows = static::query()->whereIn('id', $missing)->get();
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                $result[$id] = $row;
                self::remember(static::$table . ':' . $id, $row);
            }
        }

        return $result;
    }

    /**
     * 插入一条记录并返回主键
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $data = static::filterColumns($data);

        if (static::$timestamps) {
            $now            = time();
            $data['created_at'] = $data['created_at'] ?? $now;
            $data['updated_at'] = $now;
        }

        $id = Database::insert(static::$table, $data);

        self::flushRowCache();

        return $id;
    }

    /**
     * 按主键更新
     *
     * @param array<string, mixed> $data
     */
    public static function updateById(int $id, array $data): int
    {
        if ($id <= 0) {
            return 0;
        }

        $data = static::filterColumns($data);

        if (static::$timestamps) {
            $data['updated_at'] = time();
        }

        if ($data === []) {
            return 0;
        }

        $affected = Database::update(
            static::$table,
            $data,
            Database::identifier('id') . ' = ?',
            [$id]
        );

        self::flushRowCache();

        return $affected;
    }

    /**
     * 软删除（无软删除配置时执行物理删除）
     */
    public static function deleteById(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        if (!static::$softDelete) {
            $affected = Database::delete(static::$table, Database::identifier('id') . ' = ?', [$id]);
            self::flushRowCache();

            return $affected;
        }

        $now  = time();
        $data = ['deleted_at' => $now];

        if (static::$timestamps) {
            $data['updated_at'] = $now;
        }

        $affected = Database::update(
            static::$table,
            $data,
            Database::identifier('id') . ' = ? AND ' . Database::identifier('deleted_at') . ' IS NULL',
            [$id]
        );

        self::flushRowCache();

        return $affected;
    }

    /** 恢复软删除 */
    public static function restoreById(int $id): int
    {
        if (!static::$softDelete) {
            return 0;
        }

        $data = ['deleted_at' => null];
        if (static::$timestamps) {
            $data['updated_at'] = time();
        }

        $affected = Database::update(
            static::$table,
            $data,
            Database::identifier('id') . ' = ?',
            [$id]
        );

        self::flushRowCache();

        return $affected;
    }

    /**
     * 字段自增 / 自减（原子操作，避免「读-改-写」竞态）
     */
    public static function increment(int $id, string $column, int $delta = 1): void
    {
        if ($id <= 0 || $delta === 0) {
            return;
        }

        $columnSql = Database::identifier($column);

        Database::execute(
            'UPDATE ' . Database::identifier(static::$table)
            . ' SET ' . $columnSql . ' = ' . $columnSql . ' + ?'
            . (static::$timestamps ? ', ' . Database::identifier('updated_at') . ' = ?' : '')
            . ' WHERE ' . Database::identifier('id') . ' = ?',
            static::$timestamps ? [$delta, time(), $id] : [$delta, $id]
        );

        self::flushRowCache();
    }

    /**
     * 按条件批量软删除
     *
     * @param array<string, mixed> $conditions
     */
    public static function softDeleteWhere(array $conditions): int
    {
        $query = Database::table(static::$table);

        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        return $query->softDelete();
    }

    /** 统计条数 */
    public static function countWhere(array $conditions): int
    {
        $query = static::query();

        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /**
     * 清除进程内行缓存
     *
     * 由 Database 在所有写操作后调用，确保缓存不会陈旧。
     */
    public static function flushRowCache(): void
    {
        self::$rowCache = [];
    }

    /** 写入行缓存（带容量上限的简单淘汰） */
    protected static function remember(string $key, array $row): void
    {
        if (count(self::$rowCache) >= self::ROW_CACHE_LIMIT) {
            array_shift(self::$rowCache);
        }

        self::$rowCache[$key] = $row;
    }

    /**
     * 过滤出真实存在的字段，丢弃未知键
     *
     * 依赖 information_schema / PRAGMA 结果并做静态缓存，
     * 防止把未预期的用户输入写进数据库。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected static function filterColumns(array $data): array
    {
        $columns = static::columns();

        return array_intersect_key($data, array_flip($columns));
    }

    /**
     * 当前表的字段名列表（进程内缓存）
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        static $cache = [];

        $key = static::$table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $columns = match (Database::driver()) {
            'sqlite' => array_map(
                static fn (array $row): string => (string)$row['name'],
                Database::select('PRAGMA table_info(' . Database::identifier(static::$table) . ')')
            ),
            default => array_map(
                static fn (array $row): string => (string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? ''),
                Database::select(
                    'SELECT column_name FROM information_schema.columns WHERE table_schema = '
                    . (Database::driver() === 'mysql' ? 'DATABASE()' : 'current_schema()')
                    . ' AND table_name = ?',
                    [static::$table]
                )
            ),
        };

        $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== ''));
        $cache[$key] = $columns;

        return $columns;
    }
}
