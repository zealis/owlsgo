<?php
/**
 * 个人主页
 *
 * 变量：$profile、$isSelf、$canManage、$recentThreads、$recentPosts
 */

declare(strict_types=1);

$profile       = is_array($profile ?? null) ? $profile : [];
$isSelf        = (bool)($isSelf ?? false);
$canManage     = (bool)($canManage ?? false);
$recentThreads = is_array($recentThreads ?? null) ? $recentThreads : [];
$recentPosts   = is_array($recentPosts ?? null) ? $recentPosts : [];

$userId    = (int)($profile['id'] ?? 0);
?>

<?= $view('partials/profile-hero', ['profile' => $profile]) ?>

<?= $view('partials/user-nav', ['userNavProfile' => $profile, 'userNavActive' => 'home']) ?>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'file', 'size' => 16]) ?>最近发表的主题</h3>
        <span class="spacer"></span>
        <a class="text-light" style="font-size:13px" href="<?= e(url('/u/' . $userId . '/threads')) ?>">全部 ›</a>
    </div>

    <?php if ($recentThreads === []): ?>
        <div class="empty" style="padding:34px 16px"><p>还没有发表过主题。</p></div>
    <?php else: ?>
        <?php foreach ($recentThreads as $thread): ?>
            <?= $view('partials/thread-item', ['thread' => $thread, 'showForum' => true, 'favorited' => false]) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'reply', 'size' => 16]) ?>最近发表的回复</h3>
        <span class="spacer"></span>
        <a class="text-light" style="font-size:13px" href="<?= e(url('/u/' . $userId . '/posts')) ?>">全部 ›</a>
    </div>

    <?php if ($recentPosts === []): ?>
        <div class="empty" style="padding:34px 16px"><p>还没有发表过回复。</p></div>
    <?php else: ?>
        <?php foreach ($recentPosts as $post): ?>
            <article class="notice-item">
                <div class="notice-item__icon"><?= $view('partials/icon', ['name' => 'message', 'size' => 17]) ?></div>
                <div class="notice-item__body">
                    <div class="notice-item__text">
                        <a href="<?= e(url('/t/' . (int)($post['thread_id'] ?? 0), ['p' => (int)($post['id'] ?? 0)])) ?>">
                            <?= e((string)($post['thread_title'] ?? '主题已删除')) ?>
                        </a>
                    </div>
                    <div class="text-light" style="font-size:13px;margin-top:2px">
                        <?= e(plain_text((string)($post['content'] ?? ''), 100)) ?>
                    </div>
                    <time datetime="<?= e(date('c', (int)($post['created_at'] ?? 0))) ?>">
                        <?= e(human_time((int)($post['created_at'] ?? 0))) ?>
                    </time>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
