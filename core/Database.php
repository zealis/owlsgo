<?php
/**
 * 数据库抽象层（PDO 封装，跨 SQLite / MySQL / PostgreSQL）
 *
 * 设计要点：
 *  - 强制使用 PDO 预处理语句，任何用户输入都不得拼接进 SQL 字符串
 *  - 统一时间字段为整型 Unix 时间戳，避免三引擎的日期类型差异
 *  - 统一标识符引用（MySQL 用反引号，SQLite/PostgreSQL 用双引号）
 *  - 不区分大小写的模糊匹配：PostgreSQL 使用 ILIKE，其余使用 LIKE
 *  - 提供 insert/update/upsert/软删除等跨引擎一致的高层方法
 *  - 连接为单例，进程内复用；写操作后自动清理行缓存
 */

declare(strict_types=1);

namespace Core;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    /** @var array<string, mixed> 连接配置 */
    private static array $config = [];

    /** @var int 本次请求内执行的 SQL 条数（调试用） */
    private static int $queryCount = 0;

    /** 允许的引擎白名单，防止配置文件被篡改后构造非法 DSN */
    private const DRIVERS = ['sqlite', 'mysql', 'pgsql'];

    /**
     * 注入连接配置
     *
     * @param array<string, mixed> $config 见 config/database.php
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo    = null;   // 配置变更后需重建连接
        Model::flushRowCache();
    }

    /**
     * 规范化配置：填充默认值、校验引擎
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function normalizeConfig(array $config): array
    {
        $driver = (string)($config['driver'] ?? 'sqlite');
        if (!in_array($driver, self::DRIVERS, true)) {
            $driver = 'sqlite';
        }

        $defaultPort = match ($driver) {
            'mysql' => 3306,
            'pgsql' => 5432,
            default => 0,
        };

        $port = (int)($config['port'] ?? 0);
        if ($port <= 0) {
            $port = $defaultPort;
        }

        return [
            'driver'   => $driver,
            'database' => (string)($config['database'] ?? 'owlsgo.sqlite'),
            'host'     => (string)($config['host'] ?? '127.0.0.1'),
            'port'     => $port,
            'username' => (string)($config['username'] ?? ''),
            'password' => (string)($config['password'] ?? ''),
        ];
    }

    /** 当前引擎名（sqlite / mysql / pgsql） */
    public static function driver(): string
    {
        return (string)(self::$config['driver'] ?? 'sqlite');
    }

    /** 本次请求已执行的 SQL 条数 */
    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /**
     * 获取（惰性创建）PDO 连接
     *
     * @throws RuntimeException 当前 PHP 缺少对应 PDO 驱动时抛出
     * @throws RuntimeException 连接失败时抛出（详情写入日志，不暴露给前端）
     */
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = self::normalizeConfig(self::$config);
        $driver = $config['driver'];

        if (!class_exists(PDO::class) || !in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('当前 PHP 环境未启用 PDO ' . $driver . ' 驱动，请检查 php.ini 中的扩展配置。');
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // 使用数据库原生预处理，杜绝模拟预处理带来的注入面
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = self::sqlitePath((string)$config['database']);
                $dir  = dirname($path);
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('无法创建数据库目录：' . $dir);
                }
                $pdo = new PDO('sqlite:' . $path, null, null, $options);

                // WAL 提升并发读性能；busy_timeout 避免写锁立即失败；开启外键约束
                foreach ([
                    'PRAGMA journal_mode = WAL',
                    'PRAGMA synchronous = NORMAL',
                    'PRAGMA temp_store = MEMORY',
                    'PRAGMA busy_timeout = 5000',
                    'PRAGMA foreign_keys = ON',
                ] as $pragma) {
                    $pdo->exec($pragma);
                }
            } elseif ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config['host'],
                    $config['port'],
                    $config['database']
                );
                $pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], $options);
                $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            } else {
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $config['host'],
                    $config['port'],
                    $config['database']
                );
                $pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], $options);
                $pdo->exec("SET client_encoding TO 'UTF8'");
            }
        } catch (PDOException $e) {
            // 只记录，不把 DSN / 凭据回显给用户
            Logger::error('数据库连接失败：' . $e->getMessage());
            throw new RuntimeException('数据库连接失败，请检查配置或稍后重试。', 0, $e);
        }

        self::$pdo = $pdo;

        return self::$pdo;
    }

    /** SQLite 数据库文件的绝对路径（限制在 storage/database 下，防目录穿越） */
    public static function sqlitePath(string $name): string
    {
        // 只保留文件名，杜绝 ../ 之类的路径穿越
        $name = basename($name);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(sqlite|sqlite3|db)$/', $name)) {
            $name = 'owlsgo.sqlite';
        }

        return rtrim(APP_ROOT, '/\\') . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * 引用标识符（表名 / 字段名），自动按引擎选择引号
     *
     * @throws InvalidArgumentException 标识符非法（防注入）
     */
    public static function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('非法的数据库标识符：' . $name);
        }

        return self::driver() === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }

    /** 生成 "a"."b" 形式的多段标识符（用于 JOIN 中的表别名限定） */
    public static function qualified(string $table, string $column): string
    {
        return self::identifier($table) . '.' . self::identifier($column);
    }

    /** 生成 N 个占位符，如 ?,?,? */
    public static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(0, $count), '?'));
    }

    /** 不区分大小写的模糊匹配运算符：PostgreSQL 用 ILIKE */
    public static function likeOperator(): string
    {
        return self::driver() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    /**
     * 构造 LIKE 的匹配串（两端加 %，并转义串内的通配符）
     *
     * 用户输入里的 `%` / `_` / `\` 必须转义，否则「%」会变成「匹配任意内容」、
     * 「_」会变成「匹配任意单字符」—— 搜索框里打一个 % 就返回全表。
     * 转义后的串必须配合 likeEscapeClause() 一起用（见那里的驱动差异说明）。
     */
    public static function likePattern(string $keyword): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
    }

    /**
     * LIKE ... ESCAPE 子句（按驱动生成正确的反斜杠字面量）。
     *
     * SQLite / PostgreSQL 的单引号字符串里反斜杠就是字面反斜杠，写 ESCAPE '\'；
     * MySQL 默认模式下反斜杠是字符串转义符，'\' 会把闭引号转义掉（语法错误），
     * 必须写成 '\\'。绑定值（\%、\_）走 PDO 参数不经字面量解析，不受影响。
     */
    public static function likeEscapeClause(): string
    {
        return self::driver() === 'mysql' ? " ESCAPE '\\\\'" : " ESCAPE '\\'";
    }

    /**
     * 执行带绑定的 SQL
     *
     * @param string              $sql
     * @param array<string,mixed> $bindings
     */
    public static function query(string $sql, array $bindings = []): PDOStatement
    {
        self::$queryCount++;

        $stmt = self::connection()->prepare($sql);

        foreach (array_values($bindings) as $index => $value) {
            $position = $index + 1;
            $type     = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($position, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * 查询多行
     *
     * @param array<string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::query($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * 查询单行
     *
     * @param array<string,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $bindings = []): ?array
    {
        $row = self::query($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * 查询单值
     *
     * @param array<string,mixed> $bindings
     */
    public static function value(string $sql, array $bindings = []): mixed
    {
        $value = self::query($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * 执行写操作，返回受影响行数
     *
     * @param array<string,mixed> $bindings
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        $affected = self::query($sql, $bindings)->rowCount();
        Model::flushRowCache();

        return $affected;
    }

    /**
     * 插入一行并返回自增主键
     *
     * 注意：PostgreSQL 的 INSERT ... RETURNING 是唯一可靠的一次性取回方式，
     * 因此这里对 pgsql 单独处理；其余引擎使用 lastInsertId()。
     *
     * @param string               $table 表名（不含前缀）
     * @param array<string, mixed> $data  字段 => 值
     */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('插入数据不能为空。');
        }

        $columns = array_map([self::class, 'identifier'], array_keys($data));
        $marks   = self::placeholders(count($data));
        $tableQ  = self::identifier($table);

        $sql = 'INSERT INTO ' . $tableQ
            . ' (' . implode(',', $columns) . ') VALUES (' . $marks . ')';

        if (self::driver() === 'pgsql') {
            $id = self::value($sql . ' RETURNING "id"', array_values($data));
            Model::flushRowCache();

            return (int)$id;
        }

        self::query($sql, array_values($data));
        Model::flushRowCache();

        return (int)self::connection()->lastInsertId();
    }

    /**
     * 更新记录
     *
     * @param string               $table    表名
     * @param array<string, mixed> $data     待更新字段
     * @param string               $whereSql WHERE 子句（含占位符），必须由调用方使用占位符
     * @param array<mixed>         $bindings WHERE 绑定值
     */
    public static function update(string $table, array $data, string $whereSql, array $bindings = []): int
    {
        if ($data === []) {
            return 0;
        }

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = self::identifier($column) . ' = ?';
        }

        $sql = 'UPDATE ' . self::identifier($table)
            . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $whereSql;

        return self::execute($sql, array_merge(array_values($data), $bindings));
    }

    /**
     * 插入或更新（跨引擎 upsert）
     *
     * @param string               $table
     * @param array<string, mixed> $data
     * @param list<string>         $uniqueKeys 冲突判定字段
     */
    public static function upsert(string $table, array $data, array $uniqueKeys): int
    {
        if ($data === [] || $uniqueKeys === []) {
            throw new InvalidArgumentException('upsert 参数不完整。');
        }

        $columns = array_keys($data);
        $tableQ  = self::identifier($table);
        $cols    = implode(',', array_map([self::class, 'identifier'], $columns));
        $marks   = self::placeholders(count($columns));
        $base    = 'INSERT INTO ' . $tableQ . ' (' . $cols . ') VALUES (' . $marks . ')';

        $updatable = array_values(array_diff($columns, $uniqueKeys));

        if (self::driver() === 'mysql') {
            if ($updatable === []) {
                $sql = preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $base) ?? $base;
            } else {
                $assignments = implode(',', array_map(
                    static fn (string $c): string => self::identifier($c) . ' = VALUES(' . self::identifier($c) . ')',
                    $updatable
                ));
                $sql = $base . ' ON DUPLICATE KEY UPDATE ' . $assignments;
            }
        } else {
            $conflict = implode(',', array_map([self::class, 'identifier'], $uniqueKeys));
            if ($updatable === []) {
                $sql = $base . ' ON CONFLICT (' . $conflict . ') DO NOTHING';
            } else {
                $assignments = implode(',', array_map(
                    static fn (string $c): string => self::identifier($c) . ' = excluded.' . self::identifier($c),
                    $updatable
                ));
                $sql = $base . ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . $assignments;
            }
        }

        $affected = self::query($sql, array_values($data))->rowCount();
        Model::flushRowCache();

        return $affected;
    }

    /**
     * 存在即忽略的插入
     *
     * @param array<string, mixed> $data
     * @param list<string>         $uniqueKeys
     */
    public static function insertIgnore(string $table, array $data, array $uniqueKeys): void
    {
        $tableQ  = self::identifier($table);
        $columns = array_keys($data);
        $cols    = implode(',', array_map([self::class, 'identifier'], $columns));
        $marks   = self::placeholders(count($columns));
        $base    = 'INSERT INTO ' . $tableQ . ' (' . $cols . ') VALUES (' . $marks . ')';

        if (self::driver() === 'mysql') {
            $sql = preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $base) ?? $base;
        } else {
            $conflict = implode(',', array_map([self::class, 'identifier'], $uniqueKeys));
            $sql      = $base . ' ON CONFLICT (' . $conflict . ') DO NOTHING';
        }

        self::query($sql, array_values($data));
        Model::flushRowCache();
    }

    /**
     * 物理删除（仅在必要时使用；业务数据请走软删除）
     *
     * @param array<mixed> $bindings
     */
    public static function delete(string $table, string $whereSql, array $bindings = []): int
    {
        return self::execute('DELETE FROM ' . self::identifier($table) . ' WHERE ' . $whereSql, $bindings);
    }

    /** 判断表是否存在 */
    public static function tableExists(string $table): bool
    {
        $sql = match (self::driver()) {
            'mysql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            'pgsql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            default => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
        };

        return (bool)self::value($sql, [$table]);
    }

    /**
     * 判断字段是否存在（**实时探测，不走缓存**）
     *
     * ⚠️ 与 Model::columns() 的区别很重要：后者是**进程内静态缓存**，
     *    刚 ALTER 加完列，同一次请求里它拿到的仍是旧列名列表 ——
     *    于是 Model::filterColumns() 会静默把这个新字段丢掉。
     *    所以「补列探测」必须用这里，不能用 Model::columns()。
     *
     * 项目没有迁移机制，老站点靠惰性补列（见 UsergroupModel::ensureQuotaColumn()）。
     */
    public static function hasColumn(string $table, string $column): bool
    {
        try {
            if (self::driver() === 'sqlite') {
                foreach (self::select('PRAGMA table_info(' . self::identifier($table) . ')') as $row) {
                    if ((string)($row['name'] ?? '') === $column) {
                        return true;
                    }
                }

                return false;
            }

            return self::first(
                'SELECT column_name FROM information_schema.columns'
                . ' WHERE table_schema = '
                . (self::driver() === 'mysql' ? 'DATABASE()' : 'current_schema()')
                . ' AND table_name = ? AND column_name = ?',
                [$table, $column]
            ) !== null;
        } catch (\Throwable) {
            // 探测不了就当作已存在，避免每个请求都去重复 ALTER 并抛错
            return true;
        }
    }

    /**
     * 在事务中执行回调；发生异常自动回滚
     *
     * @template T
     * @param Closure():T $callback
     * @return T
     */
    public static function transaction(Closure $callback): mixed
    {
        $pdo = self::connection();

        // 支持嵌套：已在事务中时直接执行，由最外层统一提交
        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();
            Model::flushRowCache();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Model::flushRowCache();
            throw $e;
        }
    }

    /** 构造查询构造器 */
    public static function table(string $table): Query
    {
        return new Query($table);
    }

    /** 仅供安装向导/测试使用：直接执行原始 SQL 脚本语句 */
    public static function statement(string $sql): void
    {
        self::connection()->exec($sql);
    }

    /** 关闭连接（测试或切换数据库时使用） */
    public static function disconnect(): void
    {
        self::$pdo = null;
        Model::flushRowCache();
    }
}
