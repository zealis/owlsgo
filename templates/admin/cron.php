<?php
/**
 * 后台：计划任务
 *
 * 变量：$tasks（插件任务列表）、$taskCount、$dueCount、$lastRun、
 *       $jobs（内置维护任务清单 job => 说明）、$logs（分页结果）、
 *       $pagination、$cronUrl、$hasToken
 *
 * 手动执行与「外部定时器触发」走的是同一套逻辑（PluginManager::runCron + MaintenanceModel::runAll），
 * 因此两者结果一致，只是触发方式不同。
 */

declare(strict_types=1);

$tasks      = is_array($tasks ?? null) ? $tasks : [];
$taskCount  = (int)($taskCount ?? count($tasks));
$dueCount   = (int)($dueCount ?? 0);
$lastRun    = (int)($lastRun ?? 0);
$jobs       = is_array($jobs ?? null) ? $jobs : [];
$logs       = is_array($logs ?? null) ? $logs : ['items' => []];
$logItems   = is_array($logs['items'] ?? null) ? $logs['items'] : [];
$cronUrl    = (string)($cronUrl ?? url('/cron/run'));
$hasToken   = (bool)($hasToken ?? false);

/** 把秒数间隔格式化为可读文本 */
$intervalText = static function (int $seconds): string {
    if ($seconds <= 0) {
        return '—';
    }
    if ($seconds < 60) {
        return $seconds . ' 秒';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' 分钟';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . ' 小时';
    }

    return intdiv($seconds, 86400) . ' 天';
};

/** 执行状态 → 中文 + 徽章配色（skip = 任务停用/处理器未注册，跑之前被跳过，不算失败） */
$statusText    = static fn (string $s): string => ['ok' => '成功', 'skip' => '跳过', 'error' => '失败'][$s] ?? $s;
$statusVariant = static fn (string $s): string => ['ok' => 'outline', 'skip' => 'warning', 'error' => 'danger'][$s] ?? 'danger';
?>

<div class="admin-cards">
    <div class="admin-card">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'clock', 'size' => 20]) ?></span>
        <div>
            <div class="admin-card__value"><?= $taskCount ?></div>
            <div class="admin-card__label">插件任务</div>
        </div>
    </div>
    <div class="admin-card<?= $dueCount > 0 ? ' admin-card--warn' : '' ?>">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'alert', 'size' => 20]) ?></span>
        <div>
            <div class="admin-card__value"><?= $dueCount ?></div>
            <div class="admin-card__label">待执行（已到期）</div>
        </div>
    </div>
    <div class="admin-card">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'activity', 'size' => 20]) ?></span>
        <div>
            <div class="admin-card__value" style="font-size:16px">
                <?= e($lastRun > 0 ? human_time($lastRun) : '从未执行') ?>
            </div>
            <div class="admin-card__label">最近一次执行</div>
        </div>
    </div>
    <div class="admin-card<?= $hasToken ? '' : ' admin-card--warn' ?>">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'key', 'size' => 20]) ?></span>
        <div>
            <div class="admin-card__value" style="font-size:16px"><?= $hasToken ? '已生成' : '未生成' ?></div>
            <div class="admin-card__label">外部触发令牌</div>
        </div>
    </div>
</div>

