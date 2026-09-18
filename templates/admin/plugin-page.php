<?php
/**
 * 后台：插件注册的后台页面（占位视图）
 *
 * 变量：$pluginPage（页面元数据：slug/title/icon/permission/plugin/handler）、$pluginId
 *
 * 只有在插件提供的处理器「没有返回任何字符串」时才会走到这里。
 * 返回了 HTML 的处理器会直接接管输出，不会渲染本模板。
 *
 * 提醒插件作者：处理器可以直接 return 一段 HTML，也可以调用 $view(...)
 * 渲染插件自己的模板目录；返回空字符串则回落到本占位页。
 */

declare(strict_types=1);

$pluginPage = is_array($pluginPage ?? null) ? $pluginPage : [];
$pluginId   = (string)($pluginId ?? ($pluginPage['plugin'] ?? ''));

$slug       = (string)($pluginPage['slug'] ?? '');
$title      = (string)($pluginPage['title'] ?? '插件页面');
$permission = (string)($pluginPage['permission'] ?? '');
$handler    = (string)($pluginPage['handler'] ?? '');
?>

<section class="panel">
    <div class="panel__head">
        <h3>
            <?= $view('partials/icon', [
                'name' => (string)($pluginPage['icon'] ?? '') !== '' ? (string)$pluginPage['icon'] : 'puzzle',
                'size' => 16,
            ]) ?>
            <?= e($title) ?>
        </h3>
        <span class="spacer"></span>
        <span class="badge outline mono" style="font-size:11.5px"><?= e($pluginId) ?></span>
    </div>

    <div class="panel__body">
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'puzzle', 'size' => 46]) ?>
            <p>该插件页面没有输出任何内容。</p>
            <p class="text-light" style="font-size:13px">
                这通常意味着插件的页面处理器返回了空字符串。本页面只是占位，避免出现白屏。
            </p>
        </div>

        <dl class="kv" style="margin-top:var(--space-4)">
            <dt>所属插件</dt><dd class="mono"><?= e($pluginId) ?></dd>
            <dt>页面标识</dt><dd class="mono"><?= e($slug !== '' ? $slug : '—') ?></dd>
            <dt>访问地址</dt>
            <dd class="mono"><?= e($slug !== '' ? url('/admin/plugin/' . $slug) : '—') ?></dd>
            <dt>所需权限</dt><dd class="mono"><?= e($permission !== '' ? $permission : '未声明（默认 admin.access）') ?></dd>
            <dt>处理器</dt><dd class="mono"><?= e($handler !== '' ? $handler : '未声明') ?></dd>
        </dl>
    </div>

    <div class="panel__foot">
        <a class="button ghost small" href="<?= e(url('/admin/plugins')) ?>">
            <?= $view('partials/icon', ['name' => 'arrow-left', 'size' => 15]) ?>
            <span>返回插件列表</span>
        </a>
    </div>
</section>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'code', 'size' => 16]) ?>如何让这个页面显示内容</h3>
    </div>
    <div class="panel__body">
        <p style="margin-top:0;font-size:13.5px;color:var(--qq-ink-2)">
            在插件的入口文件里注册后台页面，并让处理器返回一段 HTML 字符串：
        </p>
        <pre class="snippet"><code>use Core\Plugin;

// plugin.json 里的 settings / hooks 之外，用代码注册页面：
Plugin::adminPage('<?= e($slug !== '' ? $slug : 'example') ?>', [
    'title'      => '<?= e($title !== '' && $title !== '插件页面' ? $title : '示例页面') ?>',
    'permission' => '<?= e($permission !== '' ? $permission : 'admin.access') ?>',
    'handler'    => [ExamplePlugin::class, 'renderAdmin'],
]);

// 处理器：返回字符串即接管输出
public static function renderAdmin(array $params): string
{
    return '&lt;section class="panel"&gt;...&lt;/section&gt;';
}</code></pre>

        <div class="doc-note" style="margin-top:12px">
            处理器返回空字符串时才会显示本占位页；抛出的异常会被框架统一记录到运行日志。
        </div>
    </div>
</section>
