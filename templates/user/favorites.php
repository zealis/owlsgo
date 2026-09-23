<?php
/**
 * 我的收藏
 *
 * 变量：$profile、$result（items 已 decorate，含 favorited_at）、$pagination
 */

declare(strict_types=1);

$profile = is_array($profile ?? null) ? $profile : [];
$result  = is_array($result ?? null) ? $result : ['items' => []];
$items   = is_array($result['items'] ?? null) ? $result['items'] : [];
$userId  = (int)($profile['id'] ?? 0);
?>

<?= $view('partials/profile-head', ['profile' => $profile, 'active' => 'favorites']) ?>

<section class="panel ow-mt-4">
    <div class="panel__head">
        <h3>我的收藏</h3>
        <span class="spacer"></span>
        <span class="ow-text-light" style="font-size:13px">仅你本人可见 · 共 <?= (int)($result['total'] ?? 0) ?> 条</span>
    </div>
    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'bookmark', 'size' => 46]) ?>
            <p>收藏夹是空的，在帖子页点击「收藏」即可加入。</p>
        </div>
    <?php else: ?>
        <?php foreach ($items as $thread): ?>
            <?= $view('partials/thread-item', ['thread' => $thread, 'showForum' => true, 'favorited' => true]) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if (($pagination ?? '') !== ''): ?>
    <div class="pager"><?= (string)$pagination ?></div>
<?php endif; ?>
