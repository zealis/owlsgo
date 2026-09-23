<?php
/**
 * 认证页布局（登录 / 注册）
 *
 * 居中卡片式布局，不包含站点头部与页脚，避免分散注意力。
 */

declare(strict_types=1);

$title    = (string)($pageTitle ?? '') !== '' ? (string)$pageTitle : (string)($siteName ?? 'owlsgo');
$subtitle = (string)($authSubtitle ?? '');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <?php /* 品牌蓝固定：后台「外观」取色器已下线，配色由 theme.css 固定令牌控制 */ ?>
    <meta name="theme-color" content="#00A0E9">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(\Core\Brand::url()) ?>">
    <?php /* 深浅色引导：必须在样式表之前同步执行（不能 defer），否则深色用户会闪一帧白底 */ ?>
    <script src="<?= e(asset('assets/js/theme-boot.js')) ?>"></script>
    <?= $view('partials/head-critical-css') ?>
    <link rel="stylesheet" href="<?= e(asset('assets/ui/ui.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="brand__mark" aria-hidden="true"><?= \Core\Brand::inlineSvg() ?></span>
            <h1><?= e((string)($siteName ?? 'owlsgo')) ?></h1>
            <?php if ($subtitle !== ''): ?>
                <p><?= e($subtitle) ?></p>
            <?php endif; ?>
        </div>

        <?php
        /*
         * 认证页不出「请检查以下问题」汇总块：错误已经显示在各自输入框下方，
         * 再来一个汇总块属于同一件事提示两遍。toast 与无 JS 兜底仍保留。
         */
        ?>
        <?= $view('partials/flash', ['showFieldErrors' => false]) ?>
        <?= $content ?? '' ?>
    </div>
</div>

<?php
/*
 * 先加载 ui.css 模块：app.js 的 notify() 依赖 window.ot.toast，
 * 缺少它登录/注册的提示会退化成浏览器原生 alert。
 */
?>
<script src="<?= e(asset('assets/ui/ui.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
