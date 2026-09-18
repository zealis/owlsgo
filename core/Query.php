<?php
/**
 * 轻量查询构造器
 *
 * 目标：让 Model 层的查询读起来像领域语言，同时**始终**使用占位符绑定，
 * 从机制上消除拼接 SQL 带来的注入可能。
 *
 * 用法示例：
 *   Database::table('threads')
 *       ->where('forum_id', 5)
 *       ->whereNull('deleted_at')
 *       ->orderBy('is_pinned', 'desc')
 *       ->orderBy('last_reply_at', 'desc')
 *       ->paginate(20, $page);
 */

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;

final class Query
{
    private string $table;

    /** @var list<string> 查询字段 */
    private array $columns = ['*'];

    /** @var list<string> WHERE 片段（不含 WHERE 关键字） */
    private array $wheres = [];

    /** @var list<mixed> WHERE 绑定值 */
    private array $bindings = [];

    /** @var list<string> ORDER BY 片段 */
    private array $orders = [];

    /** @var list<string> GROUP BY 字段 */
    private array $groups = [];

    /** @var list<string> HAVING 片段 */
    private array $havings = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** 允许的排序方向，防止注入 */
    private const DIRECTIONS = ['asc', 'desc'];

    public function __construct(string $table)
    {
        // 表名使用白名单校验，非法表名直接拒绝
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('非法表名：' . $table);
        }

