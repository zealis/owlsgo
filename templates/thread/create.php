<?php
/**
 * 发表新主题
 *
 * 变量：$forums（可发帖版块）、$forum（当前选中版块）、$uploadEnabled、$maxUploadMb
 */

declare(strict_types=1);

$forums      = is_array($forums ?? null) ? $forums : [];
$forum       = is_array($forum ?? null) ? $forum : [];
$uploadOn    = (bool)($uploadEnabled ?? false);
$maxMb       = (int)($maxUploadMb ?? 2);
$forumId     = (int)($forum['id'] ?? 0);
$titleMax    = (int)config('app.thread_title_max', 80);
$contentMax  = (int)config('app.post_max_length', 20000);
?>

<ol class="unstyled hstack crumbs">
    <li><a class="unstyled" href="<?= e(url('/')) ?>">首页</a></li>
    <li aria-hidden="true">/</li>
    <li>发表新主题</li>
</ol>

<section class="panel">
    <div class="panel__head">
        <h2><?= $view('partials/icon', ['name' => 'plus', 'size' => 17]) ?>发表新主题</h2>
    </div>

    <div class="panel__body">
        <?php /* data-draft：草稿自动保存的键名（发帖页共用一个草稿） */ ?>
        <form method="post" action="<?= e(url('/new')) ?>" enctype="multipart/form-data"
              data-ajax data-ajax-redirect data-draft="thread-new">
            <?= csrf_field() ?>

            <div data-field>
                <label for="thread-forum">发布到版块</label>
                <select id="thread-forum" name="forum_id" required>
                    <?php foreach ($forums as $option): ?>
                        <?php $optionId = (int)($option['id'] ?? 0); ?>
                        <option value="<?= $optionId ?>"<?= selected($optionId, $forumId) ?>>
                            <?= e((string)($option['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div data-field>
                <label for="thread-title">主题标题</label>
                <input type="text" id="thread-title" name="title" required
                       maxlength="<?= $titleMax ?>" autocomplete="off"
                       value="<?= e((string)old('title', '')) ?>"
                       placeholder="请用一句话概括主题（4-<?= $titleMax ?> 字）">
                <?php if (old_error('title') !== ''): ?>
                    <span class="field-error"><?= e(old_error('title')) ?></span>
                <?php endif; ?>
            </div>

            <div data-field>
                <label for="thread-content">正文内容</label>
                <?= $view('partials/editor', [
                    'editorId'    => 'thread-content',
                    'editorValue' => (string)old('content', ''),
                    'editorMax'   => $contentMax,
                    'editorUpload' => $uploadOn,
                    'editorMaxMb' => $maxMb,
                ]) ?>
                <?php if (old_error('content') !== ''): ?>
                    <span class="field-error"><?= e(old_error('content')) ?></span>
                <?php endif; ?>
            </div>

            <div class="hstack mt-4">
                <button type="submit" class="button">
                    <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                    <span>发表主题</span>
                </button>
                <a class="button ghost" href="<?= e($forumId > 0 ? url('/f/' . $forumId) : url('/')) ?>">取消</a>
                <?php if (setting_bool('thread_need_audit', false)): ?>
                    <span class="text-light" style="font-size:12.5px">本站开启了发帖审核，主题需管理员通过后才会公开。</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>
