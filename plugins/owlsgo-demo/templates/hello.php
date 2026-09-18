<?php
/**
 * 示例插件前台页面：/hello
 *
 * 由 AdminController::hello() 通过 view('plugin/owlsgo-demo/hello') 渲染，
 * 未指定布局，因此自动套用前台主布局 templates/layouts/main.php。
 *
 * 变量：$greeting、$style（card|plain）、$user、$stats、$records、$isAdmin、$adminUrl、$configUrl
 */

declare(strict_types=1);

$greeting = (string)($greeting ?? '');
$style    = (string)($style ?? 'card');
$user     = is_array($user ?? null) ? $user : null;
$stats    = is_array($stats ?? null) ? $stats : [];
$records  = is_array($records ?? null) ? $records : [];
$isAdmin  = (bool)($isAdmin ?? false);

$latest = (int)($stats['latest'] ?? 0);
?>

<ol class="crumbs">
    <li><a href="<?= e(url('/')) ?>">首页</a></li>
    <li aria-hidden="true">/</li>
    <li>插件示例</li>
</ol>

<?php if ($style === 'card'): ?>
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'bulb', 'size' => 16]) ?>owlsgo-demo 插件</h3>
            <span class="spacer"></span>
            <span class="badge">v1.0.0</span>
        </div>

        <div class="panel__body">
            <p style="font-size:16px;font-weight:600;margin:0 0 6px" data-owlsgo-demo-greeting><?= e($greeting) ?></p>
            <p class="text-light" style="margin:0">
                这个页面由插件自己的模板渲染（<code>plugins/owlsgo-demo/templates/hello.php</code>），
                并通过 <code>view('plugin/owlsgo-demo/hello')</code> 套用了站点前台布局，
                因此它和核心页面拥有一致的头部、导航与页脚。
            </p>
        </div>
    </section>
<?php else: ?>
    <h1 style="font-size:20px;margin:0 0 8px"><?= e($greeting) ?></h1>
    <p class="text-light" style="margin:0 0 var(--space-4)">
        「极简式」样式由后台配置项「前台页面样式」控制，用于演示 select 类型插件配置的读取。
    </p>
<?php endif; ?>

<div class="admin-cards" style="margin-top:var(--space-4)">
    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'activity', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value"><?= format_number((int)($stats['total'] ?? 0)) ?></div>
            <div class="admin-card__label">插件记录的活动</div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'file', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value"><?= format_number((int)($stats['threads'] ?? 0)) ?></div>
            <div class="admin-card__label">来自主题</div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'reply', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value"><?= format_number((int)($stats['posts'] ?? 0)) ?></div>
            <div class="admin-card__label">来自回复</div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card__icon"><?= $view('partials/icon', ['name' => 'clock', 'size' => 20]) ?></div>
        <div>
            <div class="admin-card__value" style="font-size:16px"><?= e(human_time($latest)) ?></div>
            <div class="admin-card__label">最近一次活动</div>
        </div>
    </div>
</div>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'list', 'size' => 16]) ?>最近活动</h3>
        <span class="spacer"></span>
        <?php if ($isAdmin): ?>
            <a class="text-light" style="font-size:13px" href="<?= e($adminUrl) ?>">在后台查看全部 ›</a>
        <?php endif; ?>
    </div>

    <?php if ($records === []): ?>
        <div class="empty" style="padding:34px 16px">
            <p>还没有活动记录。发一个主题或回复一下，就会出现在这里。</p>
        </div>
    <?php else: ?>
        <?php foreach ($records as $record): ?>
            <article class="notice-item">
                <div class="notice-item__icon">
                    <?= $view('partials/icon', ['name' => (string)($record['kind'] ?? '') === 'thread' ? 'file' : 'reply', 'size' => 17]) ?>
                </div>
                <div class="notice-item__body">
                    <div class="notice-item__text">
                        <strong><?= e((string)($record['username'] ?? '') !== '' ? (string)$record['username'] : '匿名') ?></strong>
                        ·
                        <span class="text-light"><?= e(\OwlsgoDemo\Service::kindLabel((string)($record['kind'] ?? ''))) ?></span>
                    </div>
                    <div class="text-light" style="font-size:13px;margin-top:2px">
                        <?= e((string)($record['detail'] ?? '')) ?>
                    </div>
                    <time datetime="<?= e(date('c', (int)($record['created_at'] ?? 0))) ?>">
                        <?= e(human_time((int)($record['created_at'] ?? 0))) ?>
                    </time>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'info', 'size' => 16]) ?>这个插件演示了什么</h3>
    </div>

    <div class="panel__body">
        <div class="doc-note">
            <div>
                <p style="margin:0 0 6px"><strong>内容钩子</strong>：<code>content_rendered</code> 会在正文渲染完成后追加脚注（需在插件配置中填写「正文脚注」）。</p>
                <p style="margin:0 0 6px"><strong>动作钩子</strong>：<code>after_thread_create</code> / <code>after_post_create</code> 把发帖行为记录进插件自建表。</p>
                <p style="margin:0 0 6px"><strong>资源钩子</strong>：<code>head_assets</code> / <code>footer_assets</code> 注入插件元信息与前端配置。</p>
                <p style="margin:0 0 6px"><strong>导航钩子</strong>：<code>nav_links</code> 为登录用户追加「打招呼」入口，<code>user_profile_tabs</code> 为个人中心追加标签页。</p>
                <p style="margin:0"><strong>路由 / 后台页 / 计划任务</strong>：<code>/hello</code>、<code>/admin/plugin/owlsgo-demo</code> 与每日清理任务。</p>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="hstack mt-4">
                <a class="button small" href="<?= e($adminUrl) ?>">
                    <?= $view('partials/icon', ['name' => 'dashboard', 'size' => 15]) ?>
                    <span>插件后台页面</span>
                </a>
                <a class="button outline small" href="<?= e($configUrl) ?>">
                    <?= $view('partials/icon', ['name' => 'settings', 'size' => 15]) ?>
                    <span>插件配置</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>
