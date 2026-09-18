<?php
/**
 * 示例插件后台页面：/admin/plugin/owlsgo-demo
 *
 * 由 AdminController::admin() 通过 adminView() 渲染，因此自动继承后台布局与侧栏导航。
 *
 * 变量：$records、$stats、$config、$retention、$tableName、$tableReady、
 *       $frontUrl、$configUrl、$clearUrl
 */

declare(strict_types=1);

$records    = is_array($records ?? null) ? $records : [];
$stats      = is_array($stats ?? null) ? $stats : [];
$config     = is_array($config ?? null) ? $config : [];
$retention  = (int)($retention ?? 7);
$tableName  = (string)($tableName ?? '');
$tableReady = (bool)($tableReady ?? false);
$frontUrl   = (string)($frontUrl ?? url('/hello'));
$configUrl  = (string)($configUrl ?? url('/admin/plugins/' . \OwlsgoDemo\Plugin::ID . '/config'));
$clearUrl   = (string)($clearUrl ?? url('/hello/clear'));

/** 配置项的可读展示（键 => [标签, 值]） */
$configRows = [
    ['greeting', '问候语'],
    ['welcome_style', '前台页面样式'],
    ['footnote', '正文脚注'],
    ['block_keywords', '禁止词'],
    ['enable_head_assets', '注入 <head> 样式'],
    ['enable_footer_assets', '注入底部脚本'],
    ['retention_days', '日志保留天数'],
];
?>

<div class="admin-cards">
    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'activity', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value"><?= format_number((int)($stats['total'] ?? 0)) ?></div>
            <div class="admin-card__label">活动记录总数</div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'file', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value"><?= format_number((int)($stats['threads'] ?? 0)) ?></div>
            <div class="admin-card__label">发表主题</div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'reply', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value"><?= format_number((int)($stats['posts'] ?? 0)) ?></div>
            <div class="admin-card__label">发表回复</div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'clock', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value" style="font-size:16px"><?= e(human_time((int)($stats['latest'] ?? 0))) ?></div>
            <div class="admin-card__label">最近活动</div>
        </div>
    </div>
</div>

<div class="admin-split">
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'list', 'size' => 16]) ?>活动记录</h3>
            <span class="spacer"></span>

            <?php if ($records !== []): ?>
                <form method="post" action="<?= e($clearUrl) ?>" class="inline-form"
                      data-confirm="确定要清空该插件的全部活动记录吗？该操作立即生效且无法恢复。">
                    <?= csrf_field() ?>
                    <button type="submit" class="button outline small" data-variant="danger">
                        <?= $view('partials/icon', ['name' => 'trash', 'size' => 15]) ?>
                        <span>清空记录</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($records === []): ?>
            <div class="empty" style="padding:34px 16px">
                <p>暂无活动记录。发表一个主题或回复后即可在此看到。</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th style="width:64px">ID</th>
                        <th style="width:150px">用户</th>
                        <th style="width:110px">类型</th>
                        <th>说明</th>
                        <th style="width:130px">时间</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= (int)($record['id'] ?? 0) ?></td>
                            <td>
                                <?php if ((int)($record['user_id'] ?? 0) > 0): ?>
                                    <a href="<?= e(url('/u/' . (int)$record['user_id'])) ?>">
                                        <?= e((string)($record['username'] ?? '') !== '' ? (string)$record['username'] : '用户 #' . (int)$record['user_id']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-light">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge outline"><?= e(\OwlsgoDemo\Service::kindLabel((string)($record['kind'] ?? ''))) ?></span>
                            </td>
                            <td><?= e((string)($record['detail'] ?? '')) ?></td>
                            <td>
                                <time datetime="<?= e(date('c', (int)($record['created_at'] ?? 0))) ?>">
                                    <?= e(human_time((int)($record['created_at'] ?? 0))) ?>
                                </time>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div>
        <section class="panel">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>当前配置</h3>
                <span class="spacer"></span>
                <a class="text-light" style="font-size:13px" href="<?= e($configUrl) ?>">修改 ›</a>
            </div>

            <div class="panel__body">
                <table style="width:100%">
                    <tbody>
                    <?php foreach ($configRows as [$key, $label]): ?>
                        <?php
                        $raw   = $config[$key] ?? '';
                        $value = is_scalar($raw) ? (string)$raw : '';

                        if (in_array($key, ['enable_head_assets', 'enable_footer_assets'], true)) {
                            $value = $value === '1' ? '已开启' : '已关闭';
                        } elseif ($value === '') {
                            $value = '—';
                        }
                        ?>
                        <tr>
                            <td class="text-light" style="width:44%"><?= e($label) ?></td>
                            <td><?= e($value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'database', 'size' => 16]) ?>插件自建表</h3>
            </div>

            <div class="panel__body">
                <p style="margin:0 0 8px">
                    表名：<code><?= e($tableName) ?></code>
                </p>

                <?php if ($tableReady): ?>
                    <div role="alert" data-variant="success">
                        <?= $view('partials/icon', ['name' => 'check', 'size' => 18]) ?>
                        <div>数据表已就绪。表由 <code>sql/<?= e(\Core\Database::driver()) ?>.sql</code> 在插件启用时自动创建。</div>
                    </div>
                <?php else: ?>
                    <div role="alert" data-variant="warning">
                        <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
                        <div>未检测到数据表。请先停用再重新启用本插件，核心会重新执行建表脚本。</div>
                    </div>
                <?php endif; ?>

                <div class="doc-note mt-4">
                    <div>
                        <p style="margin:0 0 6px"><strong>清理策略</strong>：计划任务 <code>owlsgo_demo_cleanup</code> 每天执行一次，删除 <strong><?= $retention ?></strong> 天前的记录。</p>
                        <p style="margin:0">可在「计划任务」页查看执行日志，或手动触发一次。</p>
                    </div>
                </div>

                <div class="hstack mt-4">
                    <a class="button small" href="<?= e($frontUrl) ?>" target="_blank" rel="noopener">
                        <?= $view('partials/icon', ['name' => 'external', 'size' => 15]) ?>
                        <span>访问插件前台页</span>
                    </a>
                </div>
            </div>
        </section>
    </div>
</div>
