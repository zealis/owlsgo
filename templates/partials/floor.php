<?php
/**
 * 楼层（首帖与回复共用）
 *
 * 传入：
 *   $post          已 decorate 的帖子数组
 *   $thread        所属主题
 *   $canModerate   是否有该版块的版主权限（bool）
 *   $canReply      当前用户是否可回复（bool）
 *   $likedPosts    当前用户已点赞的帖子 ID 映射（array<int,bool>）
 *   $highlight     是否高亮（跳转目标楼层，bool，可选）
 */

declare(strict_types=1);

if (!isset($post) || !is_array($post)) {
    return;
}

$thread      = is_array($thread ?? null) ? $thread : [];
$me          = auth_user();
$myId        = (int)($me['id'] ?? 0);
$threadId    = (int)($thread['id'] ?? ($post['thread_id'] ?? 0));
$postId      = (int)($post['id'] ?? 0);
$floor       = (int)($post['floor'] ?? 0);
$isFirst     = (int)($post['is_first'] ?? 0) === 1;
$isPending   = (int)($post['status'] ?? 1) !== 1;
$canModerate = (bool)($canModerate ?? false);
$canReply    = (bool)($canReply ?? false);
$likedPosts  = is_array($likedPosts ?? null) ? $likedPosts : [];
$highlight   = (bool)($highlight ?? false);

$author      = is_array($post['author'] ?? null) ? $post['author'] : [];
$authorId    = (int)($author['id'] ?? 0);
$isSelf      = $myId > 0 && $myId === $authorId;
$groupColor  = (string)($post['author_group_color'] ?? '#86909c');
$likeCount   = (int)($post['like_count'] ?? 0);
$liked       = isset($likedPosts[$postId]);
$attachments = is_array($post['attachments'] ?? null) ? $post['attachments'] : [];

/*
 * 权限：与 PostController::canEdit / ThreadController::canEdit 的判定口径保持一致。
 * 两者都多一道保护线 —— 管理员发布的内容，版主既不能编辑也不能删除。
 */
