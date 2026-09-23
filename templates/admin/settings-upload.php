<?php
/**
 * 后台设置：附件
 *
 * 分组 slug：upload
 * 字段：upload_enabled / upload_max_size / attachment_quota / upload_allow_ext / cache_ttl
 *
 * 变量：$val、$isOn（取值助手）、$uploadMax、$postMax、$uploadDirSize
 *
 * 「缓存有效期」跟着这一页：它管的是版块与设置项的缓存时长，
 * 与附件占用的那套统计同属「服务器侧开销」，历史上就放在一起。
 */

declare(strict_types=1);
?>
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'paperclip', 'size' => 16]) ?>附件</h3>
        <span class="spacer"></span>
        <span class="ow-text-light" style="font-size:12.5px">当前占用 <?= e($uploadDirSize) ?></span>
    </div>
    <div class="panel__body">
        <?= $view('partials/admin-switch', [
            'name'    => 'upload_enabled',
            'label'   => '允许上传附件',
            'hint'    => '关闭后发帖页与头像上传都会被禁用。',
            'checked' => $isOn('upload_enabled', '1'),
        ]) ?>

        <div class="form-grid">
            <div data-ow-field>
                <label for="upload_max_size">单个文件上限（MB）</label>
                <input type="number" id="upload_max_size" name="upload_max_size" min="1" max="512"
                       value="<?= e($val('upload_max_size', '4')) ?>">
                <span data-ow-hint>
                    服务器限制：upload_max_filesize = <?= e($uploadMax) ?>，
                    post_max_size = <?= e($postMax) ?>。
                </span>
            </div>

            <div data-ow-field>
                <label for="attachment_quota">附件总空间上限（MB）</label>
                <input type="number" id="attachment_quota" name="attachment_quota" min="0" max="1048576"
                       value="<?= e($val('attachment_quota', '0')) ?>">
                <span data-ow-hint>
                    所有用户附件加起来的上限（全站合计，不是每人上限），0 表示不限制。
                    当前已占用 <?= e($uploadDirSize) ?>，达到上限后新的上传会被拒绝。
                </span>
            </div>

            <div data-ow-field>
                <label for="cache_ttl">缓存有效期（秒）</label>
                <input type="number" id="cache_ttl" name="cache_ttl" min="0" max="86400"
                       value="<?= e($val('cache_ttl', '300')) ?>">
                <span data-ow-hint>版块与设置项的缓存时长，0 表示每次请求都重新读取。</span>
            </div>
        </div>

        <div data-ow-field>
            <label for="upload_allow_ext">允许上传的扩展名</label>
            <input type="text" id="upload_allow_ext" name="upload_allow_ext" maxlength="500"
                   value="<?= e($val('upload_allow_ext', 'jpg,jpeg,png,gif,webp,zip,pdf,txt')) ?>">
            <span data-ow-hint>
                使用逗号分隔，可带点号。svg 类不在允许范围内（它是 XML，可内嵌脚本）；
                php / html 等源码类扩展名即使写进来，落盘时也会被改名（shell.php → shell.php1），
                不会被服务器当作脚本执行。
            </span>
        </div>
    </div>

    <?= $view('partials/admin-save-foot') ?>
</section>
