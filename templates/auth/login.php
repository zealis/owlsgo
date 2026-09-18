<?php
/**
 * 登录
 *
 * 说明：认证页面统一使用 layouts/auth 布局，该布局已包含一次性提示与校验错误，
 * 因此这里不再重复渲染，避免同一条错误出现两次。
 */

declare(strict_types=1);

$registerEnabled = (bool)setting_bool('register_enabled', true);
$captchaEnabled  = (bool)($captchaEnabled ?? false);
?>

<form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
    <?= csrf_field() ?>

    <div data-field>
        <label for="login-account">用户名或邮箱</label>
        <input type="text" id="login-account" name="login" required autofocus
               autocomplete="username" maxlength="191"
               value="<?= e((string)old('login', '')) ?>">
    </div>

    <div data-field>
        <label for="login-password">密码</label>
        <input type="password" id="login-password" name="password" required
               autocomplete="current-password">
    </div>

    <?php if ($captchaEnabled): ?>
        <div data-field>
            <label for="login-captcha">验证码</label>
            <div class="captcha-row">
                <input type="text" id="login-captcha" name="captcha" required
                       autocomplete="off" maxlength="12" placeholder="输入图中字符">
                <img data-captcha-img src="<?= e(url('/captcha')) ?>"
                     data-src="<?= e(url('/captcha')) ?>"
                     alt="图形验证码" width="132" height="44" title="点击换一张">
            </div>
            <span data-hint>看不清？点击图片换一张。不区分大小写。</span>
        </div>
    <?php endif; ?>

    <label>
        <input type="checkbox" name="remember" value="1">
        <span>记住我（180 天内免登录）</span>
    </label>
    <button type="submit" class="button w-100 mt-4">
        <?= $view('partials/icon', ['name' => 'key', 'size' => 16]) ?>
        <span>登录</span>
    </button>
</form>

<p class="auth-foot">
    <?php if ($registerEnabled): ?>
        还没有账号？<a href="<?= e(url('/register')) ?>">立即注册</a>
    <?php else: ?>
        本站当前已关闭注册，请联系管理员开通账号。
    <?php endif; ?>
    <br>
    <a href="<?= e(url('/')) ?>">返回首页</a>
</p>
