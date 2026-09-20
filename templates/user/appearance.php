<?php
/**
 * 个性装扮：夜间模式跟随系统等个人外观偏好
 *
 * 变量：$profile
 *
 * 重要：这里的偏好存**浏览器 localStorage**（键 owlsgo_theme，见 public/assets/js/theme-boot.js），
 * 不落库 —— 深浅色是「这台设备/这个浏览器」的偏好而不是账号属性，服务端不参与读写。
 * 所以本页没有 POST 表单：开关的初始状态由 app.js 的 initThemeToggle() 按当前偏好回填，
 * 改动即点即生效并持久化，无需保存按钮。
 */

declare(strict_types=1);

$profile = is_array($profile ?? null) ? $profile : [];
?>

<?= $view('partials/profile-head', ['profile' => $profile, 'active' => 'appearance']) ?>

<section class="panel mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'palette', 'size' => 16]) ?>个性装扮</h3>
    </div>
    <div class="panel__body">

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'palette', 'size' => 16]) ?>色系</h4>

            <?php /*
             * 色系卡片由 app.js 的 renderSchemeGrid() 动态渲染（内置 5 套 + 用户自建，
             * 自建存 localStorage 所以服务端渲染不出），点卡片立即生效并记住。
             * 夜间模式不跟随色系 —— 夜间是独立的石墨配色（见 theme.css 第 17 节）。
             */ ?>
            <div class="scheme-grid" data-scheme-grid></div>
            <p class="field-hint" style="margin:12px 0 0">
                点击卡片立即切换日间模式的主色；夜间模式的主色 / 链接 / 悬浮软底也会跟随色系
                （页面底、卡片与按钮悬浮保持夜间石墨底），色系偏好保存在当前浏览器。
            </p>

            <?php /* 新增自定义色系：三个颜色 + 名称，保存进当前浏览器的自建列表 */ ?>
            <div class="scheme-add">
                <input type="text" data-scheme-name maxlength="12" placeholder="色系名称"
                       aria-label="色系名称">
                <label class="scheme-add__color">主色
                    <input type="color" data-scheme-brand value="#00a0e9">
                </label>
                <label class="scheme-add__color">悬浮
                    <input type="color" data-scheme-hover value="#0086c9">
                </label>
                <label class="scheme-add__color">浅底
                    <input type="color" data-scheme-soft value="#e6f7ff">
                </label>
                <button type="button" class="button small" data-scheme-create>
                    <?= $view('partials/icon', ['name' => 'plus', 'size' => 14]) ?>
                    <span>新增色系</span>
                </button>
            </div>
            <p class="field-hint" style="margin:8px 0 0">
                新增后立即启用；自建色系可随时删除（内置色系不可删）。
            </p>
        </div>

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'moon', 'size' => 16]) ?>深浅色模式</h4>

            <div class="privacy-row">
                <div class="privacy-row__label">
                    <strong>夜间模式跟随系统</strong>
                    <span class="privacy-row__state" data-appearance-state>跟随系统</span>
                </div>
                <input type="checkbox" class="switch" data-appearance-follow>
            </div>

            <p class="field-hint" style="margin:12px 0 0">
                打开后，夜间 / 日间自动跟随系统外观；关闭后保持你手动选择的模式。
                手动切换可在任意页面顶栏的齿轮菜单里进行，偏好保存在当前浏览器，换设备需重新设置。
            </p>
        </div>

        <?php
        /*
         * 「字体大小」滑块（OATUI input[type=range] 即用户所说的 Volume 滑块）暂未开发：
         * 本站样式以 px 定值为主，无法只靠根字号缩放达到一致效果，需要先把字号收敛成
         * 可缩放的令牌再上滑块，避免调了以后各处字号不成比例。
         */
        ?>
    </div>
</section>
