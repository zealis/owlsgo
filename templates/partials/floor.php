<?php
/**
 * 帖子行（首楼与评论楼层共用）—— 参考 bbs1 的「头像 + 作者信息 + 正文」版式
 *
 * 结构（.post-entry 是 CSS Grid，区域见 theme.css「帖子详情」一节）：
 *   [title]   首楼才有：帖子标题 + 状态标签 + 作者/时间/浏览/评论 + 帖子级操作条
 *   [avatar]  头像
 *   [body]    昵称 + 用户组 / 时间 / 楼层号 + 楼层操作按钮 + 元信息（帖子数、评论数、
 *             以及「回复的是哪条评论」的 @用户名 #楼层 链接）
 *   [content] 正文（含图片灯箱可点的 .content-image）
 *   [foot]    附件列表 + 楼层签名（个人简介，首楼不显示）
 *
 * 传入：
 *   $post          已 decorate 的帖子数组
 *   $thread        所属帖子
 *   $canModerate   是否有该版块的版主权限（bool）
 *   $canReply      当前用户是否可评论（bool）
 *   $likedPosts    当前用户已点赞的帖子 ID 映射（array<int,bool>）
 *   $highlight     是否高亮（跳转目标楼层，bool，可选）
 *   $topicActions  首楼的帖子级操作条 HTML（帖子详情页传入，可选）
 *
 * ⚠️ 这些不能动：楼层锚点 id="p{id}"、`#respond` 回复入口、data-ajax 表单属性、
 * content_rendered 钩子、附件下载权限判断 —— 都有外部依赖（锚点跳转 / 无刷新点赞 / 插件）。
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
$topicActions = (string)($topicActions ?? '');

$author      = is_array($post['author'] ?? null) ? $post['author'] : [];
$authorId    = (int)($author['id'] ?? 0);
$isSelf      = $myId > 0 && $myId === $authorId;
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
$parentId  = (int)($post['parent_id'] ?? 0);
$signature = trim((string)($author['bio'] ?? ''));

/* 高亮（?p= 跳转目标）：用类名而不是内联样式，深浅色都能正确显示 */
$entryClass = 'post-entry'
    . ($isFirst ? ' has-title post-entry--topic' : '')
    . ($highlight ? ' post-highlight' : '');
