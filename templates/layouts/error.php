<?php
/**
 * 错误页布局（自包含）
 *
 * 与前台/后台布局的区别：
 *  - 不渲染站点头部、导航与页脚，避免在 4xx/5xx 场景下引入额外查询或二次异常
 *  - 只依赖「有默认值」的站点设置，数据库不可用时也能正常输出
 *
 * 变量：$content、$pageTitle
 */

declare(strict_types=1);

$title = (string)($pageTitle ?? '出错了');

/* 站点名称可能因数据库不可用而取不到，这里允许为空 */
$brand = '';
try {
    $brand = (string)setting('site_name', '');
} catch (\Throwable) {
    $brand = '';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(\Core\Brand::url()) ?>">
    <?php /* 深浅色引导：必须在样式表之前同步执行（不能 defer），否则深色用户会闪一帧白底 */ ?>
    <script src="<?= e(asset('assets/js/theme-boot.js')) ?>"></script>
    <?= $view('partials/head-critical-css') ?>
    <link rel="stylesheet" href="<?= e(asset('assets/oat/oat.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="brand__mark" aria-hidden="true"><?= \Core\Brand::inlineSvg() ?></span>
            <h1><?= e($brand !== '' ? $brand : 'owlsgo') ?></h1>
        </div>

        <?= $content ?? '' ?>
    </div>
</div>

<?php /* 与其它布局一致：先加载 OATUI 模块，notify() 才能用上 toast 而不是原生 alert */ ?>
<script type="module" src="<?= e(asset('assets/oat/js/index.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