$canManage = \Core\Permission::canManageContentOf(auth_user(), $authorId);
$canEdit   = $canManage && ($canModerate || ($isSelf && $isFirst && $authorId === (int)($thread['user_id'] ?? 0)));
$canDelete = $canManage && !$isFirst && ($canModerate || $isSelf);
$editedAt  = (int)($post['updated_at'] ?? 0);
$createdAt = (int)($post['created_at'] ?? 0);
?>
<article class="floor<?= $isFirst ? ' floor--first' : '' ?>"
         id="p<?= $postId ?>"
         <?= $highlight ? 'style="box-shadow:0 0 0 2px var(--qq-blue)"' : '' ?>>
    <div class="floor__side">
        <a href="<?= e(url('/u/' . $authorId)) ?>" aria-label="查看作者主页">
            <?= avatar_img($author, 68) ?>
        </a>
        <div class="floor__name" style="color:<?= e($groupColor) ?>">
            <?= e((string)($author['username'] ?? '已注销用户')) ?>
        </div>
        <div><?= e((string)($post['author_group_name'] ?? '游客')) ?></div>

        <div class="floor__stats">
            <span title="发表主题数">主题 <?= (int)($author['thread_count'] ?? 0) ?></span>
            <span title="发表回复数">回复 <?= (int)($author['post_count'] ?? 0) ?></span>
        </div>
    </div>

    <div class="floor__main">
        <div class="floor__head">
            <?php if ($isFirst): ?>
                <span class="floor__no">楼主</span>
            <?php else: ?>
                <span class="floor__no"><?= $floor ?> 楼</span>
            <?php endif; ?>

            <time datetime="<?= e(date('c', $createdAt)) ?>"><?= e(date('Y-m-d H:i', $createdAt)) ?></time>

            <?php if ($isPending): ?>
                <span class="tag tag--pending">待审核</span>
            <?php endif; ?>

            <?php if ($editedAt > $createdAt): ?>
                <span class="text-lighter">（<?= e(human_time($editedAt)) ?>编辑）</span>
            <?php endif; ?>

            <span class="spacer"></span>

            <?php if ($floor > 0): ?>
                <a class="text-light" href="#p<?= $postId ?>" style="font-size:12.5px" title="本楼层链接">#<?= $floor ?></a>
            <?php endif; ?>
        </div>

        <?php if ((int)($post['parent_id'] ?? 0) > 0): ?>
            <div class="reply-to">
                回复 <strong><?= e((string)($post['parent_username'] ?? '')) ?></strong>
                <?php if ((int)($post['parent_floor'] ?? 0) > 0): ?>
                    （<?= (int)$post['parent_floor'] ?> 楼）
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="floor__body">
            <?php
            /*
             * 正文有两套来源：写入时缓存的 content_html（常态），以及现渲染的原始正文。
             * 两条路径都必须经过 content_rendered 钩子 —— 否则插件对正文的输出级加工
             * 会因为「有缓存就直接输出」而永远不生效（render_content() 只在无缓存时被调用）。
             */
            $postHtml = (string)($post['content_html'] ?? '');

            if ($postHtml === '') {
                $postHtml = render_content((string)($post['content'] ?? ''));
            } else {
                $postHtml = (string)\Core\Hook::filter('content_rendered', $postHtml, [
                    'raw'    => (string)($post['content'] ?? ''),
                    'post'   => $post,
                    'cached' => true,
                ]);
            }
            ?>
            <?= $postHtml ?>
        </div>

        <?php if ($attachments !== []): ?>
            <?php
            /*
             * 附件：图片与普通文件一视同仁，都要「下载附件」权限。
             * 没有权限时只把文件名与大小列出来（灰色 + 锁），不做成链接 ——
             * 点开只会撞 403，不如直接说明白。
             */
            $canDownloadAttach = \Core\Permission::allows(auth_user(), 'attachment.download')
                || \Core\Permission::allows(auth_user(), 'attachment.manage');
            ?>
            <div class="attach-list">
                <?php foreach ($attachments as $attachment): ?>
                    <?php $isImage = (int)($attachment['is_image'] ?? 0) === 1; ?>
                    <?php if ($canDownloadAttach): ?>
                        <a class="attach" href="<?= e(url('/attachment/' . (int)$attachment['id'])) ?>"
                           <?= $isImage ? 'target="_blank" rel="noopener"' : '' ?>>
                            <?= $view('partials/icon', ['name' => $isImage ? 'image' : 'paperclip', 'size' => 17]) ?>
                            <span><?= e((string)($attachment['name'] ?? '附件')) ?></span>
                            <span class="attach__size"><?= e(format_size((int)($attachment['size'] ?? 0))) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="attach attach--locked"
                              title="当前用户组没有查看附件的权限，请先登录或联系管理员">
                            <?= $view('partials/icon', ['name' => 'lock', 'size' => 17]) ?>
                            <span><?= e((string)($attachment['name'] ?? '附件')) ?></span>
                            <span class="attach__size"><?= e(format_size((int)($attachment['size'] ?? 0))) ?></span>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ((string)($author['signature'] ?? '') !== '' && !$isFirst): ?>
            <div class="floor__sign"><?= e((string)$author['signature']) ?></div>
        <?php endif; ?>

        <div class="floor__actions">
            <form method="post" action="<?= e(url('/p/' . $postId . '/like')) ?>" data-ajax
                  data-state-field="liked" data-active="<?= $liked ? '1' : '0' ?>">
                <?= csrf_field() ?>
                <button type="submit" class="<?= $liked ? 'button small' : 'button outline small' ?>">
                    <?= $view('partials/icon', ['name' => 'heart', 'size' => 15]) ?>
                    <span>赞 <span data-count><?= (int)$likeCount ?></span></span>
                </button>
            </form>

            <?php if (!$isFirst && $canReply): ?>
                <a class="button ghost small"
                   href="<?= e(url('/t/' . $threadId, ['reply_to' => $postId])) ?>#respond">
                    <?= $view('partials/icon', ['name' => 'reply', 'size' => 15]) ?>
                    <span>回复</span>
                </a>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <a class="button ghost small"
                   href="<?= e($isFirst ? url('/t/' . $threadId . '/edit') : url('/p/' . $postId . '/edit')) ?>">
                    <?= $view('partials/icon', ['name' => 'edit', 'size' => 15]) ?>
                    <span>编辑</span>
                </a>
            <?php endif; ?>

            <?php if ($canDelete): ?>
                <span class="spacer"></span>
                <form method="post" action="<?= e(url('/p/' . $postId . '/delete')) ?>"
                      data-ajax data-ajax-redirect
                      data-confirm="确定要删除这条回复吗？删除后无法自行恢复。">
                    <?= csrf_field() ?>
                    <button type="submit" class="button outline small" data-variant="danger">
                        <?= $view('partials/icon', ['name' => 'trash', 'size' => 15]) ?>
                        <span>删除</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</article>
