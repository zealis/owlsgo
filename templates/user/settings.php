<?php
/**
 * 账号设置：个人资料 / 修改密码 / 头像
 *
 * 变量：$profile、$uploadEnabled、$avatarMaxMb、$avatarStyles
 *
 * 说明：三个表单分开提交，各自独立校验；不使用 tab 切换以减少对 JS 的依赖，
 * 保证任何环境下都能正常操作。
 */

declare(strict_types=1);

$profile       = is_array($profile ?? null) ? $profile : [];
$uploadEnabled = (bool)($uploadEnabled ?? false);
$avatarMaxMb   = (int)($avatarMaxMb ?? 2);
$avatarStyles  = is_array($avatarStyles ?? null) ? $avatarStyles : [];

$groupName = (string)($profile['group_name'] ?? '游客');
$email     = (string)($profile['email'] ?? '');
?>

<?= $view('partials/profile-hero', ['profile' => $profile]) ?>

<section class="panel mb-4">
    <div class="panel__head">
        <h2><?= $view('partials/icon', ['name' => 'settings', 'size' => 17]) ?>账号设置</h2>
    </div>
    <div class="panel__body" style="padding-top:12px;padding-bottom:12px">
        <div class="hstack" style="font-size:13.5px">
            <span class="text-light">用户名：</span>
            <strong><?= e((string)($profile['username'] ?? '')) ?></strong>
            <span style="width:14px"></span>
            <span class="text-light">邮箱：</span>
            <strong><?= e($email) ?></strong>
            <span class="text-light">（用户名与邮箱为账号标识，如需修改请联系管理员）</span>
        </div>
    </div>
</section>

<?= $view('partials/user-nav', ['userNavProfile' => $profile, 'userNavActive' => 'settings']) ?>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'user', 'size' => 16]) ?>个人资料</h3>
    </div>
    <div class="panel__body">
        <form method="post" action="<?= e(url('/settings/profile')) ?>">
            <?= csrf_field() ?>

            <div class="form-grid">
                <div data-field>
                    <label for="profile-signature">个性签名</label>
                    <span class="field-hint" style="margin:0 0 6px">
                        签名显示在你发表的每个主题和回复下方。支持 BBCode 格式（如 [b]加粗[/b]、[color=red]颜色[/color]）；不支持图片。
                    </span>
                    <input type="text" id="profile-signature" name="signature" maxlength="100"
                           value="<?= e((string)old('signature', (string)($profile['signature'] ?? ''))) ?>"
                           placeholder="签名内容">
                    <span class="field-hint">最多 100 个字符，留空可删除签名。</span>
                    <?php if (old_error('signature') !== ''): ?>
                        <span class="field-error"><?= e(old_error('signature')) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div data-field>
                <label for="profile-bio">个人简介</label>
                <textarea id="profile-bio" name="bio" rows="4" maxlength="500"
                          placeholder="介绍一下自己（最多 500 字）"><?= e((string)old('bio', (string)($profile['bio'] ?? ''))) ?></textarea>
            </div>

            <button type="submit" class="button">
                <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                <span>保存个人资料</span>
            </button>
        </form>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'image', 'size' => 16]) ?>头像</h3>
    </div>
    <div class="panel__body">
        <div class="hstack" style="align-items:flex-start">
            <div id="avatar-preview">
                <img src="<?= e(avatar_url($profile, 96)) ?>" width="96" height="96"
                     alt="当前头像"
                     style="border-radius:999px;border:1px solid var(--qq-line)">
            </div>

            <div style="min-width:0;flex:1 1 auto">
                <?php if ($uploadEnabled): ?>
                    <!--
                        头像三件套：
                        1. 「上传头像」→ 选文件后弹裁切对话框（缩放 + 居中裁切），
                           「上传并应用」把裁切结果直接 POST 到 /settings/avatar，上传即生效；
                        2. 「预置头像」→ 打开内置头像库（SVG 实时生成，不占磁盘）。
                        原生 file input 隐藏（不显示文件名），由按钮代理。
                    -->
                    <form method="post" action="<?= e(url('/settings/avatar')) ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <div data-field>
                            <input type="file" id="avatar-file" name="avatar" accept="image/png,image/jpeg,image/webp"
                                   data-avatar-crop hidden>
                            <div class="hstack" style="gap:10px">
                                <button type="button" class="button small" id="avatar-pick">
                                    <?= $view('partials/icon', ['name' => 'upload', 'size' => 15]) ?>
                                    <span>上传头像</span>
                                </button>
                                <span class="text-light" style="font-size:12.5px">or</span>
                                <button type="button" class="button ghost small" id="avatar-preset-open">
                                    <?= $view('partials/icon', ['name' => 'image', 'size' => 15]) ?>
                                    <span>预置头像</span>
                                </button>
                            </div>
                            <span data-hint>
                                支持 JPG / PNG / WebP，单个文件不超过 <?= $avatarMaxMb ?> MB。
                                选择后在弹窗中缩放与裁切，「上传并应用」后立即生效。
                            </span>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="doc-note hstack" style="gap:10px;align-items:flex-start">
                        <?= $view('partials/icon', ['name' => 'info', 'size' => 18]) ?>
                        <div>站点当前已关闭文件上传，无法更换头像。</div>
                    </div>
                <?php endif; ?>

                <p class="text-light" style="font-size:12.5px;margin-top:12px">
                    未上传头像时，系统会依据你的用户名生成一张固定的 SVG 头像（零外部请求）。
                </p>
            </div>
        </div>

        <?php if ($avatarStyles !== []): ?>
            <?php $seed = \Core\Avatar::seed($profile); ?>
            <div class="doc-note" style="margin-top:14px">
                <strong>内置头像风格预览（点击图片可在新窗口查看大图）：</strong>
                <div class="hstack" style="margin-top:8px">
                    <?php foreach ($avatarStyles as $style): ?>
                        <?php $styleUrl = url('/avatar/' . rawurlencode($seed) . '.svg', ['s' => 48, 'style' => (string)$style]); ?>
                        <div style="text-align:center">
                            <a href="<?= e($styleUrl) ?>" target="_blank" rel="noopener">
                                <img src="<?= e($styleUrl) ?>" width="48" height="48"
                                     alt="<?= e((string)$style) ?> 风格头像"
                                     style="border-radius:999px;border:1px solid var(--qq-line)">
                            </a>
                            <div class="text-light" style="font-size:11.5px;margin-top:2px"><?= e((string)$style) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'key', 'size' => 16]) ?>修改密码</h3>
    </div>
    <div class="panel__body">
        <form method="post" action="<?= e(url('/settings/password')) ?>">
            <?= csrf_field() ?>

            <div data-field>
                <label for="password-current">当前密码</label>
                <input type="password" id="password-current" name="current_password" required
                       autocomplete="current-password">
                <?php if (old_error('current_password') !== ''): ?>
                    <span class="field-error"><?= e(old_error('current_password')) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-grid">
                <div data-field>
                    <label for="password-new">新密码</label>
                    <input type="password" id="password-new" name="password" required
                           autocomplete="new-password">
                    <?php if (old_error('password') !== ''): ?>
                        <span class="field-error"><?= e(old_error('password')) ?></span>
                    <?php endif; ?>
                </div>

                <div data-field>
                    <label for="password-confirm">确认新密码</label>
                    <input type="password" id="password-confirm" name="password_confirm" required
                           autocomplete="new-password">
                    <?php if (old_error('password_confirm') !== ''): ?>
                        <span class="field-error"><?= e(old_error('password_confirm')) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="hstack">
                <button type="submit" class="button">
                    <?= $view('partials/icon', ['name' => 'lock', 'size' => 16]) ?>
                    <span>更新密码</span>
                </button>
                <span class="text-light" style="font-size:12.5px">
                    修改密码后，其他设备上的登录状态会失效，需要重新登录。
                </span>
            </div>
        </form>
    </div>
