<?php
/**
 * 后台：站点设置
 *
 * 变量：$settings（全部设置项）、$groups（用户组下拉）、$uploadMax、$postMax、$uploadDirSize
 *
 * 表单字段与 AdminController::saveSettings() 的解析逻辑一一对应：
 *  - 文本框：受长度限制，服务端会再次裁剪
 *  - 颜色框：必须是 #RRGGBB，服务端正则校验
 *  - 复选框：未勾选时浏览器不提交，服务端按「未提交即 0」处理，
 *    因此每个开关都必须真实渲染出来，不能靠 hidden input 兜底
 *  - 数字框：服务端做区间钳制，这里同步给出 min/max 以便前端先拦一层
 */

declare(strict_types=1);

$settings      = is_array($settings ?? null) ? $settings : [];
$groups        = is_array($groups ?? null) ? $groups : [];
$uploadMax     = (string)($uploadMax ?? '—');
$postMax       = (string)($postMax ?? '—');
$uploadDirSize = (string)($uploadDirSize ?? '—');

/* 系统维护面板用 */
$opcacheAvailable = (bool)($opcacheAvailable ?? false);
$debugEnabled     = (bool)($debugEnabled ?? false);

/** 取值助手：优先回填上次提交值，其次取当前设置 */
$val = static fn (string $key, string $default = ''): string => (string)old($key, (string)($settings[$key] ?? $default));

/** 开关是否勾选：优先回填值，其次取当前设置 */
$isOn = static function (string $key, string $default = '0') use ($settings): bool {
    $raw = old($key, null);
    $raw = $raw === null || $raw === '' ? (string)($settings[$key] ?? $default) : (string)$raw;

    return in_array($raw, ['1', 'on', 'true', 'yes'], true);
};

/** 布尔开关定义：键 => [标题, 说明] */
$toggles = [
    'register_enabled' => ['开放注册', '关闭后注册页会提示暂停注册。'],
    'register_verify'  => ['注册需验证邮箱', '开启后新用户需要完成邮箱验证才能发言。'],
    'login_captcha'    => ['登录需要验证码', '在登录失败次数较多时建议开启。'],
    'register_captcha' => ['注册需要验证码', '用于拦截批量注册机器人，建议与「登录需要验证码」一起开启。'],
    'guest_view'       => ['允许游客浏览', '关闭后必须登录才能查看主题内容。'],
    /*
     * 审核这两个开关不配说明文案：开关名称已经说清行为，再补一句只是噪音。
     * 渲染时说明为空会走紧凑版（勾选框与标题垂直居中），见下方「发帖」分区。
     */
    'thread_need_audit' => ['主题需要审核', ''],
    'post_need_audit'  => ['回复需要审核', ''],
    'upload_enabled'   => ['允许上传附件', '关闭后发帖页与头像上传都会被禁用。'],
    'site_closed'      => ['关闭站点', '开启后前台会展示维护提示，管理员仍可正常访问后台。'],
    'debug_mode'       => ['调试模式', '开启后记录 debug 级日志、出错页显示详细报错。仅用于排错，用完请及时关闭 —— 报错细节可能暴露路径、SQL 与配置信息。'],
];
?>

