<?php
/**
 * 帖子详情：首楼（标题 + 正文）+ 评论楼层 + 评论框，右侧与首页同款侧栏
 *
 * 版式参考 reference project/bbs/bbs1 的帖子页：
 *   面包屑 → 主面板（首楼标题区 + 楼层列表）→ 翻页条 → 评论面板，
 *   右侧固定「发表新帖子 + 最新帖子 + 热门帖子」侧栏（共用 partials/sidebar）。
 *   首楼与楼层都是 .post-entry（头像 + 作者信息 + 正文，见 partials/floor.php）。
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

/* 首楼的帖子级操作条（点赞 / 收藏 / 编辑 / 删除 / 版主 / 插件），抽成局部模板 */
$topicActions = $view('partials/topic-actions', [
    'thread'      => $thread,
    'forum'       => $forum,
    'canEdit'     => $canEdit,
    'canModerate' => $canModerate,
    'liked'       => $liked,
    'favorited'   => $favorited,
]);

/* 评论框上方的状态提示（与参考站一致：一句短状态，而不是整块警示） */
if (!is_logged_in()) {
    $replyStatus = '登录后评论';
} elseif ($isLocked) {
    $replyStatus = '该帖子已锁定';
} elseif (!$canReply) {
    $replyStatus = '你没有评论权限';
} else {
    $replyStatus = '说两句';
}
?>

<div class="page-grid">
    <div>
        <section class="panel post-panel">
            <?php /*
              面包屑在卡片左上角、也就是标题的左上方（用户要求：原来它飘在卡片外面的页面上方）。
              样式用 .crumbs--topic 在卡片内收紧一点（见 theme.css 第 4 节）。
            */ ?>
            <ol class="ow-unstyled ow-hstack crumbs crumbs--topic">
                <li><a class="ow-unstyled" href="<?= e(url('/')) ?>">首页</a></li>
                <li aria-hidden="true">/</li>
                <li><a class="ow-unstyled" href="<?= e(url('/f/' . $forumId)) ?>"><?= e((string)($forum['name'] ?? '版块')) ?></a></li>
            </ol>

            <ul class="post-list">
                <?php if ($firstPost !== null && is_array($firstPost)): ?>
                    <?= $view('partials/floor', [
                        'post'         => $firstPost,
                        'thread'       => $thread,
                        'canModerate'  => $canModerate,
                        'canReply'     => $canReply,
                        'likedPosts'   => $likedPosts,
                        'highlight'    => $highlightId === (int)($firstPost['id'] ?? 0),
                        'topicActions' => $topicActions,
                    ]) ?>
                <?php endif; ?>

                <?php if ($replyItems === []): ?>
                    <li class="post-list__empty">还没有评论，来发表第一条吧。</li>
                <?php else: ?>
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
                <?php endif; ?>
            </ul>
        </section>

        <?php /* 全站统一分页条：放在卡片外面（见 theme.css 的分页约定） */ ?>
        <?php if (($pagination ?? '') !== ''): ?>
            <div class="pager"><?= (string)$pagination ?></div>
        <?php endif; ?>

        <section class="panel reply-panel" id="respond">
            <div class="panel__head">
                <h3><?= $view('partials/icon', ['name' => 'reply', 'size' => 16]) ?>发表评论</h3>
                <span class="spacer"></span>
                <span class="reply-panel__status"><?= e($replyStatus) ?></span>
            </div>

            <div class="panel__body">
                <?php if (!$canReply): ?>
                    <div role="alert" data-ow-variant="warning">
                        <?= $view('partials/icon', ['name' => 'lock', 'size' => 18]) ?>
                        <div>
                            <?php if ($isLocked): ?>
                                该帖子已被锁定，无法继续评论。
                            <?php elseif (!is_logged_in()): ?>
                                请先 <a href="<?= e(url('/login')) ?>">登录</a> 后再评论。
                            <?php else: ?>
                                你没有在该版块评论的权限。
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($replyTo !== null): ?>
                        <div class="reply-to" style="margin-bottom:10px">
                            正在评论 <strong><?= e((string)($replyTo['username'] ?? '')) ?></strong>
                            <?php if ((int)($replyTo['floor'] ?? 0) > 0): ?>（<?= (int)$replyTo['floor'] ?> 楼）<?php endif; ?>
                            · <a href="<?= e(url('/t/' . $threadId)) ?>#respond">取消</a>
                        </div>
                    <?php endif; ?>

                    <?php /* data-draft：按帖子区分草稿，不同帖子的评论互不覆盖 */ ?>
                    <form method="post" action="<?= e(url('/t/' . $threadId . '/reply')) ?>"
                          enctype="multipart/form-data" data-ajax data-ajax-redirect
                          data-draft="reply-<?= (int)$threadId ?>">
                        <?= csrf_field() ?>
                        <?php if ($replyTo !== null): ?>
                            <input type="hidden" name="parent_id" value="<?= (int)($replyTo['id'] ?? 0) ?>">
                        <?php endif; ?>

                        <?php
                        /* 评论与发布/编辑帖子、编辑评论共用同一个编辑器组件（partials/editor），
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

                        <div class="ow-hstack ow-mt-4">
                            <button type="submit" class="ow-button">
                                <?= $view('partials/icon', ['name' => 'message', 'size' => 16]) ?>
                                <span>提交评论</span>
                            </button>
                            <?php if ($uploadOn): ?>
                                <span class="ow-text-light" style="font-size:12.5px">
                                    附件会在选择后立即上传，请等待上传完成再提交。
                                </span>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php /* 与首页同款右栏（共用 partials/sidebar；个人管理页面不带侧栏） */ ?>
    <?= $view('partials/sidebar') ?>
</div>
