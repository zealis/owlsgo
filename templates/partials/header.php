<?php
/**
 * 站点头部（含搜索、导航与用户菜单）
 *
 * 变量：$currentUser（当前登录用户或 null）、$currentPath（当前路径）、$siteName、$unreadCount
 *
 * 布局约定：
 *  - 左侧：logo → 导航（插件/管理员自定义链接 + 通知），不放「首页」「发帖」——
 *    首页由 logo 承担，导航栏不再重复；
 *  - 右侧：搜索框（登录与否都显示）→ 用户菜单 / 未登录时的「登录」「注册」；
 *  - 移动端（≤860px）：搜索框隐藏，改由独立的搜索图标承担入口；
 *    导航折叠为抽屉；已登录用户的头像保留在顶栏（popover 是 fixed 定位，移动端可用）；
 *  - 未登录时搜索框挂 data-require-login，由 app.js 拦截提交并就地提示「请登录后操作」。
 *
 * 无障碍与安全：
 *  - 移动端导航由 [data-nav-toggle] + [data-site-nav] 控制（见 app.js）
 *  - 用户区 = 「头像 + 用户名」直达个人主页的链接；旁边新增**圆形齿轮按钮**：
 *    OATUI <ot-dropdown>（menu[popover]，fixed 定位、移动端可用），第一项是深浅色切换
 *    （深色时显示「日间模式」、浅色时显示「夜间模式」，由 app.js 按当前状态回填文案），
 *    第二项直达「个性装扮」（夜间模式跟随系统等设置）。
 *    深浅色的实际生效逻辑见 public/assets/js/theme-boot.js（head 内同步执行，避免闪白）
 *    与 app.js 的 initThemeToggle()；偏好存 localStorage，登录与否都可用。
 */

declare(strict_types=1);

$navUser  = isset($currentUser) ? $currentUser : auth_user();
$path     = isset($currentPath) ? (string)$currentPath : (string)\Core\Request::path();
$siteName = isset($siteName) ? (string)$siteName : (string)setting('site_name', 'owlsgo');
$unread   = isset($unreadCount) ? (int)$unreadCount : ($navUser !== null
    ? \Modules\User\NotificationModel::unreadCount((int)$navUser['id'])
    : 0);

$isActive = static fn (string $prefix): bool => $path === $prefix
    || ($prefix !== '/' && str_starts_with($path, $prefix));

/* 顶栏搜索框回显的关键词与搜索控制器共用同一套清洗，保证两处一致 */
$keyword = \Core\Text::cleanSearchKeyword(
    isset($_GET['q']) && is_string($_GET['q']) ? (string)$_GET['q'] : ''
);

/*
 * 前台附加导航链接，来源有两处：
 *  1. 插件通过 Plugin::menu() 注册的菜单项（PluginManager 收集，此处渲染）
 *  2. nav_links 钩子对数组的增删改（插件可动态插入或移除链接）
 * 统一结构：['label' => 文案, 'url' => 目标, 'icon' => 图标名]
 */
$navExtras = [];

foreach (\Core\Plugin::menus() as $menu) {
    $navExtras[] = [
        'label' => (string)($menu['title'] ?? ''),
        'url'   => (string)($menu['url'] ?? ''),
        'icon'  => (string)($menu['icon'] ?? ''),
    ];
}

