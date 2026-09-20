<?php
/**
 * 右侧栏（前台共用）
 *
 * 内容与首页右栏完全一致，全站只有这一份：
 *   ① 发表新帖子按钮（有 thread.create 权限才显示）
 *   ② 最新帖子（按最后评论时间倒序，取 8 条）
 *   ③ 热门帖子（按评论数 / 浏览数排序，取 6 条）
 *
 * 取值顺序（避免重复查库）：
 *   1. 调用方通过 `$view('partials/sidebar', ['latest' => …, 'hot' => …, 'canPost' => …])` 传入
 *      —— 首页已经把三个数据查好了，走这条路；
 *   2. 没传的字段回落到 Modules\Forum\Sidebar::data()（进程内缓存，一次请求只查一次）。
 *
 * 用法（页面需要与首页同款右栏时）：
 *   <div class="page-grid">
 *       <div>…主内容…</div>
 *       <?= $view('partials/sidebar') ?>
 *   </div>
 * 个人管理页面（用户中心 / 账号设置 / 通知中心）按设计**不带**右栏。
 */

declare(strict_types=1);

/*
 * 变量可能来自调用方，也可能完全没有 —— 用 ?? 判空，未定义时不会报错。
 * 只要有一个字段缺省，就整体走数据源（避免半传半查导致口径不一致）。
 */
$sideLatest  = $latest ?? null;
$sideHot     = $hot ?? null;
$sideCanPost = $canPost ?? null;

if (!is_array($sideLatest) || !is_array($sideHot) || $sideCanPost === null) {
    $sideData    = \Modules\Forum\Sidebar::data();
    $sideLatest  = is_array($sideLatest) ? $sideLatest : $sideData['latest'];
    $sideHot     = is_array($sideHot) ? $sideHot : $sideData['hot'];
    $sideCanPost = $sideCanPost === null ? $sideData['canPost'] : (bool)$sideCanPost;
}

$sideLatest = array_slice($sideLatest, 0, 8);
?>

<aside class="sidebar">
    <?php if ($sideCanPost): ?>
        <a class="button w-100 mb-4" href="<?= e(url('/new')) ?>">
            <?= $view('partials/icon', ['name' => 'plus', 'size' => 17]) ?>
            <span>发表新帖子</span>
        </a>
    <?php endif; ?>

    <section class="panel widget">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'activity', 'size' => 16]) ?>最新帖子</h3>
        </div>
        <?php if ($sideLatest === []): ?>
            <div class="empty" style="padding:26px 16px"><p>暂无帖子</p></div>
        <?php else: ?>
            <ul class="widget__list">
                <?php foreach ($sideLatest as $thread): ?>
                    <li>
                        <a href="<?= e(url('/t/' . (int)($thread['id'] ?? 0))) ?>"
                           title="<?= e((string)($thread['title'] ?? '')) ?>">
                            <?= e((string)($thread['title'] ?? '')) ?>
                        </a>
                        <time datetime="<?= e(date('c', (int)($thread['last_reply_at'] ?? 0))) ?>">
                            <?= e(human_time((int)($thread['last_reply_at'] ?? 0))) ?>
                        </time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel widget">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'bulb', 'size' => 16]) ?>热门帖子</h3>
        </div>
        <?php if ($sideHot === []): ?>
            <div class="empty" style="padding:26px 16px"><p>暂无帖子</p></div>
        <?php else: ?>
            <ul class="widget__list">
                <?php foreach ($sideHot as $thread): ?>
                    <li>
                        <a href="<?= e(url('/t/' . (int)($thread['id'] ?? 0))) ?>"
                           title="<?= e((string)($thread['title'] ?? '')) ?>">
                            <?= e((string)($thread['title'] ?? '')) ?>
                        </a>
                        <time><?= format_number((int)($thread['reply_count'] ?? 0)) ?> 评论</time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</aside>
