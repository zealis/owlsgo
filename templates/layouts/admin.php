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
    <?php /* 深浅色引导：必须在样式表之前同步执行（不能 defer），否则深色用户会闪一帧白底 */ ?>
    <script src="<?= e(asset('assets/js/theme-boot.js')) ?>"></script>
    <?= $view('partials/head-critical-css') ?>
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
        <?php
        /*
         * 用户区：头像 + 用户名整块就是「我的主页」入口，与前台顶栏保持同一种做法
         * （已取消下拉菜单，也不加 title 提示——悬浮气泡会挡住旁边的元素）。
         * 「账号设置」「安全退出」都在账号设置页里；后台入口在左侧导航，这里不再重复。
         *
         * 样式复用前台顶栏的全局规则（a.user-chip）——注意选择器带元素名，
         * 否则会被全局 `a:not(.button)` 的链接色覆盖。
         */
        ?>
        <a class="user-chip" href="<?= e(url('/u/' . (int)$user['id'])) ?>">
            <?= avatar_img($user, 26) ?>
            <span><?= e((string)($user['username'] ?? '')) ?></span>
        </a>
    <?php endif; ?>

    <?php /* 个性化齿轮（与前台顶栏共用同一 partial）：深浅色切换 + 个性装扮入口 */ ?>
    <?= $view('partials/user-gear') ?>
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
            <?php /*
             * 品牌名显示**站点名称**（与前台顶栏同一个 $siteName 共享变量），
             * 原来这里写死「管理后台」——后台是站点的一部分，用同一套品牌名才不会
             * 出现「前台叫 X、后台叫管理后台」的割裂感。
             * $siteName 来自 View::defaultData()，布局与前台 header.php 都能取到。
             */ ?>
            <span><?= e((string)($siteName ?? 'owlsgo')) ?></span>
        </a>
    </header>

    <nav aria-label="后台导航">
        <ul>
            <?php foreach ($nav as $item): ?>
                <?php
                $url      = (string)$item['url'];
                $children = isset($item['children']) && is_array($item['children']) ? $item['children'] : [];
                ?>
                <?php if ($children !== []): ?>
                    <?php
                    /*
                     * 分组项（如「站点设置」）：父项只是展开/收起的开关，子项才是页面链接。
                     * 用原生 <details> 而不是 JS 点击展开 —— 无脚本也能用，
                     * 而且「当前页所在分组默认展开」由 PHP 直接输出 open，不依赖任何前端状态。
                     */
                    $groupOpen = false;
                    foreach ($children as $child) {
                        if ($isCurrent((string)$child['url'])) {
                            $groupOpen = true;
                            break;
                        }
                    }
                    ?>
                    <li>
                        <details class="nav-group"<?= $groupOpen ? ' open' : '' ?>>
                            <summary>
                                <?= $view('partials/icon', ['name' => (string)($item['icon'] ?? 'dot'), 'size' => 17]) ?>
                                <span><?= e((string)$item['label']) ?></span>
                            </summary>
                            <?php /* 子项不再重复图标：同一组下 7 个图标只是噪音，缩进 + 引导线更清楚 */ ?>
                            <ul class="nav-sub">
                                <?php foreach ($children as $child): ?>
                                    <?php $childUrl = (string)$child['url']; ?>
                                    <li>
                                        <a href="<?= e(url($childUrl)) ?>" <?= $isCurrent($childUrl) ? 'aria-current="page"' : '' ?>>
                                            <span><?= e((string)$child['label']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </li>
                <?php else: ?>
                    <li>
                        <a href="<?= e(url($url)) ?>" <?= $isCurrent($url) ? 'aria-current="page"' : '' ?>>
                            <?= $view('partials/icon', ['name' => (string)($item['icon'] ?? 'dot'), 'size' => 17]) ?>
                            <span><?= e((string)$item['label']) ?></span>
                        </a>
                    </li>
                <?php endif; ?>
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

<?php
/*
 * 全局输入对话框：app.js 的 uiPrompt() 使用（后台「发布公告」页也用同一个编辑器）。
 * 字段由 JS 动态生成到 [data-prompt-body] 里，所以这里只放骨架。
 */
?>
<dialog id="app-prompt" class="confirm-dialog prompt-dialog">
    <p class="prompt-dialog__title" data-prompt-title></p>
    <div class="prompt-dialog__body" id="app-prompt-body"></div>
    <div class="confirm-dialog__actions">
        <button type="button" class="button ghost" data-prompt-cancel>取消</button>
        <button type="button" class="button" data-prompt-ok>确定</button>
    </div>
</dialog>
</body>
</html>
