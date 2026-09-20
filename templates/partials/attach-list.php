<?php
/**
 * 附件列表（楼层正文与公告正文共用）
 *
 * 传入：
 *   $attachments  附件行数组（每条含 id / name / size / is_image）
 *   $embedded     正文里已引用过的附件 ID 映射（array<int,true>，可选，见 content_attachment_ids()）
 *
 * 规则：
 *   ① **图片并且已经在正文里贴出来了**（Markdown `![](/attachment/N)` / UBB `[img]`）：
 *      不再在下方列表里重复列一遍 —— 正文里已经看得到那张图，再来一份文件名列表是重复信息。
 *      ⚠️ 只在「确实贴进正文」时才跳过：没贴进正文的图片照旧列出，否则那个文件就再也点不到了。
 *   ② 非图片文件（压缩包、文档…）一律列出。
 *   ③ 权限：没有「下载附件」权限时只显示文件名 + 锁，不做成链接（点开只会撞 403）。
 *
 * 列表为空时返回空字符串，调用方据此决定是否输出外层容器。
 */

declare(strict_types=1);

$attachments = is_array($attachments ?? null) ? $attachments : [];
$embedded    = is_array($embedded ?? null) ? $embedded : [];

$files = [];
foreach ($attachments as $file) {
    $fileId  = (int)($file['id'] ?? 0);
    $isImage = (int)($file['is_image'] ?? 0) === 1;

    if ($isImage && isset($embedded[$fileId])) {
        continue;
    }

    $files[] = $file;
}

if ($files === []) {
    return;
}

$canDownload = \Core\Permission::allows(auth_user(), 'attachment.download')
    || \Core\Permission::allows(auth_user(), 'attachment.manage');
?>
<div class="attach-list">
    <?php foreach ($files as $file): ?>
        <?php $isImage = (int)($file['is_image'] ?? 0) === 1; ?>
        <?php if ($canDownload): ?>
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
