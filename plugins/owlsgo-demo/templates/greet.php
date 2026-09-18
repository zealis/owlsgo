<?php
/**
 * 示例插件前台页面：/hello/{name}
 *
 * 演示「带路由参数的插件页面」以及「同一插件被导航 / 个人中心标签页链接过来」的场景。
 *
 * 变量：$name、$greeting、$isSelf、$homeUrl
 */

declare(strict_types=1);

$name     = (string)($name ?? '');
$greeting = (string)($greeting ?? '');
$isSelf   = (bool)($isSelf ?? false);
$homeUrl  = (string)($homeUrl ?? url('/hello'));
?>

<ol class="crumbs">
    <li><a href="<?= e(url('/')) ?>">首页</a></li>
    <li aria-hidden="true">/</li>
    <li><a href="<?= e($homeUrl) ?>">插件示例</a></li>
    <li aria-hidden="true">/</li>
    <li><?= e($name) ?></li>
</ol>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'bulb', 'size' => 16]) ?>打招呼</h3>
        <span class="spacer"></span>
        <?php if ($isSelf): ?>
            <span class="badge">这是你自己的主页</span>
        <?php endif; ?>
    </div>

    <div class="panel__body">
        <p style="font-size:17px;font-weight:600;margin:0 0 8px">
            <span data-owlsgo-demo-greeting><?= e($greeting) ?></span>
            <span style="color:var(--qq-blue-deep)"><?= e($name) ?></span>
        </p>

        <p class="text-light" style="margin:0">
            页面地址中的 <code>{name}</code> 是路由占位符，由
            <code>Plugin::route('GET', '/hello/{name}', ...)</code> 声明，
            核心会把匹配到的 <code>name</code> 作为 <code>$params['name']</code> 传给处理器。
            你也可以从个人主页的「插件标签页」点进来。
        </p>

        <div class="hstack mt-4">
            <a class="button small" href="<?= e($homeUrl) ?>">
                <?= $view('partials/icon', ['name' => 'arrow-left', 'size' => 15]) ?>
                <span>返回插件首页</span>
            </a>
        </div>
    </div>
</section>
