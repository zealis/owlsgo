<?php
/**
 * 后台概览
 *
 * 变量：
 *  - $stats        站点统计
 *                  （users、threads、posts、attachments、today_*、pending_*、bans、plugins、cron_due）
 *  - $forumStats   版块聚合（forums/threads/posts）
 *  - $recentUsers  最新注册用户（已 decorateMany）
 *  - $recentThreads 最新帖子（已 decorate）
 *  - $recentLogs   最新操作日志（原样行，含 action/target/detail/created_at）
 *  - $system       运行环境信息键值对
 */

declare(strict_types=1);

$stats         = is_array($stats ?? null) ? $stats : [];
$forumStats    = is_array($forumStats ?? null) ? $forumStats : [];
$recentUsers   = is_array($recentUsers ?? null) ? $recentUsers : [];
$recentThreads = is_array($recentThreads ?? null) ? $recentThreads : [];
$recentLogs    = is_array($recentLogs ?? null) ? $recentLogs : [];
$system        = is_array($system ?? null) ? $system : [];

/*
 * 右栏（运行环境 / 最近操作）是系统运维信息，控制器已按权限决定是否取数：
 *   运行环境 → 「站点设置」权限；最近操作 → 「查看日志」权限。
 * 两项都无权限时右栏整块不出现，容器同时收成单列，左栏内容铺满。
 */
$canSystem     = (bool)($canSystem ?? false);
$canLogs       = (bool)($canLogs ?? false);
$showRightBar  = $canSystem || $canLogs;

/** 主统计卡片：图标 / 标签 / 数值键 / 是否告警态 */
$primaryCards = [
    ['icon' => 'users',  'label' => '注册用户', 'key' => 'users',       'warn' => false],
    ['icon' => 'file',   'label' => '帖子总数', 'key' => 'threads',     'warn' => false],
    ['icon' => 'message', 'label' => '评论总数', 'key' => 'posts',      'warn' => false],
    ['icon' => 'paperclip', 'label' => '附件数量', 'key' => 'attachments', 'warn' => false],
];

/** 待处理事项：为 0 时不显示告警配色 */
$todoCards = [
    ['icon' => 'alert',  'label' => '待审核帖子', 'key' => 'pending_threads'],
    ['icon' => 'alert',  'label' => '待审核评论', 'key' => 'pending_posts'],
    ['icon' => 'ban',    'label' => '生效中的封禁', 'key' => 'bans'],
    ['icon' => 'clock',  'label' => '待执行任务', 'key' => 'cron_due'],
];
?>

<div class="admin-cards">
    <?php foreach ($primaryCards as $card): ?>
        <div class="admin-card">
            <span class="admin-card__icon">
                <?= $view('partials/icon', ['name' => $card['icon'], 'size' => 20]) ?>
            </span>
            <div style="min-width:0">
                <div class="admin-card__value"><?= format_number((int)($stats[$card['key']] ?? 0)) ?></div>
                <div class="admin-card__label"><?= e($card['label']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="admin-cards" style="margin-top:var(--space-3)">
    <?php foreach ($todoCards as $card): ?>
        <?php $todoCount = (int)($stats[$card['key']] ?? 0); ?>
        <div class="admin-card<?= $todoCount > 0 ? ' admin-card--warn' : '' ?>">
            <span class="admin-card__icon">
                <?= $view('partials/icon', ['name' => $card['icon'], 'size' => 20]) ?>
            </span>
            <div style="min-width:0">
                <div class="admin-card__value"><?= format_number($todoCount) ?></div>
                <div class="admin-card__label"><?= e($card['label']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="admin-cards" style="margin-top:var(--space-3)">
    <div class="admin-card">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'activity', 'size' => 20]) ?></span>
        <div style="min-width:0">
            <div class="admin-card__value"><?= format_number((int)($stats['today_users'] ?? 0)) ?></div>
            <div class="admin-card__label">今日新增用户</div>
        </div>
    </div>
    <div class="admin-card">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'activity', 'size' => 20]) ?></span>
        <div style="min-width:0">
            <div class="admin-card__value"><?= format_number((int)($stats['today_threads'] ?? 0)) ?></div>
            <div class="admin-card__label">今日新增帖子</div>
        </div>
    </div>
    <div class="admin-card">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'activity', 'size' => 20]) ?></span>
        <div style="min-width:0">
            <div class="admin-card__value"><?= format_number((int)($stats['today_posts'] ?? 0)) ?></div>
            <div class="admin-card__label">今日新增评论</div>
        </div>
    </div>
    <div class="admin-card">
        <span class="admin-card__icon"><?= $view('partials/icon', ['name' => 'plug', 'size' => 20]) ?></span>
        <div style="min-width:0">
            <div class="admin-card__value"><?= format_number((int)($stats['plugins'] ?? 0)) ?></div>
            <div class="admin-card__label">已启用插件</div>
        </div>
    </div>
