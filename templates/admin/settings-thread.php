<?php
/**
 * 后台设置：帖子
 *
 * 分组 slug：thread
 * 字段：thread_need_audit / post_need_audit / post_interval / post_min_length / post_max_length
 *       + fold_long_content / fold_topic_height / fold_reply_height / fold_notice_height
 *
 * 「发帖」与「长内容折叠」两张卡片合成一页（原是两个分组页）：
 * 都是「帖子怎么发、怎么显示」，集中看更顺；保存条跟着最后一张卡片走。
 *
 * 变量：$val、$isOn（取值助手）
 *
 * 三个折叠高度的区间（100–2000）必须与 SettingsPages::INT_KEYS 和
 * helpers.php 里 content_fold() 的钳制区间一致，否则会出现「存进去的值被前端再夹一次」。
 */

declare(strict_types=1);

/**
 * 审核这两个开关不配说明文案：开关名称已经说清行为，再补一句只是噪音。
 * 说明为空时开关行会走紧凑版（勾选框与标题垂直居中），见 partials/admin-switch.php。
 */
$toggles = [
    'thread_need_audit' => ['帖子需要审核', ''],
    'post_need_audit'   => ['评论需要审核', ''],
];
?>
<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'message', 'size' => 16]) ?>发帖</h3>
    </div>
    <div class="panel__body">
        <?php foreach (['thread_need_audit', 'post_need_audit'] as $key): ?>
            <?= $view('partials/admin-switch', [
                'name'    => $key,
                'label'   => $toggles[$key][0],
                'hint'    => $toggles[$key][1],
                'checked' => $isOn($key),
            ]) ?>
        <?php endforeach; ?>

        <div class="form-grid">
            <div data-ow-field>
                <label for="post_interval">发帖间隔（秒）</label>
                <input type="number" id="post_interval" name="post_interval" min="0" max="86400"
                       value="<?= e($val('post_interval', '15')) ?>">
                <span data-ow-hint>同一用户两次发帖之间的最短间隔，0 表示不限制。</span>
            </div>

            <div data-ow-field>
                <label for="post_min_length">正文最短字数</label>
                <input type="number" id="post_min_length" name="post_min_length" min="1" max="1000"
                       value="<?= e($val('post_min_length', '2')) ?>">
                <span data-ow-hint>最短不得小于 1，且不能大于最长字数。</span>
            </div>
        </div>

        <div data-ow-field style="max-width:280px">
            <label for="post_max_length">正文最长字数</label>
            <input type="number" id="post_max_length" name="post_max_length" min="10" max="200000"
                   value="<?= e($val('post_max_length', '20000')) ?>">
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'layers', 'size' => 16]) ?>长内容折叠</h3>
    </div>
    <div class="panel__body">
        <?= $view('partials/admin-switch', [
            'name'    => 'fold_long_content',
            'label'   => '自动折叠过长的正文',
            'hint'    => '超过下面设定的显示高度时，正文末尾出现「展开全文」；内容不够长则完全不出现按钮。'
                . ' 只影响展示，不改动、不截断原文，也不影响搜索与引用。',
            'checked' => $isOn('fold_long_content', '1'),
        ]) ?>

        <div class="form-grid form-grid--3">
            <div data-ow-field>
                <label for="fold_topic_height">帖子正文高度（px）</label>
                <input type="number" id="fold_topic_height" name="fold_topic_height" min="100" max="2000"
                       value="<?= e($val('fold_topic_height', '250')) ?>">
                <span data-ow-hint>首楼正文超过这个显示高度就折叠，默认 250。</span>
            </div>

            <div data-ow-field>
                <label for="fold_reply_height">回帖高度（px）</label>
                <input type="number" id="fold_reply_height" name="fold_reply_height" min="100" max="2000"
                       value="<?= e($val('fold_reply_height', '180')) ?>">
                <span data-ow-hint>评论楼层用这个阈值，默认 180（比首楼矮一档）。</span>
            </div>

            <div data-ow-field>
                <label for="fold_notice_height">全站通知高度（px）</label>
                <input type="number" id="fold_notice_height" name="fold_notice_height" min="100" max="2000"
                       value="<?= e($val('fold_notice_height', '270')) ?>">
                <span data-ow-hint>通知中心里的公告正文，默认 270。</span>
            </div>
        </div>
    </div>

    <?= $view('partials/admin-save-foot') ?>
</section>
