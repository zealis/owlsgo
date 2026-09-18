<?php
/**
 * 用户中心子导航
 *
 * 传入：$userNavProfile（用户数组，取 id 用于拼接链接）、$userNavActive（当前项：home|threads|posts|favorites）
 */

declare(strict_types=1);

$navProfile = is_array($userNavProfile ?? null) ? $userNavProfile : [];
$navActive  = (string)($userNavActive ?? 'home');
$navId      = (int)($navProfile['id'] ?? 0);

$items = [
    ['key' => 'home',      'label' => '个人主页', 'url' => '/u/' . $navId],
    ['key' => 'threads',   'label' => '发表的主题', 'url' => '/u/' . $navId . '/threads'],
    ['key' => 'posts',     'label' => '发表的回复', 'url' => '/u/' . $navId . '/posts'],
];

/* 收藏夹是私密内容，仅本人与管理员可见，因此只对本人展示入口 */
$viewer = auth_user();
if ($viewer !== null && ((int)($viewer['id'] ?? 0) === $navId || can('user.manage'))) {
    $items[] = ['key' => 'favorites', 'label' => '我的收藏', 'url' => '/u/' . $navId . '/favorites'];
}

if (is_logged_in()) {
    $items[] = ['key' => 'settings', 'label' => '账号设置', 'url' => '/settings'];
}

/*
 * 插件可增删改用户中心标签页（Plugin::filter('user_profile_tabs', ...)）。
 * 结构：[['key' => 唯一键, 'label' => 文案, 'url' => 站内路径或完整地址], ...]
 */
$items = (array)hook('user_profile_tabs', $items, ['profile' => $navProfile, 'active' => $navActive]);
?>
<nav class="settings-tabs" aria-label="用户中心导航">
    <div class="filter-tabs">
        <?php foreach ($items as $item): ?>
            <?php
            if (!is_array($item)) {
                continue;
            }

            $itemLabel = (string)($item['label'] ?? '');
            $itemUrl   = (string)($item['url'] ?? '');

            if ($itemLabel === '' || $itemUrl === '') {
                continue;
            }
            ?>
            <a href="<?= e(url($itemUrl)) ?>"
               <?= $navActive === (string)($item['key'] ?? '') ? 'aria-current="page"' : '' ?>>
                <?= e($itemLabel) ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
