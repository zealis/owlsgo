<?php
/**
 * 编辑回复
 *
 * 变量：$post（原始记录）、$thread、$forum
 */

declare(strict_types=1);

$post    = is_array($post ?? null) ? $post : [];
$thread  = is_array($thread ?? null) ? $thread : [];
$forum   = is_array($forum ?? null) ? $forum : [];

$postId     = (int)($post['id'] ?? 0);
$threadId   = (int)($post['thread_id'] ?? ($thread['id'] ?? 0));
$forumId    = (int)($thread['forum_id'] ?? 0);
$contentMax = (int)config('app.post_max_length', 20000);
$contentValue = (string)old('content', (string)($post['content'] ?? ''));
?>


<section class="panel">
    <div class="panel__head">
        <h2><?= $view('partials/icon', ['name' => 'edit', 'size' => 17]) ?>编辑回复</h2>
        <?php if ((int)($post['floor'] ?? 0) > 0): ?>
            <span class="spacer"></span>
            <span class="text-light" style="font-size:13px">#<?= (int)$post['floor'] ?> 楼</span>
        <?php endif; ?>
    </div>

    <div class="panel__body">
        <?php /* data-draft：按回复 id 区分草稿 */ ?>
        <form method="post" action="<?= e(url('/p/' . $postId . '/edit')) ?>"
              data-ajax data-ajax-redirect data-draft="post-edit-<?= (int)$postId ?>">
            <?= csrf_field() ?>

            <div data-field>
                <label for="post-content">回复内容</label>
                <?= $view('partials/editor', [
                    'editorId'    => 'post-content',
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
                <a class="button ghost" href="<?= e(url('/t/' . $threadId, ['p' => $postId])) ?>">取消</a>
            </div>
        </form>
    </div>
</section>
