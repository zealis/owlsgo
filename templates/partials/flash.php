<?php
/**
 * 一次性提示 + 表单校验错误
 *
 * 由各布局统一引入，页面模板不需要重复渲染，避免同一提示出现两次。
 * 变量来源：Core\View::defaultData() 注入的 $flashMessage / $flashType / $formErrors
 *
 * ── 样式约定（同一类反馈只用一种样式，不要混用）──
 *   1. 用户操作的结果（发帖、评论、登录、收藏、后台增删改…）
 *      → 统一走「弹出式通知」toast，和 app.js 里 AJAX 操作的 notify() 完全同源同款；
 *   2. 表单「字段校验错误」→ 保留内联块。它必须常驻可见、供用户逐项对照修改，
 *      几秒后自动消失的 toast 不适合承载这类信息；
 *   3. 站点公告一类的「不依赖操作的提醒」不走本模板 —— 公告已改为通过通知系统分发
 *      （NotificationModel::broadcastSystem()），用户在通知中心查看，前台不再有公告条。
 *
 * 渐进增强：toast 依赖 JS。这里只输出一个隐藏载体交给 app.js 弹出；
 * 禁用 JS 时由 <noscript> 中的内联样式兜底，保证提示任何情况下都不会丢失。
 */

declare(strict_types=1);

/*
 * 说明：局部模板只会拿到「显式传入的变量」与全局共享变量，不会继承页面变量。
 * 因此这里在未传入时直接回退到会话读取，保证任何调用点都能正常工作。
 *
 * $showFieldErrors：布局可传 false 关闭顶部的「请检查以下问题」汇总块。
 * 认证页（登录/注册）就是这种用法——错误直接显示在各自输入框下方，
 * 再来一个汇总块属于重复提示。
 */
$flashMessage    = isset($flashMessage) ? (string)$flashMessage : (string)\Core\Session::flash('message', '');
$flashType       = isset($flashType) ? (string)$flashType : (string)\Core\Session::flash('type', 'info');
$formErrors      = isset($formErrors) && is_array($formErrors) ? $formErrors : \Core\Session::errors();
$showFieldErrors = !isset($showFieldErrors) || (bool)$showFieldErrors;

$variant = match ($flashType) {
    'success'        => 'success',
    'error', 'danger' => 'error',
    'warning'        => 'warning',
    default          => 'info',
};

$iconName = match ($variant) {
    'success' => 'check',
    'error'   => 'alert',
    'warning' => 'alert',
    default   => 'info',
};
?>
<?php if ($flashMessage !== '' && $formErrors === []): ?>
    <?php
    /*
     * 只有「不依附于表单字段」的操作结果才走弹出式通知。
     * 若本次同时存在字段校验错误，则由下方内联块统一呈现——
     * 这样同一次反馈永远只有一种样式，不会既弹 toast 又出内联条。
     */
    ?>
    <div data-flash
         data-message="<?= e($flashMessage) ?>"
         data-type="<?= e($flashType) ?>"
         hidden></div>

    <noscript>
        <?php /* 无 JS 时的兜底：内联展示同一条消息 */ ?>
        <div class="form-flash">
            <?php /* 'info' 未在 OATUI 中定义配色，此时不输出 data-variant，回落到基础的带边框 alert 样式 */ ?>
            <div role="alert"<?= $variant !== 'info' ? ' data-variant="' . e($variant) . '"' : '' ?>>
                <?= $view('partials/icon', ['name' => $iconName, 'size' => 18]) ?>
                <div><?= e($flashMessage) ?></div>
            </div>
        </div>
    </noscript>
<?php endif; ?>

<?php if ($showFieldErrors && $formErrors !== []): ?>
    <div class="form-flash">
        <div role="alert" data-variant="error">
            <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
            <div>
                <strong>请检查以下问题：</strong>
                <ul class="unstyled" style="margin:6px 0 0;padding:0;display:flex;flex-direction:column;gap:2px">
                    <?php foreach ($formErrors as $field => $messages): ?>
                        <?php
                        /* 字段名为 key 时（如 install / ban）不展示字段前缀，避免出现英文键名 */
                        $isNamedKey = preg_match('/^[a-z][a-z0-9_]*$/', (string)$field) === 1;
                        /* 同一字段可能命中多条规则，全部列出，用户改一次就能改对 */
                        $list = is_array($messages) ? $messages : [(string)$messages];
                        ?>
                        <li>
                            <?php if (!$isNamedKey): ?>
                                <span class="text-light"><?= e((string)$field) ?>：</span>
                            <?php endif; ?>
                            <?= e(implode('；', array_map(static fn (mixed $m): string => (string)$m, $list))) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>
