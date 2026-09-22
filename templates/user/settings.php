<?php
/**
 * 账号设置：个人资料 / 修改密码 / 个人主页封面图
 *
 * 变量：$profile、$uploadEnabled、$avatarMaxMb
 *
 * 说明：三个表单分开提交，各自独立校验；不使用 tab 切换以减少对 JS 的依赖，
 * 保证任何环境下都能正常操作。
 */

declare(strict_types=1);

$profile       = is_array($profile ?? null) ? $profile : [];
$uploadEnabled = (bool)($uploadEnabled ?? false);
$avatarMaxMb   = (int)($avatarMaxMb ?? 2);

/* 「我的隐私」开关的当前状态（缺字段时按所有人可见） */
$publicThreads = (bool)($profile['public_threads'] ?? true);
$publicPosts   = (bool)($profile['public_posts'] ?? true);
?>

<?= $view('partials/profile-head', ['profile' => $profile, 'active' => 'settings']) ?>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>账号设置</h3>
    </div>
    <div class="panel__body">

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>账号信息</h4>
                    <form method="post" action="<?= e(url('/settings/account')) ?>" data-ajax data-ajax-redirect>
                        <?= csrf_field() ?>

                        <div class="form-grid">
                            <div data-field>
                                <label for="account-username">用户名</label>
                                <input type="text" id="account-username" name="username" maxlength="20" required
                                       value="<?= e((string)old('username', (string)($profile['username'] ?? ''))) ?>"
                                       autocomplete="username">
                                <span class="field-hint">2-20 位，支持中文、字母、数字、下划线；修改后立即生效。</span>
                                <?php if (old_error('username') !== ''): ?>
                                    <span class="field-error"><?= e(old_error('username')) ?></span>
                                <?php endif; ?>
                            </div>

                            <div data-field>
                                <label for="account-email">邮箱</label>
                                <input type="email" id="account-email" name="email" maxlength="191" required
                                       value="<?= e((string)old('email', (string)($profile['email'] ?? ''))) ?>"
                                       autocomplete="email">
                                <span class="field-hint">用于登录与找回密码，请填写常用邮箱。</span>
                                <?php if (old_error('email') !== ''): ?>
                                    <span class="field-error"><?= e(old_error('email')) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php
                        /*
                         * 验证码 / 人机验证的字段插槽 —— 插件可在此追加字段
                         * （如 hook('account_change_fields') 返回图形验证码或 Turnstile 容器），
                         * 服务端校验点见 UserController::verifyAccountChange()。
                         */
                        echo (string)hook('account_change_fields', '', ['user' => $profile]);
                        ?>

                        <button type="submit" class="button">
                            <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                            <span>保存账号信息</span>
                        </button>
                    </form>
    
        </div>

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'user', 'size' => 16]) ?>个人资料</h4>
                    <form method="post" action="<?= e(url('/settings/profile')) ?>">
                        <?= csrf_field() ?>

                        <?php /* 个人简介同时作为楼层签名展示，因此只有一个输入框、统一 100 字上限 */ ?>
                        <div data-field>
                            <label for="profile-bio">个人简介</label>
                            <textarea id="profile-bio" name="bio" rows="3" maxlength="100"
                                      placeholder="介绍一下自己"><?= e((string)old('bio', (string)($profile['bio'] ?? ''))) ?></textarea>
                            <span class="field-hint">
                                显示在你的个人主页，以及你发表的每个帖子和评论下方。最多 100 个字符，留空则不显示。
                            </span>
                            <?php if (old_error('bio') !== ''): ?>
                                <span class="field-error"><?= e(old_error('bio')) ?></span>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="button">
                            <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                            <span>保存个人资料</span>
                        </button>
                    </form>
    
        </div>

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'key', 'size' => 16]) ?>修改密码</h4>
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
    </div>
</section>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'lock', 'size' => 16]) ?>我的隐私</h3>
    </div>
    <div class="panel__body">
        <form method="post" action="<?= e(url('/settings/privacy')) ?>">
            <?= csrf_field() ?>

            <?php /* 开关勾选 = 所有人可见；取消勾选 = 仅自己可见（后端 assertTabVisible 把关） */ ?>
            <div class="privacy-row">
                <div class="privacy-row__label">
                    <strong>帖子</strong>
                    <span class="privacy-row__state">所有人可见</span>
                </div>
                <input type="checkbox" class="switch" name="public_threads" value="1"
                       data-state-text<?= $publicThreads ? ' checked' : '' ?>>
            </div>

            <div class="privacy-row">
                <div class="privacy-row__label">
                    <strong>评论</strong>
                    <span class="privacy-row__state">所有人可见</span>
                </div>
                <input type="checkbox" class="switch" name="public_posts" value="1"
                       data-state-text<?= $publicPosts ? ' checked' : '' ?>>
            </div>

            <p class="field-hint" style="margin:12px 0 0">控制个人主页的标签页权限，保护隐私。</p>

            <button type="submit" class="button" style="margin-top:14px">
                <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                <span>保存隐私设置</span>
            </button>
        </form>
    </div>
</section>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'logout', 'size' => 16]) ?>安全</h3>
    </div>
    <div class="panel__body">
        <div class="hstack">
            <a class="button" href="<?= e(url('/logout')) ?>">
                <?= $view('partials/icon', ['name' => 'logout', 'size' => 16]) ?>
                <span>安全退出</span>
            </a>
        </div>
    </div>
</section>
