<?php
/**
 * 版块详情：帖子列表 + 筛选 + 分页
 *
 * 变量：$forum、$result（items 已 decorate）、$filters、$favorited、$pagination、
 *       $canCreate、$canModerate、$subForums、$backUrl
 */

declare(strict_types=1);

$forum      = is_array($forum ?? null) ? $forum : [];
$result     = is_array($result ?? null) ? $result : ['items' => []];
$items      = is_array($result['items'] ?? null) ? $result['items'] : [];
$filters    = is_array($filters ?? null) ? $filters : [];
$favorited  = is_array($favorited ?? null) ? $favorited : [];
$subForums  = is_array($subForums ?? null) ? $subForums : [];
$canCreate  = (bool)($canCreate ?? false);

$forumId     = (int)($forum['id'] ?? 0);
$essenceOnly = !empty($filters['essence']);
$keyword     = (string)($filters['keyword'] ?? '');
$announce    = trim((string)($forum['announcement'] ?? ''));

/* 保留筛选条件，切换 tab 时不丢失关键词 */
$tabAll      = url('/f/' . $forumId, array_filter(['q' => $keyword]));
$tabEssence  = url('/f/' . $forumId, array_filter(['q' => $keyword, 'essence' => 1]));
?>
<?php /* 与首页同款右栏：前台（除个人管理页面外）统一用 partials/sidebar */ ?>
<div class="page-grid">
    <div>
    <?php if ((string)($forum['description'] ?? '') !== ''): ?>
        <section class="panel ow-mb-4">
            <div class="panel__head">
                <h2><?= e((string)($forum['name'] ?? '')) ?></h2>
            </div>
            <div class="panel__body">
                <p style="margin:0;color:var(--qq-ink-2)"><?= e((string)$forum['description']) ?></p>
                <?php
                /*
                 * 版块公告不在前台渲染了（$announce 仍参与下方的判断，但不再输出）。
                 * 公告改由通知系统分发（kind = system），用户在「通知」里查看。
                 */
                ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel">
        <div class="thread-toolbar">
            <nav class="filter-tabs" aria-label="帖子筛选">
                <a href="<?= e($tabAll) ?>" <?= $essenceOnly ? '' : 'aria-current="true"' ?>>全部帖子</a>
                <a href="<?= e($tabEssence) ?>" <?= $essenceOnly ? 'aria-current="true"' : '' ?>>精华</a>
            </nav>

            <span class="spacer"></span>

            <?php if ($canCreate): ?>
                <a class="ow-button ow-small" href="<?= e(url('/new', ['fid' => $forumId])) ?>">
                    <?= $view('partials/icon', ['name' => 'plus', 'size' => 15]) ?>
                    <span>发表帖子</span>
                </a>
            <?php endif; ?>
        </div>

        <?php if ($items === []): ?>
            <div class="empty">
                <?= $view('partials/icon', ['name' => 'file', 'size' => 46]) ?>
                <p><?= $keyword !== '' ? '没有找到匹配的帖子。' : '该版块还没有帖子，来发第一帖吧。' ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($items as $thread): ?>
                <?= $view('partials/thread-item', [
                    'thread'     => $thread,
                    'showForum'  => false,
                    'favorited'  => isset($favorited[(int)($thread['id'] ?? 0)]),
                ]) ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if ($subForums !== []): ?>
        <section class="panel ow-mt-4">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'layers', 'size' => 16]) ?>子版块</h3>
            </div>
            <?php foreach ($subForums as $child): ?>
                <div class="forum-row">
                    <span class="forum-row__icon"><?= $view('partials/icon', ['name' => 'message', 'size' => 22]) ?></span>
                    <div style="min-width:0">
                        <a class="forum-row__name" href="<?= e(url('/f/' . (int)$child['id'])) ?>">
                            <?= e((string)($child['name'] ?? '')) ?>
                        </a>
                        <?php if ((string)($child['description'] ?? '') !== ''): ?>
                            <p class="forum-row__desc"><?= e((string)$child['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="forum-row__meta">
                        <div>
                            <strong><?= format_number((int)($child['thread_count'] ?? 0)) ?></strong>
                            <span>帖子</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (($pagination ?? '') !== ''): ?>
        <div class="pager"><?= (string)$pagination ?></div>
    <?php endif; ?>
    </div>

    <?= $view('partials/sidebar') ?>
</div>
