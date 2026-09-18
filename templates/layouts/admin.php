<?php
/**
 * 后台布局
 *
 * 使用 OATUI 的侧栏布局（data-sidebar-layout）：窄屏自动收起为抽屉式侧栏，
 * 由 [data-sidebar-toggle] 按钮控制显隐（逻辑在 oat 的 sidebar.js 中）。
 *
 * 变量：$content、$adminNav（已按权限过滤的导航项）、$adminTitle、$adminSubtitle、$currentUser
 */

declare(strict_types=1);

$nav     = isset($adminNav) && is_array($adminNav) ? $adminNav : [];
$path    = isset($currentPath) ? (string)$currentPath : '/';
$title   = (string)($adminTitle ?? '');
$sub     = (string)($adminSubtitle ?? '');
$docName = (string)($pageTitle ?? '管理后台');
$user    = $currentUser ?? null;

/** 判断导航项是否为当前页：精确匹配，或当前路径位于该导航项的下一级 */
$isCurrent = static function (string $url) use ($path): bool {
    if ($url === '/admin') {
        return $path === '/admin';
    }

    return $path === $url || str_starts_with($path, $url . '/');
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($docName) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#00A0E9">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/oat/oat.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">

    <?php if (\Core\PluginManager::hasAssets()): ?>
        <link rel="stylesheet" href="<?= e(url('/plugin-assets/css')) ?>">
    <?php endif; ?>

    <?php
    /* 插件追加的 <head> 资源（与前台布局保持一致，返回 HTML 字符串） */
    echo (string)hook('head_assets', '');
    ?>
</head>
<body data-sidebar-layout="always">
<nav data-topnav>
    <button type="button" data-sidebar-toggle aria-label="收起或展开侧栏">
        <?= $view('partials/icon', ['name' => 'menu', 'size' => 18]) ?>
    </button>

    <?php /*
     * 这里原来还显示当前页面名（「概览」「附件管理」…），已移除：
     * 侧栏本就高亮了当前项、浏览器标签页也有标题，顶栏再放一遍是重复信息，
     * 而且会把左侧空间占掉。$title / $sub 仍由控制器传入，保留给插件/其它布局取用。
     */ ?>

    <span class="spacer" style="margin-left:auto"></span>

    <a class="button ghost small" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">
        <span>访问前台</span>
    </a>

    <?php if ($user !== null): ?>
        <ot-dropdown>
            <button type="button" class="user-chip" popovertarget="admin-user-menu" aria-haspopup="menu">
                <?= avatar_img($user, 24) ?>
                <span><?= e((string)($user['username'] ?? '')) ?></span>
                <?= $view('partials/icon', ['name' => 'chevron-down', 'size' => 13]) ?>
            </button>

            <menu id="admin-user-menu" popover>
                <li>
                    <a role="menuitem" href="<?= e(url('/u/' . (int)$user['id'])) ?>">
                        <?= $view('partials/icon', ['name' => 'user']) ?>
                        <span>我的主页</span>
                    </a>
                </li>
                <li>
                    <a role="menuitem" href="<?= e(url('/settings')) ?>">
                        <?= $view('partials/icon', ['name' => 'settings']) ?>
                        <span>账号设置</span>
                    </a>
                </li>
                <hr>
                <li>
                    <a role="menuitem" href="<?= e(url('/logout')) ?>">
                        <?= $view('partials/icon', ['name' => 'logout']) ?>
                        <span>退出登录</span>
                    </a>
                </li>
            </menu>
        </ot-dropdown>
    <?php endif; ?>
</nav>

<aside data-sidebar>
    <header class="sidebar__brand">
        <a class="brand" href="<?= e(url('/admin')) ?>">
            <span class="brand__mark" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                    <ellipse cx="12" cy="13" rx="7.5" ry="8"/>
                    <circle cx="9.4" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
                    <circle cx="14.6" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
                    <path d="M12 15.4l1.7 2.2h-3.4z" fill="currentColor" stroke="none"/>
                </svg>
            </span>
            <span>管理后台</span>
        </a>
    </header>

    <nav aria-label="后台导航">
        <ul>
            <?php foreach ($nav as $item): ?>
                <?php $url = (string)$item['url']; ?>
                <li>
                    <a href="<?= e(url($url)) ?>" <?= $isCurrent($url) ? 'aria-current="page"' : '' ?>>
                        <?= $view('partials/icon', ['name' => (string)($item['icon'] ?? 'dot'), 'size' => 17]) ?>
                        <span><?= e((string)$item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <footer>
        <a class="button ghost small w-100" href="<?= e(url('/')) ?>">
            <?= $view('partials/icon', ['name' => 'arrow-left', 'size' => 15]) ?>
            <span>返回前台</span>
        </a>
    </footer>
</aside>

<main>
    <div class="admin-main">
        <?= $view('partials/flash') ?>
        <?= $content ?? '' ?>
    </div>
</main>

<script type="module" src="<?= e(asset('assets/oat/js/index.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>

<?php if (\Core\PluginManager::hasAssets()): ?>
    <script type="module" src="<?= e(url('/plugin-assets/js')) ?>"></script>
<?php endif; ?>

<?php
/* 插件追加的 </body> 前资源（与前台布局保持一致） */
echo (string)hook('footer_assets', '');
?>

<!-- 全局确认对话框：app.js 的 uiConfirm() 使用，替代浏览器原生 confirm -->
<dialog id="app-confirm" class="confirm-dialog">
    <div class="confirm-dialog__body">
        <p class="confirm-dialog__message" data-confirm-message></p>
    </div>
    <div class="confirm-dialog__actions">
        <button type="button" class="button ghost" data-confirm-cancel>取消</button>
        <button type="button" class="button" data-variant="danger" data-confirm-ok>确定</button>
    </div>
</dialog>
</body>
</html>