</div>

<div class="admin-split<?= $showRightBar ? '' : ' admin-split--single' ?>">
    <!-- 左栏：最新内容 -->
    <div>
        <section class="panel">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'file', 'size' => 16]) ?>最新帖子</h3>
                <span class="spacer"></span>
                <a href="<?= e(url('/admin/threads')) ?>" style="font-size:13px">内容管理</a>
            </div>

            <?php if ($recentThreads === []): ?>
                <div class="empty">
                    <?= $view('partials/icon', ['name' => 'file', 'size' => 42]) ?>
                    <p>还没有任何帖子。</p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="admin-list admin-list--recent">
                        <thead>
                        <tr>
                            <?php /* 概览是两栏布局，容器不宽 —— 列宽用百分比才不会撑出滚动条 */ ?>
                            <th style="width:40%">标题</th>
                            <th style="width:18%">作者</th>
                            <th style="width:15%">版块</th>
                            <th style="width:12%">评论</th>
                            <th style="width:15%">发表时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentThreads as $thread): ?>
                            <?php $threadId = (int)($thread['id'] ?? 0); ?>
                            <tr>
                                <td>
                                    <?php /* 主值吃掉剩余宽度并单行截断，徽章排在右侧不参与换行 */ ?>
                                    <div class="cell-row">
                                        <a class="cell-title" href="<?= e(url('/t/' . $threadId)) ?>"
                                           target="_blank" rel="noopener"
                                           title="<?= e((string)($thread['title'] ?? '')) ?>">
                                            <?= e((string)($thread['title'] ?? '')) ?>
                                        </a>
                                        <?php if ((int)($thread['status'] ?? 1) !== 1): ?>
                                            <span class="badge outline">待审核</span>
                                        <?php endif; ?>
                                        <?php if ((int)($thread['is_pinned'] ?? 0) === 1): ?>
                                            <span class="badge outline">置顶</span>
                                        <?php endif; ?>
                                        <?php if ((int)($thread['is_essence'] ?? 0) === 1): ?>
                                            <span class="badge outline">精华</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-light">
                                    <span class="cell-title"><?= e((string)($thread['author']['username'] ?? '用户已删除')) ?></span>
                                </td>
                                <td class="text-light">
                                    <span class="cell-title"><?= e((string)($thread['forum_name'] ?? '—')) ?></span>
                                </td>
                                <td class="text-light"><?= (int)($thread['reply_count'] ?? 0) ?></td>
                                <td class="text-light"><?= e(human_time((int)($thread['created_at'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" style="margin-top:var(--space-4)">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'users', 'size' => 16]) ?>最新注册</h3>
                <span class="spacer"></span>
                <a href="<?= e(url('/admin/users')) ?>" style="font-size:13px">用户管理</a>
            </div>

            <?php if ($recentUsers === []): ?>
                <div class="empty">
                    <?= $view('partials/icon', ['name' => 'users', 'size' => 42]) ?>
                    <p>还没有注册用户。</p>
                </div>
            <?php else: ?>
                <div class="panel__body hstack" style="flex-wrap:wrap;gap:10px">
                    <?php foreach ($recentUsers as $user): ?>
                        <a href="<?= e(url('/admin/users/' . (int)$user['id'])) ?>"
                           class="hstack" style="gap:8px;padding:5px 10px;border:1px solid var(--qq-line);border-radius:999px;max-width:100%">
                            <?= avatar_img($user, 22) ?>
                            <span class="cell-title" style="max-width:180px;font-size:13.5px"
                                  title="<?= e((string)($user['username'] ?? '')) ?>">
                                <?= e((string)($user['username'] ?? '')) ?>
                            </span>
                            <span style="flex:none;font-size:12px;color:<?= e((string)($user['group_color'] ?? '#999999')) ?>">
                                <?= e((string)($user['group_name'] ?? '')) ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($showRightBar): ?>
    <!-- 右栏：系统信息与日志（按权限显示：运行环境要「站点设置」，最近操作要「查看日志」） -->
    <div>
        <?php if ($canSystem): ?>
        <section class="panel">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'server', 'size' => 16]) ?>运行环境</h3>
            </div>
            <div class="panel__body">
                <dl class="kv">
                    <dt>PHP 版本</dt><dd class="mono"><?= e((string)($system['php_version'] ?? '—')) ?></dd>
                    <dt>Web 服务器</dt><dd class="mono"><?= e((string)($system['server'] ?? '—')) ?></dd>
                    <dt>数据库驱动</dt><dd class="mono"><?= e((string)($system['driver'] ?? '—')) ?></dd>
                    <dt>数据库占用</dt><dd class="mono"><?= e((string)($system['db_size'] ?? '—')) ?></dd>
                    <dt>附件占用</dt>
                    <dd class="mono">
                        <?= e((string)($system['upload_size'] ?? '—')) ?>
                        <span class="text-light">（<?= e((string)($system['upload_files'] ?? '0')) ?> 个文件）</span>
                    </dd>
                    <dt>站点版本</dt><dd class="mono"><?= e((string)($system['app_version'] ?? '—')) ?></dd>
                    <dt>安装时间</dt><dd class="mono"><?= e((string)($system['installed_at'] ?? '未记录')) ?></dd>
                    <dt>URL 模式</dt><dd class="mono"><?= e((string)($system['url_style'] ?? '—')) ?></dd>
                    <dt>调试模式</dt>
                    <dd>
                        <?php if (!empty($system['debug'])): ?>
                            <span class="badge">已开启</span>
                        <?php else: ?>
                            <span class="badge outline">已关闭</span>
                        <?php endif; ?>
                    </dd>
                    <dt>本次查询</dt><dd class="mono"><?= e((string)($system['sql_queries'] ?? '0')) ?> 次</dd>
                </dl>

                <div class="doc-note" style="margin-top:12px">
                    <strong>版块聚合：</strong>
                    共 <?= (int)($forumStats['forums'] ?? 0) ?> 个版块 ·
                    <?= format_number((int)($forumStats['threads'] ?? 0)) ?> 个帖子 ·
                    <?= format_number((int)($forumStats['posts'] ?? 0)) ?> 条评论
                </div>

                <?php if (!empty($system['debug'])): ?>
                    <div role="alert" data-variant="warning" style="margin-top:12px">
                        <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
                        <div>当前处于调试模式，错误信息会暴露给访客，上线前请务必关闭。</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($canLogs): ?>
        <section class="panel"<?= $canSystem ? ' style="margin-top:var(--space-4)"' : '' ?>>
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'list', 'size' => 16]) ?>最近操作</h3>
                <span class="spacer"></span>
                <a href="<?= e(url('/admin/logs')) ?>" style="font-size:13px">全部日志</a>
            </div>

            <?php if ($recentLogs === []): ?>
                <div class="panel__body text-light" style="font-size:13.5px">暂无操作记录。</div>
            <?php else: ?>
                <div class="panel__body--flush">
                    <?php foreach ($recentLogs as $log): ?>
                        <div class="notice-item">
                            <div class="notice-item__icon">
                                <?= $view('partials/icon', ['name' => 'activity', 'size' => 17]) ?>
                            </div>
                            <div class="notice-item__body">
                                <div class="notice-item__text">
                                    <span class="badge outline" style="margin-right:6px">
                                        <?= e((string)($log['action'] ?? '')) ?>
                                    </span>
                                    <?= e((string)($log['detail'] ?? '')) ?>
                                </div>
                                <div class="hstack" style="margin-top:4px;gap:10px">
                                    <time><?= e(human_time((int)($log['created_at'] ?? 0))) ?></time>
                                    <?php if ((string)($log['target'] ?? '') !== ''): ?>
                                        <span class="text-light mono"><?= e((string)$log['target']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