$navExtras = (array)hook('nav_links', $navExtras, ['user' => $navUser]);
?>
<header class="site-header">
    <div class="wrap site-header__bar">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand__mark" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                    <ellipse cx="12" cy="13" rx="7.5" ry="8"/>
                    <circle cx="9.4" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
                    <circle cx="14.6" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
                    <path d="M12 15.4l1.7 2.2h-3.4z" fill="currentColor" stroke="none"/>
                </svg>
            </span>
            <span><?= e($siteName ?? 'owlsgo') ?></span>
        </a>

        <!-- 左侧导航：自定义链接 + 通知（不再包含首页/发帖） -->
        <nav class="site-nav" id="site-nav" data-site-nav aria-label="主导航">
            <?php foreach ($navExtras as $extra): ?>
                <?php
                if (!is_array($extra)) {
                    continue;
                }

                $extraLabel = (string)($extra['label'] ?? '');
                $extraUrl   = (string)($extra['url'] ?? '');

                // 只渲染站内绝对路径或 http(s) 地址，阻断 javascript: 之类的伪协议
                if ($extraLabel === '' || preg_match('#^(?:https?://|/)#i', $extraUrl) !== 1) {
                    continue;
                }

                $extraPath = str_starts_with($extraUrl, '/') ? $extraUrl : '';
                ?>
                <a class="site-nav__link" href="<?= e($extraUrl) ?>"
                   <?= $extraPath !== '' && $isActive($extraPath) ? 'aria-current="page"' : '' ?>>
                    <?= $view('partials/icon', ['name' => (string)($extra['icon'] ?? '') !== '' ? (string)$extra['icon'] : 'layers']) ?>
                    <span><?= e($extraLabel) ?></span>
                </a>
            <?php endforeach; ?>

            <?php if ($navUser !== null): ?>
                <a class="site-nav__link" href="<?= e(url('/notifications')) ?>" <?= $isActive('/notifications') ? 'aria-current="page"' : '' ?>>
                    <?= $view('partials/icon', ['name' => 'bell']) ?>
                    <span>通知</span>
                    <span class="nav-dot" data-unread-badge <?= $unread > 0 ? '' : 'hidden' ?>><?= (int)$unread ?></span>
                </a>
            <?php else: ?>
                <!--
                    未登录的登录 / 注册渲染两份，用 CSS 按视口二选一：
                    - .header-auth（顶栏右侧，搜索框旁）：桌面显示、移动端隐藏；
                    - .auth-drawer-only（本抽屉内）：移动端显示、桌面隐藏。
                    这样桌面用户在搜索框右侧看到它们，移动端用户在汉堡抽屉里看到。
                -->
                <a class="site-nav__link auth-drawer-only" href="<?= e(url('/login')) ?>" <?= $isActive('/login') ? 'aria-current="page"' : '' ?>>
                    <span>登录</span>
                </a>
                <a class="site-nav__link auth-drawer-only" href="<?= e(url('/register')) ?>" <?= $isActive('/register') ? 'aria-current="page"' : '' ?>>
                    <span>注册</span>
                </a>
            <?php endif; ?>
        </nav>

        <!--
            搜索框：登录与否都显示。
            未登录时挂 data-require-login，由 app.js 拦截提交并就地提示「请登录后操作」；
            若 JS 不可用则照常提交，由 ThreadController::search 的 requireLogin() 兜底跳登录。
        -->
        <form class="search-box search-box--nav" method="get" action="<?= e(url('/search')) ?>" role="search"
              <?= $navUser === null ? 'data-require-login' : '' ?>>
            <input type="search" name="q" value="<?= e($keyword) ?>" placeholder="搜索"
                   aria-label="站内搜索" autocomplete="off">
            <button type="submit" aria-label="搜索">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 17]) ?>
            </button>
        </form>

        <?php if ($navUser === null): ?>
            <!-- 未登录：登录 / 注册放在搜索框右边（同为无背景的文字链接） -->
            <a class="site-nav__link header-auth" href="<?= e(url('/login')) ?>" <?= $isActive('/login') ? 'aria-current="page"' : '' ?>>
                <span>登录</span>
            </a>
            <a class="site-nav__link header-auth" href="<?= e(url('/register')) ?>" <?= $isActive('/register') ? 'aria-current="page"' : '' ?>>
                <span>注册</span>
            </a>
        <?php endif; ?>

        <!-- 移动端搜索入口：与搜索框一样始终显示 -->
        <a class="nav-search-btn" href="<?= e(url('/search')) ?>"
           <?= $navUser === null ? 'data-require-login' : '' ?> aria-label="搜索">
            <?= $view('partials/icon', ['name' => 'search', 'size' => 18]) ?>
        </a>

        <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="site-nav"
                aria-label="展开导航">
            <?= $view('partials/icon', ['name' => 'menu', 'size' => 19]) ?>
        </button>

        <?php if ($navUser !== null): ?>
            <?php
            /*
             * 用户区：头像 + 用户名整块就是「我的主页」入口（不加 title 提示——
             * 悬浮气泡会挡住旁边的元素）。收藏 / 账号设置走个人主页顶部
             * 的用户导航；「安全退出」在账号设置页底部。
             */
            ?>
            <a class="user-chip" href="<?= e(url('/u/' . (int)$navUser['id'])) ?>">
                <?= avatar_img($navUser, 26) ?>
                <span><?= e((string)($navUser['username'] ?? '')) ?></span>
            </a>
        <?php endif; ?>

        <?php /* 个性化齿轮（与后台顶栏共用同一 partial）：深浅色切换 + 个性装扮入口 */ ?>
        <?= $view('partials/user-gear') ?>
    </div>
</header>
