<?php
/**
 * 通知中心 · 公告编辑表单（新建与编辑共用）
 *
 * 变量：$notice（null 表示新建）、$action、$heading、$maxMb
 *
 * 附件：复用发布器的上传组件（POST /upload + 隐藏域 attachments[]），
 * 与本项目发帖时的附件机制完全一致，保存后由控制器写入 attachment_ids。
 */

declare(strict_types=1);

$isEdit = is_array($notice) && $notice !== [];
$val    = static fn (string $key, string $default = ''): string
    => (string)old($key, $isEdit ? (string)($notice[$key] ?? $default) : $default);

/* 复选框：默认「公开」，校验回填时以 old() 为准（未勾选不会出现在请求里） */
$enabledDefault = $isEdit ? ((int)($notice['enabled'] ?? 1) === 1) : true;
$enabledChecked = (string)old('enabled', $enabledDefault ? '1' : '') === '1';
?>
<ol class="unstyled hstack crumbs">
    <li><a class="unstyled" href="<?= e(url('/notifications')) ?>">通知中心</a></li>
    <li aria-hidden="true">/</li>
    <li><?= e($heading) ?></li>
</ol>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'megaphone', 'size' => 16]) ?><?= e($heading) ?></h3>
    </div>

    <div class="panel__body">
        <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="form-grid">
                <div data-field>
                    <label for="notice-name">公告名称</label>
                    <input type="text" id="notice-name" name="name" maxlength="100"
                           value="<?= e($val('name', '站点公告')) ?>" autocomplete="off">
                    <span class="field-hint">仅用于在列表里标识这条公告，不影响正文。</span>
                </div>

                <div data-field>
                    <label for="notice-sort">显示顺序</label>
                    <input type="number" id="notice-sort" name="sort" min="0" max="9999"
                           value="<?= e($val('sort', (string)\Modules\Notice\NoticeModel::DEFAULT_SORT)) ?>">
                    <span class="field-hint">数字越小越靠前。</span>
                </div>
            </div>

            <div data-field>
                <label for="notice-title">公告标题</label>
                <input type="text" id="notice-title" name="title" required maxlength="200"
                       value="<?= e($val('title')) ?>" autocomplete="off"
                       placeholder="例如：欢迎来到本站">
                <?php if (old_error('title') !== ''): ?>
                    <span class="field-error"><?= e(old_error('title')) ?></span>
                <?php endif; ?>
            </div>

            <div data-field>
                <label for="notice-body">公告内容</label>
                <?= $view('partials/editor', [
                    'editorName'        => 'body',
                    'editorId'          => 'notice-body',
                    'editorValue'       => $val('body'),
                    'editorMax'         => 20000,
                    'editorUpload'      => true,
                    'editorMaxMb'       => (int)($maxMb ?? 2),
                    'editorPlaceholder' => '支持与帖子相同的 Markdown 格式，可插入图片与附件…',
                ]) ?>
                <?php if (old_error('body') !== ''): ?>
                    <span class="field-error"><?= e(old_error('body')) ?></span>
                <?php endif; ?>
            </div>

            <div data-field>
                <label class="checkbox-line">
                    <input type="checkbox" name="enabled" value="1"<?= $enabledChecked ? ' checked' : '' ?>>
                    <span>公开显示</span>
                </label>
                <span class="field-hint">关闭后普通用户在通知中心看不到这条公告，仅拥有「发布公告」权限的用户可见。</span>
            </div>

            <div class="hstack mt-4">
                <button type="submit" class="button">
                    <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                    <span>保存公告</span>
                </button>
                <a class="button ghost" href="<?= e(url('/notifications')) ?>">返回通知中心</a>
            </div>
        </form>
    </div>
</section>
