<?php
/**
 * 后台：版块新增 / 编辑（共用模板）
 *
 * 变量：$forum（null 表示新增，数组表示编辑）、$parents（父版块下拉）、
 *       $groups（用户组下拉）、$action
 *
 * 权限字段说明：
 *  - group_view / group_thread / group_reply 保存为「逗号分隔的用户组 ID」
 *  - 全部不勾选 = 不限制（所有用户组可用）
 *  - 只要勾选了任意一个，就变成白名单：只有被勾选的用户组可以在该版块做对应操作
 */

declare(strict_types=1);

$forum      = is_array($forum ?? null) ? $forum : null;
$isEdit     = $forum !== null;
$parents    = is_array($parents ?? null) ? $parents : [];
$groups     = is_array($groups ?? null) ? $groups : [];
$action     = (string)($action ?? url('/admin/forums'));

/** 取值：编辑时用当前值，新增时用默认值 */
$val = static fn (string $key, string $default = ''): string => (string)($forum[$key] ?? $default);

/** 布尔字段：编辑时用当前值，新增时默认开启 */
$flag = static fn (string $key, bool $default = true): bool => $forum === null
    ? $default
    : (int)($forum[$key] ?? 0) === 1;

/** 已勾选的白名单用户组 */
$checkedGroups = static function (string $field) use ($forum): array {
    return group_ids_from_field((string)($forum[$field] ?? ''));
};

$viewGroupIds   = $checkedGroups('group_view');
$viewThreadIds  = $checkedGroups('group_thread');
$viewReplyIds   = $checkedGroups('group_reply');
?>