        $this->table = $table;
    }

    /** 指定查询字段 */
    public function select(array|string $columns): self
    {
        $columns       = is_array($columns) ? $columns : func_get_args();
        $this->columns = array_values($columns);

        return $this;
    }

    /**
     * 追加等值条件（支持运算符）
     *
     * @param mixed $operator 运算符或值
     * @param mixed $value    值
     */
    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $operator = strtoupper((string)$operator);
        if (!in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'], true)) {
            throw new InvalidArgumentException('不支持的运算符：' . $operator);
        }

        // 使用引擎对应的大小写不敏感运算符
        if ($operator === 'LIKE') {
            $operator = Database::likeOperator();
        }

        $this->wheres[]   = Database::identifier($column) . ' ' . $operator . ' ?';
        $this->bindings[] = $value;

        return $this;
    }

    public function orWhere(string $column, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        return $this->orGroup(function (self $q) use ($column, $operator, $value): void {
            $q->where($column, $operator, $value);
        });
    }

    /** IN 条件；空数组会被翻译成恒假，避免 "IN ()" 语法错误 */
    public function whereIn(string $column, array $values): self
    {
        $values = array_values($values);

        if ($values === []) {
            $this->wheres[] = '1 = 0';

            return $this;
        }

        $this->wheres[] = Database::identifier($column) . ' IN (' . Database::placeholders(count($values)) . ')';
        foreach ($values as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    public function whereNotIn(string $column, array $values): self
    {
        $values = array_values($values);

        if ($values === []) {
            return $this;
        }

        $this->wheres[] = Database::identifier($column) . ' NOT IN (' . Database::placeholders(count($values)) . ')';
        foreach ($values as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = Database::identifier($column) . ' IS NULL';

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = Database::identifier($column) . ' IS NOT NULL';

        return $this;
    }

    /** 不区分大小写的模糊搜索，自动拼接 % 通配符并转义用户输入中的通配符 */
    public function whereContains(string $column, string $keyword): self
    {
        if ($keyword === '') {
            return $this;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);

        $this->wheres[]   = Database::identifier($column) . ' ' . Database::likeOperator() . ' ?' . Database::likeEscapeClause();
        $this->bindings[] = '%' . $escaped . '%';

        return $this;
    }

    /**
     * 原始条件片段（仅在片段完全由开发者在代码中静态书写时使用）
     *
     * @param array<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = '(' . $sql . ')';
        foreach ($bindings as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    /**
     * 括号分组条件：$q->whereGroup(fn($q) => $q->where('a',1)->orWhere('b',2))
     *
     * @param callable(self):void $callback
     */
    public function whereGroup(callable $callback): self
    {
        $sub = new self($this->table);
        $callback($sub);

        if ($sub->wheres !== []) {
            $this->wheres[] = '(' . implode(' AND ', $sub->wheres) . ')';
            foreach ($sub->bindings as $value) {
                $this->bindings[] = $value;
            }
        }

        return $this;
    }

    /**
     * OR 分组：把若干条件用 OR 连接后整体与前面的条件 AND
     *
     * @param callable(self):void $callback
     */
    public function orGroup(callable $callback): self
    {
        $sub = new self($this->table);
        $callback($sub);

        if ($sub->wheres !== []) {
            $this->wheres[] = '(' . implode(' OR ', $sub->wheres) . ')';
            foreach ($sub->bindings as $value) {
                $this->bindings[] = $value;
            }
        }

        return $this;
    }

    public function orderBy(string $column, string $direction = 'desc'): self
    {
        $direction = strtolower($direction);
        if (!in_array($direction, self::DIRECTIONS, true)) {
            $direction = 'desc';
        }

        $this->orders[] = Database::identifier($column) . ' ' . strtoupper($direction);

        return $this;
    }

    /** 原始排序片段（字段名由开发者在代码中静态书写） */
    public function orderByRaw(string $sql): self
    {
        $this->orders[] = $sql;

        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->groups[] = Database::identifier($column);

        return $this;
    }

    /**
     * @param array<mixed> $bindings
     */
    public function having(string $sql, array $bindings = []): self
    {
        $this->havings[] = $sql;
        foreach ($bindings as $value) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * 生成 SELECT 语句与绑定值
     *
     * @param string|null $columnsOverride 覆盖列清单。聚合表达式（如 COUNT(*) AS "aggregate"）
     *                                     无法通过 identifier() 校验，必须经由本参数传入。
     */
    public function toSelectSql(?string $columnsOverride = null): array
    {
        $columns = $columnsOverride ?? ($this->columns === ['*']
            ? '*'
            : implode(',', array_map([Database::class, 'identifier'], $this->columns)));

        $sql = 'SELECT ' . $columns . ' FROM ' . Database::identifier($this->table);

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(',', $this->groups);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(',', $this->orders);
        }

        // LIMIT/OFFSET 已强制转换为 int，此处内联不会引入注入风险，
        // 同时规避部分驱动对 LIMIT 绑定参数类型推断不一致的问题。
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        } elseif ($this->offset !== null) {
            // PostgreSQL / MySQL 在仅有 OFFSET 时需要显式 LIMIT
            $sql .= ' LIMIT ' . ($this->offset + 1);
        }

        if ($this->offset !== null && $this->limit !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        } elseif ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return [$sql, $this->bindings];
    }

    /** 执行查询，返回多行 */
    public function get(): array
    {
        [$sql, $bindings] = $this->toSelectSql();

        return Database::select($sql, $bindings);
    }

    /** 执行查询，返回首行或 null */
    public function first(): ?array
    {
        $this->limit(1);
        [$sql, $bindings] = $this->toSelectSql();

        return Database::first($sql, $bindings);
    }

    /** 取首行某列的值 */
    public function value(string $column): mixed
    {
        $this->columns = [$column];
        $row           = $this->first();

        return $row[$column] ?? null;
    }

    /**
     * 取某列的所有值
     *
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $this->columns = [$column];

        return array_values(array_map(
            static fn (array $row): mixed => $row[$column] ?? null,
            $this->get()
        ));
    }

    /** 统计条数（自动丢弃 ORDER BY / LIMIT，避免无意义的排序开销） */
    public function count(): int
    {
        $savedOrders = $this->orders;
        $savedLimit  = $this->limit;
        $savedOffset = $this->offset;

        $this->orders = [];
        $this->limit  = null;
        $this->offset = null;

        // 聚合表达式必须走 columnsOverride 传入：它含括号与 AS 别名，
        // 交给 identifier() 校验会被判定为「非法标识符」而抛异常。
        $aggregate = 'COUNT(*) AS "aggregate"';

        // 分组统计时需要包一层子查询
        if ($this->groups !== []) {
            [$inner, $bindings] = $this->toSelectSql($aggregate);
            $result = (int)Database::value(
                'SELECT COUNT(*) FROM (' . $inner . ') AS "sub"',
                $bindings
            );
        } else {
            [$sql, $bindings] = $this->toSelectSql($aggregate);
            $result = (int)Database::value($sql, $bindings);
        }

        $this->orders = $savedOrders;
        $this->limit  = $savedLimit;
        $this->offset = $savedOffset;

        return $result;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * 分页查询
     *
     * @return array{items: list<array<string,mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $perPage, int $page): array
    {
        $perPage = max(1, $perPage);
        $page    = max(1, $page);

        $total = $this->count();

        $this->limit($perPage)->offset(($page - 1) * $perPage);
        $items = $this->get();

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int)ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    /**
     * 插入一行并返回主键
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return Database::insert($this->table, $data);
    }

    /**
     * 按当前 WHERE 条件更新
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        $where = $this->wheres === [] ? '1 = 1' : implode(' AND ', $this->wheres);

        return Database::update($this->table, $data, $where, $this->bindings);
    }

    /** 按当前 WHERE 条件物理删除 */
    public function delete(): int
    {
        $where = $this->wheres === [] ? '1 = 1' : implode(' AND ', $this->wheres);

        return Database::delete($this->table, $where, $this->bindings);
    }

    /**
     * 按当前 WHERE 条件做软删除
     */
    public function softDelete(): int
    {
        $now = time();

        return $this->update(['deleted_at' => $now, 'updated_at' => $now]);
    }

    /** 当前已绑定的 WHERE 条件值（供 Model 复用） */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /** 当前表名 */
    public function tableName(): string
    {
        return $this->table;
    }
}