?>
<li class="<?= e($entryClass) ?>" id="p<?= $postId ?>">
    <?php if ($isFirst): ?>
        <?php
        /*
         * 标题行 = 标题（左） + 发布时间 / 已编辑（右）。作者名已经在下面的作者行里，标题下方不再重复。
         * 状态标签：置顶 / 精华 / 推荐 / 已锁定都改由作者行右侧的图标或「⋯」菜单表达（用户要求）；
         * 只有「审核中」留在标题旁 —— 那是「内容还看不到」的审核态，必须显式告知。
         *
         * 「已编辑」的悬浮提示要写出「谁最后编辑于何时」：编辑人来自后加的 posts.updated_by，
         * 由 ThreadController::show() 按需查一次用户名后塞进 editor_name（不在 decorate() 里查，避免 N+1）。
         */
        $threadAt   = (int)($thread['created_at'] ?? 0);
        $editorName = trim((string)($post['editor_name'] ?? ''));
        $editedTip  = ($editorName !== '' ? $editorName . ' 最后编辑于 ' : '最后编辑于 ')
            . date('Y-m-d H:i', $editedAt);
        ?>
        <div class="post-topic-title">
            <div class="post-title-row">
                <h1 class="post-title-main">
                    <?= e((string)($thread['title'] ?? '')) ?>
                    <?php if ((int)($thread['status'] ?? 1) !== 1): ?><span class="tag tag--pending">审核中</span><?php endif; ?>
                </h1>

                <div class="post-title-meta">
                    <time datetime="<?= e(date('c', $threadAt)) ?>"><?= e(date('Y-m-d H:i', $threadAt)) ?></time>
                    <?php /*
                      只有真的改过才出现（帖子编辑 / 评论编辑都会刷新 updated_at）。
                      data-ow-tooltip-placement="bottom"：这个标记贴着卡片上沿，气泡默认向上显示会被卡片
                      的 overflow:hidden 裁掉，所以改成向下（定位样式见 theme.css 第 7 节）。
                      title 会被 ui.css 的 tooltip.js 转成 data-ow-tooltip。
                    */ ?>
                    <?php if ($editedAt > $threadAt): ?>
                        <span class="post-edited-flag" data-ow-tooltip-placement="bottom"
                              title="<?= e($editedTip) ?>">已编辑</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="post-avatar">
        <a href="<?= e(url('/u/' . $authorId)) ?>" aria-label="查看作者主页">
            <?= avatar_img($author, 44) ?>
        </a>
    </div>

    <div class="post-body">
        <div class="post-head">
            <div class="post-info">
                <a class="post-author" href="<?= e(url('/u/' . $authorId)) ?>">
                    <?= e((string)($author['username'] ?? '用户已删除')) ?>
                </a>

                <?php /* 用户组跟在昵称后面同一排（用户要求），不再是下面元信息行里的一项 */ ?>
                <span class="post-group"><?= e((string)($post['author_group_name'] ?? '游客')) ?></span>

                <span class="post-time"><?= e(human_time($createdAt)) ?></span>

                <?php if ($isFirst): ?>
                    <span class="post-badge">楼主</span>
                <?php elseif ($floor > 0): ?>
                    <a class="post-floor" href="#p<?= $postId ?>" title="本楼层链接">#<?= $floor ?></a>
                <?php endif; ?>

                <?php if ($isPending): ?>
                    <span class="tag tag--pending">待审核</span>
                <?php endif; ?>

                <?php
                /*
                 * 「X 编辑过」只留给评论楼层：首楼的编辑时间已经移到标题行（发布时间右边的「已编辑」，
                 * 悬浮显示「谁最后编辑于何时」），这里不再重复。
                 */
                ?>
                <?php if (!$isFirst && $editedAt > $createdAt): ?>
                    <span class="post-edited"><?= e(human_time($editedAt)) ?>编辑过</span>
                <?php endif; ?>
            </div>

            <?php
            /*
             * 楼层操作：一律只显示图标（用户要求），文案退到 title / aria-label，保证悬浮与无障碍可读。
             * 顺序（首楼）＝ 楼层点赞 → 帖子级操作（收藏 / 版主图标 / 「⋯」菜单，见 partials/topic-actions）。
             *
             * ⚠️ 点赞仍是 data-ajax 表单：`data-ajax` + `data-state-field` + `data-active` 是
             *    app.js initAjaxForms() 的依赖，类名与属性都不能改（激活态配色见 theme.css 第 20 节）。
             * ⚠️ 删除按钮刻意**不用** data-ow-variant="danger"：点击之前与其它图标同色（用户要求）。
             */
            ?>
            <div class="post-ops">
                <form method="post" action="<?= e(url('/p/' . $postId . '/like')) ?>" data-ajax
                      data-state-field="liked" data-active="<?= $liked ? '1' : '0' ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="ow-button ow-ghost ow-small" title="赞 <?= (int)$likeCount ?>" aria-label="赞">
                        <?= $view('partials/icon', ['name' => 'heart', 'size' => 15]) ?>
                    </button>
                </form>

                <?php if ($isFirst): ?>
                    <?php /* 帖子级操作区（收藏 / 置顶 / 精华 / 推荐 / 「⋯」菜单 / 插件扩展） */ ?>
                    <?= $topicActions !== '' ? $topicActions : '' ?>
                <?php else: ?>
                    <?php if ($canReply): ?>
                        <a class="ow-button ow-ghost ow-small"
                           href="<?= e(url('/t/' . $threadId, ['reply_to' => $postId])) ?>#respond"
                           title="评论" aria-label="评论">
                            <?= $view('partials/icon', ['name' => 'reply', 'size' => 15]) ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($canEdit): ?>
                        <a class="ow-button ow-ghost ow-small" href="<?= e(url('/p/' . $postId . '/edit')) ?>"
                           title="编辑" aria-label="编辑">
                            <?= $view('partials/icon', ['name' => 'edit', 'size' => 15]) ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($canDelete): ?>
                        <form method="post" action="<?= e(url('/p/' . $postId . '/delete')) ?>"
                              data-ajax data-ajax-redirect
                              data-confirm="确定要删除这条评论吗？删除后无法自行恢复。">
                            <?= csrf_field() ?>
                            <button type="submit" class="ow-button ow-ghost ow-small" title="删除" aria-label="删除">
                                <?= $view('partials/icon', ['name' => 'trash', 'size' => 15]) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php
        /*
         * 元信息行。
         *
         * 原来这里还有「发表帖子数 / 发表评论数」两项计数，用户要求取消（作者头像下面不再显示计数），
         * 所以整行现在只剩「回复指向」——没有回复指向时**不输出这一行**，免得留下一道空行。
         *
         * 回复的是哪条评论：@用户名 #楼层，点进对应楼层。
         *
         * 数据来自 PostModel::decorate() 补的 $post['parent']（作者名 + 楼层 + 是否首楼 +
         * 是否已删除）。跳转用 /t/{id}?p={评论ID}#p{评论ID}：
         *   ?p= 让控制器算出该楼层所在的**分页**（跨页也能一次点到），
         *   #p{id} 让浏览器滚到那一层（theme.css 给了 .post-entry 的 scroll-margin-top，
         *           避免被吸顶导航挡住）。
         * 目标已删除时只输出文字、不给链接 —— 免得点进不存在的锚点。
         */
        $parent = is_array($post['parent'] ?? null) ? $post['parent'] : null;
        ?>
        <?php if ($parent !== null || $parentId > 0): ?>
            <div class="post-meta">
                <?php if ($parent !== null): ?>
                    <?php
                    /* 首楼没有楼层号（评论从 #1 起），对楼主用「（楼主）」表述 */
                    $parentLabel = '@' . (string)($parent['username'] ?? '')
                        . ((bool)($parent['is_first'] ?? false) ? '（楼主）' : ' #' . (int)($parent['floor'] ?? 0));
                    ?>
                    <?php if ((bool)($parent['deleted'] ?? false)): ?>
                        <span class="post-replyto post-replyto--gone" title="被回复的评论已删除">
                            <?= $view('partials/icon', ['name' => 'reply', 'size' => 13]) ?>
                            <?= e($parentLabel) ?>（已删除）
                        </span>
                    <?php else: ?>
                        <?php $parentHref = url('/t/' . $threadId, ['p' => (int)$parent['id']]) . '#p' . (int)$parent['id']; ?>
                        <a class="post-replyto" href="<?= e($parentHref) ?>" title="前往该楼层">
                            <?= $view('partials/icon', ['name' => 'reply', 'size' => 13]) ?>
                            <?= e($parentLabel) ?>
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php /* parent_id 有值但查不到记录（被彻底清理） */ ?>
                    <span class="post-replyto post-replyto--gone">被回复的评论已删除</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    /* 正文：content_html 是写入时缓存的渲染结果（常态），无缓存时现渲染。
       两条路径都必须过 content_rendered 钩子 —— 否则插件对正文的输出级加工
       会因为「有缓存直接输出」而永远不生效。 */
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

    /* 没有「下载附件」权限时，把正文里的附件图换成「锁 + 文件名」占位，避免破图 */
    $postHtml = content_attachment_lock($postHtml);

    /*
     * 下方附件列表：图片若已贴进正文（$embedded 命中）就不再重复列一遍，
     * 规则与权限处理都在 partials/attach-list 里；列表为空时它返回空字符串。
     * 先渲染好再决定是否输出 .post-foot —— 否则会出现一个只有签名（或整块是空的）的容器。
     */
    $attachHtml = $view('partials/attach-list', [
        'attachments' => $attachments,
        'embedded'    => content_attachment_ids((string)($post['content'] ?? '')),
    ]);

    /*
     * 长内容折叠：首楼与回帖用不同的高度阈值（见 helpers.php 的 content_fold()）。
     * 这里只输出标记与按钮，是否真的超长由前端按**实际渲染高度**判断 ——
     * 图片、代码块、宽表格渲染出来多高，服务端算不出来。
     */
    $fold = content_fold($isFirst ? 'topic' : 'reply', $postId);
    ?>
    <div class="post-content"<?= $fold !== null ? ' id="' . e($fold['id']) . '" data-fold data-fold-height="' . (int)$fold['height'] . '"' : '' ?>><?= $postHtml ?></div>

    <?php if ($fold !== null): ?>
        <?= $view('partials/fold-toggle', ['foldTarget' => $fold['id']]) ?>
    <?php endif; ?>

    <?php if ($attachHtml !== '' || (!$isFirst && $signature !== '')): ?>
        <div class="post-foot">
            <?= $attachHtml ?>

            <?php /* 楼层签名即用户资料里的「个人简介」（首楼是帖子正文，不重复展示） */ ?>
            <?php if (!$isFirst && $signature !== ''): ?>
                <div class="post-sign"><?= nl2br(e($signature)) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</li>
