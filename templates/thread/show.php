<?php
/**
 * 主题详情：首帖 + 回复楼层 + 回复框
 *
 * 变量：$thread、$forum、$firstPost、$replies（items 已 decorate）、$pagination、
 *       $favorited、$liked、$likedPosts、$canReply、$canModerate、$canEdit、
 *       $canCreate、$replyTo、$uploadEnabled、$maxUploadMb
 */

declare(strict_types=1);

$thread      = is_array($thread ?? null) ? $thread : [];
$forum       = is_array($forum ?? null) ? $forum : [];
$replies     = is_array($replies ?? null) ? $replies : ['items' => []];
$replyItems  = is_array($replies['items'] ?? null) ? $replies['items'] : [];
$likedPosts  = is_array($likedPosts ?? null) ? $likedPosts : [];
$replyTo     = is_array($replyTo ?? null) ? $replyTo : null;
$canReply    = (bool)($canReply ?? false);
$canModerate = (bool)($canModerate ?? false);
$canEdit     = (bool)($canEdit ?? false);
$favorited   = (bool)($favorited ?? false);
$liked       = (bool)($liked ?? false);
$uploadOn    = (bool)($uploadEnabled ?? false);
$maxMb       = (int)($maxUploadMb ?? 2);

$threadId  = (int)($thread['id'] ?? 0);
$forumId   = (int)($forum['id'] ?? 0);
$isPending = (int)($thread['status'] ?? 1) !== 1;
$isLocked  = (int)($thread['is_locked'] ?? 0) === 1;

/* 跳转到指定楼层时高亮该楼（?p=帖子ID） */
$highlightId = isset($_GET['p']) && is_scalar($_GET['p']) ? (int)$_GET['p'] : 0;

$author     = is_array($thread['author'] ?? null) ? $thread['author'] : [];
$groupColor = (string)($thread['author_group_color'] ?? '#86909c');

/* 版主操作按钮：动作 => [文案, 图标, 是否已启用] */
$modActions = [
    ['action' => (int)($thread['is_pinned'] ?? 0) === 1 ? 'unpin' : 'pin',
     'label'  => (int)($thread['is_pinned'] ?? 0) === 1 ? '取消置顶' : '置顶',
     'icon'   => 'pin'],
    ['action' => (int)($thread['is_essence'] ?? 0) === 1 ? 'unessence' : 'essence',
     'label'  => (int)($thread['is_essence'] ?? 0) === 1 ? '取消精华' : '加精',
     'icon'   => 'star'],
    ['action' => (int)($thread['is_recommended'] ?? 0) === 1 ? 'unrecommend' : 'recommend',
     'label'  => (int)($thread['is_recommended'] ?? 0) === 1 ? '取消推荐' : '推荐',
     'icon'   => 'flag'],
    ['action' => $isLocked ? 'unlock' : 'lock',
     'label'  => $isLocked ? '解锁' : '锁定',
     'icon'   => $isLocked ? 'unlock' : 'lock'],
];
?>

<?php
/*
 * 面包屑只到版块为止，不再重复当前主题标题。
 * 标题就在紧接着的 .thread-hero 里大字显示，面包屑再放一次是重复信息，
 * 而且长标题会把这一行撑满、换行，反而干扰阅读。
 */
?>
<ol class="unstyled hstack crumbs">
    <li><a class="unstyled" href="<?= e(url('/')) ?>">首页</a></li>
    <li aria-hidden="true">/</li>
    <li><a class="unstyled" href="<?= e(url('/f/' . $forumId)) ?>"><?= e((string)($forum['name'] ?? '版块')) ?></a></li>
</ol>