<div class="admin-split">
    <div>
        <!-- 手动执行 -->
        <section class="panel">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'play', 'size' => 16]) ?>手动执行</h3>
            </div>
            <div class="panel__body">
                <p style="margin-top:0;font-size:13.5px;color:var(--qq-ink-2)">
                    所有任务都是可重复执行且幂等的，重复运行不会产生副作用。
                </p>

                <div class="hstack" style="flex-wrap:wrap;gap:8px">
                    <form class="inline-form" method="post" action="<?= e(url('/admin/cron/run')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target" value="all">
                        <button type="submit" class="button">
                            <?= $view('partials/icon', ['name' => 'refresh', 'size' => 16]) ?>
                            <span>执行全部任务</span>
                        </button>
                    </form>

                    <form class="inline-form" method="post" action="<?= e(url('/admin/cron/run')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target" value="plugins">
                        <button type="submit" class="button ghost">
                            <?= $view('partials/icon', ['name' => 'plug', 'size' => 15]) ?>
                            <span>仅插件任务</span>
                        </button>
                    </form>

                    <form class="inline-form" method="post" action="<?= e(url('/admin/cron/run')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target" value="maintenance">
                        <button type="submit" class="button ghost">
                            <?= $view('partials/icon', ['name' => 'database', 'size' => 15]) ?>
                            <span>仅维护任务</span>
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- 内置维护任务 -->
        <section class="panel" style="margin-top:var(--space-4)">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'database', 'size' => 16]) ?>内置维护任务</h3>
                <span class="spacer"></span>
                <span class="text-light" style="font-size:12.5px">共 <?= count($jobs) ?> 项</span>
            </div>

            <?php if ($jobs === []): ?>
                <div class="panel__body text-light" style="font-size:13.5px">没有可用的维护任务。</div>
            <?php else: ?>
                <div class="panel__body--flush">
                    <?php foreach ($jobs as $job => $description): ?>
                        <div class="notice-item">
                            <div class="notice-item__icon">
                                <?= $view('partials/icon', ['name' => 'refresh', 'size' => 17]) ?>
                            </div>
                            <div class="notice-item__body">
                                <div class="notice-item__text">
                                    <code class="mono"><?= e((string)$job) ?></code>
                                </div>
                                <div class="text-light" style="font-size:12.5px;margin-top:3px">
                                    <?= e((string)$description) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- 插件任务 -->
        <section class="panel" style="margin-top:var(--space-4)">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'plug', 'size' => 16]) ?>插件任务</h3>
                <span class="spacer"></span>
                <span class="text-light" style="font-size:12.5px">共 <?= $taskCount ?> 个</span>
            </div>

            <?php if ($tasks === []): ?>
                <div class="empty">
                    <?= $view('partials/icon', ['name' => 'clock', 'size' => 44]) ?>
                    <p>还没有插件注册计划任务。</p>
                    <p class="text-light" style="font-size:13px">
                        插件通过 <code>Core\Plugin::cron()</code> 声明任务后，会自动同步到这里。
                    </p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="cron-table admin-list admin-list--cron">
                        <thead>
                        <tr>
                            <?php /*
                                    这一页是两栏布局、容器只有 ~680px，列宽用百分比：
                                    固定 px 之和会超过容器宽，表格会被撑出横向滚动条。
                                   */ ?>
                            <th style="width:17%">任务</th>
                            <th style="width:11%">所属插件</th>
                            <th style="width:7%">间隔</th>
                            <th style="width:11%">执行次数</th>
                            <th style="width:13%">下次执行</th>
                            <th style="width:11%">上次状态</th>
                            <th style="width:14%">状态</th>
                            <th style="width:16%">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $taskId    = (int)($task['id'] ?? 0);
                            $enabled   = (int)($task['enabled'] ?? 0) === 1;
                            $plugin    = (string)($task['plugin'] ?? '');
                            $lastStat  = (string)($task['last_status'] ?? '');
                            $nextRun   = (int)($task['next_run_at'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <strong class="cell-title" title="<?= e((string)($task['name'] ?? '')) ?>">
                                        <?= e((string)($task['name'] ?? '')) ?>
                                    </strong>
                                    <?php if ((string)($task['description'] ?? '') !== ''): ?>
                                        <div class="cell-title text-light" style="font-weight:400;font-size:12.5px;margin-top:3px"
                                             title="<?= e((string)$task['description']) ?>">
                                            <?= e((string)$task['description']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-light mono">
                                    <span class="cell-title"><?= e($plugin !== '' ? $plugin : '系统') ?></span>
                                </td>
                                <td class="text-light"><?= e($intervalText((int)($task['interval'] ?? 0))) ?></td>
                                <td class="text-light"><?= number_format((int)($task['run_count'] ?? 0)) ?></td>
                                <td class="text-light">
                                    <?php if (!$enabled): ?>
                                        —
                                    <?php elseif ($nextRun <= time()): ?>
                                        <span class="badge" data-variant="warning">已到期</span>
                                    <?php else: ?>
                                        <?= e(human_time($nextRun)) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($lastStat === ''): ?>
                                        <span class="text-light">—</span>
                                    <?php else: ?>
                                        <span class="badge" data-variant="<?= e($statusVariant($lastStat)) ?>"><?= e($statusText($lastStat)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($enabled): ?>
                                        <span class="badge outline"><span class="status-dot"></span>启用</span>
                                    <?php else: ?>
                                        <span class="badge outline"><span class="status-dot status-dot--off"></span>停用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$enabled): ?>
                                        <span class="cell-title text-light">已停用</span>
                                    <?php elseif (empty($task['plugin_active'])): ?>
                                        <span class="cell-title text-light" title="插件当前未启用，任务无法执行">插件未启用</span>
                                    <?php else: ?>
                                        <form class="inline-form" method="post"
                                              action="<?= e(url('/admin/cron/' . $taskId . '/toggle')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="button small ghost">
                                                <?= $view('partials/icon', ['name' => $enabled ? 'pause' : 'play', 'size' => 14]) ?>
                                                <span><?= $enabled ? '停用' : '启用' ?></span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <!-- 外部触发 -->
        <section class="panel">
            <div class="panel__head">
                <h3>外部定时触发</h3>
            </div>
            <div class="panel__body">
                <p style="margin-top:0;font-size:13.5px;color:var(--qq-ink-2)">
                    在服务器上添加一条 crontab，定时请求下面的地址即可自动执行任务：
                </p>

                <div data-field>
                    <label for="cron-url">触发地址</label>
                    <input type="text" id="cron-url" value="<?= e($cronUrl) ?>" readonly
                           onfocus="this.select()">
                    <span data-hint>
                        <?php if ($hasToken): ?>
                            地址中的令牌等同密码，请勿公开分享。
                        <?php else: ?>
                            尚未生成令牌，此时只有已登录且有权限的管理员能访问该地址。
                        <?php endif; ?>
                    </span>
                </div>

                <pre class="snippet"><code># 每 5 分钟执行一次
*/5 * * * * curl -s "<?= e($cronUrl) ?>" &gt; /dev/null</code></pre>

                <form method="post" action="<?= e(url('/admin/cron/token')) ?>"
                      data-confirm="重新生成后，旧的触发地址会立即失效。确定继续吗？">
                    <?= csrf_field() ?>
                    <button type="submit" class="button ghost small">
                        <?= $view('partials/icon', ['name' => 'key', 'size' => 15]) ?>
                        <span><?= $hasToken ? '重新生成令牌' : '生成触发令牌' ?></span>
                    </button>
                </form>

                <div class="doc-note" style="margin-top:12px">
                    该入口同样受全局限流保护，避免被当作压测入口刷接口。
                </div>
            </div>
        </section>

        <!-- 执行日志 -->
        <section class="panel" style="margin-top:var(--space-4)">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'list', 'size' => 16]) ?>执行日志</h3>
                <span class="spacer"></span>
                <span class="text-light" style="font-size:12.5px">
                    共 <?= number_format((int)($logs['total'] ?? 0)) ?> 条
                </span>
            </div>

            <?php if ($logItems === []): ?>
                <div class="panel__body text-light" style="font-size:13.5px">还没有执行记录。</div>
            <?php else: ?>
                <div class="panel__body--flush">
                    <?php foreach ($logItems as $log): ?>
                        <?php $ok = (string)($log['status'] ?? 'ok') === 'ok'; ?>
                        <div class="notice-item">
                            <div class="notice-item__icon">
                                <?= $view('partials/icon', [
                                    'name' => $ok ? 'check' : 'alert',
                                    'size' => 17,
                                ]) ?>
                            </div>
                            <div class="notice-item__body">
                                <div class="notice-item__text">
                                    <strong><?= e((string)($log['name'] ?? '')) ?></strong>
                                    <?php $logStat = (string)($log['status'] ?? 'ok'); ?>
                                    <span class="badge" data-variant="<?= e($statusVariant($logStat)) ?>" style="margin-left:6px">
                                        <?= e($statusText($logStat)) ?>
                                    </span>
                                </div>
                                <?php if ((string)($log['message'] ?? '') !== ''): ?>
                                    <div class="text-light" style="font-size:12.5px;margin-top:3px">
                                        <?= e((string)$log['message']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="hstack" style="margin-top:4px;gap:10px">
                                    <time><?= e(human_time((int)($log['created_at'] ?? 0))) ?></time>
                                    <span class="text-light mono"><?= (int)($log['duration'] ?? 0) ?> ms</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (($pagination ?? '') !== ''): ?>
            <div class="mt-4"><?= (string)$pagination ?></div>
        <?php endif; ?>
    </div>
</div>
