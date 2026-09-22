<?php
/**
 * 内置维护任务
 *
 * 与「插件计划任务」区分开：这里放的是系统自身的维护工作 ——
 * 清理（日志、限流计数、会话、缓存）与**自检**（冗余计数对账）。
 * 后台可以手动执行，也可以由外部定时器通过 /cron/run 触发。
 *
 * 所有任务都是「可重复执行且幂等」的：重复运行不会产生副作用。
 * ⚠️ 因此自检类任务**只读、只报告**：定时任务里静默改数据会把
 *    「写入路径出了问题」这件事盖掉，反而更难发现。
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
        'counter_check' => '自检版块 / 帖子 / 用户的冗余计数是否与实际数据一致（只报告，不改数据）',
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
                'counter_check' => self::checkCounters(),
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
     * 计数自检：比对 5 个冗余计数与「按实际数据算出来」的值
     *
     * 口径与 .tools/repair-counters.php **完全一致** —— 那个脚本就是自检发现问题后
     * 用来对齐数据的工具，两边口径必须一样，否则会出现「自检说 3 处、修复改了 4 处」。
     *
     *   forums.thread_count = 该版块未删帖子数
     *   forums.post_count   = 未删帖子数 + 未删已通过的非首帖评论数
     *   threads.reply_count = 该帖未删已通过的非首帖评论数
     *   users.thread_count  = 该用户未删帖子数
     *   users.post_count    = 未删帖子数 + 本人未删已通过的非首帖评论数
     *
     * 三点设计取舍：
     *  1. **只报告，不自动修**。定时任务里静默改数据会掩盖「写入路径出了问题」这件事；
     *     要修就人工跑一次 repair-counters.php。
     *  2. 期望值用「一次聚合 + LEFT JOIN」算，**不用逐行相关子查询** ——
     *     帖子/评论上量之后，逐行子查询会把定时任务拖死。
     *  3. 发现偏差时返回 ok=false，让计划任务日志把它标成异常（成功时是绿的，
     *     有问题时应该一眼看得出来，而不是混在一堆「已清理 0 条」里）。
     *
     * @return array{ok:bool, message:string}
     */
    private static function checkCounters(): array
    {
        /** 标识符按各驱动正确加引号（MySQL 反引号 / SQLite·PG 双引号） */
        $q = static fn (string $name): string => Database::identifier($name);

        /*
         * 期望值用「按**各自粒度**聚合 + LEFT JOIN」算，不用逐行相关子查询
         * （帖子/评论上量后逐行子查询会拖死定时任务）。
         *
         * ⚠️ 每个子查询只能按「被连接的那一列」分组：比如给用户算帖子数时必须
         *    `GROUP BY user_id`，若顺手带上 forum_id/thread_id，一个用户就会出多行，
         *    LEFT JOIN 之后行数翻倍，外层 COUNT(*) 数的是行而不是人 —— 结论全错。
         *
         * 统一把聚合结果别名为 k（连接键）/ n（计数），三段 join 写法才一致。
         */
        $agg = static function (string $table, string $key, string $where = '') use ($q): string {
            return 'SELECT ' . $q($key) . ' AS k, COUNT(*) AS n FROM ' . $q($table)
                . ' WHERE ' . ($where !== '' ? $where . ' AND ' : '') . $q('deleted_at') . ' IS NULL'
                . ' GROUP BY ' . $q($key);
        };

        /* 已通过的非首帖评论 —— 三处「评论数」口径共用同一段条件 */
        $approved = $q('is_first') . ' = 0 AND ' . $q('status') . ' = 1';

        $threadsByForum  = $agg('threads', 'forum_id');
        $repliesByForum  = $agg('posts', 'forum_id', $approved);
        $repliesByThread = $agg('posts', 'thread_id', $approved);
        $threadsByUser   = $agg('threads', 'user_id');
        $repliesByUser   = $agg('posts', 'user_id', $approved);

        /* 版块：thread_count 对未删帖子数；post_count 再加已通过评论数 */
        $forumDrift = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $q('forums') . ' f'
            . ' LEFT JOIN (' . $threadsByForum . ') t ON t.k = f.' . $q('id')
            . ' LEFT JOIN (' . $repliesByForum . ') p ON p.k = f.' . $q('id')
            . ' WHERE f.' . $q('deleted_at') . ' IS NULL'
            . ' AND (f.' . $q('thread_count') . ' <> COALESCE(t.n, 0)'
            . ' OR f.' . $q('post_count') . ' <> (COALESCE(t.n, 0) + COALESCE(p.n, 0)))'
        );

        /* 帖子：reply_count 对未删已通过评论数（不含首帖） */
        $threadDrift = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $q('threads') . ' th'
            . ' LEFT JOIN (' . $repliesByThread . ') p ON p.k = th.' . $q('id')
            . ' WHERE th.' . $q('deleted_at') . ' IS NULL'
            . ' AND th.' . $q('reply_count') . ' <> COALESCE(p.n, 0)'
        );

        /*
         * 用户：不按 users.deleted_at 过滤 —— repair-counters.php 也是全表遍历，
         * 两边保持一致，自检报出的条数才等于修复会改动的条数。
         */
        $userDrift = (int)Database::value(
            'SELECT COUNT(*) FROM ' . $q('users') . ' u'
            . ' LEFT JOIN (' . $threadsByUser . ') t ON t.k = u.' . $q('id')
            . ' LEFT JOIN (' . $repliesByUser . ') p ON p.k = u.' . $q('id')
            . ' WHERE u.' . $q('thread_count') . ' <> COALESCE(t.n, 0)'
            . ' OR u.' . $q('post_count') . ' <> (COALESCE(t.n, 0) + COALESCE(p.n, 0))'
        );


        $drift = $forumDrift + $threadDrift + $userDrift;

        if ($drift > 0) {
            $detail = '版块 ' . $forumDrift . '、帖子 ' . $threadDrift . '、用户 ' . $userDrift;

            Logger::warning('维护任务：计数自检发现偏差（' . $detail . '）', [
                'job'     => 'counter_check',
                'forums'  => $forumDrift,
                'threads' => $threadDrift,
                'users'   => $userDrift,
            ]);

            return [
                'ok'      => false,
                'message' => '计数自检发现 ' . $drift . ' 处偏差（' . $detail . '）。',
            ];
        }

        $scope = '版块 ' . (int)Database::value(
            'SELECT COUNT(*) FROM ' . $q('forums') . ' WHERE ' . $q('deleted_at') . ' IS NULL'
        )
            . ' 个、帖子 ' . (int)Database::value(
                'SELECT COUNT(*) FROM ' . $q('threads') . ' WHERE ' . $q('deleted_at') . ' IS NULL'
            )
            . ' 条、用户 ' . (int)Database::value('SELECT COUNT(*) FROM ' . $q('users')) . ' 个';

        return ['ok' => true, 'message' => '计数一致（' . $scope . '）。'];
    }

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

        $threshold = time() - (int)config('app.cookie_ttl', 2592000);
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