<form method="post" action="<?= e(url('/admin/settings')) ?>" data-ajax data-ajax-redirect>
    <?= csrf_field() ?>

    <!-- 基本信息 -->
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

            <?php
            /*
             * 「管理员邮箱」与「全站公告」已从这里移除：
             *  - 管理员邮箱：注册/找回流程不依赖它，留着只会误导，已下线；
             *  - 全站公告：改造成「全站通知」，统一收归通知中心管理
             *    （前台 /notifications，拥有「发布公告」权限的用户可编辑）。
             */
            ?>

            <div class="form-grid">
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
        </div>
    </section>

    <!-- 外观 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'image', 'size' => 16]) ?>外观</h3>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <?php
                $colors = [
                    'theme_primary'      => ['主色调', '#00A0E9'],
                    'theme_primary_dark' => ['深色主色', '#0078D4'],
                    'theme_highlight'    => ['高亮底色', '#E6F7FF'],
                ];
                ?>
                <?php foreach ($colors as $key => [$label, $fallback]): ?>
                    <div data-field>
                        <label for="<?= e($key) ?>"><?= e($label) ?></label>
                        <input type="color" id="<?= e($key) ?>" name="<?= e($key) ?>"
                               value="<?= e($val($key, $fallback)) ?>">
                        <?php if (old_error($key) !== ''): ?>
                            <span class="field-error"><?= e(old_error($key)) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <span data-hint>颜色值必须为 #RRGGBB 格式。</span>
        </div>
    </section>

    <!-- 注册与登录 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'user', 'size' => 16]) ?>注册与登录</h3>
        </div>
        <div class="panel__body">
            <?php foreach (['register_enabled', 'register_verify', 'login_captcha', 'register_captcha', 'guest_view'] as $key): ?>
                <label class="hstack" style="gap:8px;align-items:flex-start;margin-bottom:12px">
                    <input type="checkbox" class="switch" name="<?= e($key) ?>" value="1" <?= checked($isOn($key, $key === 'register_enabled' || $key === 'guest_view' ? '1' : '0')) ?>>
                    <span>
                        <strong style="font-size:14px"><?= e($toggles[$key][0]) ?></strong>
                        <span class="text-light" style="display:block;font-size:12.5px"><?= e($toggles[$key][1]) ?></span>
                    </span>
                </label>
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
    </section>

    <!-- 发帖 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'message', 'size' => 16]) ?>发帖</h3>
        </div>
        <div class="panel__body">
            <?php foreach (['thread_need_audit', 'post_need_audit'] as $key): ?>
                <?php $hint = (string)$toggles[$key][1]; ?>
                <?php /* 说明为空时改用居中对齐：只有一行标题还按 flex-start 会让勾选框吊在文字上方 */ ?>
                <label class="hstack" style="gap:8px;align-items:<?= $hint === '' ? 'center' : 'flex-start' ?>;margin-bottom:12px">
                    <input type="checkbox" class="switch" name="<?= e($key) ?>" value="1" <?= checked($isOn($key)) ?>>
                    <?php if ($hint === ''): ?>
                        <strong style="font-size:14px"><?= e($toggles[$key][0]) ?></strong>
                    <?php else: ?>
                        <span>
                            <strong style="font-size:14px"><?= e($toggles[$key][0]) ?></strong>
                            <span class="text-light" style="display:block;font-size:12.5px"><?= e($hint) ?></span>
                        </span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>

            <div class="form-grid">
                <div data-field>
                    <label for="post_interval">发帖间隔（秒）</label>
                    <input type="number" id="post_interval" name="post_interval" min="0" max="86400"
                           value="<?= e($val('post_interval', '15')) ?>">
                    <span data-hint>同一用户两次发帖之间的最短间隔，0 表示不限制。</span>
                </div>

                <div data-field>
                    <label for="post_min_length">正文最短字数</label>
                    <input type="number" id="post_min_length" name="post_min_length" min="1" max="1000"
                           value="<?= e($val('post_min_length', '2')) ?>">
                    <span data-hint>最短不得小于 1，且不能大于最长字数。</span>
                </div>
            </div>

            <div data-field style="max-width:280px">
                <label for="post_max_length">正文最长字数</label>
                <input type="number" id="post_max_length" name="post_max_length" min="10" max="200000"
                       value="<?= e($val('post_max_length', '20000')) ?>">
            </div>
        </div>
    </section>

    <!-- 附件 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'paperclip', 'size' => 16]) ?>附件</h3>
            <span class="spacer"></span>
            <span class="text-light" style="font-size:12.5px">当前占用 <?= e($uploadDirSize) ?></span>
        </div>
        <div class="panel__body">
            <label class="hstack" style="gap:8px;align-items:flex-start;margin-bottom:12px">
                <input type="checkbox" class="switch" name="upload_enabled" value="1" <?= checked($isOn('upload_enabled', '1')) ?>>
                <span>
                    <strong style="font-size:14px"><?= e($toggles['upload_enabled'][0]) ?></strong>
                    <span class="text-light" style="display:block;font-size:12.5px"><?= e($toggles['upload_enabled'][1]) ?></span>
                </span>
            </label>

            <div class="form-grid">
                <div data-field>
                    <label for="upload_max_size">单个文件上限（MB）</label>
                    <input type="number" id="upload_max_size" name="upload_max_size" min="1" max="512"
                           value="<?= e($val('upload_max_size', '4')) ?>">
                    <span data-hint>
                        服务器限制：upload_max_filesize = <?= e($uploadMax) ?>，
                        post_max_size = <?= e($postMax) ?>。
                    </span>
                </div>

                <div data-field>
                    <label for="attachment_quota">附件总空间上限（MB）</label>
                    <input type="number" id="attachment_quota" name="attachment_quota" min="0" max="1048576"
                           value="<?= e($val('attachment_quota', '0')) ?>">
                    <span data-hint>
                        所有附件加起来的上限，0 表示不限制。当前已占用 <?= e($uploadDirSize) ?>，
                        达到上限后新的上传会被拒绝。
                    </span>
                </div>

                <div data-field>
                    <label for="cache_ttl">缓存有效期（秒）</label>
                    <input type="number" id="cache_ttl" name="cache_ttl" min="0" max="86400"
                           value="<?= e($val('cache_ttl', '300')) ?>">
                    <span data-hint>版块与设置项的缓存时长，0 表示每次请求都重新读取。</span>
                </div>
            </div>

            <div data-field>
                <label for="upload_allow_ext">允许上传的扩展名</label>
                <input type="text" id="upload_allow_ext" name="upload_allow_ext" maxlength="500"
                       value="<?= e($val('upload_allow_ext', 'jpg,jpeg,png,gif,webp,zip,pdf,txt')) ?>">
                <span data-hint>
                    使用逗号分隔，可带点号。系统会自动剔除 php / phtml / phar / html / svg 等高危扩展名。
                </span>
            </div>
        </div>
    </section>

    <!-- 站点开关 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'shield', 'size' => 16]) ?>站点开关</h3>
        </div>
        <div class="panel__body">
            <label class="hstack" style="gap:8px;align-items:flex-start;margin-bottom:12px">
                <input type="checkbox" class="switch" name="site_closed" value="1" <?= checked($isOn('site_closed')) ?>>
                <span>
                    <strong style="font-size:14px"><?= e($toggles['site_closed'][0]) ?></strong>
                    <span class="text-light" style="display:block;font-size:12.5px"><?= e($toggles['site_closed'][1]) ?></span>
                </span>
            </label>

            <div data-field>
                <label for="site_closed_reason">维护提示语</label>
                <textarea id="site_closed_reason" name="site_closed_reason" rows="2" maxlength="200"><?= e($val('site_closed_reason', '站点正在维护，请稍后再访问。')) ?></textarea>
            </div>

            <label class="hstack" style="gap:8px;align-items:flex-start;margin-top:4px">
                <input type="checkbox" class="switch" name="debug_mode" value="1" <?= checked($isOn('debug_mode')) ?>>
                <span>
                    <strong style="font-size:14px"><?= e($toggles['debug_mode'][0]) ?></strong>
                    <span class="text-light" style="display:block;font-size:12.5px"><?= e($toggles['debug_mode'][1]) ?></span>
                </span>
            </label>
        </div>
        <div class="panel__foot hstack">
            <button type="submit" class="button">
                <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                <span>保存设置</span>
            </button>
            <span class="text-light" style="font-size:12.5px">
                保存后会立即清空缓存，前台即刻生效。
            </span>
        </div>
    </section>
