<?php
/**
 * 主题列表行
 *
 * 传入：$thread（ThreadModel::decorate 后的数组）、$favorited（可选，bool）、$showForum（可选，bool）
 *
 * 注意：局部模板只接收显式传入的变量，因此这里不依赖任何页面级变量；
 * 需要身份信息时统一走 auth_user() 读取。
 */

declare(strict_types=1);

if (!isset($thread) || !is_array($thread)) {
    return;
}

$threadId   = (int)($thread['id'] ?? 0);
$showForum  = isset($showForum) ? (bool)$showForum : false;
$favorited  = isset($favorited) ? (bool)$favorited : false;
$isPending  = (int)($thread['status'] ?? 1) !== 1;
$replyCount = (int)($thread['reply_count'] ?? 0);
$views      = (int)($thread['views'] ?? 0);
$author     = is_array($thread['author'] ?? null) ? $thread['author'] : [];
$groupColor = (string)($thread['author_group_color'] ?? '#86909c');
$lastUser   = is_array($thread['last_reply_user'] ?? null) ? $thread['last_reply_user'] : null;
$lastAt     = (int)($thread['last_reply_at'] ?? 0) ?: (int)($thread['created_at'] ?? 0);
$title      = (string)($thread['title'] ?? '');
?>
<article class="thread-item">
    <div class="thread-item__avatar">
        <a href="<?= e(url('/u/' . (int)($author['id'] ?? 0))) ?>" aria-label="查看作者主页">
            <?= avatar_img($author, 44) ?>
        </a>
    </div>

    <div style="min-width:0">
        <h3 style="margin:0;font-size:15px;font-weight:600;display:inline">
            <?php if ((int)($thread['is_pinned'] ?? 0) === 1): ?>
                <span class="tag tag--pin">置顶</span>
            <?php endif; ?>
            <?php if ((int)($thread['is_essence'] ?? 0) === 1): ?>
                <span class="tag tag--essence">精华</span>
            <?php endif; ?>
            <?php if ((int)($thread['is_recommended'] ?? 0) === 1): ?>
                <span class="tag tag--hot">推荐</span>
            <?php endif; ?>
            <?php if ((int)($thread['is_locked'] ?? 0) === 1): ?>
                <span class="tag tag--lock">已锁定</span>
            <?php endif; ?>
            <?php if ($isPending): ?>
                <span class="tag tag--pending">审核中</span>
            <?php endif; ?>
            <a class="thread-item__title" href="<?= e(url('/t/' . $threadId)) ?>"><?= e($title) ?></a>
        </h3>

        <?php if ((string)($thread['excerpt'] ?? '') !== ''): ?>
            <p class="thread-item__excerpt"><?= e((string)$thread['excerpt']) ?></p>
        <?php endif; ?>

        <div class="thread-item__sub">
            <a href="<?= e(url('/u/' . (int)($author['id'] ?? 0))) ?>"
               style="color:<?= e($groupColor) ?>;font-weight:600">
                <?= e((string)($author['username'] ?? '已注销用户')) ?>
            </a>
            <span class="dot">·</span>
            <time datetime="<?= e(date('c', (int)($thread['created_at'] ?? 0))) ?>">
                <?= e(human_time((int)($thread['created_at'] ?? 0))) ?>
            </time>

            <?php if ($showForum && (string)($thread['forum_name'] ?? '') !== ''): ?>
                <span class="dot">·</span>
                <a href="<?= e(url('/f/' . (int)($thread['forum_id'] ?? 0))) ?>" style="color:var(--qq-ink-3)">
                    <?= e((string)$thread['forum_name']) ?>
                </a>
            <?php endif; ?>

            <?php if ($favorited): ?>
                <span class="dot">·</span>
                <span class="tag tag--hot">已收藏</span>
            <?php endif; ?>

            <?php if ($replyCount > 0 && $lastUser !== null): ?>
                <span class="dot">·</span>
                <span>最后回复
                    <a href="<?= e(url('/u/' . (int)$lastUser['id'])) ?>"><?= e((string)($lastUser['username'] ?? '')) ?></a>
                    <?= e(human_time($lastAt)) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="thread-item__stats">
        <div><strong><?= format_number($replyCount) ?></strong> 回复</div>
        <div><strong><?= format_number($views) ?></strong> 浏览</div>
    </div>
</article>
