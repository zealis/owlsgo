<?php
/**
 * 操作日志模型（后台审计）
 *
 * logs 表用于记录后台与用户侧的关键动作（登录、发帖、删帖、改设置……），
 * 便于事后追责与排查。写日志失败绝不允许影响主流程，因此内部吞掉异常。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Database;
use Core\Logger;
use Core\Model;
use Core\Request;

final class LogModel extends Model
{
    protected static string $table = 'logs';

    /** 日志表只有 created_at，没有软删除与更新时间 */
    protected static bool $softDelete = false;
    protected static bool $timestamps = false;

    /**
     * 写入一条操作日志
     */
    public static function record(int $userId, string $action, string $target = '', string $detail = ''): void
    {
        try {
            static::create([
                'user_id'    => $userId,
                'action'     => mb_substr($action, 0, 60),
                'target'     => mb_substr($target, 0, 60),
                'detail'     => mb_substr($detail, 0, 500),
                'ip'         => Request::ip(),
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('写操作日志失败：' . $e->getMessage());
        }
    }

    /**
     * 分页查询日志
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginate(string $action, string $keyword, int $page, int $perPage = 30): array
    {
        $query = static::query();

        if ($action !== '') {
            $query->where('action', $action);
        }

        if ($keyword !== '') {
            $query->orGroup(static function ($q) use ($keyword): void {
                $q->whereContains('detail', $keyword);
                $q->whereContains('target', $keyword);
                $q->whereContains('ip', $keyword);
            });
        }

        return $query->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /**
     * 最近若干条日志
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        return static::query()->orderBy('id', 'desc')->limit($limit)->get();
    }

    /** 出现过的动作种类（用于筛选下拉） */
    public static function actions(): array
    {
        $rows = Database::select(
            'SELECT DISTINCT ' . Database::identifier('action') . ' AS ' . Database::identifier('action')
            . ' FROM ' . Database::identifier('logs')
            . ' WHERE ' . Database::identifier('action') . ' <> \'\''
            . ' ORDER BY ' . Database::identifier('action') . ' ASC'
        );

        return array_map(static fn (array $row): string => (string)$row['action'], $rows);
    }

    /**
     * 导出用：按条件取一批日志（不分页，但强制带上限）
     *
     * @return list<array<string, mixed>>
     */
    public static function exportRows(string $action, string $keyword, int $limit = 5000): array
    {
        $query = static::query();

        if ($action !== '') {
            $query->where('action', $action);
        }

        if ($keyword !== '') {
            $query->orGroup(static function ($q) use ($keyword): void {
                $q->whereContains('detail', $keyword);
                $q->whereContains('target', $keyword);
                $q->whereContains('ip', $keyword);
            });
        }

        return $query->orderBy('id', 'desc')->limit(max(1, min(20000, $limit)))->get();
    }

    /** 日志总条数 */
    public static function totalCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('logs')
        );
    }

    /** 清理旧日志，返回删除条数 */
    public static function prune(int $days = 30): int
    {
        return Database::delete(
            'logs',
            Database::identifier('created_at') . ' < ?',
            [time() - max(1, $days) * 86400]
        );
    }
}