<article class="thread-hero">
    <h1>
        <?php if ((int)($thread['is_pinned'] ?? 0) === 1): ?><span class="tag tag--pin">置顶</span><?php endif; ?>
        <?php if ((int)($thread['is_essence'] ?? 0) === 1): ?><span class="tag tag--essence">精华</span><?php endif; ?>
        <?php if ((int)($thread['is_recommended'] ?? 0) === 1): ?><span class="tag tag--hot">推荐</span><?php endif; ?>
        <?php if ($isLocked): ?><span class="tag tag--lock">已锁定</span><?php endif; ?>
        <?php if ($isPending): ?><span class="tag tag--pending">审核中</span><?php endif; ?>
        <?= e((string)($thread['title'] ?? '')) ?>
    </h1>

    <div class="thread-hero__meta">
        <a href="<?= e(url('/u/' . (int)($author['id'] ?? 0))) ?>" style="color:<?= e($groupColor) ?>;font-weight:600">
            <?= e((string)($author['username'] ?? '已注销用户')) ?>
        </a>
        <span>·</span>
        <time datetime="<?= e(date('c', (int)($thread['created_at'] ?? 0))) ?>">
            <?= e(date('Y-m-d H:i', (int)($thread['created_at'] ?? 0))) ?>
        </time>
        <span>·</span>
        <span><?= $view('partials/icon', ['name' => 'eye', 'size' => 14]) ?> <?= format_number((int)($thread['views'] ?? 0)) ?> 浏览</span>
        <span>·</span>
        <span><?= $view('partials/icon', ['name' => 'message', 'size' => 14]) ?> <?= format_number((int)($thread['reply_count'] ?? 0)) ?> 回复</span>
    </div>

    <div class="thread-hero__actions">
        <form method="post" action="<?= e(url('/t/' . $threadId . '/like')) ?>" data-ajax
              data-state-field="liked" data-active="<?= $liked ? '1' : '0' ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="<?= $liked ? 'button small' : 'button outline small' ?>">
                <?= $view('partials/icon', ['name' => 'heart', 'size' => 15]) ?>
                <span>赞 <span data-count><?= (int)($thread['like_count'] ?? 0) ?></span></span>
            </button>
        </form>

        <form method="post" action="<?= e(url('/t/' . $threadId . '/favorite')) ?>" data-ajax
              data-state-field="favorited" data-active="<?= $favorited ? '1' : '0' ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="<?= $favorited ? 'button small' : 'button outline small' ?>">
                <?= $view('partials/icon', ['name' => 'bookmark', 'size' => 15]) ?>
                <span><span data-count><?= (int)($thread['favorite_count'] ?? 0) ?></span> 收藏</span>
            </button>
        </form>

        <?php if ($canEdit): ?>
            <a class="button outline small" href="<?= e(url('/t/' . $threadId . '/edit')) ?>">
                <?= $view('partials/icon', ['name' => 'edit', 'size' => 15]) ?>
                <span>编辑主题</span>
            </a>
        <?php endif; ?>

        <?php if ($canEdit || $canModerate): ?>
            <form method="post" action="<?= e(url('/t/' . $threadId . '/delete')) ?>"
                  data-confirm="确定要删除该主题吗？主题下的所有回复也会一并删除，且无法自行恢复。"
                  class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="button outline small" data-variant="danger">
                    <?= $view('partials/icon', ['name' => 'trash', 'size' => 15]) ?>
                    <span>删除主题</span>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($canModerate): ?>
            <span class="spacer"></span>
            <form method="post" action="<?= e(url('/t/' . $threadId . '/moderate')) ?>"
                  class="inline-form" style="display:flex;gap:6px;flex-wrap:wrap">
                <?= csrf_field() ?>
                <?php foreach ($modActions as $mod): ?>
                    <button type="submit" name="action" value="<?= e($mod['action']) ?>"
                            class="button ghost small" title="<?= e($mod['label']) ?>">
                        <?= $view('partials/icon', ['name' => $mod['icon'], 'size' => 15]) ?>
                        <span><?= e($mod['label']) ?></span>
                    </button>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>

        <?php
        /* 插件可往操作区追加按钮，返回 HTML 字符串（由插件自行保证转义） */
        echo (string)hook('thread_view_actions', '', [
            'thread' => $thread,
            'forum'  => $forum,
            'user'   => auth_user(),
        ]);
        ?>
    </div>
