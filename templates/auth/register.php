<?php
/**
 * 注册
 *
 * 变量：$requireVerify（是否开启了邮箱验证开关）、$captchaEnabled（是否开启注册验证码）
 */

declare(strict_types=1);

$requireVerify  = (bool)($requireVerify ?? false);
$captchaEnabled = (bool)($captchaEnabled ?? false);
?>

<form method="post" action="<?= e(url('/register')) ?>" autocomplete="on">
    <?= csrf_field() ?>

    <div data-field>
        <label for="register-username">用户名</label>
        <input type="text" id="register-username" name="username" required autofocus
               autocomplete="username" maxlength="20"
               value="<?= e((string)old('username', '')) ?>"
               placeholder="2-20 个字符，支持中英文与数字">
        <?php if (old_error('username') !== ''): ?>
            <span class="field-error"><?= e(old_error('username')) ?></span>
        <?php endif; ?>
    </div>

    <div data-field>
        <label for="register-email">邮箱</label>
        <input type="email" id="register-email" name="email" required
               autocomplete="email" maxlength="191"
               value="<?= e((string)old('email', '')) ?>">
        <?php if (old_error('email') !== ''): ?>
            <span class="field-error"><?= e(old_error('email')) ?></span>
        <?php endif; ?>
    </div>

    <div data-field>
        <label for="register-password">密码</label>
        <input type="password" id="register-password" name="password" required
               autocomplete="new-password">
        <?php if (old_error('password') !== ''): ?>
            <span class="field-error"><?= e(old_error('password')) ?></span>
        <?php endif; ?>
    </div>

    <div data-field>
        <label for="register-password-confirm">确认密码</label>
        <input type="password" id="register-password-confirm" name="password_confirm" required
               autocomplete="new-password">
        <?php if (old_error('password_confirm') !== ''): ?>
            <span class="field-error"><?= e(old_error('password_confirm')) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($captchaEnabled): ?>
        <div data-field>
            <label for="register-captcha">验证码</label>
            <div class="captcha-row">
                <input type="text" id="register-captcha" name="captcha" required
                       autocomplete="off" maxlength="12" placeholder="输入图中字符">
                <img data-captcha-img src="<?= e(url('/captcha', ['scope' => 'register'])) ?>"
                     data-src="<?= e(url('/captcha', ['scope' => 'register'])) ?>"
                     alt="图形验证码" width="132" height="44" title="点击换一张">
            </div>
            <span data-hint>看不清？点击图片换一张。不区分大小写。</span>
            <?php if (old_error('captcha') !== ''): ?>
                <span class="field-error"><?= e(old_error('captcha')) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($requireVerify): ?>
        <div class="doc-note hstack" style="gap:10px;align-items:flex-start;margin-bottom:var(--space-4)">
            <?= $view('partials/icon', ['name' => 'mail', 'size' => 18]) ?>
            <div>本站建议使用真实邮箱，以便后续找回账号。</div>
        </div>
    <?php endif; ?>

    <button type="submit" class="button w-100">
        <?= $view('partials/icon', ['name' => 'user', 'size' => 16]) ?>
        <span>注册并登录</span>
    </button>
</form>

<p class="auth-foot">
    已有账号？<a href="<?= e(url('/login')) ?>">直接登录</a>
    <br>
    <a href="<?= e(url('/')) ?>">返回首页</a>
</p>
