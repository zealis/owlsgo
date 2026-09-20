<?php
/**
 * 前台主布局
 *
 * 变量：$content（页面主体）、$pageTitle、$currentUser、$siteName、$siteNotice 等
 * 布局由 Core\View::render() 自动套用，控制器无需显式指定。
 */

declare(strict_types=1);

$navUser     = $currentUser ?? null;
$unreadCount = 0;

if ($navUser !== null) {
    $unreadCount = \Modules\User\NotificationModel::unreadCount((int)$navUser['id']);
}

$title       = (string)($pageTitle ?? '') !== '' ? (string)$pageTitle : (string)($siteName ?? 'owlsgo');
$description = (string)setting('site_description', '');
$keywords    = (string)setting('site_keywords', '');
$icp         = (string)setting('site_icp', '');
$notice      = trim((string)($siteNotice ?? ''));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>

    <?php if ($description !== ''): ?>
        <meta name="description" content="<?= e(mb_substr($description, 0, 160)) ?>">
    <?php endif; ?>
    <?php if ($keywords !== ''): ?>
        <meta name="keywords" content="<?= e($keywords) ?>">
    <?php endif; ?>

    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <?php /* 品牌蓝固定：后台「外观」取色器已下线，配色由 theme.css 固定令牌控制 */ ?>
    <meta name="theme-color" content="#00A0E9">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/favicon.svg')) ?>">
    <?php /* 深浅色引导：必须在样式表之前同步执行（不能 defer），否则深色用户会闪一帧白底 */ ?>
    <script src="<?= e(asset('assets/js/theme-boot.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('assets/oat/oat.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">

    <?php
    /* 插件合并样式（由 PluginManager::mergeAssets() 生成，未启用插件时文件不存在） */
    if (\Core\PluginManager::hasAssets()):
        ?>
        <link rel="stylesheet" href="<?= e(url('/plugin-assets/css')) ?>">
    <?php endif; ?>

    <?php
    /* 插件追加的 <head> 资源（hook('head_assets') 返回 HTML 字符串，由插件自行保证安全） */
    echo (string)hook('head_assets', '');
    ?>
</head>
<body>
<a class="skip-link" href="#main-content">跳到主要内容</a>

<?= $view('partials/header', ['currentUser' => $navUser, 'currentPath' => $currentPath ?? '/', 'siteName' => $siteName ?? 'owlsgo', 'unreadCount' => $unreadCount]) ?>

<?php
/*
 * 全站公告不在前台渲染了。
 * 公告改由通知系统分发（NotificationModel::broadcastSystem()，kind = system），
 * 用户在「通知」里查看；后台的「全站公告」设置项保留为公告的编辑入口。
 * $notice 变量仍保留在上下文，仅用于后台与后续可能的其它出口。
 */
?>

<main id="main-content">
    <div class="wrap page">
        <?= $view('partials/flash') ?>
        <?= $content ?? '' ?>
    </div>
</main>

<footer class="site-footer">
    <div class="wrap site-footer__inner">
        <span>
            &copy; <?= date('Y') ?>
            <a href="<?= e(url('/')) ?>"><?= e((string)($siteName ?? 'owlsgo')) ?></a>
        </span>

        <?php /* 备案号属于站点身份信息，与版权同在左侧一组 */ ?>
        <?php if ($icp !== ''): ?>
            <span>
                <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer nofollow"><?= e($icp) ?></a>
            </span>
        <?php endif; ?>

        <span class="spacer"></span>

        <span class="site-footer__links">
            <a href="<?= e(url('/search')) ?>">搜索</a>
            <?php if (is_logged_in()): ?>
                <a href="<?= e(url('/notifications')) ?>">通知</a>
            <?php endif; ?>
            <a href="<?= e(url('/')) ?>">返回顶部</a>
        </span>
    </div>
</footer>

<script type="module" src="<?= e(asset('assets/oat/js/index.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php if (\Core\PluginManager::hasAssets()): ?>
    <script type="module" src="<?= e(url('/plugin-assets/js')) ?>"></script>
<?php endif; ?>

<?php
/* 插件追加的 </body> 前资源（hook('footer_assets') 返回 HTML 字符串，由插件自行保证安全） */
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
