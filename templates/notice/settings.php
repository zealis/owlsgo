<?php
/**
 * 通知中心 · 设置
 *
 * 变量：$intro（副标题）、$push（公告是否同步推送到个人通知）
 */

declare(strict_types=1);
?>
<ol class="ow-unstyled ow-hstack crumbs">
    <li><a class="ow-unstyled" href="<?= e(url('/notifications')) ?>">通知中心</a></li>
    <li aria-hidden="true">/</li>
    <li>通知中心设置</li>
</ol>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>通知中心设置</h3>
        <span class="spacer"></span>
        <a class="ow-button ow-ghost ow-small" href="<?= e(url('/notifications')) ?>">返回通知中心</a>
    </div>

    <div class="panel__body">
        <form method="post" action="<?= e(url('/notices/settings')) ?>">
            <?= csrf_field() ?>

            <div data-ow-field>
                <label for="notice-center-intro">通知中心副标题</label>
                <input type="text" id="notice-center-intro" name="notice_center_intro" maxlength="120"
                       value="<?= e($intro) ?>" autocomplete="off"
                       placeholder="发布站点公告与社区消息">
                <span class="field-hint">显示在通知中心页标题下方，留空则不显示。</span>
            </div>

            <div data-ow-field>
                <label class="checkbox-line">
                    <input type="checkbox" name="notice_push_enabled" value="1"<?= $push ? ' checked' : '' ?>>
                    <span>公告同步推送到个人通知</span>
                </label>
                <span class="field-hint">
                    开启后，公开的公告在「新建」或「标题/正文有改动」时，会给所有用户各推送一条系统通知；
                    关闭则公告只在通知中心展示，不打扰用户。修改排序、开关公开状态不会触发推送。
                </span>
            </div>

            <div class="ow-hstack ow-mt-4">
                <button type="submit" class="ow-button">
                    <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                    <span>保存设置</span>
                </button>
            </div>
        </form>
    </div>
</section>
