<?php
/**
 * 个性装扮：头像 / 个人主页封面 / 色系 / 日夜模式 等个人外观设置
 *
 * 变量：$profile、$uploadEnabled、$avatarMaxMb
 *
 * 说明：
 *  - 头像与封面的**偏好存服务端**（users.avatar / users.cover）；
 *  - 头像裁切（#avatar-crop）与预置头像库（#avatar-preset-dialog）两个对话框在本页末尾，
 *    逻辑见 app.js 的 initAvatar()；
 *  - 色系与日夜模式存**浏览器 localStorage**（键 owlsgo_theme，见 theme-boot.js）——
 *    它们属于「这台设备」的偏好而不是账号属性，开关状态由 app.js 回填，改动即生效。
 */

declare(strict_types=1);

$profile = is_array($profile ?? null) ? $profile : [];
$hasCover = trim((string)($profile['cover'] ?? '')) !== '';
?>

<?= $view('partials/profile-head', ['profile' => $profile, 'active' => 'appearance']) ?>

<section class="panel ow-mt-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'palette', 'size' => 16]) ?>个性装扮</h3>
    </div>
    <div class="panel__body">

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'image', 'size' => 16]) ?>头像</h4>

            <div class="ow-hstack" style="align-items:flex-start">
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
                            2. 「预置头像」→ 打开随机生成的一批头像（选中即保存）。
                            原生 file input 隐藏（不显示文件名），由按钮代理。
                        -->
                        <form method="post" action="<?= e(url('/settings/avatar')) ?>" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <div data-ow-field>
                                <span class="field-hint" style="display:block;margin:0 0 8px">
                                    支持 JPG / PNG / WebP，单个文件不超过 <?= $avatarMaxMb ?> MB，
                                    选择后在弹窗中缩放与裁切。
                                </span>
                                <input type="file" id="avatar-file" name="avatar" accept="image/png,image/jpeg,image/webp"
                                       data-avatar-crop hidden>
                                <div class="ow-hstack" style="gap:10px">
                                    <button type="button" class="ow-button ow-small" id="avatar-pick">
                                        <?= $view('partials/icon', ['name' => 'upload', 'size' => 15]) ?>
                                        <span>上传头像</span>
                                    </button>
                                    <span class="ow-text-light" style="font-size:12.5px">or</span>
                                    <button type="button" class="ow-button ow-ghost ow-small" id="avatar-preset-open">
                                        <?= $view('partials/icon', ['name' => 'image', 'size' => 15]) ?>
                                        <span>预置头像</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <?php /*
                                上传被站点关闭时，只收起「上传头像」。
                                「预置头像」由服务端抓取并落盘，走的是自己的路由，
                                不经过上传通道，所以不受上传开关影响（见 Core\Avatar）。
                        */ ?>
                        <div data-ow-field>
                            <button type="button" class="ow-button ow-ghost ow-small" id="avatar-preset-open">
                                <?= $view('partials/icon', ['name' => 'image', 'size' => 15]) ?>
                                <span>预置头像</span>
                            </button>
                            <span data-ow-hint>
                                站点当前已关闭文件上传，无法上传头像；
                                仍可选择预置头像（SVG 实时生成，不占用存储）。
                            </span>
                        </div>
                    <?php endif; ?>

                    <p class="ow-text-light" style="font-size:12.5px;margin-top:12px">
                        未上传头像时，系统会依据你的用户名生成一张固定的 SVG 头像。
                    </p>
                </div>
            </div>
        </div>

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'image', 'size' => 16]) ?>个人主页封面</h4>

            <div class="setting-block__preview" style="height:110px;border-radius:var(--radius-medium);
                        border:1px solid var(--qq-line);
                        background:<?= $hasCover
                            ? "url('" . e('/media/' . $profile['cover']) . "') center / cover no-repeat"
                            : 'linear-gradient(135deg, #00a0e9 0%, #38bdf8 55%, #7dd3fc 100%)' ?>;"></div>

            <?php if ($uploadEnabled): ?>
                <?php /* 选择文件后立即上传（见 app.js 的 initCoverUpload），无需再点按钮 */ ?>
                <form method="post" action="<?= e(url('/settings/cover')) ?>" enctype="multipart/form-data"
                      style="margin-top:12px" data-auto-submit>
                    <?= csrf_field() ?>
                    <ow-upload>
                        <input type="file" name="cover" id="cover-file"
                               accept="image/png,image/jpeg,image/webp" hidden>
                        <div data-files>
                            <small data-ow-hint>
                                把封面图拖到这里，或点击选择（JPG / PNG / WebP，≤5MB）—— 选好即自动上传
                            </small>
                        </div>
                    </ow-upload>
                    <?php if ($hasCover): ?>
                        <div class="ow-hstack" style="gap:10px;margin-top:10px">
                            <button type="submit" class="ow-button ow-ghost ow-small"
                                    formaction="<?= e(url('/settings/cover/remove')) ?>">
                                <?= $view('partials/icon', ['name' => 'refresh', 'size' => 15]) ?>
                                <span>恢复默认</span>
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div class="ow-text-light" style="font-size:12.5px;margin-top:10px">
                    站点当前已关闭文件上传，暂时无法更换封面图。
                </div>
            <?php endif; ?>
        </div>

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'palette', 'size' => 16]) ?>色系</h4>

            <?php /*
             * 色系卡片由 app.js 的 renderSchemeGrid() 动态渲染（内置 5 套 + 用户自建，
             * 自建存 localStorage 所以服务端渲染不出），点卡片立即生效并记住。
             * 夜间模式不跟随色系 —— 夜间是独立的石墨配色（见 theme.css 第 17 节）。
             */ ?>
            <div class="scheme-grid" data-scheme-grid></div>
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
                <button type="button" class="ow-button ow-small" data-scheme-create>
                    <?= $view('partials/icon', ['name' => 'plus', 'size' => 14]) ?>
                    <span>新增色系</span>
                </button>
            </div>
            <p class="field-hint" style="margin:8px 0 0">
                新增后立即启用；自建色系可随时删除（内置色系不可删）。
            </p>
        </div>

        <div class="setting-block">
            <h4 class="setting-block__title"><?= $view('partials/icon', ['name' => 'moon', 'size' => 16]) ?>日夜模式切换</h4>

            <div class="privacy-row">
                <div class="privacy-row__label">
                    <strong>夜间模式跟随系统</strong>
                    <span class="privacy-row__state" data-appearance-state>跟随系统</span>
                </div>
                <input type="checkbox" class="switch" data-appearance-follow>
            </div>
        </div>

        <?php
        /*
         * 「字体大小」滑块（ui.css input[type=range] 即用户所说的 Volume 滑块）暂未开发：
         * 本站样式以 px 定值为主，无法只靠根字号缩放达到一致效果，需要先把字号收敛成
         * 可缩放的令牌再上滑块，避免调了以后各处字号不成比例。
         */
        ?>
    </div>
