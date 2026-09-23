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

<?= $view('partials/profile-head', ['profile' => $profile, 'active' => 'home']) ?>

<?php /* 数据卡只在个人主页出现（帖子/评论/收藏/设置四个子页不渲染） */ ?>
<?= $view('partials/profile-stats', ['profile' => $profile]) ?>

<section class="panel ow-mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'file', 'size' => 16]) ?>最近发表的帖子</h3>
        <span class="spacer"></span>
        <a class="ow-text-light" style="font-size:13px" href="<?= e(url('/u/' . $userId . '/threads')) ?>">全部 ›</a>
    </div>

    <?php if ($recentThreads === []): ?>
        <div class="empty" style="padding:34px 16px"><p>还没有发表过帖子。</p></div>
    <?php else: ?>
        <?php foreach ($recentThreads as $thread): ?>
            <?= $view('partials/thread-item', ['thread' => $thread, 'showForum' => true, 'favorited' => false]) ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel ow-mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'reply', 'size' => 16]) ?>最近发表的评论</h3>
        <span class="spacer"></span>
        <a class="ow-text-light" style="font-size:13px" href="<?= e(url('/u/' . $userId . '/posts')) ?>">全部 ›</a>
    </div>

    <?php if ($recentPosts === []): ?>
        <div class="empty" style="padding:34px 16px"><p>还没有发表过评论。</p></div>
    <?php else: ?>
        <?php foreach ($recentPosts as $post): ?>
            <article class="notice-item">
                <div class="notice-item__icon"><?= $view('partials/icon', ['name' => 'message', 'size' => 17]) ?></div>
                <div class="notice-item__body">
                    <div class="notice-item__text">
                        <a href="<?= e(url('/t/' . (int)($post['thread_id'] ?? 0), ['p' => (int)($post['id'] ?? 0)])) ?>">
                            <?= e((string)($post['thread_title'] ?? '帖子已删除')) ?>
                        </a>
                    </div>
                    <div class="ow-text-light" style="font-size:13px;margin-top:2px">
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
