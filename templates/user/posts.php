<?php
/**
 * Ta 发表的评论
 *
 * 变量：$profile、$result（items 已 decorate，含 thread_title）、$pagination
 */

declare(strict_types=1);

$profile = is_array($profile ?? null) ? $profile : [];
$result  = is_array($result ?? null) ? $result : ['items' => []];
$items   = is_array($result['items'] ?? null) ? $result['items'] : [];
?>

<?= $view('partials/profile-head', ['profile' => $profile, 'active' => 'posts']) ?>

<section class="panel ow-mt-4">
    <div class="panel__head">
        <h3>发表的评论</h3>
        <span class="spacer"></span>
        <span class="ow-text-light" style="font-size:13px">共 <?= (int)($result['total'] ?? 0) ?> 条</span>
    </div>
    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'reply', 'size' => 46]) ?>
            <p>该用户还没有发表过评论。</p>
        </div>
    <?php else: ?>
        <?php foreach ($items as $post): ?>
            <?php $threadId = (int)($post['thread_id'] ?? 0); ?>
            <article class="notice-item">
                <div class="notice-item__icon">
                    <?= $view('partials/icon', ['name' => 'message', 'size' => 17]) ?>
                </div>
                <div class="notice-item__body">
                    <div class="notice-item__text">
                        <a href="<?= e(url('/t/' . $threadId, ['p' => (int)($post['id'] ?? 0)])) ?>">
                            <?= e((string)($post['thread_title'] ?? '帖子已删除')) ?>
                        </a>
                        <?php if ((int)($post['floor'] ?? 0) > 0): ?>
                            <span class="ow-text-light" style="font-size:12.5px">· <?= (int)$post['floor'] ?> 楼</span>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:4px;color:var(--qq-ink-2);font-size:14px">
                        <?= e(plain_text((string)($post['content'] ?? ''), 160)) ?>
                    </div>

                    <time datetime="<?= e(date('c', (int)($post['created_at'] ?? 0))) ?>">
                        <?= e(human_time((int)($post['created_at'] ?? 0))) ?>
                    </time>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if (($pagination ?? '') !== ''): ?>
    <div class="pager"><?= (string)$pagination ?></div>
<?php endif; ?>
