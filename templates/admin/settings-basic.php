<?php
/**
 * 后台设置：基本信息
 *
 * 分组 slug：basic
 * 字段：site_name / site_url / site_description / site_keywords / site_icp
 *
 * 变量：$val、$isOn（取值助手，见模板外壳 admin/settings.php）
 *
 * 「管理员邮箱」与「全站公告」已从这里移除：
 *  - 管理员邮箱：注册/找回流程不依赖它，留着只会误导，已下线；
 *  - 全站公告：改造成「全站通知」，统一收归通知中心管理
 *    （前台 /notifications，拥有「发布公告」权限的用户可编辑）。
 *
 * 「外观」（主色调 / 深色主色 / 高亮底色三个取色器）也已删除：
 * 站点配色是「经典蓝白」固定品牌色，由 theme.css 的令牌统一控制，
 * 除了浏览器地址栏 theme-color 外没有任何真实消费点。
 */

declare(strict_types=1);
?>
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>基本信息</h3>
    </div>
    <div class="panel__body">
        <div class="form-grid">
            <div data-field>
                <label for="site_name">站点名称 <span class="text-light">（必填）</span></label>
                <input type="text" id="site_name" name="site_name" maxlength="60" required
                       value="<?= e($val('site_name', 'owlsgo')) ?>">
                <?php if (old_error('site_name') !== ''): ?>
                    <span class="field-error"><?= e(old_error('site_name')) ?></span>
                <?php endif; ?>
            </div>

            <div data-field>
                <label for="site_url">站点地址</label>
                <input type="text" id="site_url" name="site_url" maxlength="191"
                       placeholder="https://example.com（留空则自动使用当前域名）"
                       value="<?= e($val('site_url')) ?>">
                <?php if (old_error('site_url') !== ''): ?>
                    <span class="field-error"><?= e(old_error('site_url')) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div data-field>
            <label for="site_description">站点描述</label>
            <input type="text" id="site_description" name="site_description" maxlength="200"
                   value="<?= e($val('site_description')) ?>">
        </div>

        <div data-field>
            <label for="site_keywords">站点关键词</label>
            <input type="text" id="site_keywords" name="site_keywords" maxlength="200"
                   placeholder="用英文逗号分隔" value="<?= e($val('site_keywords')) ?>">
        </div>

        <div data-field>
            <label for="site_icp">备案号</label>
            <input type="text" id="site_icp" name="site_icp" maxlength="60"
                   placeholder="如：京ICP备00000000号-1"
                   value="<?= e($val('site_icp')) ?>">
            <span class="field-hint">
                面向中国大陆提供服务的站点需填写备案号并显示在页脚。
            </span>
        </div>
    </div>

    <?= $view('partials/admin-save-foot') ?>
</section>
