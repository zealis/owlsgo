<?php
/**
 * 安装向导
 *
 * 变量：$requirements（环境自检项）、$installable、$driver、$drivers、$phpVersion、
 *       $storageWritable、$termsText（《前置同意声明》全文）
 *
 * 版面约定：
 *  - 左侧主栏：数据库配置 → 站点信息 → 创建管理员 → 前置同意声明，一次填完即可安装；
 *  - 右侧辅栏：环境检查（只读，必须项最上方且标红）+ 安装说明。
 *
 * 表单说明：
 *  - 整个表单使用 AJAX 提交：安装成功后跟随 JSON 中的 redirect 跳转到前台首页；
 *  - 「测试数据库连接」是同一表单内的第二个提交按钮（action=test），
 *    后端会走 Installer::testConnection() 并返回 JSON，由前端以提示条呈现结果；
 *  - 数据库类型切换时，主机/端口/用户/密码等字段由 app.js 的 initInstallForm() 显隐，
 *    SQLite 不需要任何连接信息，也不需要填库名（文件名固定为 owlsgo.sqlite）；
 *  - 两处勾选（同意条款 / 确认全新安装）未勾满时，「开始安装」按钮保持禁用。
 *    注意那只是提示，真正的校验在 Installer::install() 里，改 DOM 绕不过去。
 */

declare(strict_types=1);

$requirements    = is_array($requirements ?? null) ? $requirements : [];
$installable     = (bool)($installable ?? false);
$driver          = (string)($driver ?? 'sqlite');
$drivers         = is_array($drivers ?? null) ? $drivers : [];
$phpVersion      = (string)($phpVersion ?? PHP_VERSION);
$storageWritable = (bool)($storageWritable ?? false);
$termsText       = (string)($termsText ?? '');

$isSqlite = $driver === 'sqlite';
?>

<?php if (!$installable): ?>
    <div role="alert" data-ow-variant="error" style="margin-bottom:var(--space-4)">
        <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
        <div>服务器环境未满足安装要求，请先解决右侧标记为红色的「必须」项。</div>
    </div>