</article>

<?php if ($firstPost !== null && is_array($firstPost)): ?>
    <?= $view('partials/floor', [
        'post'        => $firstPost,
        'thread'      => $thread,
        'canModerate' => $canModerate,
        'canReply'    => $canReply,
        'likedPosts'  => $likedPosts,
        'highlight'   => $highlightId === (int)($firstPost['id'] ?? 0),
    ]) ?>
<?php endif; ?>

<?php if ($replyItems !== []): ?>
    <div style="margin-top:var(--space-4)">
        <?php foreach ($replyItems as $reply): ?>
            <?= $view('partials/floor', [
                'post'        => $reply,
                'thread'      => $thread,
                'canModerate' => $canModerate,
                'canReply'    => $canReply,
                'likedPosts'  => $likedPosts,
                'highlight'   => $highlightId === (int)($reply['id'] ?? 0),
            ]) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (($pagination ?? '') !== ''): ?>
    <div class="floor-pager"><?= (string)$pagination ?></div>
<?php endif; ?>

<section class="panel" id="respond" style="margin-top:var(--space-4)">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'reply', 'size' => 16]) ?>发表回复</h3>
    </div>

    <div class="panel__body">
        <?php if (!$canReply): ?>
            <div role="alert" data-variant="warning">
                <?= $view('partials/icon', ['name' => 'lock', 'size' => 18]) ?>
                <div>
                    <?php if ($isLocked): ?>
                        该主题已被锁定，无法继续回复。
                    <?php elseif (!is_logged_in()): ?>
                        请先 <a href="<?= e(url('/login')) ?>">登录</a> 后再回复。
                    <?php else: ?>
                        你没有在该版块回复的权限。
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php if ($replyTo !== null): ?>
                <div class="reply-to" style="margin-bottom:10px">
                    正在回复 <strong><?= e((string)($replyTo['username'] ?? '')) ?></strong>
                    <?php if ((int)($replyTo['floor'] ?? 0) > 0): ?>（<?= (int)$replyTo['floor'] ?> 楼）<?php endif; ?>
                    · <a href="<?= e(url('/t/' . $threadId)) ?>#respond">取消</a>
                </div>
            <?php endif; ?>

            <?php /* data-draft：按主题区分草稿，不同主题的回复互不覆盖 */ ?>
            <form method="post" action="<?= e(url('/t/' . $threadId . '/reply')) ?>"
                  enctype="multipart/form-data" data-ajax data-ajax-redirect
                  data-draft="reply-<?= (int)$threadId ?>">
                <?= csrf_field() ?>
                <?php if ($replyTo !== null): ?>
                    <input type="hidden" name="parent_id" value="<?= (int)($replyTo['id'] ?? 0) ?>">
                <?php endif; ?>

                <?php
                /* 回帖与发布/编辑主题、编辑回复共用同一个编辑器组件（partials/editor），
                   工具栏、字数统计、附件上传（行式列表 + 进度/复制）行为完全一致。 */
                ?>
                <?= $view('partials/editor', [
                    'editorName'      => 'content',
                    'editorId'        => 'reply',
                    'editorMax'       => (int)config('app.post_max_length', 20000),
                    'editorUpload'    => $uploadOn,
                    'editorMaxMb'     => $maxMb,
                    'editorValue'     => (string)old('content', ''),
                    'editorPlaceholder' => '支持 Markdown：**加粗**、`代码`、> 引用、[链接](https://)',
                ]) ?>

                <div class="hstack mt-4">
                    <button type="submit" class="button">
                        <?= $view('partials/icon', ['name' => 'message', 'size' => 16]) ?>
                        <span>提交回复</span>
                    </button>
                    <?php if ($uploadOn): ?>
                        <span class="text-light" style="font-size:12.5px">
                            附件会在选择后立即上传，请等待上传完成再提交。
                        </span>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
