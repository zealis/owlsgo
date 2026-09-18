<?php
/**
 * 首页：版块总览 + 站点统计 + 最新/热门主题
 *
 * 变量：$tree（版块树）、$stats（站点统计）、$latest、$hot、$canPost
 */

declare(strict_types=1);

$tree   = is_array($tree ?? null) ? $tree : [];
$stats  = is_array($stats ?? null) ? $stats : ['forums' => 0, 'threads' => 0, 'posts' => 0, 'users' => 0];
$latest = is_array($latest ?? null) ? $latest : [];
$hot    = is_array($hot ?? null) ? $hot : [];

/* 版块图标名：模板变量可能为空或不是内置图标，交给 icon 局部模板兜底为 dot */
$forumIcon = static function (array $forum): string {
    $icon = trim((string)($forum['icon'] ?? ''));

    return $icon !== '' ? $icon : 'message';
};
?>

<div class="stat-strip">
    <div class="stat">
        <span class="stat__value"><?= format_number((int)($stats['forums'] ?? 0)) ?></span>
        <span class="stat__label">开放版块</span>
    </div>
    <div class="stat">
        <span class="stat__value"><?= format_number((int)($stats['threads'] ?? 0)) ?></span>
        <span class="stat__label">主题总数</span>
    </div>
    <div class="stat">
        <span class="stat__value"><?= format_number((int)($stats['posts'] ?? 0)) ?></span>
        <span class="stat__label">回复总数</span>
    </div>
    <div class="stat">
        <span class="stat__value"><?= format_number((int)($stats['users'] ?? 0)) ?></span>
        <span class="stat__label">注册会员</span>
    </div>
</div>

<div class="page-grid">
    <div>
        <?php if ($tree === []): ?>
            <div class="panel">
                <div class="empty">
                    <?= $view('partials/icon', ['name' => 'grid', 'size' => 46]) ?>
                    <p>还没有任何版块，请先到后台创建版块。</p>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($tree as $node): ?>
            <?php
            $forum    = is_array($node['forum'] ?? null) ? $node['forum'] : [];
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $forumId  = (int)($forum['id'] ?? 0);
            ?>
            <section class="panel forum-group">
                <div class="forum-group__title">
                    <?= $view('partials/icon', ['name' => 'layers', 'size' => 17]) ?>
                    <a href="<?= e(url('/f/' . $forumId)) ?>" style="color:inherit"><?= e((string)($forum['name'] ?? '')) ?></a>
                </div>

                <?php
                /* 父版块自身也是一行，随后紧跟其子版块 */
                $rows = array_merge(
                    [['forum' => $forum, 'child' => false]],
                    array_map(static fn (array $child): array => ['forum' => $child, 'child' => true], $children)
                );
                ?>

                <?php foreach ($rows as $row): ?>
                    <?php
                    $item     = $row['forum'];
                    $itemId   = (int)($item['id'] ?? 0);
                    $isChild  = (bool)$row['child'];
                    $lastName = (string)($item['last_thread_name'] ?? '');
                    $lastAt   = (int)($item['last_reply_at'] ?? 0);
                    ?>
                    <div class="forum-row<?= $isChild ? ' forum-row--child' : '' ?>">
                        <span class="forum-row__icon">
                            <?= $view('partials/icon', ['name' => $forumIcon($item), 'size' => 22]) ?>
                        </span>

                        <div style="min-width:0">
                            <a class="forum-row__name" href="<?= e(url('/f/' . $itemId)) ?>">
                                <?= e((string)($item['name'] ?? '')) ?>
                            </a>
                            <?php if ((string)($item['description'] ?? '') !== ''): ?>
                                <p class="forum-row__desc"><?= e((string)$item['description']) ?></p>
                            <?php endif; ?>
                            <?php if ($lastName !== ''): ?>
                                <?php $lastThreadId = (int)($item['last_thread_id'] ?? 0); ?>
                                <p class="forum-row__desc" style="margin-top:4px">
                                    最后发表：<?php if ($lastThreadId > 0): ?><a href="<?= e(url('/t/' . $lastThreadId)) ?>" title="跳转到该主题"><?= e($lastName) ?></a><?php else: ?><?= e($lastName) ?><?php endif; ?>
                                    <span class="text-lighter">· <?= e(human_time($lastAt)) ?></span>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="forum-row__meta">
                            <div>
                                <strong><?= format_number((int)($item['thread_count'] ?? 0)) ?></strong>
                                <span>主题</span>
                            </div>
                            <div>
                                <strong><?= format_number((int)($item['post_count'] ?? 0)) ?></strong>
                                <span>回复</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <aside>
        <?php if (!empty($canPost)): ?>
            <a class="button w-100 mb-4" href="<?= e(url('/new')) ?>">
                <?= $view('partials/icon', ['name' => 'plus', 'size' => 17]) ?>
                <span>发表新主题</span>
            </a>
        <?php endif; ?>

        <section class="panel widget">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'activity', 'size' => 16]) ?>最新主题</h3>
            </div>
            <?php if ($latest === []): ?>
                <div class="empty" style="padding:26px 16px"><p>暂无主题</p></div>
            <?php else: ?>
                <ul class="widget__list">
                    <?php foreach ($latest as $thread): ?>
                        <li>
                            <a href="<?= e(url('/t/' . (int)($thread['id'] ?? 0))) ?>"
                               title="<?= e((string)($thread['title'] ?? '')) ?>">
                                <?= e((string)($thread['title'] ?? '')) ?>
                            </a>
                            <time datetime="<?= e(date('c', (int)($thread['created_at'] ?? 0))) ?>">
                                <?= e(human_time((int)($thread['created_at'] ?? 0))) ?>
                            </time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel widget">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'bulb', 'size' => 16]) ?>热门主题</h3>
            </div>
            <?php if ($hot === []): ?>
                <div class="empty" style="padding:26px 16px"><p>暂无主题</p></div>
            <?php else: ?>
                <ul class="widget__list">
                    <?php foreach ($hot as $thread): ?>
                        <li>
                            <a href="<?= e(url('/t/' . (int)($thread['id'] ?? 0))) ?>"
                               title="<?= e((string)($thread['title'] ?? '')) ?>">
                                <?= e((string)($thread['title'] ?? '')) ?>
                            </a>
                            <time><?= format_number((int)($thread['reply_count'] ?? 0)) ?> 回复</time>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </aside>
</div>
