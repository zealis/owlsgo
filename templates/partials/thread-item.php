<?php
/**
 * 帖子列表行
 *
 * 传入：$thread（ThreadModel::decorate 后的数组）、$favorited（可选，bool）、$showForum（可选，bool）
 *
 * 注意：局部模板只接收显式传入的变量，因此这里不依赖任何页面级变量；
 * 需要身份信息时统一走 auth_user() 读取。
 *
 * 版式（2026-09-20 改版）：
 *   正文区副信息 = 作者 / 时间 / 评论数 / 已收藏 / 最后评论者+时间，分隔靠 CSS flex gap，不输出「·」；
 *   右侧摘要   = 上「版块」（$showForum 为真时）、下「浏览」；
 *   ≤700px 时右摘要改为独立一行，排在副信息行之后（见 theme.css 的 .thread-item 规则）。
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
$lastUser   = is_array($thread['last_reply_user'] ?? null) ? $thread['last_reply_user'] : null;
$lastAt     = (int)($thread['last_reply_at'] ?? 0) ?: (int)($thread['created_at'] ?? 0);
$title      = (string)($thread['title'] ?? '');
$forumName  = (string)($thread['forum_name'] ?? '');
?>
<article class="thread-item">
    <div class="thread-item__avatar">
        <a href="<?= e(url('/u/' . (int)($author['id'] ?? 0))) ?>" aria-label="查看作者主页">
            <?= avatar_img($author, 44) ?>
        </a>
    </div>

    <div class="thread-item__body">
        <h3 style="margin:0;font-size:15px;font-weight:600;display:inline">
            <a class="thread-item__title" href="<?= e(url('/t/' . $threadId)) ?>"><?= e($title) ?></a>
            <?php /* 标记统一放在标题后面（与帖子详情页一致） */ ?>
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
        </h3>

        <?php if ((string)($thread['excerpt'] ?? '') !== ''): ?>
            <p class="thread-item__excerpt"><?= e((string)$thread['excerpt']) ?></p>
        <?php endif; ?>

        <div class="thread-item__sub">
            <a href="<?= e(url('/u/' . (int)($author['id'] ?? 0))) ?>" class="thread-item__author">
                <?= e((string)($author['username'] ?? '用户已删除')) ?>
            </a>
            <time datetime="<?= e(date('c', (int)($thread['created_at'] ?? 0))) ?>">
                <?= e(human_time((int)($thread['created_at'] ?? 0))) ?>
            </time>

            <?php /* 评论数占原来「版块」的位置（版块已移到右侧摘要顶部） */ ?>
            <span class="thread-item__comments">
                <strong><?= format_number($replyCount) ?></strong> 评论
            </span>

            <?php if ($favorited): ?>
                <span class="tag tag--hot">已收藏</span>
            <?php endif; ?>

            <?php if ($replyCount > 0 && $lastUser !== null): ?>
                <span class="thread-item__last">
                    <a href="<?= e(url('/u/' . (int)$lastUser['id'])) ?>"><?= e((string)($lastUser['username'] ?? '')) ?></a>
                    <span><?= e(human_time($lastAt)) ?></span>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="thread-item__stats">
        <?php if ($showForum && $forumName !== ''): ?>
            <a class="thread-item__forum" href="<?= e(url('/f/' . (int)($thread['forum_id'] ?? 0))) ?>"
               title="<?= e($forumName) ?>"><?= e($forumName) ?></a>
        <?php endif; ?>
        <div class="thread-item__views">
            <strong><?= format_number($views) ?></strong> 浏览
        </div>
    </div>
</article>
