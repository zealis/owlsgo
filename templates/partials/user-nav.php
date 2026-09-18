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

$viewer    = auth_user();
$isOwner   = $viewer !== null && (int)($viewer['id'] ?? 0) === $navId;
$canManage = can('user.manage');

/*
 * 「我的隐私」：作者把「发表的主题 / 发表的回复」设为仅自己可见时，
 * 对他人（以及没有 user.manage 权限的人）隐藏这两个入口。
 * 后端也有同样的把关（UserController::assertTabVisible），
 * 这里只是不让入口露出来。
 */
$navPrivacy = \Modules\User\UserModel::privacyOf($navProfile);

if (!$isOwner && !$canManage) {
    $items = array_values(array_filter($items, static function (array $item) use ($navPrivacy): bool {
        return match ((string)($item['key'] ?? '')) {
            'threads' => $navPrivacy['threads'],
            'posts'   => $navPrivacy['posts'],
            default   => true,
        };
    }));
}

/* 收藏夹是私密内容，仅本人与管理员可见，因此只对本人展示入口 */
if ($isOwner || $canManage) {
    $items[] = ['key' => 'favorites', 'label' => '我的收藏', 'url' => '/u/' . $navId . '/favorites'];
}

/*
 * 「账号设置」是本人专属入口：只有访问自己的用户中心时才出现。
 * 它固定指向当前登录者自己的 /settings，若在他人主页也渲染出来，
 * 点一下就会「跳到自己的设置页」，既突兀又容易误解成在改别人的资料。
 */
if ($isOwner) {
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
