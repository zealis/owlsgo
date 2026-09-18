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
use Core\Paginator;
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

        $lastRun = 0;
        foreach ($tasks as $task) {
            $lastRun = max($lastRun, (int)$task['last_run_at']);
        }

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
            $pluginResults = PluginManager::runCron(50);
        }

        if (in_array($target, ['all', 'maintenance'], true)) {
            $maintenance = MaintenanceModel::runAll();
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
