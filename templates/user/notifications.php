<?php
/**
 * 通知中心
 *
 * 变量：$result（items 已 decorate）、$unread、$pagination
 *
 * 每条通知携带 link 字段（指向主题楼层），kind 决定图标。
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
 * 全站通知（公告）区域。
 *  - 所有登录用户都能看到「公开显示」的公告；
 *  - 拥有 notice.manage（用户组里的「发布公告」）的用户额外看到：
 *    发布公告 / 通知中心设置，以及每条公告上的「编辑公告」；
 *  - 「附件设置」入口直接跳到后台设置的附件分区（附件总空间、允许的扩展名都在那儿），
 *    所以它看的是 admin.settings —— 与目标页的准入保持一致，点过去不会撞 403。
 * 公告正文与帖子共用 Markdown 渲染，正文为空时不输出空容器。
 */
$canManage       = \Core\Permission::allows(auth_user(), 'notice.manage');
$canAttachConfig = \Core\Permission::allows(auth_user(), 'admin.settings');
$notices   = \Modules\Notice\NoticeModel::published();
$noticeIntro = trim((string)setting('notice_center_intro', ''));
$hiddenCount = $canManage ? \Modules\Notice\NoticeModel::hiddenCount() : 0;
?>

<section class="panel mb-4">
    <div class="panel__head">
        <h2><?= $view('partials/icon', ['name' => 'megaphone', 'size' => 17]) ?>全站通知</h2>
        <span class="spacer"></span>
        <?php if ($canManage || $canAttachConfig): ?>
            <?php if ($canManage && $hiddenCount > 0): ?>
                <span class="badge outline" title="未公开的公告只有拥有发布权限的用户能看到"><?= $hiddenCount ?> 条未公开</span>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <a class="button ghost small" href="<?= e(url('/notices/create')) ?>">
                    <?= $view('partials/icon', ['name' => 'plus', 'size' => 15]) ?>
                    <span>发布公告</span>
                </a>
            <?php endif; ?>
            <?php if ($canAttachConfig): ?>
                <a class="button ghost small" href="<?= e(url('/admin/settings')) ?>">附件设置</a>
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
                <div class="notice-card__bar">
                    <span class="notice-card__name">
                        <?= e((string)($notice['name'] ?? '站点公告')) ?>
                        <?php if (!$isPublic): ?>
                            <span class="badge outline">未公开</span>
                        <?php endif; ?>
                    </span>
                    <span class="spacer"></span>
                    <?php if ($canManage): ?>
                        <a class="notice-card__edit" href="<?= e(url('/notices/' . $noticeId . '/edit')) ?>">编辑公告</a>
                    <?php endif; ?>
                </div>
                <h3 class="notice-card__title"><?= e((string)($notice['title'] ?? '')) ?></h3>
                <?php if ($noticeBody !== ''): ?>
                    <div class="notice-card__body"><?= \Core\Text::toHtml($noticeBody) ?></div>
                <?php endif; ?>

                <?php
                // 公告引用的附件：复用帖子里的 .attach-list / .attach 样式，观感与楼层一致；
                // 权限口径也与楼层一致 —— 没有「下载附件」权限时只列文件名、不做成链接
                $noticeFiles = is_array($notice['attachments'] ?? null) ? $notice['attachments'] : [];
                $canDownloadAttach = \Core\Permission::allows(auth_user(), 'attachment.download')
                    || \Core\Permission::allows(auth_user(), 'attachment.manage');
                ?>
                <?php if ($noticeFiles !== []): ?>
                    <div class="attach-list">
                        <?php foreach ($noticeFiles as $file): ?>
                            <?php $isImage = (int)($file['is_image'] ?? 0) === 1; ?>
                            <?php if ($canDownloadAttach): ?>
                                <a class="attach" href="<?= e(url('/attachment/' . (int)$file['id'])) ?>"
                                   <?= $isImage ? 'target="_blank" rel="noopener"' : '' ?>>
                                    <?= $view('partials/icon', ['name' => $isImage ? 'image' : 'paperclip', 'size' => 17]) ?>
                                    <span><?= e((string)($file['name'] ?? '附件')) ?></span>
                                    <span class="attach__size"><?= e(format_size((int)($file['size'] ?? 0))) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="attach attach--locked"
                                      title="当前用户组没有查看附件的权限，请先登录或联系管理员">
                                    <?= $view('partials/icon', ['name' => 'lock', 'size' => 17]) ?>
                                    <span><?= e((string)($file['name'] ?? '附件')) ?></span>
                                    <span class="attach__size"><?= e(format_size((int)($file['size'] ?? 0))) ?></span>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
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
                当有人回复你的主题、引用你的回复，或在帖子里 @ 你时，这里会出现提醒。
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
    <div class="mt-4"><?= (string)$pagination ?></div>
<?php endif; ?>