</section>

<?php
/*
 * 头像相关的两个对话框：
 *  - #avatar-crop：上传裁切（缩放 + 居中裁切，app.js 的 canvas 实现负责绘制与导出）；
 *  - #avatar-preset-dialog：预置头像库（Core\Avatar::presets()，SVG 实时生成不占磁盘）。
 * 选择预置头像通过 #avatar-preset-form 提交到 /settings/avatar/preset。
 */
$presets = \Core\Avatar::presets();
?>
<dialog id="avatar-crop" class="avatar-dialog">
    <div class="avatar-dialog__head">调整头像</div>
    <div class="avatar-dialog__stage">
        <canvas id="avatar-crop-canvas" width="280" height="280"></canvas>
    </div>
    <div class="avatar-dialog__zoom">
        <span>缩放</span>
        <input type="range" id="avatar-crop-zoom" min="1" max="3" step="0.01" value="1">
    </div>
    <div class="confirm-dialog__actions">
        <button type="button" class="button ghost" data-crop-cancel>取消</button>
        <button type="button" class="button" data-crop-ok>上传并应用</button>
    </div>
</dialog>

<dialog id="avatar-preset-dialog" class="avatar-dialog">
    <div class="avatar-dialog__head">选择预置头像</div>
    <div class="avatar-preset-grid">
        <?php foreach ($presets as $preset): ?>
            <button type="button" class="avatar-preset"
                    data-preset-seed="<?= e($preset['seed']) ?>"
                    data-preset-style="<?= e($preset['style']) ?>"
                    title="使用该头像">
                <img src="<?= e(url('/avatar/' . rawurlencode($preset['seed']) . '.svg', ['s' => 96, 'style' => $preset['style']])) ?>"
                     width="72" height="72" alt="">
            </button>
        <?php endforeach; ?>
    </div>
    <div class="confirm-dialog__actions">
        <button type="button" class="button ghost" data-preset-cancel>取消</button>
    </div>
</dialog>

<form method="post" action="<?= e(url('/settings/avatar/preset')) ?>" id="avatar-preset-form" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="seed" value="">
    <input type="hidden" name="style" value="">
</form>