</form>

<?php
/*
 * 系统维护
 *
 * 刻意放在设置表单「外面」：HTML 不允许 form 嵌套，
 * 而且这两个动作（清缓存、看日志）与「保存设置」是两回事，不该被一起提交。
 */
?>
<section class="panel" style="margin-top:var(--space-4)">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>系统维护</h3>
    </div>
    <div class="panel__body">
        <dl class="kv" style="margin:0 0 14px">
            <dt>OPcache</dt>
            <dd><?= $opcacheAvailable ? '已启用' : '未启用（无需清理）' ?></dd>

            <dt>调试模式</dt>
            <dd><?= $debugEnabled ? '已开启' : '已关闭' ?></dd>

            <dt>日志目录</dt>
            <dd><code>storage/logs/</code></dd>
        </dl>

        <div class="hstack" style="flex-wrap:wrap;gap:8px">
            <form method="post" action="<?= e(url('/admin/maintenance/opcache')) ?>"
                  data-ajax class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="button outline small"<?= $opcacheAvailable ? '' : ' disabled' ?>>
                    <?= $view('partials/icon', ['name' => 'refresh', 'size' => 15]) ?>
                    <span>清理 OPcache</span>
                </button>
            </form>

            <a class="button outline small" href="<?= e(url('/admin/logs/system')) ?>">
                <?= $view('partials/icon', ['name' => 'file', 'size' => 15]) ?>
                <span>查看系统日志</span>
            </a>
        </div>

        <p class="text-light" style="margin:12px 0 0;font-size:12.5px">
            改完 PHP 源码若页面没变化，多半是 OPcache 缓存了旧代码，点一下清理即可（会同时清空站点缓存）。<br>
            系统日志里是 PHP 报错与异常堆栈；调试模式关闭时不会产生 <code>debug</code> 日志，但错误始终会记录。
        </p>
    </div>
</section>