</section>

<?php
/*
 * 头像相关的两个对话框：
 *  - #avatar-crop：上传裁切（缩放 + 居中裁切，app.js 的 canvas 实现负责绘制与导出）；
 *  - #avatar-preset-dialog：预置头像库（DiceBear 随机一批，见 Core\Avatar::presets()），
 *    预览走本站代理 /avatar/dice/{seed}.svg，选中的那张由 /settings/avatar/dice 抓下来落盘。
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
        <button type="button" class="ow-button ow-ghost" data-crop-cancel>取消</button>
        <?php /*
                站点 Logo 场景专用：位图不裁剪、原图直传。
                （头像场景由 app.js 隐藏该按钮 —— 头像必须方形裁切。）
        */ ?>
        <button type="button" class="ow-button ow-ghost" data-crop-skip hidden>原图上传</button>
        <button type="button" class="ow-button" data-crop-ok>上传并应用</button>
    </div>
</dialog>

<dialog id="avatar-preset-dialog" class="avatar-dialog">
    <div class="avatar-dialog__head">选择预置头像</div>
    <div class="avatar-preset-grid" id="avatar-preset-grid"
         data-endpoint="<?= e(url('/avatar/candidates.json')) ?>">
        <?php foreach ($presets as $preset): ?>
            <button type="button" class="avatar-preset"
                    data-preset-seed="<?= e($preset['seed']) ?>"
                    title="使用该头像">
                <img src="<?= e($preset['url']) ?>" width="72" height="72" alt="" loading="lazy">
            </button>
        <?php endforeach; ?>
    </div>
    <p class="ow-text-light" id="avatar-preset-hint" style="font-size:12.5px;margin:6px 20px 0">
        每次打开都是随机的一批；选中的那张会保存到你的账号。
    </p>
    <div class="confirm-dialog__actions">
        <button type="button" class="ow-button ow-ghost" id="avatar-preset-more">换一批</button>
        <button type="button" class="ow-button ow-ghost" data-preset-cancel>取消</button>
    </div>
</dialog>

<form method="post" action="<?= e(url('/settings/avatar/dice')) ?>" id="avatar-preset-form" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="seed" value="">
</form>
