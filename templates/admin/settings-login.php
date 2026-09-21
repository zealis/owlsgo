<?php
/**
 * 后台设置：注册与登录
 *
 * 分组 slug：login
 * 字段：register_enabled / register_verify / register_captcha / login_captcha /
 *       guest_view / register_group
 *
 * 变量：$val、$isOn（取值助手）、$groups（用户组下拉）
 */

declare(strict_types=1);

/** 开关文案：键 => [标题, 说明] */
$toggles = [
    'register_enabled' => ['开放注册', '关闭后注册页会提示暂停注册。'],
    'register_verify'  => ['注册需验证邮箱', '开启后新用户需要完成邮箱验证才能发言。'],
    'login_captcha'    => ['登录需要验证码', '在登录失败次数较多时建议开启。'],
    'register_captcha' => ['注册需要验证码', '用于拦截批量注册机器人，建议与「登录需要验证码」一起开启。'],
    'guest_view'       => ['允许游客浏览', '关闭后必须登录才能查看帖子内容。'],
];
?>
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'user', 'size' => 16]) ?>注册与登录</h3>
    </div>
    <div class="panel__body">
        <?php foreach (['register_enabled', 'register_verify', 'login_captcha', 'register_captcha', 'guest_view'] as $key): ?>
            <?= $view('partials/admin-switch', [
                'name'    => $key,
                'label'   => $toggles[$key][0],
                'hint'    => $toggles[$key][1],
                'checked' => $isOn($key, $key === 'register_enabled' || $key === 'guest_view' ? '1' : '0'),
            ]) ?>
        <?php endforeach; ?>

        <div data-field style="max-width:280px">
            <label for="register_group">新用户默认用户组</label>
            <select id="register_group" name="register_group">
                <?php foreach ($groups as $groupId => $groupName): ?>
                    <option value="<?= (int)$groupId ?>"
                        <?= selected($val('register_group', '3'), (string)$groupId) ?>>
                        <?= e((string)$groupName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?= $view('partials/admin-save-foot') ?>
</section>
