<?php
/**
 * 安装向导布局
 *
 * 安装阶段数据表可能尚未建立，因此这里只读取「有默认值」的站点设置，
 * 且不引入任何依赖数据库的导航组件。
 */

declare(strict_types=1);

$title = (string)($pageTitle ?? '安装');
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(\Core\Brand::url()) ?>">
    <?php /* 深浅色引导：必须在样式表之前同步执行（不能 defer），否则深色用户会闪一帧白底 */ ?>
    <script src="<?= e(asset('assets/js/theme-boot.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('assets/ui/ui.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
</head>
<body>
<div class="auth-page">
    <div class="auth-card auth-card--wide auth-card--install">
        <div class="auth-brand">
            <span class="brand__mark" aria-hidden="true"><?= \Core\Brand::inlineSvg() ?></span>
        </div>

        <?= $view('partials/flash') ?>
        <?= $content ?? '' ?>
    </div>
</div>

<?php
/*
 * 必须先加载 ui.css 的模块脚本：app.js 的 notify() 依赖 window.ot.toast，
 * 缺了它就会退化成浏览器原生的 alert 弹窗（安装页的「测试连接 / 安装」结果就是这样）。
 */
?>
<script src="<?= e(asset('assets/ui/ui.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
