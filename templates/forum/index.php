<?php
/**
 * 首页：四个页签（新评论 / 新帖子 / 推荐 / 板块）+ 右侧栏
 *
 * 变量：$tree（版块树）、$newComments（按最后评论时间倒序的帖子）、
 *       $newThreads（按发布时间倒序的帖子）、$recommended、$hot、$canPost、$activeTab
 *
 * 页签是**链接**，不是 OATUI 的 <ot-tabs>：点页签 = 带 ?tab=N 的整页跳转，
 * 激活哪个面板由服务端决定（非激活的 [role="tabpanel"] 直接带 hidden）。
 * 这样「翻到第 2 页」和「当前页签」永远不会各说各话，也不需要任何本站 JS。
 *
 * 「新评论」列的是**帖子**（按最后评论时间排序），不是评论列表：
 * 谁刚被评论过，帖子就排到前面。
 */

declare(strict_types=1);

$tree        = is_array($tree ?? null) ? $tree : [];
$newComments = is_array($newComments ?? null) ? $newComments : [];
$homePaginator = is_string($homePaginator ?? null) ? $homePaginator : '';
$activeTab   = max(1, min(4, (int)($activeTab ?? 1)));
$newThreads  = is_array($newThreads ?? null) ? $newThreads : [];
$hot         = is_array($hot ?? null) ? $hot : [];

/* 版块图标名：模板变量可能为空或不是内置图标，交给 icon 局部模板兜底为 dot */
$forumIcon = static function (array $forum): string {
    $icon = trim((string)($forum['icon'] ?? ''));

    return $icon !== '' ? $icon : 'message';
};
?>

<div class="page-grid">
    <div>
        <?php /*
         * 整个左栏是一个「框」（.panel）：页签条做框头，四个页签的内容都在框内。
         *
         * ⚠️ 页签是**链接**（<a> + aria-current），不是 OATUI 的 <button role="tab">：
         * 点页签 = 带 ?tab=N 的整页跳转，选中态**完全由服务端**按 ?tab= 渲染，
         * 于是「在『新帖子』里点第 2 页重载后页签跳回『新评论』」这类错位从根上不存在
         * （原来是客户端组件按 DOM 顺序激活，页码和页签是两个互不知情的状态）。
         * 这与后台「内容管理」页签、用户中心导航是同一套做法（见 admin-content-tabs.php）。
         *
         * 「板块」页签没有翻页：它是版块总览，控制器在 tab=4 时不生成 $homePaginator。
         */ ?>
        <section class="panel home-panel">
            <div class="tabbar" role="tablist" aria-label="首页内容切换">
                <?php foreach ([1 => '新评论', 2 => '新帖子', 3 => '推荐', 4 => '板块'] as $tabIndex => $tabLabel): ?>
                    <?php /* 第一个页签用干净的 /，避免首页出现 /?tab=1 这种冗余地址 */ ?>
                    <?php $tabHref = $tabIndex === 1 ? url('/') : url('/', ['tab' => $tabIndex]); ?>
                    <a role="tab"
                       href="<?= e($tabHref) ?>"<?= $activeTab === $tabIndex ? ' aria-current="page"' : '' ?>><?= e($tabLabel) ?></a>
                <?php endforeach; ?>
            </div>

            <?php /* ---------- 新评论：最近被评论过的帖子 ---------- */ ?>
            <div role="tabpanel"<?= $activeTab === 1 ? '' : ' hidden' ?>>
                <?php if ($newComments === []): ?>
                    <div class="empty">
                        <?= $view('partials/icon', ['name' => 'message', 'size' => 46]) ?>
                        <p>还没有帖子。</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($newComments as $thread): ?>
                        <?= $view('partials/thread-item', [
                            'thread'    => $thread,
                            'showForum' => true,
                        ]) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php /* ---------- 新帖子：最新发布的帖子 ---------- */ ?>
            <div role="tabpanel"<?= $activeTab === 2 ? '' : ' hidden' ?>>
                <?php if ($newThreads === []): ?>
                    <div class="empty">
                        <?= $view('partials/icon', ['name' => 'file', 'size' => 46]) ?>
                        <p>还没有帖子。</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($newThreads as $thread): ?>
                        <?= $view('partials/thread-item', [
                            'thread'    => $thread,
                            'showForum' => true,
                        ]) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php /* ---------- 推荐：全站标记了「推荐」的帖子 ---------- */ ?>
            <div role="tabpanel"<?= $activeTab === 3 ? '' : ' hidden' ?>>
                <?php if (($recommended ?? []) === []): ?>
                    <div class="empty">
                        <?= $view('partials/icon', ['name' => 'star', 'size' => 46]) ?>
                        <p>还没有推荐帖子。</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recommended as $thread): ?>
                        <?= $view('partials/thread-item', [
                            'thread'    => $thread,
                            'showForum' => true,
                        ]) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php /* ---------- 板块：版块总览（首页原来的样子，只是不再各自套卡片） ---------- */ ?>
            <div role="tabpanel"<?= $activeTab === 4 ? '' : ' hidden' ?>>
                <?php if ($tree === []): ?>
                    <div class="empty">
                        <?= $view('partials/icon', ['name' => 'grid', 'size' => 46]) ?>
                        <p>还没有任何版块，请先到后台创建版块。</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($tree as $node): ?>
                <?php
                $forum    = is_array($node['forum'] ?? null) ? $node['forum'] : [];
                $children = is_array($node['children'] ?? null) ? $node['children'] : [];
                $forumId  = (int)($forum['id'] ?? 0);
                ?>
                <section class="forum-group">
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
                                        最后发表：<?php if ($lastThreadId > 0): ?><a href="<?= e(url('/t/' . $lastThreadId)) ?>" title="跳转到该帖子"><?= e($lastName) ?></a><?php else: ?><?= e($lastName) ?><?php endif; ?>
                                        <span class="text-lighter">· <?= e(human_time($lastAt)) ?></span>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="forum-row__meta">
                                <div>
                                    <strong><?= format_number((int)($item['thread_count'] ?? 0)) ?></strong>
                                    <span>帖子</span>
                                </div>
                                <div>
                                    <strong><?= format_number((int)($item['post_count'] ?? 0)) ?></strong>
                                    <span>评论</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            </div>
        </section>

        <?php /*
          翻页条放在白色卡片**外面**：卡片内原本那条 border-top 会让它看起来
          像是卡片的一部分（有白底、有分隔线），而楼层页 / 版块页的分页是独立的。
          这里与它们统一用 .pager —— 全站分页同一个样式。
          只输出一次：三个列表共用 $homePaginator，控制器按激活页签生成对应链接。
        */ ?>
        <?php if ($homePaginator !== ''): ?>
            <div class="pager"><?= (string)$homePaginator ?></div>
        <?php endif; ?>
    </div>

    <?php
    /*
     * 右栏（发表新帖子 + 最新帖子 + 热门帖子）已抽成共用 partial `partials/sidebar`：
     * 前台其它页面（版块页 / 帖子详情 / 搜索 …）用同一份，保证全站右栏一致。
     * 首页自己已经查好了数据，直接传进去，partial 不会再去查库。
     */
    ?>
    <?= $view('partials/sidebar', [
        'latest'  => $newComments,
        'hot'     => $hot,
        'canPost' => !empty($canPost),
    ]) ?>
</div>
