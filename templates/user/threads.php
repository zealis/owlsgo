<?php
/**
 * Ta 发表的主题
 *
 * 变量：$profile、$result（items 已 decorate）、$pagination
 */

declare(strict_types=1);

$profile = is_array($profile ?? null) ? $profile : [];
$result  = is_array($result ?? null) ? $result : ['items' => []];
$items   = is_array($result['items'] ?? null) ? $result['items'] : [];
$userId  = (int)($profile['id'] ?? 0);
?>

<?= $view('partials/profile-hero', ['profile' => $profile]) ?>

<?= $view('partials/user-nav', ['userNavProfile' => $profile, 'userNavActive' => 'threads']) ?>

<section class="panel mt-4">
    <div class="panel__head">
        <h3>发表的主题</h3>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">共 <?= (int)($result['total'] ?? 0) ?> 条</span>
    </div>
    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'file', 'size' => 46]) ?>
            <p>该用户还没有发表过主题。</p>
        </div>
    <?php else: ?>
        <?php foreach ($items as $thread): ?>
            <?= $view('partials/thread-item', ['thread' => $thread, 'showForum' => true, 'favorited' => false]) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if (($pagination ?? '') !== ''): ?>
    <div class="mt-4"><?= (string)$pagination ?></div>
<?php endif; ?>
