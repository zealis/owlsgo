<?php
/**
 * 通知中心 · 附件管理
 *
 * 变量：$rows（每条公告 + 它引用的附件）、$stats（total / used_total / unused_total）
 *
 * 只呈现引用关系。
 */

declare(strict_types=1);

$total  = (int)($stats['total'] ?? 0);
$used   = (int)($stats['used_total'] ?? 0);
$unused = (int)($stats['unused_total'] ?? 0);
?>
<ol class="unstyled hstack crumbs">
    <li><a class="unstyled" href="<?= e(url('/notifications')) ?>">通知中心</a></li>
    <li aria-hidden="true">/</li>
    <li>附件管理</li>
</ol>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'paperclip', 'size' => 16]) ?>通知中心附件管理</h3>
        <span class="spacer"></span>
        <a class="button ghost small" href="<?= e(url('/notifications')) ?>">返回通知中心</a>
    </div>

    <div class="panel__body" style="padding-top:12px;padding-bottom:12px">
        <div class="hstack" style="gap:18px;font-size:13.5px">
            <span>附件资源 <strong><?= $total ?></strong></span>
            <span>已引用 <strong><?= $used ?></strong></span>
            <span>未被公告引用 <strong><?= $unused ?></strong></span>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'paperclip', 'size' => 46]) ?>
            <p>暂无公告。</p>
        </div>
    <?php else: ?>
        <?php foreach ($rows as $index => $row): ?>
            <?php
            $notice = $row['notice'];
            $files  = $row['attachments'];
            $noticeId = (int)($notice['id'] ?? 0);
            ?>
            <div class="admin-filter" style="align-items:center;justify-content:space-between">
                <div style="min-width:0">
                    <strong><?= e((string)($notice['title'] ?? '')) ?></strong>
                    <span class="field-hint" style="margin-top:2px">
                        公告名称：<?= e((string)($notice['name'] ?? '')) ?>
                        ·
                        <?= (int)($notice['enabled'] ?? 0) === 1 ? '公开' : '未公开' ?>
                        ·
                        引用附件 <?= count($files) ?> 个
                    </span>
                </div>
                <a class="button ghost small" href="<?= e(url('/notices/' . $noticeId . '/edit')) ?>">编辑公告</a>
            </div>

            <?php if ($files === []): ?>
                <div class="panel__body text-light" style="font-size:13px">这条公告没有引用任何附件。</div>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead>
                        <tr>
                            <th>文件</th>
                            <th style="width:90px">类型</th>
                            <th style="width:100px">大小</th>
                            <th style="width:140px">上传者</th>
                            <th style="width:150px">上传时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($files as $file): ?>
                            <?php if (!empty($file['missing'])): ?>
                                <?php /* 公告引用的附件记录已不存在（被清理/删除）—— 显示死引用占位 */ ?>
                                <tr>
                                    <td>
                                        <span class="text-light">
                                            <?= e((string)($file['name'] ?? '')) ?>
                                        </span>
                                        <span class="badge" data-variant="warning" style="margin-left:6px">引用已失效</span>
                                    </td>
                                    <td class="text-light">—</td>
                                    <td class="text-light">—</td>
                                    <td class="text-light">—</td>
                                    <td class="text-light">—</td>
                                </tr>
                                <?php continue; ?>
                            <?php endif; ?>
                            <tr>
                                <td>
                                    <a href="<?= e(url('/attachment/' . (int)$file['id'])) ?>">
                                        <?= e((string)($file['name'] ?? '')) ?>
                                    </a>
                                </td>
                                <td><?= e((string)($file['mime'] ?? '-')) ?></td>
                                <td><?= e(format_size((int)($file['size'] ?? 0))) ?></td>
                                <td><?= e((string)($file['username'] ?? '已注销')) ?></td>
                                <td><?= e(date('Y-m-d H:i', (int)($file['created_at'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
