<?php
/**
 * 计划任务模型
 *
 * cron_tasks 由插件通过 Core\Plugin::cron() 声明后被 PluginManager 同步入库，
 * 后台只负责查看、手动触发与启停。执行逻辑本身在 PluginManager::runCron()。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Database;
use Core\Model;

final class CronModel extends Model
{
    protected static string $table = 'cron_tasks';

    /** 计划任务不做软删除 */
    protected static bool $softDelete = false;

    /**
     * 全部任务（按下次执行时间排序）
     *
     * @return list<array<string, mixed>>
     */
    public static function tasks(): array
    {
        return static::query()
            ->orderBy('enabled', 'desc')
            ->orderBy('next_run_at', 'asc')
            ->get();
    }

    /**
     * 切换启用状态
     *
     * @return array{ok:bool, message:string, enabled:bool}
     */
    public static function toggle(int $id): array
    {
        $task = static::find($id);

        if ($task === null) {
            return ['ok' => false, 'message' => '计划任务不存在。', 'enabled' => false];
        }

        $enabled = (int)$task['enabled'] !== 1;

        $data = [
            'enabled'     => $enabled ? 1 : 0,
            'next_run_at' => $enabled ? time() + max(60, (int)$task['interval']) : 0,
        ];

        static::updateById($id, $data);

        return [
            'ok'      => true,
            'message' => $enabled ? '任务已启用。' : '任务已停用。',
            'enabled' => $enabled,
        ];
    }

    /**
     * 任务日志分页
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function paginateLogs(int $page, int $perPage = 30): array
    {
        $total = (int)Database::value('SELECT COUNT(*) FROM ' . Database::identifier('cron_logs'));
        $page  = max(1, $page);

        $items = Database::select(
            'SELECT * FROM ' . Database::identifier('cron_logs')
            . ' ORDER BY ' . Database::identifier('id') . ' DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int)ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    /** 待执行任务数量（用于后台角标） */
    public static function dueCount(): int
    {
        return (int)Database::value(
            'SELECT COUNT(*) FROM ' . Database::identifier('cron_tasks')
            . ' WHERE ' . Database::identifier('enabled') . ' = 1'
            . ' AND ' . Database::identifier('next_run_at') . ' <= ?',
            [time()]
        );
    }
}
