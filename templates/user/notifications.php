<?php
/**
 * 通知中心
 *
 * 变量：$result（items 已 decorate）、$unread、$pagination
 *
 * 每条通知携带 link 字段（指向帖子楼层），kind 决定图标。
 * 控制器在非 AJAX 请求下会 redirectWith 回本页并附带 flash 提示，
 * 因此这里的表单采用普通 POST 提交，保证列表与未读状态能同步刷新。
 *
 * 隐私说明：通知仅对接收者本人可见，控制器已强制校验归属并静默忽略越权操作，
 * 模板层无需再做判权。
 */

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : ['items' => []];
$items  = is_array($result['items'] ?? null) ? $result['items'] : [];
$unread = (int)($unread ?? 0);

/** kind => 图标名 */
$kindIcons = [
    'reply'   => 'reply',
    'mention' => 'user',
    'quote'   => 'quote',
    'system'  => 'megaphone',
    'audit'   => 'shield',
];

/*
 * 通知（全站公告）区域 —— 面板标题就写「通知」（原来是「全站通知」），
 * 与下面的「我的通知」配成一对：上面是站点广播，下面是发给自己的提醒。
 *  - 所有登录用户都能看到「公开显示」的公告；
 *  - 拥有 notice.manage（用户组里的「发布公告」）的用户额外看到：
 *    发布公告 / 通知中心设置，以及每条公告上的「编辑公告」；
 *  - 「附件管理」指向通知中心自己的附件页 /notices/resources（查看公告引用/未引用的
 *    附件资源），所以它看的是 attachment.manage —— 与目标页 NoticeController::resources()
 *    的准入一致，点过去不会撞 403。
 *    （附件总空间、允许的扩展名这类**配置**在后台「站点设置」里，属于后台范畴，
 *      从后台左侧导航进，不在通知中心里放跳转入口。）
 * 公告正文与帖子共用 Markdown 渲染，正文为空时不输出空容器。
 */
$canManage         = \Core\Permission::allows(auth_user(), 'notice.manage');
$canAttachManage   = \Core\Permission::allows(auth_user(), 'attachment.manage');
/*
 * 公告由控制器按「站点统一的每页条数」分页后传进来（$notices 是当前页的条目）。
 * 公告条数没超过每页上限时 $noticePagination 是空字符串，界面与不分页时一致。
 */
$notices           = is_array($notices ?? null) ? $notices : [];
$noticeTotal       = (int)($noticeTotal ?? 0);
$noticeIntro       = trim((string)setting('notice_center_intro', ''));
$hiddenCount       = $canManage ? \Modules\Notice\NoticeModel::hiddenCount() : 0;
?>
<?php /*
 * 与首页同款右栏（用户要求：通知中心也要有侧栏）。
 * 通知中心其余页面（用户中心 / 设置）按设计仍不带右栏。
 */ ?>
