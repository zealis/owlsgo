<?php
/**
 * 后台「内容管理」的页签：帖子 / 回帖 / 回收站
 *
 * 与首页、个人中心用的是**同一个 `.tabbar` 组件**（本站只此一套页签样式）。
 * 这里用 `<a>` + `aria-current="page"` 而不是 ui.css 的 `<button role="tab">`：
 * 三个页签是三个**独立页面**（各自有搜索框、翻页和批量操作），点它是跳转而非切换面板，
 * 用链接才能让 URL 可分享、可刷新、可收藏。
 *
 * 传入：$tabsActive（threads | posts | recycle）
 */

declare(strict_types=1);

$tabsActive = (string)($tabsActive ?? 'threads');

$tabs = [
    'threads' => ['label' => '帖子', 'url' => '/admin/threads'],
    'posts'   => ['label' => '回帖', 'url' => '/admin/posts'],
    'recycle' => ['label' => '回收站', 'url' => '/admin/recycle'],
];
?>
<nav class="tabbar" aria-label="内容管理切换">
    <?php foreach ($tabs as $key => $tab): ?>
        <a href="<?= e(url($tab['url'])) ?>"
           <?= $tabsActive === $key ? 'aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
    <?php endforeach; ?>
</nav>
