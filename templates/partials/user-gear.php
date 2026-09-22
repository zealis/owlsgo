<?php
/**
 * 个性化齿轮按钮（顶栏用户区共用：前台 partials/header.php 与后台 layouts/admin.php）
 *
 * <ot-dropdown>（OATUI dropdown.js）负责 fixed 定位 / 键盘导航 / aria-expanded，
 * 点击菜单项后由 app.js 的 initDropdownAutoClose() 收起菜单。
 * 第一项是深浅色切换：文案由 app.js 按当前状态回填（深色 → 「日间模式」、浅色 → 「夜间模式」），
 * 服务端渲染的只是无 JS 时的兜底值；太阳 / 月亮图标的显隐交给 CSS（html[data-theme]）。
 * 第二项直达「个性装扮」页（夜间模式跟随系统等设置）。
 * 末项「退出」（仅登录后出现）＝ GET /logout，与账号设置页的「安全退出」同一入口，用分隔线隔开。
 *
 * 深浅色的实际生效逻辑：public/assets/js/theme-boot.js（head 内同步执行，避免闪白）
 * + app.js 的 initThemeToggle()；偏好存 localStorage（键 owlsgo_theme），登录与否都可用。
 *
 * 菜单 id 全站唯一：一个页面只会渲染一份顶栏（前台 main / 后台 admin 二选一）。
 */
?>
<ot-dropdown class="user-gear">
    <button type="button" class="user-gear__btn" popovertarget="user-gear-menu"
            aria-haspopup="menu" aria-expanded="false" aria-label="个性化设置">
        <?= $view('partials/icon', ['name' => 'settings', 'size' => 18]) ?>
    </button>
    <menu popover id="user-gear-menu" class="user-gear__menu" aria-label="个性化菜单">
        <li>
            <button type="button" role="menuitem" data-theme-toggle>
                <span class="user-gear__item-icon" aria-hidden="true">
                    <span data-theme-icon-sun><?= $view('partials/icon', ['name' => 'sun', 'size' => 16]) ?></span>
                    <span data-theme-icon-moon><?= $view('partials/icon', ['name' => 'moon', 'size' => 16]) ?></span>
                </span>
                <span data-theme-label>夜间模式</span>
            </button>
        </li>
        <li>
            <a role="menuitem" href="<?= e(url('/settings/appearance')) ?>">
                <span class="user-gear__item-icon" aria-hidden="true">
                    <?= $view('partials/icon', ['name' => 'palette', 'size' => 16]) ?>
                </span>
                <span>个性装扮</span>
            </a>
        </li>
        <?php /*
              「后台管理」只给有后台准入权限（admin.access）的人显示；
              判定口径与 /admin 路由的准入、维护模式的放行一致（Auth::can）。
        */ ?>
        <?php if (auth_user() !== null && \Core\Auth::can('admin.access')): ?>
            <li>
                <a role="menuitem" href="<?= e(url('/admin')) ?>">
                    <span class="user-gear__item-icon" aria-hidden="true">
                        <?= $view('partials/icon', ['name' => 'dashboard', 'size' => 16]) ?>
                    </span>
                    <span>后台管理</span>
                </a>
            </li>
        <?php endif; ?>
        <?php /* 退出只对已登录用户显示；与上面两项用一条分隔线隔开（样式见 theme.css 18 节） */ ?>
        <?php if (auth_user() !== null): ?>
            <li class="user-gear__logout">
                <a role="menuitem" href="<?= e(url('/logout')) ?>">
                    <span class="user-gear__item-icon" aria-hidden="true">
                        <?= $view('partials/icon', ['name' => 'logout', 'size' => 16]) ?>
                    </span>
                    <span>退出</span>
                </a>
            </li>
        <?php endif; ?>
    </menu>
</ot-dropdown>
