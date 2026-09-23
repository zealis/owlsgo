<?php
/**
 * 后台：系统日志
 *
 * 展示 storage/logs/ 下的文件日志（PHP 报错、异常堆栈、调试信息）。
 * 与「操作日志」区分：那个是数据库里记录的「谁做了什么」，用于审计；
 * 这个是程序自身的运行日志，用于排错。
 */

declare(strict_types=1);

$files        = is_array($files ?? null) ? $files : [];
$current      = (string)($current ?? '');
$lines        = is_array($lines ?? null) ? $lines : [];
$truncated    = (bool)($truncated ?? false);
$maxLines     = (int)($maxLines ?? 400);
$debugEnabled = (bool)($debugEnabled ?? false);

$base = url('/admin/logs/system');
?>

<?php if (!$debugEnabled): ?>
    <?php
    /*
     * 这一段是用户最容易困惑的地方 ——
     * 「网站报错时说记录了，可我去哪看？」「为什么没有 debug 日志？」
     * 直接把原因和开启方式写在页面上，省得来回问。
     */
    ?>
    <div role="alert" data-ow-variant="warning" style="margin-bottom:var(--space-4)">
        <?= $view('partials/icon', ['name' => 'alert', 'size' => 18]) ?>
        <div>
            <strong>当前「调试模式」为关闭</strong>（<code>config/app.php</code> 的
            <code>debug = false</code>）。<br>
            这种状态下 <code>Logger::debug()</code> <strong>不会写入任何内容</strong>，
            所以看不到 <code>debug-*.log</code> 是正常的。
            需要排查细节时，把该配置改为 <code>true</code> 再复现一次即可。<br>
            另外请放心：<strong>错误与异常（<code>error-*.log</code>）无论调试模式开关与否都会照常记录</strong>。
        </div>
    </div>
<?php endif; ?>

<section class="panel ow-mb-4">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'file', 'size' => 16]) ?>系统日志</h3>
        <span class="spacer"></span>

        <a class="ow-button ow-ghost ow-small" href="<?= e(url('/admin/logs')) ?>">
            <?= $view('partials/icon', ['name' => 'arrow-left', 'size' => 15]) ?>
            <span>返回操作日志</span>
        </a>

        <?php if ($current !== ''): ?>
            <form method="post" action="<?= e(url('/admin/logs/system/clear')) ?>" class="inline-form"
                  data-confirm="确定清空 <?= e($current) ?> 吗？此操作不可撤销。">
                <?= csrf_field() ?>
                <input type="hidden" name="file" value="<?= e($current) ?>">
                <button type="submit" class="ow-button ow-outline ow-small">
                    <?= $view('partials/icon', ['name' => 'trash', 'size' => 15]) ?>
                    <span>清空当前文件</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="panel__body">
        <?php if ($files === []): ?>
            <p class="ow-text-light" style="margin:0">
                还没有任何日志文件。日志只在程序真正出错或记录异常时才会生成，
                位置是 <code>storage/logs/</code>。
            </p>
        <?php else: ?>
            <div class="log-tabs">
                <?php foreach ($files as $name => $meta): ?>
                    <a href="<?= e($base . '?file=' . urlencode((string)$name)) ?>"
                       class="log-tab"<?= $name === $current ? ' aria-current="page"' : '' ?>>
                        <span><?= e((string)$name) ?></span>
                        <small><?= e(format_size((int)$meta['size'])) ?>
                            · <?= e(date('m-d H:i', (int)$meta['mtime'])) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($current !== ''): ?>
                <?php if ($truncated): ?>
                    <p class="ow-text-light" style="margin:0 0 8px;font-size:12.5px">
                        文件较大，仅显示<strong>最后 <?= e((string)$maxLines) ?> 行</strong>。
                    </p>
                <?php endif; ?>

                <pre class="log-view"><?php
                    if ($lines === []) {
                        echo '（文件为空）';
                    } else {
                        foreach ($lines as $line) {
                            echo e((string)$line) . "\n";
                        }
                    }
                ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