<div class="page-grid">
    <div>
    <section class="panel mb-4">
        <div class="panel__head">
            <h2><?= $view('partials/icon', ['name' => 'megaphone', 'size' => 17]) ?>通知</h2>
            <span class="spacer"></span>
            <?php if ($canManage || $canAttachManage): ?>
                <?php if ($canManage && $hiddenCount > 0): ?>
                    <span class="badge outline" title="未公开的公告只有拥有发布权限的用户能看到"><?= $hiddenCount ?> 条未公开</span>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <a class="button ghost small" href="<?= e(url('/notices/create')) ?>">
                        <?= $view('partials/icon', ['name' => 'plus', 'size' => 15]) ?>
                        <span>发布公告</span>
                    </a>
                <?php endif; ?>
                <?php if ($canAttachManage): ?>
                    <a class="button ghost small" href="<?= e(url('/notices/resources')) ?>">附件管理</a>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <a class="button ghost small" href="<?= e(url('/notices/settings')) ?>">通知中心设置</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($noticeIntro !== ''): ?>
            <div class="panel__body" style="padding-top:10px;padding-bottom:0">
                <p style="margin:0;color:var(--qq-ink-3);font-size:13px"><?= e($noticeIntro) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($notices === []): ?>
            <div class="empty">
                <?= $view('partials/icon', ['name' => 'megaphone', 'size' => 42]) ?>
                <p>暂无公告。</p>
            </div>
        <?php else: ?>
            <?php foreach ($notices as $notice): ?>
                <?php
                $noticeId   = (int)($notice['id'] ?? 0);
                $isPublic   = (int)($notice['enabled'] ?? 0) === 1;
                $noticeBody = trim((string)($notice['body'] ?? ''));
                ?>
                <article class="notice-card" id="notice-<?= (int)($notice['id'] ?? 0) ?>">
                    <?php
                    /*
                     * 标题行：公告标题在左；「未公开」徽章与「编辑公告」贴在行的最右。
                     * （原先标题上方还有一行灰字「站点公告」——那是公告名称，已去掉；
                     *   未公开的提示合并到这一行，不再单独占一行。）
                     */
                    ?>
                    <div class="notice-card__head">
                        <h3 class="notice-card__title"><?= e((string)($notice['title'] ?? '')) ?></h3>
                        <span class="spacer"></span>
                        <?php if (!$isPublic): ?>
                            <span class="badge outline">未公开</span>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <a class="notice-card__edit" href="<?= e(url('/notices/' . $noticeId . '/edit')) ?>">编辑公告</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($noticeBody !== ''): ?>
                        <?php
                        /*
                         * 公告正文也支持长内容折叠（阈值见后台「长内容折叠 · 全站通知高度」）。
                         * 与楼层一样：按钮默认隐藏，是否真的超长由前端按实际渲染高度判断。
                         */
                        $noticeFold = content_fold('notice', $noticeId);
                        ?>
                        <?php /* content_attachment_lock：无「下载附件」权限时把正文附图换成锁 + 文件名，避免破图 */ ?>
                        <div class="notice-card__body"<?= $noticeFold !== null ? ' id="' . e($noticeFold['id']) . '" data-fold data-fold-height="' . (int)$noticeFold['height'] . '"' : '' ?>><?= content_attachment_lock(\Core\Text::toHtml($noticeBody)) ?></div>
                        <?php if ($noticeFold !== null): ?>
                            <?= $view('partials/fold-toggle', ['foldTarget' => $noticeFold['id']]) ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php
                    /*
                     * 公告引用的附件：与楼层同一套规则（partials/attach-list）——
                     * 图片已经贴在公告正文里的不再重复列，非图片文件照旧列出；
                     * 没有「下载附件」权限时只显示文件名 + 锁，不做成链接。
                     */
                    $noticeFiles = is_array($notice['attachments'] ?? null) ? $notice['attachments'] : [];
                    $noticeFilesHtml = $view('partials/attach-list', [
                        'attachments' => $noticeFiles,
                        'embedded'    => content_attachment_ids($noticeBody),
                    ]);
                    ?>
                    <?= $noticeFilesHtml ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php /*
          公告分页：条数超过每页上限（config app.per_page）时才出现，
          与下方「我的通知」共用 .pager —— 全站分页同一个样式。
        */ ?>
        <?php if (($noticePagination ?? '') !== ''): ?>
            <div class="pager"><?= (string)$noticePagination ?></div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel__head">
            <h2><?= $view('partials/icon', ['name' => 'bell', 'size' => 17]) ?>我的通知</h2>
            <span class="spacer"></span>
            <span class="text-light" style="font-size:13px">共 <?= format_number((int)($result['total'] ?? 0)) ?> 条</span>
            <?php if ($unread > 0): ?>
                <span class="badge"><?= $unread ?> 条未读</span>
                <form method="post" action="<?= e(url('/notifications/read')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="button small ghost">
                        <?= $view('partials/icon', ['name' => 'check', 'size' => 15]) ?>
                        <span>全部标为已读</span>
                    </button>
                </form>
            <?php else: ?>
                <span class="badge outline">已全部读完</span>
            <?php endif; ?>
        </div>

        <?php if ($items === []): ?>
            <div class="empty">
                <?= $view('partials/icon', ['name' => 'bell', 'size' => 46]) ?>
                <p>暂时没有任何通知。</p>
                <p class="text-light" style="font-size:13px">
                    当有人评论你的帖子、引用你的评论，或在帖子里 @ 你时，这里会出现提醒。
                </p>
            </div>
        <?php else: ?>
            <div class="panel__body--flush">
                <?php foreach ($items as $notice): ?>
                    <?php
                    $noticeId   = (int)($notice['id'] ?? 0);
                    $isRead     = (int)($notice['is_read'] ?? 0) === 1;
                    $kind       = (string)($notice['kind'] ?? 'system');
                    $kindName   = (string)($notice['kind_name'] ?? '通知');
                    $icon       = $kindIcons[$kind] ?? 'dot';
                    $link       = (string)($notice['link'] ?? '');
                    $sender     = is_array($notice['sender'] ?? null) ? $notice['sender'] : [];
                    $senderId   = (int)($sender['id'] ?? 0);
                    $senderName = (string)($sender['username'] ?? '系统');
                    $createdAt  = (int)($notice['created_at'] ?? 0);
                    ?>
                    <div class="notice-item<?= $isRead ? '' : ' notice-item--unread' ?>">
                        <div class="notice-item__icon">
                            <?= $view('partials/icon', ['name' => $icon, 'size' => 17]) ?>
                        </div>

                        <div class="notice-item__body">
                            <div class="notice-item__text">
                                <span class="badge outline" style="margin-right:6px"><?= e($kindName) ?></span>

                                <?php if ($senderId > 0): ?>
                                    <a href="<?= e(url('/u/' . $senderId)) ?>"><?= e($senderName) ?></a>
                                <?php else: ?>
                                    <strong><?= e($senderName) ?></strong>
                                <?php endif; ?>

                                <?php if (trim((string)($notice['content'] ?? '')) !== ''): ?>
                                    <span class="text-light">·</span> <?= e((string)$notice['content']) ?>
                                <?php endif; ?>
                            </div>

                            <div class="hstack" style="margin-top:6px;gap:12px">
                                <time datetime="<?= e(date('c', $createdAt)) ?>"><?= e(human_time($createdAt)) ?></time>

                                <?php if ($link !== ''): ?>
                                    <a href="<?= e($link) ?>" style="font-size:12.5px">查看详情</a>
                                <?php endif; ?>

                                <?php if (!$isRead && $noticeId > 0): ?>
                                    <form method="post" action="<?= e(url('/notifications/read')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $noticeId ?>">
                                        <button type="submit" class="button small ghost"
                                                style="height:auto;padding:1px 8px;font-size:12.5px">
                                            标为已读
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (($pagination ?? '') !== ''): ?>
        <div class="pager"><?= (string)$pagination ?></div>
    <?php endif; ?>
    </div>

    <?= $view('partials/sidebar') ?>
</div>
