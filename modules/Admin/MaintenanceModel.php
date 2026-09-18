<?php
/**
 * 内置维护任务
 *
 * 与「插件计划任务」区分开：这里放的是系统自身的清理工作（日志、限流计数、会话、缓存）。
 * 后台可以手动执行，也可以由外部定时器通过 /cron/run 触发。
 *
 * 所有任务都是「可重复执行且幂等」的：重复运行不会产生副作用。
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Cache;
use Core\Database;
use Core\Logger;

final class MaintenanceModel
{
    /** 内置维护任务清单：任务键 => 说明 */
    public const JOBS = [
        'logs'        => '清理过期操作日志（默认保留 30 天）',
        'cron_logs'   => '清理计划任务日志（默认保留 7 天）',
        'rate_limits' => '清理已过期的限流计数',
        'sessions'    => '清理过期的会话文件',
        'cache'       => '清空文件缓存',
    ];

    /**
     * 执行单个维护任务
     *
     * @return array{ok:bool, message:string}
     */
    public static function run(string $job, int $days = 0): array
    {
        try {
            return match ($job) {
                'logs'        => self::pruneLogs($days > 0 ? $days : 30),
                'cron_logs'   => self::pruneCronLogs($days > 0 ? $days : 7),
                'rate_limits' => self::pruneRateLimits(),
                'sessions'    => self::pruneSessions(),
                'cache'       => self::flushCache(),
                default       => ['ok' => false, 'message' => '未知的维护任务：' . $job],
            };
        } catch (\Throwable $e) {
            Logger::exception($e, 'maintenance:' . $job);

            return ['ok' => false, 'message' => '执行失败：' . $e->getMessage()];
        }
    }

    /**
     * 执行全部维护任务
     *
     * @return list<array{job:string, ok:bool, message:string}>
     */
    public static function runAll(): array
    {
        $results = [];

        foreach (array_keys(self::JOBS) as $job) {
            $result    = self::run($job);
            $results[] = ['job' => $job, 'ok' => $result['ok'], 'message' => $result['message']];
        }

        return $results;
    }

    /* ------------------------------------------------------------------ */
    /*  具体任务                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 清理操作日志：数据库记录 + 磁盘日志文件
     *
     * @return array{ok:bool, message:string}
     */
    private static function pruneLogs(int $days): array
    {
        $rows  = LogModel::prune($days);
        $files = Logger::prune($days);

        return [
            'ok'      => true,
            'message' => '已清理操作日志 ' . $rows . ' 条、日志文件 ' . $files . ' 个。',
        ];
    }

    /**
     * 清理计划任务历史
     *
     * @return array{ok:bool, message:string}
     */
    private static function pruneCronLogs(int $days): array
    {
        $deleted = Database::delete(
            'cron_logs',
            Database::identifier('created_at') . ' < ?',
            [time() - max(1, $days) * 86400]
        );

        return ['ok' => true, 'message' => '已清理计划任务日志 ' . $deleted . ' 条。'];
    }

    /**
     * 清理过期的限流计数
     *
     * @return array{ok:bool, message:string}
     */
    private static function pruneRateLimits(): array
    {
        $deleted = Database::delete(
            'rate_limits',
            Database::identifier('expires_at') . ' > 0 AND ' . Database::identifier('expires_at') . ' < ?',
            [time()]
        );

        return ['ok' => true, 'message' => '已清理限流计数 ' . $deleted . ' 条。'];
    }

    /**
     * 清理超过登录有效期仍未回收的会话文件
     *
     * @return array{ok:bool, message:string}
     */
    private static function pruneSessions(): array
    {
        $dir = APP_ROOT . '/storage/sessions';

        if (!is_dir($dir)) {
            return ['ok' => true, 'message' => '会话目录不存在，无需清理。'];
        }

        $threshold = time() - (int)config('app.cookie_ttl', 15552000);
        $removed   = 0;

        foreach ((array)glob($dir . '/sess_*') as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
                $removed++;
            }
        }

        return ['ok' => true, 'message' => '已清理会话文件 ' . $removed . ' 个。'];
    }

    /**
     * 清空文件缓存
     *
     * @return array{ok:bool, message:string}
     */
    private static function flushCache(): array
    {
        $removed = Cache::flush();

        // 设置与版块的进程内缓存也一并失效
        \Core\Settings::flush();

        return ['ok' => true, 'message' => '已清空缓存文件 ' . $removed . ' 个。'];
    }
}
