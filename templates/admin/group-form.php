<?php
/**
 * 后台：用户组新增 / 编辑（共用模板）
 *
 * 变量：$group（null 表示新增）、$catalog（Core\Permission::CATALOG）、
 *       $checked（权限键 => bool，已合并默认值）、$action、$isSuper（仅编辑时传入）
 *
 * 权限清单不在这里硬编码：直接遍历 Permission::CATALOG，按其中的「分组」字段
 * 自动分区渲染，因此以后在代码里新增一条权限，这个页面会自动出现对应开关。
 *
 * 注意：编辑接口不接收 slug（更新时不会修改标识），因此编辑态把标识作为只读信息展示。
 */

declare(strict_types=1);

$group   = is_array($group ?? null) ? $group : null;
$isEdit  = $group !== null;
$catalog = is_array($catalog ?? null) ? $catalog : [];
$checked = is_array($checked ?? null) ? $checked : [];
$action  = (string)($action ?? url('/admin/groups'));
$isSuper = (bool)($isSuper ?? false);

/** 按「分组」归集权限项 */
$sections = [];
foreach ($catalog as $key => $meta) {
    $name    = (string)($meta[0] ?? $key);
    $section = (string)($meta[1] ?? '其他');
    $desc    = (string)($meta[2] ?? '');

    $sections[$section][(string)$key] = ['name' => $name, 'desc' => $desc];
}

$val = static fn (string $key, string $default = ''): string => (string)($group[$key] ?? $default);
?>

<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <?php if ($isSuper): ?>
        <div role="alert" data-ow-variant="warning" style="margin-bottom:var(--space-4)">
            <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
            <div>
                这是超级管理员用户组，拥有全部权限且<strong>不可被削减</strong>。
                保存时系统仍会强制写回全部权限，避免把自己锁在门外。
            </div>
        </div>
    <?php endif; ?>

    <!-- 基本信息 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'shield', 'size' => 16]) ?>用户组信息</h3>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <div data-ow-field>
                    <label for="group-name">用户组名称 <span class="ow-text-light">（必填）</span></label>
                    <input type="text" id="group-name" name="name" maxlength="60" required
                           value="<?= e((string)old('name', $val('name'))) ?>">
                    <?php if (old_error('name') !== ''): ?>
                        <span class="field-error"><?= e(old_error('name')) ?></span>
                    <?php else: ?>
                        <span data-ow-hint>显示在后台列表与前台用户名旁。</span>
                    <?php endif; ?>
                </div>

                <?php if ($isEdit): ?>
                    <div data-ow-field>
                        <label for="group-slug">用户组标识</label>
                        <input type="text" id="group-slug" value="<?= e($val('slug')) ?>" readonly disabled>
                        <span data-ow-hint>标识创建后不可修改。</span>
                    </div>
                <?php else: ?>
                    <div data-ow-field>
                        <label for="group-slug">用户组标识</label>
                        <input type="text" id="group-slug" name="slug" maxlength="32"
                               placeholder="例如 vip（小写字母、数字、下划线或短横线）"
                               value="<?= e((string)old('slug', '')) ?>">
                        <?php if (old_error('slug') !== ''): ?>
                            <span class="field-error"><?= e(old_error('slug')) ?></span>
                        <?php endif; ?>
                        <span data-ow-hint>留空会自动生成，需保证唯一。</span>
                    </div>
                <?php endif; ?>
            </div>

            <div data-ow-field>
                <label for="group-description">简要说明</label>
                <input type="text" id="group-description" name="description" maxlength="120"
                       placeholder="展示在后台列表中，帮助识别该组的用途"
                       value="<?= e($val('description')) ?>">
                <span data-ow-hint>选填，最多 120 字。</span>
            </div>

            <div class="form-grid">
                <div data-ow-field>
                    <label for="group-quota">附件空间上限</label>
                    <input type="number" id="group-quota" name="attach_quota_mb" min="0"
                           max="<?= (int)\Modules\User\UsergroupModel::QUOTA_MAX ?>" step="1"
                           value="<?= (int)($quotaMb ?? 0) ?>">
                    <span data-ow-hint>单位 MB，<strong>0 = 不限制</strong>；超限后拒绝上传，删除附件即释放。</span>
                </div>

                <div data-ow-field>
                    <label for="group-sort">排序值</label>
                    <input type="number" id="group-sort" name="sort_order" min="-9999" max="9999"
                           value="<?= e($val('sort_order', '0')) ?>">
                    <span data-ow-hint>数字越小越靠前，内置组默认 0~5。</span>
                </div>
            </div>

            <div class="form-grid">
                <div data-ow-field>
                    <label for="group-color">组标识颜色</label>
                    <input type="color" id="group-color" name="color"
                           value="<?= e($val('color', '#00A0E9')) ?>">
                    <span data-ow-hint>用于前台用户名着色。</span>
                </div>
            </div>
        </div>
    </section>

    <!-- 权限清单 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'key', 'size' => 16]) ?>权限设置</h3>
            <span class="spacer"></span>
            <span class="ow-text-light" style="font-size:12.5px">共 <?= count($catalog) ?> 项</span>
        </div>
        <div class="panel__body">
            <?php if ($isSuper): ?>
                <div class="doc-note ow-hstack" style="gap:10px;align-items:flex-start;margin-bottom:14px">
                    <?= $view('partials/icon', ['name' => 'info', 'size' => 18]) ?>
                    <div>超级管理员组的开关已全部锁定为开启。</div>
                </div>
            <?php endif; ?>

            <?php foreach ($sections as $sectionName => $items): ?>
                <div style="margin-bottom:var(--space-4)">
                    <h4 style="font-size:14px;margin:0 0 10px"><?= e((string)$sectionName) ?></h4>

                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
                        <?php foreach ($items as $permKey => $item): ?>
                            <?php
                            $isOn = $isSuper || !empty($checked[$permKey]);
                            ?>
                            <label class="perm-item">
                                <input type="checkbox" name="permissions[]" value="<?= e($permKey) ?>"
                                    <?= checked($isOn) ?>
                                    <?= $isSuper ? 'disabled' : '' ?>>
                                <?php if ($isSuper): ?>
                                    <?php /* 禁用控件不会提交，用隐藏字段补上真实值 */ ?>
                                    <input type="hidden" name="permissions[]" value="<?= e($permKey) ?>">
                                <?php endif; ?>
                                <strong><?= e($item['name']) ?></strong>
                                <?php if ($item['desc'] !== ''): ?>
                                    <small><?= e($item['desc']) ?></small>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="panel__foot ow-hstack">
            <button type="submit" class="ow-button">
                <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
                <span><?= $isEdit ? '保存修改' : '创建用户组' ?></span>
            </button>
            <a class="ow-button ow-ghost" href="<?= e(url('/admin/groups')) ?>">
                <?= $view('partials/icon', ['name' => 'arrow-left', 'size' => 15]) ?>
                <span>返回列表</span>
            </a>
        </div>
    </section>
</form>
