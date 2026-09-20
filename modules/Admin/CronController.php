<?php
/**
 * 后台：计划任务
 *
 * 两类任务：
 *  1. 插件计划任务（cron_tasks 表，由插件通过 Plugin::cron 声明）
 *  2. 系统内置维护任务（MaintenanceModel::JOBS）
 *
 * 触发方式：
 *  - 后台「立即执行」按钮（POST /admin/cron/run）
 *  - 系统 crontab 定时访问 GET /cron/run?token=xxx（令牌在后台生成）
 *  - 已登录且有 admin.cron 权限的管理员可直接访问该地址，便于第一次配置
 */

declare(strict_types=1);

namespace Modules\Admin;

use Core\Auth;
use Core\Database;
use Core\Paginator;
use Core\Plugin;
use Core\PluginManager;
use Core\Request;
use Core\Response;
use Core\Router;
use Core\Settings;

final class CronController extends AdminBaseController
{
    /**
     * 任务面板
     *
     * @param array<string, string> $params
     */
    public function index(array $params): string
    {
        $tasks = CronModel::tasks();

        /*
         * 插件任务在注册表（Plugin::crons()）里带有中文描述，数据库表不存它 ——
         * 按「插件::任务名」从注册表补齐，后台就能看到这个任务是干什么的。
         * 插件停用时注册表里没有条目，description 留空（任务本身照常显示）。
         */
        $descriptions = [];
        $activePlugins = [];
        foreach (Plugin::crons() as $cron) {
            $key = (string)$cron['plugin'] . '::' . (string)$cron['name'];
            $descriptions[$key] = (string)($cron['description'] ?? '');
            $activePlugins[(string)$cron['plugin']] = true;
        }
        foreach ($tasks as &$task) {
            $plugin = (string)($task['plugin'] ?? '');
            $key = $plugin . '::' . (string)($task['name'] ?? '');
            $task['description'] = $descriptions[$key] ?? '';
            /*
             * 插件停用后其任务仍留在 cron_tasks 表里，但每次执行都会因
             * 「处理器未注册」被跳过 —— 对这种任务再提供「启用」按钮是误导，
             * 模板据此显示「插件未启用」并隐藏启停开关。
             */
            $task['plugin_active'] = isset($activePlugins[$plugin]) || $plugin === '';
        }
        unset($task);

        $lastRun = CronModel::lastRunAt();

        $logs = CronModel::paginateLogs($this->currentPage(), 30);
        $token = (string)Settings::get('cron_token', '');

        return $this->adminView('admin/cron', [
            'pageTitle'  => '计划任务 - ' . (string)setting('site_name'),
            'adminTitle' => '计划任务',

            'tasks'      => $tasks,
            'taskCount'  => count($tasks),
            'dueCount'   => CronModel::dueCount(),
            'lastRun'    => $lastRun,

            'jobs'       => MaintenanceModel::JOBS,

            'logs'       => $logs,
            'pagination' => Paginator::render($logs, '/admin/cron'),

            'cronUrl'    => $this->externalUrl($token),
            'hasToken'   => $token !== '',
        ]);
    }

    /**
     * 立即执行
     *
     * @param array<string, string> $params
     */
    public function run(array $params): never
    {
        $target = Request::string('target', 'all', 20);
        $back   = Router::url('/admin/cron');

        $pluginResults = [];
        $maintenance   = [];

        if (in_array($target, ['all', 'plugins'], true)) {
            /*
             * 后台手动执行传 force=true：忽略 next_run_at，把启用的插件任务全部跑一遍。
             * 否则刚执行过的任务要等满整个间隔（如 1 天）才会再次到期，
             * 连点「立即执行」永远是「插件任务执行 0 个」，最近一次执行时间也不动。
             */
            $pluginResults = PluginManager::runCron(50, true);
        }

        if (in_array($target, ['all', 'maintenance'], true)) {
            $maintenance = MaintenanceModel::runAll();
            $this->recordMaintenance($maintenance);
        }

        $summary = $this->buildSummary($pluginResults, $maintenance);

        $this->audit('cron.run', 'cron', $summary);

        if (Request::wantsJson()) {
            $this->json([
                'ok'          => true,
                'message'     => $summary,
                'plugins'     => $pluginResults,
                'maintenance' => $maintenance,
                'redirect'    => $back,
            ]);
        }

        $this->redirectWith($back, $summary);
    }

