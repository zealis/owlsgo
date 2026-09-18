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
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/oat/oat.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="brand__mark" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                    <ellipse cx="12" cy="13" rx="7.5" ry="8"/>
                    <circle cx="9.4" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
                    <circle cx="14.6" cy="11.4" r="2.4" fill="currentColor" stroke="none"/>
                    <path d="M12 15.4l1.7 2.2h-3.4z" fill="currentColor" stroke="none"/>
                </svg>
            </span>
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
