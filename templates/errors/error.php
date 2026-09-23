<?php
/**
 * 错误页
 *
 * 变量：$code（HTTP 状态码）、$title（简短标题）、$message（说明文案）、$debug（可选，调试信息）
 *
 * 该模板同时被 Core\App::renderServerError 与 Core\Security::guardCsrf 复用，
 * 因此所有变量都做了兜底，避免某个入口少传字段时页面白屏。
 */

declare(strict_types=1);

$code    = (int)($code ?? 500);
$title   = (string)($title ?? '出错了');
$message = (string)($message ?? '');
$debug   = (bool)($debug ?? false);

$isOffline = $code >= 500;
$iconName  = match (true) {
    $code === 404              => 'search',
    $code === 403              => 'lock',
    $code === 401, $code === 419 => 'key',
    $code === 429              => 'clock',
    $isOffline                 => 'alert',
    default                    => 'info',
};
?>

<div class="ow-vstack ow-gap-4" style="text-align:center">
    <div style="font-size:52px;font-weight:700;line-height:1;color:var(--qq-blue-deep)"><?= $code ?></div>

    <div role="alert" data-ow-variant="<?= $isOffline ? 'error' : 'warning' ?>" style="text-align:left">
        <?= $view('partials/icon', ['name' => $iconName, 'size' => 18]) ?>
        <div>
            <strong><?= e($title) ?></strong>
            <?php if ($message !== ''): ?>
                <div style="margin-top:4px"><?= nl2br(e($message)) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ow-hstack ow-justify-center">
        <?php /* 站点的 CSP 只允许 'self'，因此不使用 javascript: 链接，改由 app.js 代理 */ ?>
        <button type="button" class="ow-button" data-history-back>
            <?= $view('partials/icon', ['name' => 'arrow-left', 'size' => 16]) ?>
            <span>返回上一页</span>
        </button>
        <a class="ow-button ow-outline" href="<?= e(url('/')) ?>">
            <?= $view('partials/icon', ['name' => 'home', 'size' => 16]) ?>
            <span>返回首页</span>
        </a>
    </div>

    <?php if ($debug && $message !== ''): ?>
        <pre class="snippet" style="text-align:left;white-space:pre-wrap"><?= e($message) ?></pre>
    <?php endif; ?>
</div>
