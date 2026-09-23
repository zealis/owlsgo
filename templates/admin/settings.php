<?php
/**
 * 后台：站点设置（分组页面的外壳）
 *
 * 设置已拆成「一个分组一页」：本文件只负责页面骨架 ——
 * 面包屑、表单包裹、保存条；各组字段在 `templates/admin/settings-{slug}.php`。
 *
 * 分组清单与「键 → 处理方式」登记在 Modules\Admin\SettingsPages：
 * 侧栏下拉、这里的页面模板、以及保存时处理哪些键，三处共用同一份定义。
 *
 * 变量：
 *   $settingSlug      当前分组 slug
 *   $settingPage      分组定义（label / icon / summary / form / keys）
 *   $settings         全部设置项
 *   $groups           用户组下拉
 *   $uploadMax/$postMax/$uploadDirSize  服务器与占用信息
 *   $opcacheAvailable/$debugEnabled     系统维护面板用
 *
 * 表单字段与服务端的解析逻辑一一对应（见 AdminController::saveSettings()）：
 *  - 文本框：受长度限制，服务端会再次裁剪
 *  - 复选框：未勾选时浏览器不提交，服务端按「未提交即 0」处理
 *  - 数字框：服务端做区间钳制，这里同步给出 min/max 以便前端先拦一层
 */

declare(strict_types=1);

$slug              = (string)($settingSlug ?? 'basic');
$page              = is_array($settingPage ?? null) ? $settingPage : ['label' => '', 'summary' => '', 'form' => true];
$settings          = is_array($settings ?? null) ? $settings : [];
$groups            = is_array($groups ?? null) ? $groups : [];
$uploadMax         = (string)($uploadMax ?? '—');
$postMax           = (string)($postMax ?? '—');
$uploadDirSize     = (string)($uploadDirSize ?? '—');
$opcacheAvailable  = (bool)($opcacheAvailable ?? false);
$debugEnabled      = (bool)($debugEnabled ?? false);

/** 取值助手：优先回填上次提交值，其次取当前设置 */
$val = static fn (string $key, string $default = ''): string => (string)old($key, (string)($settings[$key] ?? $default));

/** 开关是否勾选：优先回填值，其次取当前设置 */
$isOn = static function (string $key, string $default = '0') use ($settings): bool {
    $raw = old($key, null);
    $raw = $raw === null || $raw === '' ? (string)($settings[$key] ?? $default) : (string)$raw;

    return in_array($raw, ['1', 'on', 'true', 'yes'], true);
};

/*
 * 分组页面本体。先渲染成字符串，再决定要不要套表单：
 * 「系统与维护」页自带多个独立表单（保存站点开关 / 清 OPcache），套进来会变成 form 嵌套。
 */
$fields = $view('admin/settings-' . $slug, [
    'val'              => $val,
    'isOn'             => $isOn,
    'groups'           => $groups,
    'uploadMax'        => $uploadMax,
    'postMax'          => $postMax,
    'uploadDirSize'    => $uploadDirSize,
    'opcacheAvailable' => $opcacheAvailable,
    'debugEnabled'     => $debugEnabled,
]);
?>
<ol class="ow-unstyled ow-hstack crumbs">
    <li><a class="ow-unstyled" href="<?= e(url('/admin')) ?>">管理后台</a></li>
    <li aria-hidden="true">/</li>
    <li>站点设置</li>
    <li aria-hidden="true">/</li>
    <li><?= e((string)$page['label']) ?></li>
</ol>

<?php if (!empty($page['form'])): ?>
    <form method="post" action="<?= e(url('/admin/settings/' . $slug)) ?>" data-ajax data-ajax-redirect>
        <?= csrf_field() ?>
        <?= $fields ?>
    </form>
<?php else: ?>
    <?= $fields ?>
<?php endif; ?>