<?php elseif (!$storageWritable): ?>
    <div role="alert" data-ow-variant="warning" style="margin-bottom:var(--space-4)">
        <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
        <div><code>storage/</code> 目录不可写，安装过程会失败，请先调整目录权限。</div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/install')) ?>" data-ajax data-ajax-redirect data-install-form>
    <?= csrf_field() ?>

    <div class="install-layout">
        <?php /* ---------- 左栏：配置表单 ---------- */ ?>
        <div class="install-main">

            <section class="panel ow-mb-4">
                <div class="panel__head">
                    <h3><?= $view('partials/icon', ['name' => 'database', 'size' => 16]) ?>数据库配置</h3>
                </div>
                <div class="panel__body">
                    <div data-ow-field>
                        <label for="install-driver">数据库类型</label>
                        <select id="install-driver" name="driver" required data-install-driver>
                            <?php foreach ($drivers as $code => $label): ?>
                                <option value="<?= e((string)$code) ?>"<?= selected($code, $driver) ?>>
                                    <?= e((string)$label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php /* ---------- MySQL / PostgreSQL：库名 + 连接凭据（SQLite 无需任何输入项） ---------- */ ?>
                    <div data-install-remote<?= $isSqlite ? ' hidden' : '' ?>>
                        <div data-ow-field>
                            <label for="install-database">数据库名</label>
                            <input type="text" id="install-database" name="database" maxlength="191"
                                   value="<?= e((string)old('database', '')) ?>">
                            <span data-ow-hint>数据库需提前创建，安装器只会创建其中的数据表。</span>
                        </div>

                        <div class="form-grid">
                            <div data-ow-field>
                                <label for="install-host">主机</label>
                                <input type="text" id="install-host" name="host" maxlength="191"
                                       value="<?= e((string)old('host', '127.0.0.1')) ?>">
                            </div>

                            <div data-ow-field>
                                <label for="install-port">端口</label>
                                <input type="number" id="install-port" name="port" min="0" max="65535"
                                       value="<?= e((string)old('port', '')) ?>" placeholder="留空使用默认端口">
                            </div>
                        </div>

                        <div class="form-grid">
                            <div data-ow-field>
                                <label for="install-username">数据库用户</label>
                                <input type="text" id="install-username" name="username" maxlength="191"
                                       autocomplete="off" value="<?= e((string)old('username', '')) ?>">
                            </div>

                            <div data-ow-field>
                                <label for="install-password">数据库密码</label>
                                <input type="password" id="install-password" name="password"
                                       autocomplete="new-password">
                            </div>
                        </div>

                        <button type="submit" name="action" value="test"
                                class="ow-button ow-outline ow-small install-test-btn">
                            <?= $view('partials/icon', ['name' => 'plug', 'size' => 15]) ?>
                            <span>测试数据库连接</span>
                        </button>
                    </div>
                </div>
            </section>

            <section class="panel ow-mb-4">
                <div class="panel__head">
                    <h3><?= $view('partials/icon', ['name' => 'layers', 'size' => 16]) ?>站点信息</h3>
                </div>
                <div class="panel__body">
                    <div data-ow-field>
                        <label for="install-site-name">站点名称</label>
                        <input type="text" id="install-site-name" name="site_name" maxlength="60"
                               value="<?= e((string)old('site_name', 'owlsgo')) ?>">
                    </div>

                    <label style="margin-top:4px">
                        <input type="checkbox" name="register_enabled" value="1" checked>
                        <span>允许访客注册账号</span>
                    </label>
                </div>
            </section>

            <section class="panel ow-mb-4">
                <div class="panel__head">
                    <h3><?= $view('partials/icon', ['name' => 'shield', 'size' => 16]) ?>创建管理员</h3>
                </div>
                <div class="panel__body">
                    <div data-ow-field>
                        <label for="install-admin-username">管理员用户名</label>
                        <input type="text" id="install-admin-username" name="admin_username" required
                               maxlength="20" autocomplete="off"
                               value="<?= e((string)old('admin_username', '')) ?>">
                    </div>

                    <div data-ow-field>
                        <label for="install-admin-email">管理员邮箱</label>
                        <input type="email" id="install-admin-email" name="admin_email" required
                               maxlength="191" autocomplete="off"
                               value="<?= e((string)old('admin_email', '')) ?>">
                        <span data-ow-hint>用于找回密码与系统通知。</span>
                    </div>

                    <div data-ow-field>
                        <label for="install-admin-password">管理员密码</label>
                        <input type="password" id="install-admin-password" name="admin_password" required
                               autocomplete="new-password">
                        <span data-ow-hint>建议使用 8 位以上、包含字母与数字的组合。</span>
                    </div>

                    <div data-ow-field>
                        <label for="install-admin-password-confirm">确认密码</label>
                        <input type="password" id="install-admin-password-confirm" name="admin_password_confirm"
                               required autocomplete="new-password">
                        <span data-ow-hint>请再次输入，两次必须完全一致。</span>
                    </div>
                </div>
            </section>

            <?php /* ---------- 前置同意声明与风险确认：「开始安装」按钮就在本块下方 ---------- */ ?>
            <section class="panel ow-mb-4">
                <div class="panel__head">
                    <h3><?= $view('partials/icon', ['name' => 'file', 'size' => 16]) ?>前置同意声明</h3>
                </div>
                <div class="panel__body">
                    <p class="install-license-lead">请阅读以下声明全文后，勾选同意方可继续安装。</p>

                    <pre class="install-license" tabindex="0" aria-label="前置同意声明全文"><?= e($termsText) ?></pre>

                    <label class="install-check">
                        <input type="checkbox" name="agree_license" value="1" required
                               data-install-agree<?= (string)old('agree_license', '') === '1' ? ' checked' : '' ?>>
                        <span>我已完整阅读、理解并同意以上《前置同意声明》</span>
                    </label>

                    <label class="install-check">
                        <input type="checkbox" name="confirm_fresh" value="1" required
                               data-install-agree<?= (string)old('confirm_fresh', '') === '1' ? ' checked' : '' ?>>
                        <span>我已确认这是全新安装，数据将被清理。</span>
                    </label>

                    <div class="ow-hstack install-submit">
                        <button type="submit" class="ow-button" data-install-submit
                                disabled<?= $installable ? '' : ' data-install-blocked' ?>>
                            <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                            <span>开始安装</span>
                        </button>
                        <span class="ow-text-light" style="font-size:12.5px">
                            勾选以上两项后方可提交。
                        </span>
                    </div>
                </div>
            </section>
        </div>

        <?php /* ---------- 右栏：环境检查 + 安装说明 ---------- */ ?>
        <aside class="install-aside">

            <section class="panel ow-mb-4">
                <div class="panel__head">
                    <h3><?= $view('partials/icon', ['name' => 'server', 'size' => 16]) ?>环境检查</h3>
                </div>
                <div class="panel__body">
                    <div class="req-list">
                        <?php foreach ($requirements as $item): ?>
                            <?php
                            $ok       = (bool)($item['ok'] ?? false);
                            $required = (string)($item['required'] ?? '');
                            $level    = (string)($item['level'] ?? '必须');

                            /*
                             * 三种级别用三种颜色表达（注意判断依据是 level 而非 required，
                             * required 是给人看的要求文案，比如 ">= 8.1"）：
                             *   必须：缺失标红（会拦下安装）
                             *   建议：缺失标橙（装得上，但安全性打折）
                             *   可选：缺失标灰（纯功能增强，不处理也完全没问题）
                             * 可选项不该用告警色，否则用户会以为必须去装。
                             */
                            $state = '';
                            if (!$ok) {
                                $state = match ($level) {
                                    '必须' => ' req-item--fail',
                                    '建议' => ' req-item--warn',
                                    default => ' req-item--optional',
                                };
                            }
                            ?>
                            <div class="req-item<?= $state ?>">
                                <span class="req-item__icon"><?= $ok ? '✓' : ($level === '可选' ? '–' : '!') ?></span>
                                <span class="req-item__name"><?= e((string)($item['name'] ?? '')) ?></span>
                                <span class="req-item__value">
                                    当前：<?= e((string)($item['current'] ?? '')) ?>
                                    · 要求：<?= e($required) ?>
                                </span>
                            </div>
                            <?php if (!$ok && (string)($item['hint'] ?? '') !== ''): ?>
                                <div class="req-item__hint" style="margin:-2px 0 4px 30px"><?= e((string)$item['hint']) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel__head">
                    <h3><?= $view('partials/icon', ['name' => 'info', 'size' => 16]) ?>安装说明</h3>
                </div>
                <div class="panel__body">
                    <ul class="install-notes">
                        <li>
                            <strong>MySQL / PostgreSQL</strong> 数据库需提前创建，当前 PHP 版本
                            <code><?= e($phpVersion) ?></code>。
                        </li>
                        <li>
                            安装会写入 <code>storage/config/database.php</code>（数据库连接配置），
                            并生成 <code>storage/install.lock</code> 作为「已安装」标记 ——
                            <strong>请保留该文件</strong>，删掉它安装入口会重新开放。
                        </li>
                        <li>
                            安装完成后请把 <code>storage/</code> 权限收紧为 755（属主为 PHP 运行用户），
                            不要使用 777，并确认该目录不可通过 Web 直接访问。
                        </li>
                        <li>
                            第一个管理员将拥有全部权限，管理员邮箱可用于找回密码。
                        </li>
                    </ul>
                </div>
            </section>
        </aside>
    </div>
</form>
