<?php
/**
 * 编辑主题（标题 + 首帖内容）
 *
 * 变量：$thread、$forum、$firstPost（原始记录，含 content）、
 */

declare(strict_types=1);

$thread    = is_array($thread ?? null) ? $thread : [];
$forum     = is_array($forum ?? null) ? $forum : [];
$firstPost = is_array($firstPost ?? null) ? $firstPost : [];

$threadId   = (int)($thread['id'] ?? 0);
$forumId    = (int)($forum['id'] ?? 0);
$titleMax   = (int)config('app.thread_title_max', 80);
$contentMax = (int)config('app.post_max_length', 20000);

$titleValue   = (string)old('title', (string)($thread['title'] ?? ''));
$contentValue = (string)old('content', (string)($firstPost['content'] ?? ''));
?>


<section class="panel">
    <div class="panel__head">
        <h2><?= $view('partials/icon', ['name' => 'edit', 'size' => 17]) ?>编辑主题</h2>
    </div>

    <div class="panel__body">
        <?php /* data-draft：编辑也有草稿，未保存的修改刷新后可选恢复 */ ?>
        <form method="post" action="<?= e(url('/t/' . $threadId . '/edit')) ?>"
              data-ajax data-ajax-redirect data-draft="thread-edit-<?= (int)$threadId ?>">
            <?= csrf_field() ?>

            <div data-field>
                <label for="edit-title">主题标题</label>
                <input type="text" id="edit-title" name="title" required
                       maxlength="<?= $titleMax ?>" autocomplete="off"
                       value="<?= e($titleValue) ?>">
                <?php if (old_error('title') !== ''): ?>
                    <span class="field-error"><?= e(old_error('title')) ?></span>
                <?php endif; ?>
            </div>

            <div data-field>
                <label for="edit-content">正文内容</label>
                <?= $view('partials/editor', [
                    'editorId'    => 'edit-content',
                    'editorValue' => $contentValue,
                    'editorMax'   => $contentMax,
                    'editorUpload' => $editorUpload,
                    'editorMaxMb'  => $editorMaxMb,
                    'editorAttachments' => $editorAttachments,
                ]) ?>
                <?php if (old_error('content') !== ''): ?>
                    <span class="field-error"><?= e(old_error('content')) ?></span>
                <?php endif; ?>
            </div>

            <div class="hstack mt-4">
                <button type="submit" class="button">
                    <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                    <span>保存修改</span>
                </button>
                <a class="button ghost" href="<?= e(url('/t/' . $threadId)) ?>">取消</a>
            </div>
        </form>
    </div>
</section>
