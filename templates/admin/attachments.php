<?php
/**
 * 后台：附件管理
 *
 * 变量：$result（items 已补 uploader / size_text）、$keyword、$stats（total / bytes）、$pagination
 *
 * 下载链接统一走 /attachment/{id}：该路由会重新做一次可见性校验，
 * 并且对非图片类型强制以附件形式下载，不会把上传文件当作页面执行。
 */

declare(strict_types=1);

$result  = is_array($result ?? null) ? $result : ['items' => []];
$items   = is_array($result['items'] ?? null) ? $result['items'] : [];
$keyword = (string)($keyword ?? '');
$stats   = is_array($stats ?? null) ? $stats : [];
?>

<section class="panel">
    <div class="panel__head">
        <h3><?= $view('partials/icon', ['name' => 'paperclip', 'size' => 16]) ?>附件列表</h3>
        <span class="spacer"></span>
        <span class="text-light" style="font-size:13px">
            共 <?= number_format((int)($stats['total'] ?? 0)) ?> 个 ·
            占用 <?= e((string)($stats['bytes'] ?? '0 B')) ?>
        </span>
    </div>

    <form class="admin-filter" method="get" action="<?= e(url('/admin/attachments')) ?>">
        <?php /* 与前台同一套胶囊搜索框（.search-box），放大镜按钮即提交 */ ?>
        <div class="search-box search-box--admin">
            <input type="search" name="q" value="<?= e($keyword) ?>" maxlength="60"
                   placeholder="文件名中包含…" aria-label="文件名关键词">
            <button type="submit" aria-label="筛选">
                <?= $view('partials/icon', ['name' => 'search', 'size' => 16]) ?>
            </button>
        </div>

        <?php if ($keyword !== ''): ?>
            <a class="button small ghost" href="<?= e(url('/admin/attachments')) ?>">重置</a>
        <?php endif; ?>
    </form>

    <?php if ($items === []): ?>
        <div class="empty">
            <?= $view('partials/icon', ['name' => 'paperclip', 'size' => 46]) ?>
            <p><?= $keyword !== '' ? '没有匹配的附件。' : '还没有上传任何附件。' ?></p>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>文件</th>
                    <th style="width:190px">关联内容</th>
                    <th style="width:90px">大小</th>
                    <th style="width:140px">上传者</th>
                    <th style="width:70px">下载</th>
                    <th style="width:120px">上传时间</th>
                    <th style="width:150px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $file): ?>
                    <?php
                    $fileId   = (int)($file['id'] ?? 0);
                    $isImage  = (int)($file['is_image'] ?? 0) === 1;
                    $threadId = (int)($file['thread_id'] ?? 0);
                    $postId   = (int)($file['post_id'] ?? 0);
                    $uploader = is_array($file['uploader'] ?? null) ? $file['uploader'] : null;
                    $noticeOwner = is_array($file['notice'] ?? null) ? $file['notice'] : null;
                    $downloadUrl = url('/attachment/' . $fileId);
                    ?>
                    <tr>
                        <td>
                            <div class="hstack" style="gap:8px">
                                <span class="text-light">
                                    <?= $view('partials/icon', ['name' => $isImage ? 'image' : 'file', 'size' => 16]) ?>
                                </span>
                                <span style="min-width:0">
                                    <a href="<?= e($downloadUrl) ?>" target="_blank" rel="noopener">
                                        <?= e((string)($file['name'] ?? '')) ?>
                                    </a>
                                    <span class="text-light mono" style="display:block;font-size:11.5px">
                                        #<?= $fileId ?>
                                        <?php if ((string)($file['mime'] ?? '') !== ''): ?>
                                            · <?= e((string)$file['mime']) ?>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td class="text-light">
                            <?php if ($noticeOwner !== null): ?>
                                <?php /* 公告引用的附件：公告没有 thread_id/post_id，归属要向 notices 反查 */ ?>
                                <a href="<?= e(url('/notifications')) ?>">
                                    公告《<?= e((string)$noticeOwner['title']) ?>》
                                </a>
                                <?php if ((int)$noticeOwner['enabled'] !== 1): ?>
                                    <span class="mono" style="display:block;font-size:11.5px">未公开</span>
                                <?php endif; ?>
                            <?php elseif ($threadId > 0): ?>
                                <a href="<?= e(url('/t/' . $threadId, $postId > 0 ? ['p' => $postId] : [])) ?>"
                                   target="_blank" rel="noopener">
                                    主题 #<?= $threadId ?>
                                </a>
                                <?php if ($postId > 0): ?>
                                    <span class="mono" style="display:block;font-size:11.5px">回复 #<?= $postId ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span>未绑定</span>
                                <span class="text-light" style="display:block;font-size:11.5px">
                                    用户上传后未发表对应内容
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-light mono"><?= e((string)($file['size_text'] ?? '')) ?></td>
                        <td>
                            <?php if ($uploader !== null): ?>
                                <a href="<?= e(url('/admin/users/' . (int)$uploader['id'])) ?>">
                                    <?= e((string)($uploader['username'] ?? '')) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-light">已注销用户</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-light"><?= number_format((int)($file['downloads'] ?? 0)) ?></td>
                        <td class="text-light"><?= e(human_time((int)($file['created_at'] ?? 0))) ?></td>
                        <td>
                            <div class="admin-table-actions">
                                <a class="button small ghost" href="<?= e($downloadUrl) ?>"
                                   target="_blank" rel="noopener">
                                    <?= $view('partials/icon', ['name' => 'download', 'size' => 14]) ?>
                                    <span>下载</span>
                                </a>

                                <form class="inline-form" method="post"
                                      action="<?= e(url('/admin/attachments/' . $fileId . '/delete')) ?>"
                                      data-confirm="确定要删除附件「<?= e((string)($file['name'] ?? '')) ?>」吗？磁盘文件会同时删除。">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="button small ghost" data-variant="danger">
                                        <?= $view('partials/icon', ['name' => 'trash', 'size' => 14]) ?>
                                        <span>删除</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="panel__foot text-light" style="font-size:12.5px">
        删除附件会同时清除磁盘文件，且无法在应用内恢复。
    </div>

    <?php if (($pagination ?? '') !== ''): ?>
        <div class="panel__foot"><?= (string)$pagination ?></div>
    <?php endif; ?>
</section>