<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>

    <!-- 基本信息 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'grid', 'size' => 16]) ?>基本信息</h3>
        </div>
        <div class="panel__body">
            <div class="form-grid">
                <div data-field>
                    <label for="forum-name">版块名称 <span class="text-light">（必填）</span></label>
                    <input type="text" id="forum-name" name="name" maxlength="60" required
                           value="<?= e($val('name', (string)old('name', ''))) ?>">
                    <?php if (old_error('name') !== ''): ?>
                        <span class="field-error"><?= e(old_error('name')) ?></span>
                    <?php endif; ?>
                </div>

                <div data-field>
                    <label for="forum-slug">版块标识</label>
                    <input type="text" id="forum-slug" name="slug" maxlength="60"
                           placeholder="例如 general（小写字母、数字、下划线或短横线）"
                           value="<?= e($val('slug', (string)old('slug', ''))) ?>">
                    <?php if (old_error('slug') !== ''): ?>
                        <span class="field-error"><?= e(old_error('slug')) ?></span>
                    <?php endif; ?>
                    <span data-hint>用于生成更友好的地址，可留空。</span>
                </div>
            </div>

            <div data-field>
                <label for="forum-description">版块简介</label>
                <input type="text" id="forum-description" name="description" maxlength="200"
                       placeholder="展示在版块列表与版块头部" value="<?= e($val('description')) ?>">
            </div>

            <div data-field>
                <label for="forum-announcement">版块公告</label>
                <textarea id="forum-announcement" name="announcement" rows="3" maxlength="1000"
                          placeholder="留空则不发送；填写并保存后，会以系统通知的形式发给所有用户"><?= e($val('announcement')) ?></textarea>
                <span class="field-hint">
                    前台版块页不再展示公告，公告统一走通知中心：保存时若内容有变动，
                    会给所有正常状态的用户各推送一条系统通知，内容自动带上「【本版块名】」前缀。
                    内容未改动时不会重复推送。
                </span>
            </div>

            <div class="form-grid">
                <div data-field>
                    <label for="forum-icon">图标标识</label>
                    <input type="text" id="forum-icon" name="icon" maxlength="40"
                           placeholder="预留字段，可留空" value="<?= e($val('icon')) ?>">
                </div>

                <div data-field>
                    <label for="forum-sort">排序值</label>
                    <input type="number" id="forum-sort" name="sort_order" min="-9999" max="9999"
                           value="<?= e($val('sort_order', '0')) ?>">
                    <span data-hint>数字越小越靠前。</span>
                </div>
            </div>

            <div data-field style="max-width:340px">
                <label for="forum-parent">上级版块</label>
                <select id="forum-parent" name="parent_id">
                    <option value="0">— 作为一级版块 —</option>
                    <?php foreach ($parents as $parentId => $parentName): ?>
                        <option value="<?= (int)$parentId ?>"
                            <?= selected($val('parent_id', '0'), (string)$parentId) ?>>
                            <?= e((string)$parentName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isEdit): ?>
                    <span data-hint>不能选择自己作为上级版块，保存时会自动忽略。</span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- 功能开关 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'settings', 'size' => 16]) ?>功能开关</h3>
        </div>
        <div class="panel__body">
            <?php
            $switches = [
                'status'           => ['显示该版块', '关闭后前台不再展示，后台仍可管理。', true],
                'allow_thread'     => ['允许发表帖子', '关闭后该版块只能浏览，不能发新帖子。', true],
                'allow_reply'      => ['允许评论', '关闭后已存在的帖子也不能评论。', true],
                'allow_attachment' => ['允许上传附件', '关闭后仅影响该版块内的附件上传。', true],
            ];
            ?>
            <?php foreach ($switches as $key => [$label, $hint, $default]): ?>
                <label class="hstack" style="gap:8px;align-items:flex-start;margin-bottom:12px">
                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= checked($flag($key, $default)) ?>>
                    <span>
                        <strong style="font-size:14px"><?= e($label) ?></strong>
                        <span class="text-light" style="display:block;font-size:12.5px"><?= e($hint) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 用户组白名单 -->
    <section class="panel">
        <div class="panel__head">
            <h3><?= $view('partials/icon', ['name' => 'shield', 'size' => 16]) ?>用户组白名单</h3>
        </div>
        <div class="panel__body">
            <div class="doc-note hstack" style="gap:10px;align-items:flex-start;margin-bottom:14px">
                <?= $view('partials/icon', ['name' => 'info', 'size' => 18]) ?>
                <div>
                    三项均<strong>全部不勾选</strong>表示不限制，任何用户组都可用；
                    只要勾选了任意一项，就变成白名单模式，只有被勾选的用户组拥有该权限。
                    超级管理员不受此限制。
                </div>
            </div>

            <?php if ($groups === []): ?>
                <p class="text-light">尚未创建任何用户组。</p>
            <?php else: ?>
                <?php
                $whitelists = [
                    ['field' => 'group_view',   'label' => '可浏览该版块的用户组',   'checked' => $viewGroupIds],
                    ['field' => 'group_thread', 'label' => '可发表帖子的用户组',     'checked' => $viewThreadIds],
                    ['field' => 'group_reply',  'label' => '可评论帖子的用户组',     'checked' => $viewReplyIds],
                ];
                ?>
                <?php foreach ($whitelists as $list): ?>
                    <div data-field>
                        <label><?= e($list['label']) ?></label>
                        <div class="hstack" style="flex-wrap:wrap;gap:10px 16px">
                            <?php foreach ($groups as $groupId => $groupName): ?>
                                <label class="hstack" style="gap:6px;font-size:13.5px">
                                    <input type="checkbox"
                                           name="<?= e($list['field']) ?>[]"
                                           value="<?= (int)$groupId ?>"
                                        <?= checked(in_array((int)$groupId, $list['checked'], true)) ?>>
                                    <span><?= e((string)$groupName) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- 保存（整个表单只有一个提交按钮，覆盖上面三个面板的全部字段） -->
    <div class="panel__foot hstack" style="margin-top:16px">
        <button type="submit" class="button">
            <?= $view('partials/icon', ['name' => 'check', 'size' => 16]) ?>
            <span><?= $isEdit ? '保存设置' : '创建版块' ?></span>
        </button>
        <a class="button ghost" href="<?= e(url('/admin/forums')) ?>">返回版块列表</a>
        <span class="text-light" style="font-size:12.5px">
            <?= $isEdit ? '保存后前台立即生效。' : '创建后可在列表中继续编辑。' ?>
        </span>
    </div>

</form>