    /**
     * 切换插件计划任务的启用状态
     *
     * @param array<string, string> $params
     */
    public function toggle(array $params): never
    {
        $id   = (int)($params['id'] ?? 0);
        $back = Router::url('/admin/cron');

        $result = CronModel::toggle($id);

        if (!$result['ok']) {
            $this->redirectWith($back, $result['message'], 'error');
        }

        $this->audit('cron.toggle', 'cron:' . $id, $result['message']);

        if (Request::wantsJson()) {
            $this->json([
                'ok'      => true,
                'message' => $result['message'],
                'enabled' => $result['enabled'],
            ]);
        }

        $this->redirectWith($back, $result['message']);
    }

    /**
     * 重新生成外部触发令牌
     *
     * @param array<string, string> $params
     */
    public function token(array $params): never
    {
        $token = bin2hex(random_bytes(24));

        Settings::save(['cron_token' => $token]);

        $this->audit('cron.token', 'cron', '重置计划任务触发令牌');

        $this->redirectWith(
            Router::url('/admin/cron'),
            '新的触发令牌已生成，旧的触发地址立即失效。'
        );
    }

    /**
     * 外部定时触发入口（供系统 crontab 调用）
     *
     * @param array<string, string> $params
     */
    public function external(array $params): never
    {
        if (!$this->authorize()) {
            Response::json(['ok' => false, 'message' => '触发令牌无效或已过期。'], 403);
        }

        // 外部触发同样受全局限流保护，避免被当作压测入口
        $this->throttle('default', 'cron:' . Request::ip());

        $pluginResults = PluginManager::runCron(50);
        $maintenance   = MaintenanceModel::runAll();
        $this->recordMaintenance($maintenance);

        Response::json([
            'ok'          => true,
            'message'     => $this->buildSummary($pluginResults, $maintenance),
            'time'        => date('c'),
            'plugins'     => $pluginResults,
            'maintenance' => $maintenance,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  内部辅助                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * 外部触发鉴权：令牌匹配或已登录的后台管理员
     */
    private function authorize(): bool
    {
        $stored = (string)Settings::get('cron_token', '');
        $given  = Request::string('token', '', 128);

        if ($stored !== '' && $given !== '' && hash_equals($stored, $given)) {
            return true;
        }

        return Auth::can('admin.cron');
    }

    /**
     * 外部触发地址（无令牌时提示先到后台生成）
     */
    private function externalUrl(string $token): string
    {
        $url = Router::url('/cron/run');

        return $token === '' ? $url : $url . '?token=' . rawurlencode($token);
    }

    /**
     * 把维护任务的执行结果落到 cron_logs（一条汇总记录）
     *
     * 维护任务不写 cron_tasks.last_run_at，之前也不留任何痕迹 ——
     * 「最近一次执行」卡片与任务日志都看不到它们，点完按钮页面毫无变化。
     * 插件任务每次执行都会写一条 cron_logs，这里保持同一口径。
     *
     * @param list<array{job:string, ok:bool, message:string}> $maintenance
     */
    private function recordMaintenance(array $maintenance): void
    {
        if ($maintenance === []) {
            return;
        }

        $parts = [];
        $failures = 0;

        foreach ($maintenance as $item) {
            if (!$item['ok']) {
                $failures++;
            }
            $parts[] = $item['job'] . '：' . $item['message'];
        }

        $now = time();

        Database::insert('cron_logs', [
            'name'       => 'maintenance',
            'status'     => $failures > 0 ? 'error' : 'ok',
            'message'    => mb_substr(implode('；', $parts), 0, 400),
            'duration'   => 0,
            'created_at' => $now,
        ]);
    }

    /**
     * 汇总执行结果为一句话
     *
     * @param list<array{name:string, status:string, message:string, duration:int}> $pluginResults
     * @param list<array{job:string, ok:bool, message:string}>                       $maintenance
     */
    private function buildSummary(array $pluginResults, array $maintenance): string
    {
        $failures = 0;
        foreach ($pluginResults as $item) {
            if (($item['status'] ?? '') === 'error') {
                $failures++;
            }
        }

        $maintenanceFailures = 0;
        foreach ($maintenance as $item) {
            if (!$item['ok']) {
                $maintenanceFailures++;
            }
        }

        $parts = [];

        $parts[] = '插件任务执行 ' . count($pluginResults) . ' 个'
            . ($failures > 0 ? '（失败 ' . $failures . ' 个）' : '');

        $parts[] = '维护任务执行 ' . count($maintenance) . ' 项'
            . ($maintenanceFailures > 0 ? '（失败 ' . $maintenanceFailures . ' 项）' : '');

        return '执行完成：' . implode('，', $parts) . '。';
    }
}
