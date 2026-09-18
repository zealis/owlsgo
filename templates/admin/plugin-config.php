<?php
/**
 * 后台：插件配置
 *
 * 变量：$plugin（插件元数据）、$fields（key => label/type/default/hint/options）、
 *       $values（当前值，缺失项已用默认值补齐）、$action
 *
 * 支持的字段类型：text / textarea / number / checkbox / select
 * 只有清单中声明过的键才会被渲染与保存，插件无法借此写入任意配置。
 */

declare(strict_types=1);

$plugin = is_array($plugin ?? null) ? $plugin : [];
$fields = is_array($fields ?? null) ? $fields : [];
$values = is_array($values ?? null) ? $values : [];
$action = (string)($action ?? '');

$pluginId = (string)($plugin['id'] ?? '');
$isEnabled = (bool)($plugin['enabled'] ?? false);

/** 当前值：优先回填上次提交，其次取已保存值，最后退回默认值 */
$valueOf = static function (string $key, string $default = ''): string {
    $raw = old($key, null);

    return $raw === null ? $default : (string)$raw;
};

$isTruthy = static fn (string $value): bool => in_array($value, ['1', 'on', 'true', 'yes'], true);
?>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'plug', 'size' => 16]) ?><?= e((string)($plugin['name'] ?? $pluginId)) ?></h3>
        <span class="spacer"></span>
        <span class="badge outline mono" style="font-size:11.5px">v<?= e((string)($plugin['version'] ?? '0')) ?></span>
        <?php if ($isEnabled): ?>
            <span class="badge outline"><span class="status-dot"></span>已启用</span>
        <?php else: ?>
            <span class="badge outline"><span class="status-dot status-dot--off"></span>已停用</span>
        <?php endif; ?>
    </div>

    <div class="panel__body">
        <?php if ((string)($plugin['description'] ?? '') !== ''): ?>
            <p style="margin:0 0 4px;color:var(--qq-ink-2);font-size:13.5px">
                <?= e((string)$plugin['description']) ?>
            </p>
        <?php endif; ?>

        <dl class="kv" style="margin-top:10px">
            <dt>插件标识</dt><dd class="mono"><?= e($pluginId) ?></dd>
            <?php if ((string)($plugin['author'] ?? '') !== ''): ?>
                <dt>作者</dt>
                <dd>
                    <?php if ((string)($plugin['url'] ?? '') !== ''): ?>
                        <a href="<?= e((string)$plugin['url']) ?>" target="_blank" rel="noopener nofollow">
                            <?= e((string)$plugin['author']) ?>
                        </a>
                    <?php else: ?>
                        <?= e((string)$plugin['author']) ?>
                    <?php endif; ?>
                </dd>
            <?php endif; ?>
            <?php if ((string)($plugin['entry'] ?? '') !== ''): ?>
                <dt>入口文件</dt><dd class="mono"><?= e((string)$plugin['entry']) ?></dd>
            <?php endif; ?>
            <dt>注册钩子</dt><dd class="mono"><?= count((array)($plugin['hooks'] ?? [])) ?> 个</dd>
        </dl>

        <?php if (!$isEnabled): ?>
            <div class="doc-note hstack" style="gap:10px;align-items:flex-start;margin-top:14px">
                <?= $view('partials/icon', ['name' => 'info', 'size' => 18]) ?>
                <div>该插件当前处于停用状态，配置可以保存，但要启用后才会生效。</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>配置项</h3>
            <span class="spacer"></span>
            <span class="text-light" style="font-size:12.5px">共 <?= count($fields) ?> 项</span>
        </div>

        <div class="panel__body">
            <?php if ($fields === []): ?>
                <div class="empty">
                    <?= $view('partials/icon', ['name' => 'settings', 'size' => 42]) ?>
                    <p>该插件没有声明任何配置项。</p>
                </div>
            <?php else: ?>
                <?php foreach ($fields as $key => $field): ?>
                    <?php
                    $type    = (string)($field['type'] ?? 'text');
                    $label   = (string)($field['label'] ?? $key);
                    $default = (string)($field['default'] ?? '');
                    $hint    = (string)($field['hint'] ?? '');
                    $options = is_array($field['options'] ?? null) ? $field['options'] : [];

                    // 已保存值缺失时回落到清单里的默认值
                    $current = $valueOf((string)$key, (string)($values[$key] ?? $default));
                    $fieldId = 'plugin-field-' . preg_replace('/[^a-z0-9_\-]/i', '_', (string)$key);
                    ?>
                    <?php if ($type === 'checkbox'): ?>
                        <label class="hstack" style="gap:8px;align-items:flex-start;margin-bottom:14px">
                            <input type="checkbox" id="<?= e($fieldId) ?>" name="<?= e((string)$key) ?>"
                                   value="1" <?= checked($isTruthy($current)) ?>>
                            <span>
                                <strong style="font-size:14px"><?= e($label) ?></strong>
                                <span class="text-light" style="display:block;font-size:12px">
                                    <?= e($key) ?><?= $hint !== '' ? ' · ' . e($hint) : '' ?>
                                </span>
                            </span>
                        </label>
                    <?php else: ?>
                        <div data-field>
                            <label for="<?= e($fieldId) ?>"><?= e($label) ?></label>

                            <?php if ($type === 'textarea'): ?>
                                <textarea id="<?= e($fieldId) ?>" name="<?= e((string)$key) ?>" rows="4"
                                          maxlength="2000"><?= e($current) ?></textarea>
                            <?php elseif ($type === 'select'): ?>
                                <select id="<?= e($fieldId) ?>" name="<?= e((string)$key) ?>">
                                    <?php foreach ($options as $optionValue => $optionLabel): ?>
                                        <option value="<?= e((string)$optionValue) ?>"
                                            <?= selected($current, (string)$optionValue) ?>>
                                            <?= e((string)$optionLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($type === 'number'): ?>
                                <input type="number" id="<?= e($fieldId) ?>" name="<?= e((string)$key) ?>"
                                       value="<?= e($current) ?>">
                            <?php else: ?>
                                <input type="text" id="<?= e($fieldId) ?>" name="<?= e((string)$key) ?>"
                                       maxlength="2000" value="<?= e($current) ?>">
                            <?php endif; ?>

                            <span data-hint>
                                <code><?= e((string)$key) ?></code><?= $hint !== '' ? ' · ' . e($hint) : '' ?>
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($fields !== []): ?>
            <div class="panel__foot hstack">
                <button type="submit" class="button">
                    <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                    <span>保存配置</span>
                </button>
                <a class="button ghost" href="<?= e(url('/admin/plugins')) ?>">
                    <?= $view('partials/icon', ['name' => 'arrow-left', 'size' => 15]) ?>
                    <span>返回插件列表</span>
                </a>
            </div>
        <?php endif; ?>
    </section>
</form>
